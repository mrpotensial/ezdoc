<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\Envelope\PkcsSevenEnvelope;
use Ezdoc\Signature\KeyStore\FileKeyStore;
use Ezdoc\Signature\Pdf\IncrementalPdfSigner;
use Ezdoc\Signature\Pdf\PdfBytesRange;
use Ezdoc\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Menandatangani PDF dengan menambahkan bagian baru di ujungnya.
 *
 * Satu sifat menentukan segalanya di sini, dan ia yang paling banyak diuji:
 * **memasukkan tanda tangan tidak boleh mengubah satu pun byte di luar
 * lubangnya.** Kalau ia mengubah, ringkasan yang ditandatangani tidak lagi
 * cocok, dan setiap pembaca PDF akan melaporkan dokumen sudah diubah — menuduh
 * orang yang tidak melakukan apa-apa.
 *
 * PDF ujinya disusun di sini dengan offset xref yang dihitung sungguhan, bukan
 * ditulis tangan. PDF yang offsetnya asal akan membuat tes lulus atas berkas
 * yang tidak pernah bisa dibuka siapa pun.
 */
final class IncrementalPdfSignerTest extends TestCase
{
    private IncrementalPdfSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new IncrementalPdfSigner();
    }

    // ─── bentuk keluarannya ───

    public function testThePreparedPdfCarriesAParseableByteRange(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf());

        $range = PdfBytesRange::fromPdf($prepared->pdf());

        self::assertFalse($range->isPlaceholder());
        self::assertTrue($range->isFullCoverage(strlen($prepared->pdf())));
    }

    /**
     * Lubangnya harus persis `<` … `>`. Meleset satu byte berarti tanda
     * tangannya menandatangani sebagian dirinya sendiri.
     */
    public function testTheGapIsExactlyTheContentsHexString(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf());
        $pdf = $prepared->pdf();
        $range = $prepared->byteRange();

        self::assertSame('<', $pdf[$range->getSignatureOffset()]);
        self::assertSame('>', $pdf[$range->getStart2() - 1]);
        self::assertSame(
            str_repeat('0', $range->getSignatureLength() - 2),
            substr($pdf, $range->getSignatureOffset() + 1, $range->getSignatureLength() - 2)
        );
    }

    /**
     * Incremental update: yang lama tetap utuh, yang baru menempel di ujung.
     * Kalau byte awal berubah, tanda tangan lain yang sudah ada ikut batal.
     */
    public function testTheOriginalBytesAreUntouched(): void
    {
        $original = self::samplePdf();

        $prepared = $this->signer->prepare($original);

        self::assertSame($original, substr($prepared->pdf(), 0, strlen($original)));
    }

    public function testThePreparedPdfStillEndsAsAPdf(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf());

        self::assertStringEndsWith("%%EOF\n", $prepared->pdf());
        self::assertMatchesRegularExpression('/startxref\s+\d+\s+%%EOF\s*$/', $prepared->pdf());
    }

    public function testItPointsBackAtThePreviousCrossReferenceTable(): void
    {
        $original = self::samplePdf();
        preg_match('/startxref\s+(\d+)/', $original, $m);

        $prepared = $this->signer->prepare($original);

        self::assertStringContainsString('/Prev ' . $m[1], $prepared->pdf());
    }

    public function testTheSignatureFieldIsRegisteredInTheDocument(): void
    {
        $pdf = $this->signer->prepare(self::samplePdf(), ['field_name' => 'TandaTangan1'])->pdf();

        self::assertStringContainsString('/AcroForm', $pdf);
        self::assertStringContainsString('/SigFlags 3', $pdf);
        self::assertStringContainsString('/Annots', $pdf);
        self::assertStringContainsString('(TandaTangan1)', $pdf);
    }

    public function testTheDeclaredMetadataReachesTheFile(): void
    {
        $pdf = $this->signer->prepare(self::samplePdf(), [
            'name'     => 'dr. Budi',
            'reason'   => 'Persetujuan tindakan',
            'location' => 'RSIA Anugrah',
        ])->pdf();

        self::assertStringContainsString('/Name (dr. Budi)', $pdf);
        self::assertStringContainsString('/Reason (Persetujuan tindakan)', $pdf);
        self::assertStringContainsString('/Location (RSIA Anugrah)', $pdf);
    }

    /**
     * Tabel xref bagian tambahan harus menunjuk objeknya sungguhan.
     *
     * Ini kelas kesalahan paling berbahaya yang tersisa: offset yang meleset
     * menghasilkan berkas yang lulus setiap pemeriksaan tekstual dan tetap gagal
     * dibuka. Karena itu diperiksa dari arah pembaca PDF — ikuti offsetnya, dan
     * di sana harus ada objek yang nomornya dijanjikan.
     */
    public function testEveryOffsetInTheNewCrossReferenceTablePointsAtItsObject(): void
    {
        $pdf = $this->signer->prepare(self::samplePdf())->pdf();

        preg_match_all('/startxref\s+(\d+)/', $pdf, $sx);
        $xrefAt = (int) end($sx[1]);

        $end = strpos($pdf, 'trailer', $xrefAt);
        self::assertNotFalse($end, 'bagian xref baru tanpa trailer');

        $section = substr($pdf, $xrefAt + 4, $end - $xrefAt - 4);

        preg_match_all(
            '/(\d+)\s+(\d+)\s*\n((?:\d{10}\s+\d{5}\s+[nf]\s*\n?)+)/',
            $section,
            $subs,
            PREG_SET_ORDER
        );
        self::assertNotEmpty($subs, 'tabel xref baru tidak terbaca');

        $checked = 0;
        foreach ($subs as $sub) {
            $start = (int) $sub[1];
            preg_match_all('/(\d{10})\s+(\d{5})\s+([nf])/', $sub[3], $entries, PREG_SET_ORDER);

            foreach ($entries as $i => $entry) {
                if ($entry[3] !== 'n') {
                    continue;
                }
                $num = $start + $i;
                $at = (int) $entry[1];

                self::assertMatchesRegularExpression(
                    '/^' . $num . '\s+0\s+obj/',
                    substr($pdf, $at, 32),
                    "offset xref untuk objek #{$num} tidak menunjuk ke awal objeknya"
                );
                $checked++;
            }
        }

        // Empat objek diperbarui: kamus tanda tangan, medannya, halaman, katalog.
        self::assertSame(4, $checked);
    }

    // ─── sifat yang menentukan segalanya ───

    /**
     * Yang ditandatangani sebelum memasukkan CMS harus sama persis dengan yang
     * dicakup sesudahnya. Ini satu-satunya alasan tanda tangan PDF bisa bekerja.
     */
    public function testInjectingDoesNotChangeWhatWasSigned(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf());

        $before = $prepared->digest();
        $signed = $prepared->inject(str_repeat("\x30\x82\x01\x00", 64));

        $after = PdfBytesRange::computeHash($signed, PdfBytesRange::fromPdf($signed));

        self::assertSame(bin2hex($before), bin2hex($after));
    }

    public function testInjectingDoesNotChangeTheFileLength(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf());

        self::assertSame(
            strlen($prepared->pdf()),
            strlen($prepared->inject(random_bytes(512)))
        );
    }

    public function testTheInjectedBytesComeBackOut(): void
    {
        $cms = random_bytes(300);
        $prepared = $this->signer->prepare(self::samplePdf());
        $signed = $prepared->inject($cms);

        $range = PdfBytesRange::fromPdf($signed);
        $hex = substr($signed, $range->getSignatureOffset() + 1, $range->getSignatureLength() - 2);

        self::assertSame(bin2hex($cms), substr($hex, 0, strlen($cms) * 2));
    }

    public function testACmsTooLargeForTheGapIsRefused(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf(), ['reserved_size' => 2048]);

        $this->expectException(EzdocException::class);
        $prepared->inject(random_bytes(2049));
    }

    public function testTheGapSizeFollowsTheRequest(): void
    {
        $prepared = $this->signer->prepare(self::samplePdf(), ['reserved_size' => 4096]);

        self::assertSame(4096, $prepared->capacity());
    }

    // ─── batas lingkup, dinyatakan terang-terangan ───

    public function testSomethingThatIsNotAPdfIsRefused(): void
    {
        $this->expectException(EzdocException::class);
        $this->signer->prepare('halo, ini bukan pdf');
    }

    /**
     * Menumpuk tanda tangan kedua memang sah di PDF, tapi bukan tugas kelas ini
     * — dan diam-diam merusaknya jauh lebih buruk daripada menolaknya.
     */
    public function testAnAlreadySignedPdfIsRefused(): void
    {
        $once = $this->signer->prepare(self::samplePdf())->inject(random_bytes(128));

        $this->expectException(EzdocException::class);
        $this->signer->prepare($once);
    }

    public function testAnEncryptedPdfIsRefused(): void
    {
        $pdf = str_replace('/Size 6', '/Size 6 /Encrypt 9 0 R', self::samplePdf());

        $this->expectException(EzdocException::class);
        $this->signer->prepare($pdf);
    }

    public function testAPdfThatAlreadyHasAFormIsRefused(): void
    {
        $pdf = self::samplePdf('<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [] >> >>');

        $this->expectException(EzdocException::class);
        $this->signer->prepare($pdf);
    }

    // ─── ujung ke ujung dengan OpenSSL sungguhan ───

    /**
     * Bukti bahwa rangkaiannya bekerja dengan CMS sungguhan.
     *
     * CMS-nya dibuat lewat `PkcsSevenEnvelope` — pembuat yang benar-benar
     * dipakai produksi — bukan dirakit sendiri di tes ini. Versi pertama tes ini
     * merakit sendiri dari keluaran S/MIME, salah membaca bentuk
     * `multipart/signed`, dan diam-diam menandatangani 20 byte sampah. Ia tetap
     * lulus, karena yang diperiksanya cuma pengawetan byte.
     *
     * Pelajarannya: perkakas tes yang menyalin logika produksi akan menyalin
     * kesalahannya juga — atau menambah kesalahan sendiri yang tidak terlihat.
     */
    public function testARealCmsSurvivesTheRoundTrip(): void
    {
        if (!TestPki::available()) {
            self::markTestSkipped('OpenSSL tidak bisa membuat kunci di mesin ini.');
        }

        $pki = TestPki::selfSigned('pdf-sign');
        $dir = sys_get_temp_dir() . '/ezdoc-pdfsign-' . bin2hex(random_bytes(6));
        mkdir($dir, 0770, true);
        file_put_contents($dir . '/k.key', $pki['keyPem']);
        file_put_contents($dir . '/k.crt', $pki['certPem']);

        try {
            $store = new FileKeyStore($dir);
            $prepared = $this->signer->prepare(self::samplePdf(), ['reserved_size' => 8192]);

            $cms = (new PkcsSevenEnvelope('', true))->pack(
                '',
                $prepared->signedBytes(),
                $store->loadCertificate('k'),
                ['private_key' => $store->loadPrivateKey('k')]
            );

            self::assertGreaterThan(500, strlen($cms), 'CMS terlalu kecil untuk masuk akal');
            self::assertSame('3082', bin2hex(substr($cms, 0, 2)), 'CMS bukan DER SEQUENCE');

            $signed = $prepared->inject($cms);

            // Dibaca ulang dari berkas jadinya, bukan dari nilai yang tadi
            // dipegang — yang diuji justru apakah berkasnya sendiri konsisten.
            $range = PdfBytesRange::fromPdf($signed);
            self::assertTrue($range->isFullCoverage(strlen($signed)));

            self::assertSame(
                bin2hex($prepared->signedBytes()),
                bin2hex($range->computeHashedContent($signed)),
                'byte yang dicakup berubah setelah tanda tangan dimasukkan'
            );

            $hex = substr($signed, $range->getSignatureOffset() + 1, $range->getSignatureLength() - 2);
            self::assertSame(bin2hex($cms), substr($hex, 0, strlen($cms) * 2));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    // ─── perkakas ───

    /**
     * PDF satu halaman yang benar-benar sah, offset xref-nya dihitung.
     *
     * Ditulis ulang tiap dipanggil alih-alih disimpan sebagai berkas biner:
     * fixture biner tidak bisa dibaca saat meninjau perubahan, dan yang tidak
     * bisa dibaca tidak bisa dipercaya.
     */
    private static function samplePdf(?string $catalog = null): string
    {
        $stream = "BT /F1 24 Tf 72 700 Td (Halo dunia) Tj ET";

        $objects = [
            1 => $catalog ?? '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
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
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d %05d n \n", $off, 0);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . ' /Root 1 0 R'
            . " /ID [<0102030405060708090a0b0c0d0e0f10> <0102030405060708090a0b0c0d0e0f10>] >>\n"
            . "startxref\n" . $xrefAt . "\n%%EOF\n";

        return $pdf;
    }
}
