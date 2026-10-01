<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Daftar versi: satu kartu yang selalu tampil, lengkap dengan detailnya.
 *
 * Diminta pemakai berturut-turut:
 *   1. "untuk versi ini user sering bingung… bukan dari dropdown?"
 *   2. "user suka asal klik buat versi, jadi sebaiknya list versi langsung
 *      tampil saja kali ya?"
 *   3. "tampilkan langsung detail aja versinya, tidak perlu klik detail"
 *
 * Tiga permintaan, satu arah yang sama: apa pun yang menuntut satu tindakan
 * sebelum orang tahu ada versi lain adalah penghalang — dan yang tidak tahu,
 * membuat versi baru. Di basis data pemakai sepertiga versi lanjutan lahir
 * kosong.
 *
 * Jadi yang dijaga di sini: daftarnya tampil sendiri, isinya menjawab "yang
 * mana yang sudah lengkap", dan ia tidak pernah lebih tinggi dari layar.
 */
final class KartuVersiTest extends TestCase
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
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_version_list.php'),
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
        foreach (self::daftarVersi()['versions'] as $v) {
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
     * sama yang melahirkan eager loading di Doctrine dan Eloquent.
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

    // ── Kartunya ─────────────────────────────────────────────────────────

    /** Daftarnya tampil sendiri: tidak ada pemicu, tidak ada panel terpisah. */
    public function testDaftarnyaTampilTanpaTindakanApaPun(): void
    {
        $view = self::baca('views/document/generate.php');

        self::assertStringContainsString('id="versionCard"', $view, 'kartu daftar versi tidak ada');
        self::assertStringContainsString('id="versionCardList"', $view, 'isi kartunya tidak ada');
        self::assertStringContainsString('id="versionCardCount"', $view, 'jumlah versinya tidak ada');
        self::assertStringContainsString('id="btnToggleHidden"', $view, 'penyaring versi tersembunyi hilang');
        self::assertStringContainsString('class="ez-versi-select"', $view, 'select-nya tidak ditandai untuk disembunyikan');

        // Tiga hal yang sudah dibuang; masing-masing pernah menuntut satu klik.
        foreach (['id="versionPanel"', 'id="btnVersionPanel"', 'ez-versi-kartu-detail'] as $sisa) {
            self::assertStringNotContainsString($sisa, $view, 'masih ada ' . $sisa . ' yang menuntut klik');
        }
    }

    /** Dan tidak ada sisa panel di gaya maupun skripnya. */
    public function testTidakAdaSisaPanel(): void
    {
        foreach ([
            'views/_partials/generate_styles.php' => ['ez-versi-panel', 'ez-versi-tirai'],
            'views/_partials/generate_scripts.php' => ['toggleVersionPanel', 'renderVersionPanel'],
        ] as $berkas => $sisaan) {
            $isi = self::baca($berkas);
            foreach ($sisaan as $sisa) {
                self::assertStringNotContainsString($sisa, $isi, $berkas . ': ' . $sisa . ' tertinggal');
            }
        }
    }

    /**
     * Kartunya saudara sidebar, bukan anaknya.
     *
     * `.toolbar` punya `overflow-y: auto`. Anak berposisi fixed di dalam kotak
     * yang menggulung akan terpotong begitu daftarnya lebih panjang dari
     * sidebar — dan justru daftar panjang yang butuh kartu ini.
     */
    public function testKartunyaDiLuarSidebar(): void
    {
        $view = self::baca('views/document/generate.php');

        $posKartu = strpos($view, 'id="versionCard"');
        $posToolbar = strpos($view, '<div class="toolbar" id="toolbarPanel">');
        self::assertNotFalse($posKartu);
        self::assertNotFalse($posToolbar);
        self::assertLessThan($posToolbar, $posKartu, 'kartunya berada di dalam sidebar');
    }

    /**
     * Kartunya di kiri atas, aksinya di kanan.
     *
     * Dilaporkan pemakai: "ukurannya berbeda jadi jelek. versi card tampilkan
     * di paling kiri atas aja". Dua kotak melayang bertumpuk di sudut yang
     * sama dengan lebar berbeda terbaca sebagai salah gambar.
     */
    public function testKartunyaDiKiriAtas(): void
    {
        $css = self::baca('views/_partials/generate_styles.php');

        self::assertSame(
            1,
            preg_match('/\.ez-versi-kartu \{[^}]*top: 20px;[^}]*left: 20px;/s', $css),
            'kartunya tidak di kiri atas'
        );
        self::assertSame(
            0,
            preg_match('/\.ez-versi-kartu \{[^}]*right:/s', $css),
            'kartunya masih terikat ke tepi kanan'
        );
    }

    /**
     * Tingginya dibatasi layar, dan daftarnya yang menggulung.
     *
     * Diminta pemakai: "pastikan tinggi card dinamis agar tidak melebihi
     * layar, jika versi banyak bisa di scroll". Sejak kartunya berdiri
     * sendiri, batas itu cukup ditulis di CSS — tidak ada lagi tinggi sidebar
     * yang perlu dikurangi, jadi tidak ada yang perlu dihitung ulang tiap kali
     * jendela berubah.
     */
    public function testTingginyaTidakMelebihiLayar(): void
    {
        $css = self::baca('views/_partials/generate_styles.php');
        $skrip = self::baca('views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match('/\.ez-versi-kartu \{[^}]*max-height: calc\(100vh - 40px\);/s', $css),
            'tinggi kartunya tidak dibatasi layar'
        );
        self::assertSame(
            1,
            preg_match('/\.ez-versi-kartu-isi \{ overflow-y: auto; min-height: 0; \}/', $css),
            'daftarnya tidak bisa digulung'
        );

        // Tidak ada sisa perhitungan JavaScript yang dulu dipakai.
        self::assertStringNotContainsString('positionVersionCard', $skrip, 'perhitungan letak lama masih ada');
        self::assertStringNotContainsString('kartu.style.maxHeight', $skrip, 'tingginya masih disetel dari skrip');
    }

    /**
     * Kartunya punya tombol sembunyi sendiri.
     *
     * Diminta pemakai: "dan tombol hidenya pisah aja". Satu tombol bersama
     * berarti menyembunyikan yang satu menyembunyikan yang lain — dan sidebar
     * sudah punya tombolnya sendiri.
     */
    public function testKartunyaPunyaTombolSembunyiSendiri(): void
    {
        $view = self::baca('views/document/generate.php');
        $skrip = self::baca('views/_partials/generate_scripts.php');
        $css = self::baca('views/_partials/generate_styles.php');

        self::assertStringContainsString('id="btnVersionCardToggle"', $view, 'kartunya tidak punya tombol sembunyi');
        self::assertStringContainsString('onclick="toggleVersionCard()"', $view);
        self::assertStringContainsString('function toggleVersionCard(', $skrip);

        // Keadaannya diingat, sama seperti sidebar.
        self::assertStringContainsString("localStorage.setItem('ezdoc_versi_collapsed'", $skrip, 'keadaan tergulungnya tidak diingat');
        self::assertStringContainsString("localStorage.getItem('ezdoc_versi_collapsed')", $skrip, 'keadaan tergulungnya tidak dipakai lagi');

        // Dan tidak lagi ikut tombol sembunyi milik sidebar.
        self::assertSame(
            0,
            preg_match("/function toggleToolbar\(\)[\s\S]{0,700}?(updateVersionCard|toggleVersionCard)\(\)/", $skrip),
            'kartunya masih ikut tombol sembunyi sidebar'
        );

        self::assertSame(
            1,
            preg_match('/\.ez-versi-kartu\.collapsed \.ez-versi-kartu-isi,/', $css),
            'tergulungnya tidak menyembunyikan daftarnya'
        );
    }


    /**
     * Dua lebar, dan sasaran sentuh yang lebih besar hanya di layar sentuh.
     *
     * Diminta pemakai: "pastikan responsive ya, karena kadang buka di tablet
     * dan ipad".
     */
    public function testLebarnyaMenyesuaikanLayar(): void
    {
        $css = self::baca('views/_partials/generate_styles.php');

        self::assertSame(1, preg_match('/\.ez-versi-kartu \{[^}]*width: 260px;/s', $css), 'lebar bawaannya berubah');
        self::assertSame(
            1,
            preg_match('/@media screen and \(max-width: 1024px\) \{[\s\S]*?\.ez-versi-kartu \{ top: 10px; left: 10px; width: 210px; max-height: calc\(100vh - 20px\); \}/', $css),
            'kartunya tidak menyempit di layar sempit'
        );
        self::assertSame(
            1,
            preg_match('/@media \(hover: none\) and \(pointer: coarse\) \{[\s\S]*?\.ez-versi-baris \{ padding: 10px; \}/', $css),
            'baris versinya tidak diperbesar untuk sentuhan'
        );
    }

    /** Kalimatnya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            foreach ([
                'version_count', 'version_open_now', 'version_complete', 'version_partial',
                'version_draft', 'version_hidden', 'version_no_slots', 'show_hidden_versions',
            ] as $kunci) {
                self::assertArrayHasKey($kunci, $lang['toolbar'], $bahasa . ': ' . $kunci);
            }
        }
    }

    // ── Isinya, dijalankan sungguhan ─────────────────────────────────────

    /** Percabangan lencana, batas tinggi, dan ikut-tersembunyinya diperiksa di atas DOM tiruan. */
    public function testIsiKartunyaDirenderBenar(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = self::baca('views/_partials/generate_scripts.php');
        $awal = strpos($skrip, 'function _versiWaktu(teks) {');
        self::assertNotFalse($awal, 'pemformat waktu tidak ketemu');
        $akhir = strpos($skrip, 'async function toggleHiddenVersions(', $awal);
        self::assertNotFalse($akhir, 'ujung bagian render tidak ketemu');

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-kartu-') . '.js';
        file_put_contents(
            $js,
            substr($skrip, $awal, $akhir - $awal)
            . "\nthis.renderVersionCard = renderVersionCard;\nthis.toggleVersionCard = toggleVersionCard;\n"
        );

        try {
            $keluaran = (string) @shell_exec(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/version-card.js'),
                escapeshellarg($js)
            ));
        } finally {
            @unlink($js);
        }

        self::assertStringContainsString('LULUS', $keluaran, $keluaran);
    }
}
