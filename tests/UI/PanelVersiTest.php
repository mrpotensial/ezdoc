<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Daftar versi: panel, bukan dropdown.
 *
 * Dilaporkan pemakai: "untuk versi ini user sering bingung… apa kita buat
 * disebelah kiri list versi yang ada detail lainnya gitu? jadi user bisa pilih
 * dengan jelas bukan dari dropdown?".
 *
 * Dropdown lamanya menyebut nomor versi dan tidak lebih. Pertanyaan yang
 * sebenarnya ditanyakan orang adalah "yang mana yang sudah lengkap tanda
 * tangannya?" — dan itu tidak bisa dijawab dari daftar yang isinya "v1, v2,
 * v3". Jadi yang dijaga di sini bukan panelnya sendiri, melainkan keterangan
 * yang membuatnya berguna: kemajuan tanda tangan tiap versi, dan siapa yang
 * membuatnya.
 */
final class PanelVersiTest extends TestCase
{
    private static function node(): ?string
    {
        foreach (['node', 'nodejs'] as $bin) {
            $probe = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
            if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
                return $bin;
            }
        }

        return null;
    }

    private static function baca(string $berkas): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $berkas);
    }

    /** @return array<string,mixed> */
    private static function daftarVersi(bool $denganTersembunyi = false): array
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_daftar_versi.php'),
            escapeshellarg($denganTersembunyi ? 'dengan-tersembunyi' : '')
        ), $baris);
        $keluaran = trim(implode("\n", $baris));

        $data = json_decode($keluaran, true);
        self::assertIsArray($data, 'jawaban bukan JSON: ' . $keluaran);

        return $data;
    }

    // ── Keterangan yang membuat daftarnya berguna ────────────────────────

    /**
     * Tiap versi membawa kemajuan tanda tangannya.
     *
     * Dan yang dihitung SLOT yang terisi, bukan baris tanda tangan: contoh di
     * fixture menandatangani satu slot dua kali, dan "4 dari 3" bukan kalimat
     * yang menolong siapa pun.
     */
    public function testTiapVersiMembawaKemajuanTandaTangan(): void
    {
        $data = self::daftarVersi();

        self::assertSame(3, $data['slot_total'], 'jumlah slot tanda tangan tidak dikirim');
        self::assertCount(2, $data['versions']);

        $perVersi = [];
        foreach ($data['versions'] as $v) {
            $perVersi[(int) $v['version']] = $v;
        }

        self::assertSame(3, $perVersi[1]['signed_count'], 'slot yang ditandatangani dua kali ikut terhitung dua');
        self::assertSame(1, $perVersi[2]['signed_count']);
    }

    /** Dan siapa yang membuatnya, lewat sumber nama yang disambung pemakai. */
    public function testTiapVersiMembawaNamaPembuat(): void
    {
        $data = self::daftarVersi();

        foreach ($data['versions'] as $v) {
            self::assertSame('Bidan Ratna', $v['created_by_name'], 'nama pembuat versi tidak ikut');
        }
    }

    /** Versi tersembunyi tetap hanya muncul kalau diminta. */
    public function testVersiTersembunyiTetapHanyaMunculKalauDiminta(): void
    {
        self::assertCount(2, self::daftarVersi()['versions']);

        $dengan = self::daftarVersi(true);
        self::assertCount(3, $dengan['versions']);

        $tersembunyi = null;
        foreach ($dengan['versions'] as $v) {
            if ((int) $v['version'] === 3) {
                $tersembunyi = $v;
            }
        }
        self::assertNotNull($tersembunyi);
        self::assertSame(1, $tersembunyi['is_hidden']);
        self::assertSame(0, $tersembunyi['signed_count']);
    }

    /**
     * Keterangan tambahannya diambil sekaligus, bukan per baris.
     *
     * Satu slot bisa punya belasan versi. Satu kueri per versi adalah N+1 yang
     * sama yang melahirkan eager loading di Doctrine dan Eloquent — dan panel
     * ini dibuka justru saat orang sedang ragu, bukan saat ia santai menunggu.
     */
    public function testKeteranganTambahannyaDiambilSekaligus(): void
    {
        $aksi = self::baca('actions/document/list_versions.php');

        self::assertSame(
            1,
            preg_match('/FROM `ezdoc_signatures`\s*\n?\s*WHERE `document_id` IN \(/', $aksi),
            'kemajuan tanda tangan diambil per baris, bukan sekaligus'
        );
        self::assertStringContainsString('ezdoc_actor_names(', $aksi, 'nama pembuat diambil satu per satu');
    }

    // ── Panelnya ─────────────────────────────────────────────────────────

    /** Panelnya ada, dan pemicunya menggantikan dropdown di sidebar. */
    public function testPanelnyaAdaDanDropdownnyaTersembunyi(): void
    {
        $view = self::baca('views/document/generate.php');

        self::assertStringContainsString('id="versionPanel"', $view, 'panel daftar versi tidak ada');
        self::assertStringContainsString('id="btnVersionPanel"', $view, 'pemicunya tidak ada');
        self::assertStringContainsString('class="ez-versi-select"', $view, 'select-nya tidak ditandai untuk disembunyikan');

        self::assertSame(
            1,
            preg_match('/\.ez-versi-select \{ display: none; \}/', self::baca('views/_partials/generate_styles.php')),
            'select lamanya masih terlihat'
        );
    }

    /**
     * Panelnya saudara sidebar, bukan anaknya.
     *
     * `.toolbar` punya `overflow-y: auto`. Anak berposisi fixed di dalam kotak
     * yang menggulung akan terpotong begitu daftarnya lebih panjang dari
     * sidebar — dan justru daftar panjang yang butuh panel ini.
     */
    public function testPanelnyaDiLuarSidebar(): void
    {
        $view = self::baca('views/document/generate.php');

        $posPanel = strpos($view, 'id="versionPanel"');
        $posToolbar = strpos($view, '<div class="toolbar" id="toolbarPanel">');
        self::assertNotFalse($posPanel);
        self::assertNotFalse($posToolbar);
        self::assertLessThan($posToolbar, $posPanel, 'panelnya berada di dalam sidebar');
    }

    /**
     * Dua perilaku lebar, bukan satu yang dipaksakan.
     *
     * Diminta pemakai: "pastikan responsive ya, karena kadang buka di tablet
     * dan ipad". Di bawah 1024px sidebar sudah pindah ke pojok bawah dan panel
     * 320px di sampingnya tidak muat lagi, jadi ia jadi lembar bawah selebar
     * layar. Ambangnya sengaja sama dengan yang sudah dipakai sidebar.
     */
    public function testPanelnyaBerubahBentukDiLayarSempit(): void
    {
        $css = self::baca('views/_partials/generate_styles.php');

        // Lebar: menempel di kiri sidebar.
        self::assertSame(
            1,
            preg_match('/\.ez-versi-panel \{[^}]*right: 246px;/s', $css),
            'panelnya tidak menempel di kiri sidebar'
        );

        // Sempit: lembar bawah, di dalam media query yang sudah ada.
        self::assertSame(
            1,
            preg_match('/@media screen and \(max-width: 1024px\) \{[\s\S]*?\.ez-versi-panel \{[^}]*bottom: 0;[^}]*\}/', $css),
            'panelnya tidak jadi lembar bawah di layar sempit'
        );

        // Sentuh: sasarannya diperbesar hanya di perangkat sentuh.
        self::assertSame(
            1,
            preg_match('/@media \(hover: none\) and \(pointer: coarse\) \{[\s\S]*?\.ez-versi-baris \{ padding: 11px 12px; \}/', $css),
            'baris versinya tidak diperbesar untuk sentuhan'
        );
    }

    /** Bisa ditutup dengan Esc dan dengan menekan di luar panelnya. */
    public function testBisaDitutupTanpaMencariTombolnya(): void
    {
        $skrip = self::baca('views/_partials/generate_scripts.php');
        $view = self::baca('views/document/generate.php');

        self::assertSame(
            1,
            preg_match("/if \(e\.key !== 'Escape'\) return;[\s\S]{0,300}?toggleVersionPanel\(false\)/", $skrip),
            'Esc tidak menutup panelnya'
        );
        self::assertStringContainsString('id="versionBackdrop"', $view, 'tidak ada tirai untuk ditekan');
        self::assertStringContainsString('onclick="toggleVersionPanel(false)"', $view);
    }

    /**
     * Daftarnya disegarkan tiap kali panelnya dibuka.
     *
     * Versi bisa bertambah dari tab lain atau dari orang lain. Daftar basi
     * lebih menyesatkan daripada tidak ada daftar, karena ia terlihat pasti.
     */
    public function testDaftarnyaDisegarkanTiapDibuka(): void
    {
        $skrip = self::baca('views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match('/function toggleVersionPanel[\s\S]{0,900}?refreshVersionList\(\);/', $skrip),
            'panelnya menampilkan daftar lama saat dibuka'
        );
    }

    /** Kalimatnya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            foreach ([
                'version_panel_title', 'version_count', 'version_none', 'version_open_now',
                'version_complete', 'version_partial', 'version_draft', 'version_hidden',
                'version_no_slots',
            ] as $kunci) {
                self::assertArrayHasKey($kunci, $lang['toolbar'], $bahasa . ': ' . $kunci);
            }
            self::assertArrayHasKey('version_panel', $lang['title'], $bahasa);
        }
    }

    // ── Isinya, dijalankan sungguhan ─────────────────────────────────────

    /** Percabangan lencananya diperiksa di atas DOM tiruan. */
    public function testIsiPanelnyaDirenderBenar(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = self::baca('views/_partials/generate_scripts.php');
        $awal = strpos($skrip, 'function _versiWaktu(teks) {');
        self::assertNotFalse($awal, 'pemformat waktu tidak ketemu');
        $akhir = strpos($skrip, '/** Buka atau tutup panelnya.', $awal);
        self::assertNotFalse($akhir, 'ujung bagian render tidak ketemu');

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-panel-') . '.js';
        file_put_contents(
            $js,
            substr($skrip, $awal, $akhir - $awal)
            . "\nthis.renderVersionPanel = renderVersionPanel;\n"
        );

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/panel-versi.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }
}
