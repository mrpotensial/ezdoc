<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use Ezdoc\Document\PkiArtifactSigner;
use Ezdoc\Signature\KeyStore\FileKeyStore;
use Ezdoc\Signature\Pdf\PdfBytesRange;
use Ezdoc\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Menyegel artefak dengan sertifikat sendiri, dari ujung ke ujung.
 *
 * Kunci sungguhan, sertifikat sungguhan, OpenSSL sungguhan. Yang diuji bukan
 * "apakah fungsinya terpanggil" tapi apakah berkas hasilnya benar-benar bisa
 * dipertanggungjawabkan: byte yang dicakup tanda tangan harus tetap sama setelah
 * tanda tangannya masuk, dan CMS di dalamnya harus benar-benar terverifikasi
 * terhadap byte itu.
 *
 * Kalau satu offset saja meleset, tes terakhir gagal — dan tanpa tes itu,
 * kesalahannya baru muncul di Adobe Reader orang lain.
 */
final class PkiArtifactSignerTest extends TestCase
{
    private string $keyDir = '';

    protected function setUp(): void
    {
        if (!TestPki::available()) {
            self::markTestSkipped('OpenSSL tidak bisa membuat kunci di mesin ini.');
        }

        $pki = TestPki::selfSigned('artifact-seal', 365, 'RSIA Anugrah');

        $this->keyDir = sys_get_temp_dir() . '/ezdoc-seal-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0770, true);
        file_put_contents($this->keyDir . '/segel.key', $pki['keyPem']);
        file_put_contents($this->keyDir . '/segel.crt', $pki['certPem']);
    }

    protected function tearDown(): void
    {
        if ($this->keyDir !== '' && is_dir($this->keyDir)) {
            foreach (glob($this->keyDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->keyDir);
        }
    }

    private function signer(): PkiArtifactSigner
    {
        return new PkiArtifactSigner(new FileKeyStore($this->keyDir), 'segel');
    }

    public function testSealingProducesAPdfWithASignature(): void
    {
        $sealed = $this->signer()->sign(self::samplePdf());

        self::assertStringStartsWith('%PDF-', $sealed);
        self::assertStringContainsString('/Type /Sig', $sealed);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $sealed);
    }

    public function testTheOriginalPdfIsStillIntactInside(): void
    {
        $original = self::samplePdf();

        $sealed = $this->signer()->sign($original);

        self::assertSame($original, substr($sealed, 0, strlen($original)));
    }

    public function testTheDeclaredSignerNameReachesTheFile(): void
    {
        $sealed = $this->signer()->sign(self::samplePdf(), [
            'name'   => 'dr. Budi Santoso',
            'reason' => 'Persetujuan tindakan medis',
        ]);

        self::assertStringContainsString('/Name (dr. Budi Santoso)', $sealed);
        self::assertStringContainsString('/Reason (Persetujuan tindakan medis)', $sealed);
    }

    /** Tanpa nama yang disebutkan, yang dipakai nama di sertifikatnya. */
    public function testItFallsBackToTheCertificateSubject(): void
    {
        $sealed = $this->signer()->sign(self::samplePdf());

        self::assertStringContainsString('/Name (RSIA Anugrah)', $sealed);
    }

    /**
     * `/ByteRange` harus menutupi seluruh berkas, dengan satu lubang saja.
     *
     * Cakupan yang berlubang di tempat lain adalah cara klasik menyelundupkan
     * perubahan: bagian yang tidak tercakup bebas diubah tanpa membatalkan
     * tanda tangannya.
     */
    public function testTheSignatureCoversTheWholeFile(): void
    {
        $sealed = $this->signer()->sign(self::samplePdf());

        $range = PdfBytesRange::fromPdf($sealed);

        self::assertTrue($range->isFullCoverage(strlen($sealed)));
        self::assertSame('<', $sealed[$range->getSignatureOffset()]);
        self::assertSame('>', $sealed[$range->getStart2() - 1]);
    }

    /**
     * Yang tertanam di `/Contents` benar-benar CMS kita, bukan sisa isian nol.
     *
     * Diperiksa lewat sertifikatnya: CMS SignedData membawa sertifikat
     * penandatangan di dalamnya, jadi byte sertifikat kita harus ada di sana.
     * Ini yang membedakan "ada sesuatu di lubangnya" dari "yang benar ada di
     * lubangnya".
     *
     * **Catatan jujur:** verifikasi kriptografis penuh atas CMS *terpisah*
     * (detached) tidak bisa dilakukan dari PHP — `openssl_pkcs7_verify()` tidak
     * punya jalan memberi isinya, dan ia menjawab "no content". Itu keterbatasan
     * yang sudah dicatat di `PkcsSevenEnvelope`, bukan temuan baru. Yang
     * memeriksa sungguhan nanti adalah pembaca PDF-nya. Karena itu risiko yang
     * benar-benar bisa kita kejar — offset yang meleset — diuji habis-habisan di
     * tempat lain, dan pembuatan CMS-nya sendiri sudah punya tesnya di
     * `PkcsSevenEnvelopeTest`.
     */
    public function testTheEmbeddedBytesAreOurRealCms(): void
    {
        $sealed = $this->signer()->sign(self::samplePdf());

        $range = PdfBytesRange::fromPdf($sealed);
        $hex = substr($sealed, $range->getSignatureOffset() + 1, $range->getSignatureLength() - 2);
        $der = (string) hex2bin($hex);

        // DER SignedData selalu SEQUENCE bentuk panjang: 0x30 0x82 ...
        self::assertSame('3082', bin2hex(substr($der, 0, 2)), 'bukan struktur DER SEQUENCE');

        $certDer = self::certDer((string) file_get_contents($this->keyDir . '/segel.crt'));
        self::assertNotSame('', $certDer);
        self::assertStringContainsString(
            $certDer,
            $der,
            'CMS di dalam berkas tidak membawa sertifikat kita'
        );
    }

    /** Byte DER sebuah sertifikat PEM. */
    private static function certDer(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m) !== 1) {
            return '';
        }

        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);

        return is_string($der) ? $der : '';
    }

    /**
     * Menyegel dua kali ditolak, bukan diam-diam menumpuk.
     *
     * Menumpuk tanda tangan memang sah di PDF, tapi bukan di sini — dan yang
     * dilakukan diam-diam akan menghasilkan berkas yang tidak seorang pun sengaja
     * membuatnya.
     */
    public function testSealingTwiceIsRefused(): void
    {
        $once = $this->signer()->sign(self::samplePdf());

        $this->expectException(\Ezdoc\Exceptions\EzdocException::class);
        $this->signer()->sign($once);
    }

    /** PDF satu halaman yang sah, offset xref-nya dihitung sungguhan. */
    private static function samplePdf(): string
    {
        $stream = 'BT /F1 18 Tf 72 700 Td (Dokumen uji) Tj ET';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
                 . ' /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d %05d n \n", $off, 0);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n"
            . "startxref\n" . $xrefAt . "\n%%EOF\n";

        return $pdf;
    }
}
