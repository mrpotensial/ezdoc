<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use PHPUnit\Framework\TestCase;

/**
 * Janji jalur "kirim ke aplikasi penanda tangan" yang tidak boleh bergeser.
 *
 * Perilakunya diuji di {@see ScanSignerSideTest} (layanan dan repositori) dan
 * lewat HTTP sungguhan di uji ujung-ke-ujung. Yang di sini adalah sifat yang
 * paling mahal kalau diam-diam berubah — semuanya soal siapa memegang apa.
 */
final class ScanAppDeliveryContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /** Potongan aksi dari cabang `delivery === 'app'` sampai jawabannya. */
    private static function appBranch(): string
    {
        $src = self::source('actions/document/request_scan_code.php');
        $mulai = strpos($src, "if (\$delivery === 'app') {\n    \$doc = ");
        self::assertNotFalse($mulai, 'cabang app tidak ditemukan');
        $akhir = strpos($src, 'ezdoc_respond_success', (int) $mulai);
        self::assertNotFalse($akhir);

        return substr($src, (int) $mulai, (int) $akhir - (int) $mulai);
    }

    /**
     * Pengirim milik aplikasi pemakai tidak pernah menerima token.
     *
     * Tokennya adalah hak membuka permintaan. Pengirim cukup tahu "ada
     * permintaan untuk subjek ini"; aplikasi pemakai mengambil tokennya sendiri
     * lewat ezdoc_scan_pending() setelah memastikan yang bertanya memang
     * tertaut. Kalau token ikut dikirim, ia bisa berakhir di log notifikasi,
     * antrean pesan, atau isi pemberitahuan di layar kunci.
     */
    public function testTheDelivererNeverReceivesTheToken(): void
    {
        $cabang = self::appBranch();
        $panggil = substr($cabang, (int) strpos($cabang, 'call_user_func'));
        $panggil = substr($panggil, 0, (int) strpos($panggil, ']);') + 3);

        self::assertStringContainsString('subject_id', $panggil);
        self::assertStringNotContainsString('scan_token', $panggil);
    }

    /** Jawaban ke layar petugas juga tidak membawa token permintaan aplikasi. */
    public function testTheStaffResponseCarriesNoToken(): void
    {
        $src = self::source('actions/document/request_scan_code.php');
        $mulai = (int) strpos($src, "if (\$delivery === 'app') {\n    \$doc = ");
        $jawab = substr($src, (int) strpos($src, 'ezdoc_respond_success', $mulai));
        $jawab = substr($jawab, 0, (int) strpos($jawab, ']);'));

        self::assertStringContainsString("'challenge_id'", $jawab);
        self::assertStringNotContainsString('scan_token', $jawab);
    }

    /** Gagalnya pengirim tidak membatalkan permintaan yang sudah terbit. */
    public function testAFailingDelivererDoesNotCancelTheRequest(): void
    {
        $cabang = self::appBranch();
        $kirim = (int) strpos($cabang, 'call_user_func');
        $coba = strrpos(substr($cabang, 0, $kirim), 'try {');

        self::assertNotFalse($coba, 'pengirim harus dibungkus try');
        self::assertStringContainsString('catch (\Throwable', substr($cabang, $kirim));
    }

    /** Permintaan lama untuk slot yang sama dimatikan sebelum yang baru terbit. */
    public function testAnEarlierRequestIsRetiredFirst(): void
    {
        $cabang = self::appBranch();

        self::assertNotFalse(strpos($cabang, 'expirePendingForSlot'));
        self::assertLessThan(
            strpos($cabang, '->issue('),
            strpos($cabang, 'expirePendingForSlot'),
            'permintaan lama harus dimatikan SEBELUM yang baru terbit'
        );
    }

    /** Permintaan aplikasi menunjuk dokumen milik template yang sama, dan yang belum dihapus. */
    public function testTheDocumentMustBelongToTheTemplate(): void
    {
        $cabang = self::appBranch();

        self::assertStringContainsString('`template_id` = ?', $cabang);
        self::assertStringContainsString('`deleted_at` IS NULL', $cabang);
    }

    /** Pola tautan QR hanya disyaratkan untuk QR — permintaan aplikasi tidak menggambar QR. */
    public function testTheQrUrlIsOnlyRequiredForQr(): void
    {
        $src = self::source('actions/document/request_scan_code.php');

        self::assertMatchesRegularExpression(
            "/if \\(\\\$delivery === 'qr' && \\(\\\$urlTemplate === ''/",
            $src
        );
    }

    /** Tombolnya hanya digambar kalau aplikasi pemakai menyediakan pengirimnya. */
    public function testTheButtonExistsOnlyWhenADelivererIsConfigured(): void
    {
        $view = self::source('views/document/generate.php');
        $tombol = (int) strpos($view, 'id="btnRequestApp"');
        $jaga = strrpos(substr($view, 0, $tombol), "defined('EZDOC_SCAN_APP_DELIVERY') && is_callable(EZDOC_SCAN_APP_DELIVERY)");

        self::assertGreaterThan(0, $tombol);
        self::assertNotFalse($jaga, 'tombol kirim ke aplikasi harus dijaga konfigurasi');
        self::assertLessThan(400, $tombol - (int) $jaga, 'penjaganya harus tepat di atas tombol');
    }

    /** Kalimat untuk penanda tangan tidak menyebut satu domain — pustaka ini netral. */
    public function testTheWordingStaysNeutral(): void
    {
        foreach (['lang/id/generate.php', 'lang/en/generate.php', 'views/document/generate.php'] as $berkas) {
            $src = self::source($berkas);
            $mulai = (int) strpos($src, 'app_send_btn');
            $potong = substr($src, $mulai, 1200);

            foreach (['pasien', 'patient', 'rumah sakit', 'hospital', 'SIMpel'] as $kata) {
                self::assertStringNotContainsStringIgnoringCase($kata, $potong, $berkas . ' menyebut "' . $kata . '"');
            }
        }
    }
}
