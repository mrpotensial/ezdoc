<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Signature\SignatureValue;
use PHPUnit\Framework\TestCase;

final class SignatureValueTest extends TestCase
{
    /**
     * Yang dijaga tes ini: penanda mode QR tidak boleh pernah lolos sebagai
     * gambar. Kalau lolos, ia berakhir di atribut `src` dan yang tercetak di
     * dokumen adalah ikon gambar rusak di tempat tanda tangan.
     */
    public function testQrActIsNotAnImage(): void
    {
        self::assertFalse(SignatureValue::isImage(SignatureValue::QR_ACT));
        self::assertTrue(SignatureValue::isQrAct(SignatureValue::QR_ACT));
    }

    public function testDataUriIsAnImage(): void
    {
        self::assertTrue(SignatureValue::isImage('data:image/png;base64,iVBORw0KGgo='));
        self::assertFalse(SignatureValue::isQrAct('data:image/png;base64,iVBORw0KGgo='));
    }

    /**
     * Prefix diperiksa dari awal string, bukan dengan pencarian di mana saja.
     * Nilai yang cuma MEMUAT "data:" bukan data URI.
     */
    public function testImageCheckIsAnchoredAtTheStart(): void
    {
        self::assertFalse(SignatureValue::isImage('x data:image/png;base64,AAAA'));
        self::assertFalse(SignatureValue::isImage(' data:image/png;base64,AAAA'));
    }

    public function testEmptyValueIsNeitherImageNorQrAct(): void
    {
        self::assertFalse(SignatureValue::isImage(''));
        self::assertFalse(SignatureValue::isQrAct(''));
    }

    /**
     * @dataProvider unsignedValues
     */
    public function testUnsignedValues(string $value): void
    {
        self::assertFalse(SignatureValue::isSigned($value));
    }

    /** @return array<string, array{string}> */
    public static function unsignedValues(): array
    {
        return [
            'kosong'        => [''],
            'spasi'         => ['   '],
            'tab dan enter' => ["\t\n "],
        ];
    }

    /**
     * isSigned() sengaja lebih luas dari dua bentuk yang dikenali. Dokumen lama
     * bisa menyimpan bentuk lain, dan menyebutnya "belum ditandatangani" akan
     * menghapus tanda tangan yang sungguhan terjadi.
     *
     * @dataProvider signedValues
     */
    public function testSignedValues(string $value): void
    {
        self::assertTrue(SignatureValue::isSigned($value));
    }

    /** @return array<string, array{string}> */
    public static function signedValues(): array
    {
        return [
            'gambar'                => ['data:image/png;base64,iVBORw0KGgo='],
            'penanda qr'            => [SignatureValue::QR_ACT],
            'bentuk tak dikenal'    => ['sesuatu-dari-versi-lama'],
        ];
    }

    /**
     * Penandanya berversi supaya bentuknya bisa berubah nanti tanpa membuat
     * dokumen lama jadi ambigu. Kalau nilai ini pernah berubah, dokumen yang
     * sudah tersimpan berhenti terbaca sebagai ditandatangani — jadi
     * perubahannya harus disengaja, bukan efek samping refactor.
     */
    public function testMarkerValueIsFrozen(): void
    {
        self::assertSame('qr:v1', SignatureValue::QR_ACT);
    }

    /**
     * Penandanya tidak boleh bisa dikira URL, path, atau data URI oleh kode lain
     * yang cuma melihat "ada isinya".
     */
    public function testMarkerLooksLikeNothingElse(): void
    {
        $marker = SignatureValue::QR_ACT;
        self::assertStringNotContainsString('/', $marker);
        self::assertStringNotContainsString('.', $marker);
        self::assertStringNotContainsString('base64', $marker);
        self::assertSame($marker, trim($marker));
    }

    /**
     * Penandanya hidup di dua tempat: konstanta ini, dan satu literal di sisi
     * klien yang menuliskannya ke input tersembunyi.
     *
     * Kalau keduanya bergeser, tidak ada yang error. Dokumennya tersimpan, layar
     * menunjukkan QR, dan tidak ada satu pun record tanda tangan yang tertulis —
     * karena gerbang di save_document.php mencocokkan nilai POST dengan konstanta
     * ini secara persis. Kegagalan yang tidak bersuara seperti itu justru yang
     * paling layak dites.
     */
    public function testClientSideMarkerMatchesTheConstant(): void
    {
        $path = dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php';
        self::assertFileExists($path);

        $source = (string) file_get_contents($path);
        self::assertSame(
            1,
            preg_match("/const EZDOC_QR_ACT = '([^']*)'/", $source, $m),
            'Konstanta EZDOC_QR_ACT tidak ditemukan di generate_scripts.php'
        );
        self::assertSame(SignatureValue::QR_ACT, $m[1]);
    }

    /**
     * Gerbang di save_document.php harus memakai konstantanya, bukan literal
     * salinan — kalau tidak, tes di atas menjaga kesejajaran yang salah.
     */
    public function testSaveGateUsesTheConstant(): void
    {
        $path = dirname(__DIR__, 2) . '/actions/document/save_document.php';
        self::assertFileExists($path);

        $source = (string) file_get_contents($path);
        self::assertStringContainsString('SignatureValue::QR_ACT', $source);
        self::assertStringNotContainsString("=== 'qr:v1'", $source);
    }
}
