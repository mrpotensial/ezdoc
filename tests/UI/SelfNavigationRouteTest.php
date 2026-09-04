<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Halaman generate harus menavigasi ke nama rutenya SENDIRI.
 *
 * Ini lahir dari bug yang dilaporkan pengguna: membuka dokumen dari daftar lalu
 * mengganti versi menghasilkan **"Document Not Found"**.
 *
 * Sebabnya rantai yang masuk akal di tiap langkahnya:
 *
 *   1. daftar menaut ke `?ezdoc_page=view&uuid=…`
 *   2. `handleView()` menyelesaikan uuid, mengisi `template_id` + `doc_id`,
 *      lalu memanggil `handleGenerate()` — jadi halaman generate yang tampil
 *   3. `_preservedParams()` membaca `ezdoc_page=view` dari URL peramban dan
 *      membawanya apa adanya
 *   4. navigasi berikutnya jadi `?ezdoc_page=view&template_id=…&version=…`
 *      — **tanpa uuid**
 *   5. `handleView()` tanpa uuid menjawab 404
 *
 * `view` bukan tujuan; ia pintu. Begitu juga `download` (menyalakan
 * `download=1`) dan `generate_list` (memaksa `template_id=0`). Ketiganya
 * meneruskan ke `handleGenerate()`, dan ketiganya rusak kalau namanya ikut
 * terbawa ke navigasi mandiri halaman.
 *
 * Yang dijaga di sini: nama rute kanoniknya dikirim server ke JS, dan JS
 * memakainya alih-alih menebak dari URL yang mengantarnya.
 */
final class SelfNavigationRouteTest extends TestCase
{
    private function router(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Router.php');
    }

    private function scripts(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');
    }

    private function view(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');
    }

    /**
     * Rute penerusnya masih ada — kalau tidak, tes di bawah menjaga sesuatu yang
     * sudah tidak berlaku, dan lulus selamanya tanpa memeriksa apa pun.
     */
    public function testRutePenerusMemangAda(): void
    {
        $r = $this->router();

        foreach (['handleView', 'handleDownload', 'handleGenerateList'] as $handler) {
            self::assertStringContainsString(
                'function ' . $handler . '(',
                $r,
                "{$handler} hilang — premis tes ini perlu ditinjau ulang"
            );
        }

        // Ketiganya harus benar-benar meneruskan ke generate.
        self::assertGreaterThanOrEqual(
            3,
            substr_count($r, 'return $this->handleGenerate($req, $res);'),
            'rute penerus ke generate berkurang — periksa apakah tes ini masih relevan'
        );
    }

    /** `view` memang menuntut uuid, dan menjawab 404 tanpanya. */
    public function testViewMenuntutUuid(): void
    {
        $r = $this->router();

        self::assertStringContainsString("\$uuid = (string) \$req->query('uuid', '');", $r);
        self::assertStringContainsString('Document Not Found', $r);
    }

    // ─── Perbaikannya ───

    public function testServerMengirimNamaRuteKanonik(): void
    {
        $v = $this->view();

        self::assertStringContainsString("\$config->get('urls.self_page'", $v, 'nama rutenya harus bisa diatur consumer');
        self::assertStringContainsString("'generate'", $v);
        self::assertStringContainsString("'selfPage'", $v, 'harus ikut di wadah URL yang sampai ke JS');
    }

    public function testJsMenulisUlangNamaRuteDenganMiliknyaSendiri(): void
    {
        $js = $this->scripts();

        self::assertStringContainsString('selfPage', $js);
        self::assertMatchesRegularExpression(
            "/p\.set\('ezdoc_page',\s*milikSendiri\)/",
            $js,
            'nama rute harus ditulis ulang, bukan dibawa apa adanya'
        );
    }

    /**
     * Hanya ditulis ulang kalau `ezdoc_page` memang dipakai mount ini.
     *
     * Menambahkannya di mount yang merutekan lewat path akan merusak yang
     * tadinya jalan — perbaikan yang menciptakan bug baru di tempat lain.
     */
    public function testTidakMenambahkanNamaRuteDiMountYangTidakMemakainya(): void
    {
        $js = $this->scripts();

        self::assertMatchesRegularExpression(
            "/if \(p\.has\('ezdoc_page'\)\)/",
            $js,
            "penulisan ulang harus dijaga p.has('ezdoc_page')"
        );
    }

    /**
     * Parameter milik rute penerus tidak boleh ikut terbawa.
     *
     * `uuid` yang tertinggal menunjuk dokumen lama setelah pindah versi.
     * `download` yang tertinggal membuat SETIAP ganti versi memicu unduhan —
     * gejala yang sama sekali tidak menyerupai sebabnya.
     */
    public function testParameterMilikRutePenerusDibuang(): void
    {
        $js = $this->scripts();

        foreach (['uuid', 'download', 'print'] as $param) {
            self::assertStringContainsString(
                "p.delete('" . $param . "')",
                $js,
                "'{$param}' milik rute penerus dan tidak boleh ikut ke navigasi berikutnya"
            );
        }
    }

    /**
     * Semua navigasi mandiri lewat satu pintu.
     *
     * Kalau ada yang merakit URL-nya sendiri, ia akan melewatkan perbaikan ini —
     * dan bug-nya kembali hanya di jalur itu, yang jauh lebih sulit dilacak
     * daripada bug yang muncul di mana-mana.
     */
    public function testSemuaNavigasiMandiriLewatSatuPintu(): void
    {
        $js = $this->scripts();

        $langsung = preg_match_all(
            "/location\.href\s*=\s*'\?ezdoc_page=/",
            $js
        );

        self::assertSame(
            0,
            $langsung,
            'ada navigasi yang merakit ezdoc_page sendiri, melewati _preservedParams()'
        );
    }
}
