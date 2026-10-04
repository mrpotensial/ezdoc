<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use PHPUnit\Framework\TestCase;

/**
 * Janji jalur "kirim kode ke email penanda tangan" yang tidak boleh bergeser.
 *
 * Perilakunya diuji di {@see ScanEmailDeliveryTest} dan lewat HTTP sungguhan di
 * uji ujung-ke-ujung. Yang di sini adalah urutan di dalam aksinya — siapa
 * memegang kode, dan apa yang terjadi kalau suratnya tidak terkirim.
 */
final class ScanEmailDeliveryContractTest extends TestCase
{
    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /** Potongan aksi dari cabang `delivery === 'email'` sampai akhir jawabannya. */
    private static function emailBranch(): string
    {
        $src = self::source('actions/document/request_scan_code.php');
        $mulai = strpos($src, "if (\$delivery === 'email') {\n    \$repo = ");
        self::assertNotFalse($mulai, 'cabang email tidak ditemukan');
        $jawab = strpos($src, 'ezdoc_respond_success', (int) $mulai);
        self::assertNotFalse($jawab);
        $akhir = strpos($src, ']);', (int) $jawab);

        return substr($src, (int) $mulai, (int) $akhir + 3 - (int) $mulai);
    }

    /** Kode tidak pernah sampai ke layar petugas — ia datang dari mulut penanda tangan. */
    public function testTheStaffResponseCarriesNoCodeAndNoToken(): void
    {
        $cabang = self::emailBranch();
        $jawab = substr($cabang, (int) strpos($cabang, 'ezdoc_respond_success'));

        self::assertStringNotContainsString("'code'", $jawab);
        self::assertStringNotContainsString('scan_token', $jawab);
        self::assertStringContainsString('masked_recipient', $jawab);
    }

    /** Surat yang gagal mematikan kodenya SEBELUM menjawab — kode tak terkirim tak boleh bisa dipakai. */
    public function testAnUndeliveredCodeIsBurnedBeforeAnswering(): void
    {
        $cabang = self::emailBranch();
        $gagal = (int) strpos($cabang, "if (!\$sent['ok']) {");
        $bakar = strpos($cabang, "\$repo->expire(\$issued['challenge_id'])", $gagal);
        $jawabGagal = strpos($cabang, 'ezdoc_respond_error', $gagal);

        self::assertGreaterThan(0, $gagal);
        self::assertNotFalse($bakar);
        self::assertNotFalse($jawabGagal);
        self::assertLessThan($jawabGagal, $bakar);
    }

    /** Kalimat persetujuan disimpan hanya sesudah suratnya terbukti terkirim. */
    public function testConsentIsRecordedOnlyAfterASuccessfulSend(): void
    {
        $cabang = self::emailBranch();

        self::assertGreaterThan(
            (int) strpos($cabang, "if (!\$sent['ok']) {"),
            (int) strpos($cabang, '->recordConsent(')
        );
    }

    /**
     * Kode lama untuk slot itu baru dimatikan SESUDAH surat baru terkirim.
     *
     * Ditemukan uji ujung-ke-ujung: versi pertama mematikannya sebelum
     * mengirim, sehingga alamat salah ketik yang ditolak SMTP ikut mematikan
     * kode sah yang sedang dipegang pasien.
     */
    public function testOldCodesAreRetiredOnlyAfterASuccessfulSend(): void
    {
        $cabang = self::emailBranch();
        $gagal = (int) strpos($cabang, "if (!\$sent['ok']) {");
        $pensiun = strpos($cabang, '->expirePendingForSlot(');

        self::assertNotFalse($pensiun);
        self::assertGreaterThan($gagal, $pensiun);
        self::assertStringContainsString(
            "ScanOtpService::ROUTE_EMAIL, \$issued['challenge_id'])",
            substr($cabang, (int) $pensiun, 160),
            'kode yang baru terkirim harus dikecualikan'
        );
    }

    /** Formulirnya hanya digambar kalau aplikasi pemakai menyediakan pengirimnya. */
    public function testTheFormExistsOnlyWhenADelivererIsConfigured(): void
    {
        $view = self::source('views/document/generate.php');
        $form = (int) strpos($view, 'id="signEmailForm"');
        $jaga = strrpos(substr($view, 0, $form), "defined('EZDOC_SCAN_EMAIL_DELIVERY') && is_callable(EZDOC_SCAN_EMAIL_DELIVERY)");

        self::assertGreaterThan(0, $form);
        self::assertNotFalse($jaga, 'formulir email harus dijaga konfigurasi');
        self::assertLessThan(800, $form - (int) $jaga, 'penjaganya harus tepat di atas formulir');
    }

    /**
     * Pesan hasilnya ditulis di bawah kolom, bukan toast.
     *
     * Toast tertutup modal tanda tangan — itulah sebabnya "kirim ke aplikasi"
     * terlihat tidak melakukan apa-apa saat dokumennya belum disimpan.
     */
    public function testFeedbackIsInlineNotAToast(): void
    {
        $js = self::source('views/_partials/generate_scripts.php');
        $mulai = (int) strpos($js, 'async function requestEmailSign()');
        $akhir = (int) strpos($js, "document.addEventListener('submit'", $mulai);
        $fungsi = substr($js, $mulai, $akhir - $mulai);

        self::assertGreaterThan(0, $mulai);
        self::assertStringNotContainsString('showToast', $fungsi);
        self::assertStringContainsString('signEmailMsg', $fungsi);
    }

    /** Kalimat untuk petugas tidak menyebut satu domain — pustaka ini netral. */
    public function testTheWordingStaysNeutral(): void
    {
        foreach (['lang/id/generate.php', 'lang/en/generate.php'] as $berkas) {
            $src = self::source($berkas);
            $mulai = (int) strpos($src, 'email_send_label');
            $potong = substr($src, $mulai, 900);

            foreach (['pasien', 'patient', 'rumah sakit', 'hospital', 'SIMpel'] as $kata) {
                self::assertStringNotContainsStringIgnoringCase($kata, $potong, $berkas . ' menyebut "' . $kata . '"');
            }
        }
    }
}
