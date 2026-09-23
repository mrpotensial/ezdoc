<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use Ezdoc\UI\Config;
use Ezdoc\UI\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Halaman pengaturan punya pintu di menu — dan hanya untuk yang boleh masuk.
 *
 * Dilaporkan dari pemakaian: "kok gak ada di menu ya?", tentang halaman yang
 * sudah berjalan di server. Halaman tanpa tautan sama saja dengan tidak ada.
 *
 * Gerbangnya tetap di halamannya sendiri; tautan ini hanya menyembunyikan yang
 * memang tidak bisa dibuka, seperti tautan admin yang hanya tampil untuk staf
 * di Django.
 */
final class AdminNavTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/role_provider.php';
        require_once dirname(__DIR__, 2) . '/lib/authorization.php';
    }

    protected function tearDown(): void
    {
        ezdoc_reset_role_provider();
    }

    /** @param list<string> $peran */
    private function nav(array $peran, ?array $itemKustom = null): string
    {
        ezdoc_set_role_provider([
            'has_role'           => static function ($r) use ($peran): bool {
                return array_intersect((array) $r, $peran) !== [];
            },
            'current_user_id'    => static function () { return 7; },
            'current_user_roles' => static function () use ($peran) { return $peran; },
        ]);

        $isi = ['brand.app_name' => 'Uji'];
        if ($itemKustom !== null) {
            $isi['layout.nav.items'] = $itemKustom;
        }
        $config = new Config($isi);
        $theme = new Theme($config);
        $content = '<p>halaman</p>';
        $title = 'Uji';

        ob_start();
        include dirname(__DIR__, 2) . '/views/layout.php';

        return (string) ob_get_clean();
    }

    public function testSuperadminMelihatTautanPengaturan(): void
    {
        $html = $this->nav(['superadmin']);

        self::assertStringContainsString('ezdoc_page=admin_migrate', $html);
        self::assertStringContainsString('Settings', $html);
    }

    public function testPenggunaBiasaTidakMelihatnya(): void
    {
        $html = $this->nav(['perawat']);

        self::assertStringNotContainsString('admin_migrate', $html);
    }

    /** Menu bawaan tetap utuh — tautan ini menambah, bukan mengganti. */
    public function testMenuBawaanTidakBerubah(): void
    {
        $html = $this->nav(['superadmin']);

        foreach (['Documents', 'Templates', 'Create'] as $judul) {
            self::assertStringContainsString($judul, $html);
        }
    }

    /**
     * Aplikasi pemakai yang menyusun menunya sendiri tidak mendapat dua tautan.
     *
     * Ia menyusunnya dengan sengaja; menambahkan yang sudah ada di sana berarti
     * menimpa keputusan itu dengan salinan.
     */
    public function testTidakDigandakanKalauSudahDisebutSendiri(): void
    {
        $html = $this->nav(['superadmin'], [
            ['label' => 'Docs', 'page' => 'list'],
            ['label' => 'Admin', 'page' => 'admin_migrate'],
        ]);

        self::assertSame(1, substr_count($html, 'ezdoc_page=admin_migrate'));
        self::assertStringNotContainsString('Settings', $html);
    }

    /**
     * Tab-nya bekerja: yang dipilih tampil, sisanya disembunyikan.
     *
     * Skripnya dijalankan di atas DOM tiruan, bukan dibaca — pemeriksaan atas
     * teks sumbernya akan lolos untuk kode yang tidak pernah menyembunyikan apa
     * pun. Dilewati kalau Node tidak ada, seperti tes JS lain di sini.
     */
    public function testTabMenampilkanSatuPanelSaja(): void
    {
        foreach (['node', 'nodejs'] as $bin) {
            $probe = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
            if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
                $node = $bin;
                break;
            }
        }
        if (!isset($node)) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/migrate.php');
        $awal = strrpos($sumber, '<script>');
        $akhir = strrpos($sumber, '</script>');
        self::assertNotFalse($awal, 'blok skrip halaman tidak ketemu');

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-admin-') . '.js';
        file_put_contents($js, substr($sumber, $awal + 8, $akhir - $awal - 8));

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/admin-tabs.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }
}
