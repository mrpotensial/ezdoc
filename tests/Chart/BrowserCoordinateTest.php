<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\SeriesValues;
use Ezdoc\Chart\TimeAxis;
use PHPUnit\Framework\TestCase;

/**
 * Rumus koordinat di peramban sama dengan di server.
 *
 * Gambarnya cuma dibuat satu pihak — PHP. Tapi lapisan pegangan wajib
 * menghitung sendiri ke dua arah: angka jadi tempat saat menaruh pegangan, dan
 * tempat jadi angka saat orang mengklik. Rumusnya karena itu ada di dua bahasa.
 *
 * Dua salinan rumus selalu berpisah diam-diam, dan berpisahnya tidak pernah
 * terbaca sebagai bug — cuma sebagai "titiknya agak meleset dari tempat saya
 * klik". Uji ini memakai angka yang SAMA dengan {@see SvgChartRendererTest}:
 * kalau salah satu sisi berubah sendirian, salah satunya gagal.
 */
final class BrowserCoordinateTest extends TestCase
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

    public function testBrowserMathAgreesWithTheServer(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node tidak tersedia');
        }

        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        $awal = strpos($skrip, '/** Pembulatan waktu saat menaruh titik, dalam menit. */');
        self::assertNotFalse($awal, 'blok papan grafik tidak ketemu');
        $akhir = strpos($skrip, '// ===== Document versioning =====', $awal);
        self::assertNotFalse($akhir, 'ujung blok papan grafik tidak ketemu');

        $js = tempnam(sys_get_temp_dir(), 'ezdoc-chart-') . '.js';
        file_put_contents(
            $js,
            substr($skrip, $awal, $akhir - $awal)
            . "\nthis.ezChartX = ezChartX;\nthis.ezChartY = ezChartY;\n"
            . "this.ezChartFromPoint = ezChartFromPoint;\nthis.ezChartNormalise = ezChartNormalise;\n"
            . "this.ezChartTimeToText = ezChartTimeToText;\n"
            . "this.ezChartTextToTime = ezChartTextToTime;\n"
            . "this.ezChartColumnFromTime = ezChartColumnFromTime;\n"
            . "this.ezChartColumnTime = ezChartColumnTime;\n"
        );

        try {
            // Dua zona, sengaja. Di zona tanpa DST — UTC maupun Asia/Jakarta —
            // rumus "selisih detik dibagi 86400" kebetulan benar, jadi uji yang
            // cuma berjalan di situ tidak membedakan rumus yang benar dari yang
            // kebetulan benar. Europe/London punya hari 23 dan 25 jam.
            foreach (['UTC', 'Europe/London'] as $zona) {
                $keluaran = self::jalankanDiZona($node, $js, $zona);

                self::assertStringContainsString('LULUS', $keluaran, $zona . ': ' . $keluaran);
                self::assertStringContainsString('kasus satuan diperiksa', $keluaran, $zona . ': ' . $keluaran);
                self::assertStringContainsString('kasus rapikan diperiksa', $keluaran, $zona . ': ' . $keluaran);
            }
        } finally {
            @unlink($js);
        }
    }

    /**
     * Jalankan pembanding di satu zona waktu, dua-duanya.
     *
     * Zonanya disetel untuk PHP (yang menghitung angka harapan) dan diwariskan
     * ke Node lewat `TZ` (yang menghitung ulang). Kalau cuma salah satunya
     * yang bergeser, yang gagal bukan rumusnya melainkan ujinya — dan itu
     * kegagalan yang membingungkan.
     */
    private static function jalankanDiZona(string $node, string $js, string $zona): string
    {
        $semulaPhp = date_default_timezone_get();
        $semulaTz = getenv('TZ');

        date_default_timezone_set($zona);
        putenv('TZ=' . $zona);

        $kasus = tempnam(sys_get_temp_dir(), 'ezdoc-satuan-') . '.json';
        file_put_contents($kasus, json_encode(self::kasusSatuan()));

        $rapi = tempnam(sys_get_temp_dir(), 'ezdoc-rapi-') . '.json';
        file_put_contents($rapi, json_encode(self::kasusRapikan()));

        try {
            return (string) @shell_exec(sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg(dirname(__DIR__) . '/js/chart-coordinates.js'),
                escapeshellarg($js),
                escapeshellarg($kasus),
                escapeshellarg($rapi)
            ));
        } finally {
            @unlink($kasus);
            @unlink($rapi);
            date_default_timezone_set($semulaPhp);
            putenv($semulaTz === false ? 'TZ' : 'TZ=' . $semulaTz);
        }
    }

    /**
     * Daftar kasus pembakuan nilai, dengan harapan dihitung sisi PHP.
     *
     * Yang dibandingkan bentuk TERSIMPAN — yang ikut di-hash tanda tangan.
     * Dua salinan aturan di dua bahasa, dan yang satu pernah membuang `x2`
     * tanpa ada yang tahu sampai gambarnya dibaca.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function kasusRapikan(): array
    {
        $kasus = [
            'titik biasa' => [
                ['x' => '2026-09-29 10:00', 'y' => 8],
                ['x' => '2026-09-29 08:00', 'y' => 4],
            ],
            'rentang tertutup' => [
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 14:00', 'y' => 'RL 500'],
            ],
            'rentang terbuka' => [
                ['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'Oksitosin'],
            ],
            'rentang tanpa label' => [
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 09:00'],
            ],
            'dua rentang mulai bersamaan' => [
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 14:00', 'y' => 'B'],
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'A'],
            ],
            'titik dan rentang bersamaan' => [
                ['x' => '2026-09-29 08:00', 'x2' => false, 'y' => 'terbuka'],
                ['x' => '2026-09-29 08:00', 'y' => 3],
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'tertutup'],
            ],
            'rentang kembar persis' => [
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'lama'],
                ['x' => '2026-09-29 08:00', 'x2' => '2026-09-29 10:00', 'y' => 'baru'],
            ],
            'campuran berurutan' => [
                ['x' => '2026-09-29 12:00', 'x2' => false, 'y' => 'C'],
                ['x' => '2026-09-29 08:00', 'y' => 1],
                ['x' => '2026-09-29 10:00', 'x2' => '2026-09-29 11:00', 'y' => 'B'],
            ],
        ];

        $keluar = [];
        foreach ($kasus as $nama => $masuk) {
            $keluar[] = [
                'nama' => $nama,
                'masuk' => $masuk,
                'harap' => SeriesValues::toJson($masuk),
            ];
        }

        return $keluar;
    }

    /**
     * Daftar kasus satuan, dihitung sisi PHP.
     *
     * Tanggalnya dipilih justru yang paling sering meruntuhkan rumus naif:
     * akhir bulan, Februari 28 hari, Februari kabisat, pergantian tahun, dan
     * tengah bulan yang panjang bulannya berbeda-beda.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function kasusSatuan(): array
    {
        $awal = [
            'hour' => '2026-09-29 08:00',
            // Awal sumbu harinya sengaja sebelum pergantian DST musim semi,
            // supaya sebagian kasus menyeberanginya.
            'day' => '2026-03-28 00:00',
            'month' => '2026-01-01 00:00',
        ];
        $waktu = [
            'hour' => [
                '2026-09-29 08:00', '2026-09-29 08:30', '2026-09-29 15:00',
                '2026-09-30 02:00', '2026-09-29 07:00',
            ],
            'day' => [
                '2026-09-28 00:00', '2026-09-28 12:00', '2026-09-30 00:00',
                '2026-10-01 00:00', '2026-10-31 18:00', '2026-11-01 00:00',
                '2026-09-26 00:00',
                // Melewati kedua pergantian DST Eropa 2026: maju 29 Maret
                // (hari 23 jam), mundur 25 Oktober (hari 25 jam).
                '2026-03-29 12:00', '2026-03-30 00:00',
                '2026-10-25 12:00', '2026-10-26 00:00',
            ],
            'month' => [
                '2026-01-01 00:00', '2026-01-15 00:00', '2026-02-01 00:00',
                '2026-02-15 00:00', '2026-02-28 12:00', '2026-03-01 00:00',
                '2026-12-31 23:00', '2027-01-01 00:00', '2028-02-15 00:00',
                '2025-12-01 00:00',
            ],
        ];

        $kasus = [];
        foreach ($waktu as $unit => $daftar) {
            /** @var TimeAxis $axis */
            $axis = TimeAxis::create($unit)->withOrigin($awal[$unit]);
            foreach ($daftar as $t) {
                $kolom = $axis->column($t);
                self::assertNotNull($kolom, $unit . ' ' . $t);

                $kasus[] = [
                    'unit' => $unit,
                    'origin' => $axis->origin(),
                    'waktu' => $t,
                    'kolom' => $kolom,
                ];
            }
        }

        return $kasus;
    }

    /**
     * Nilainya ditulis ke isian SEBELUM server menjawab.
     *
     * Versi pertama menulisnya hanya setelah jawaban datang — dan saat
     * jawabannya tidak datang, titik yang barusan diletakkan orang hilang tanpa
     * jejak: gambarnya tidak berubah, isiannya tidak berubah, tidak ada pesan
     * apa pun. Ditemukan saat mengklik di peramban sungguhan.
     */
    public function testValuesAreWrittenBeforeTheServerAnswers(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        $awal = strpos($skrip, 'function ezChartStore(keadaan) {');
        self::assertNotFalse($awal);
        $blok = substr($skrip, $awal, 600);

        self::assertSame(
            1,
            preg_match('/input\.value = JSON\.stringify\(keadaan\.nilai\[field\]\)/', $blok),
            'isian baru ditulis sesudah server menjawab'
        );
        self::assertTrue(
            strpos($blok, 'input.value') < strpos($blok, 'setTimeout'),
            'penulisan isian terjadi sesudah penundaan, bukan seketika'
        );
    }

    /**
     * Pegangan dan pemilih deret tidak terlihat sampai pointer masuk papannya.
     *
     * Diminta pemakai setelah mencoba partograf: "lingkaran biru transparan
     * tiap titik ... apa bisa tampil saat hover aja, agar tidak ganggu visual".
     * Tujuh puluh bulatan biru yang selalu menyala membuat grafiknya sulit
     * dibaca sebagai grafik — dan dibaca jauh lebih sering daripada diisi.
     */
    public function testHandlesStayInvisibleUntilHovered(): void
    {
        $halaman = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertSame(
            1,
            preg_match('/\.ez-chart-handle,\s*\.ez-chart-legend \{ opacity: 0;/', $halaman),
            'pegangan dan pemilih deret terlihat terus'
        );
        self::assertSame(
            1,
            preg_match('/\.ez-chart:hover \.ez-chart-handle,/', $halaman),
            'tidak ada yang memunculkannya saat pointer masuk'
        );
        self::assertSame(
            1,
            preg_match('/\.ez-chart:focus-within \.ez-chart-handle,/', $halaman),
            'yang memakai papan ketik tidak pernah melihat pegangannya'
        );
    }

    /**
     * Pemilih deret berdiri di pojok kanan atas panelnya.
     *
     * Di kiri ia menutupi titik paling awal — justru titik yang paling sering
     * dilihat orang. Diminta pemakai: "tombol untuk pilih mau tambah itu
     * mungkin pindah ke ujung kanan atas aja".
     */
    public function testSeriesPickerSitsTopRight(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match("/pilih\.style\.cssText = 'position:absolute;right:'/", $skrip),
            'pemilih deret masih ditempel dari kiri'
        );
        self::assertSame(
            1,
            preg_match('/kananKisi = keadaan\.scale\.left\s*\+ \(keadaan\.scale\.hours \* keadaan\.scale\.column_width\)/', $skrip),
            'tepi kanan kisinya ditebak, bukan dihitung dari skala'
        );
    }

    /** Dan kalau gambarnya gagal diperbarui, itu dikatakan. */
    public function testStaleDrawingIsAnnounced(): void
    {
        $skrip = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertSame(
            1,
            preg_match("/catch \(e\) \{[\s\S]{0,400}?ezChartKabari\(keadaan, t\('chart\.stale'/", $skrip),
            'gambar yang tertinggal tidak dikatakan kepada yang mengisi'
        );

        foreach (['id', 'en'] as $bahasa) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/generate.php';
            self::assertArrayHasKey('stale', $lang['chart'], $bahasa);
        }
    }
}
