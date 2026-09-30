<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Papan grafik diatur lewat formulir, bukan dengan mengetik JSON.
 *
 * Diminta pemakai setelah memakai partograf: "tidak mungkin manusia tulis
 * manual json nya". Untuk papan tiga belas baris memang bukan — satu koma yang
 * salah membuat seluruh papan berhenti tergambar, dan letak salahnya tidak
 * kelihatan.
 *
 * Syarat yang diminta bersamaan: **jangan buang penyuntingan JSON-nya**.
 * Formulir menutupi yang lazim; JSON menutupi sisanya, dan menghapusnya
 * membuat yang jarang jadi mustahil.
 */
final class PengaturChartTest extends TestCase
{
    private static function skrip(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/designer_scripts.php');
    }

    private static function halaman(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/designer.php');
    }

    /** Modalnya ada, dan terdaftar di tempat modal lain terdaftar. */
    public function testTheModalExistsAndIsRegistered(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('id="chartEditorModal"', $halaman);
        self::assertSame(
            1,
            preg_match('/chartEditor: false,/', $halaman),
            'modalnya tidak terdaftar di store Alpine — ia tidak akan pernah terbuka'
        );
        self::assertStringContainsString('$store.modals.chartEditor', $halaman);
    }

    /**
     * Penyuntingan JSON tetap ada.
     *
     * Diminta tegas oleh pemakai, dan alasannya benar: formulir tidak akan
     * pernah menutupi setiap kemungkinan spesifikasi.
     */
    public function testTheJsonEditorIsKept(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('id="chartEditorJson"', $halaman, 'penyuntingan JSON dihapus');
        self::assertStringContainsString('data-chart-tab="json"', $halaman);
        self::assertStringContainsString('data-chart-tab="form"', $halaman);
    }

    /**
     * Yang diketik di tab JSON yang berlaku saat diterapkan.
     *
     * Dua tempat menyunting hal yang sama harus punya urutan yang jelas.
     * Tanpa ini, suntingan JSON diam-diam dibuang oleh isi formulir.
     */
    public function testJsonWinsWhenItIsTheOpenTab(): void
    {
        self::assertSame(
            1,
            preg_match(
                '/if \(json && !json\.classList\.contains\(.hidden.\)\) \{[\s\S]{0,300}?JSON\.parse\(box\.value\)/',
                self::skrip()
            ),
            'suntingan di tab JSON tidak dipakai saat menerapkan'
        );
    }

    /** Pindah dari JSON ke formulir juga membawa suntingannya. */
    public function testMovingBackFromJsonKeepsTheEdit(): void
    {
        self::assertSame(
            1,
            preg_match(
                "/json\.classList\.add\('hidden'\);[\s\S]{0,300}?chartEditor\.spec = JSON\.parse\(box\.value\)/",
                self::skrip()
            ),
            'suntingan JSON hilang begitu kembali ke formulir'
        );
    }

    /**
     * Pratinjaunya hidup, dan ditunda.
     *
     * Mengatur papan tiga belas baris tanpa melihat hasilnya berarti menebak
     * lalu menutup modal untuk memeriksa. Tapi menggambar ulang pada tiap
     * ketukan tombol berarti satu permintaan per huruf.
     */
    public function testThePreviewIsLiveAndDebounced(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/chartEditor\.tunda = setTimeout\(chartEditorPratinjau, \d+\);/', $skrip),
            'pratinjaunya tidak ditunda — satu permintaan per huruf'
        );
        self::assertSame(
            1,
            preg_match('/chartEditorPratinjauTunda\(\);/', $skrip),
            'tidak ada yang memicu pratinjau saat isian berubah'
        );
    }

    /**
     * Nilai angka disimpan sebagai angka.
     *
     * `"hours": "16"` lolos dari sisi peramban dan ditolak server — pesannya
     * benar tapi sebabnya tidak kelihatan, karena di layar tertulis 16.
     */
    public function testNumbersAreStoredAsNumbers(): void
    {
        self::assertSame(
            1,
            preg_match("/else if \(el\.dataset\.num\) nilai = nilai === '' \? undefined : Number\(nilai\);/", self::skrip()),
            'isian angka tersimpan sebagai teks'
        );
    }

    /**
     * Legenda kosong berarti tidak ada legenda.
     *
     * Pilihan kosong di menu tidak boleh tersimpan sebagai legenda bernama
     * kosong — ChartSpec akan menolaknya.
     */
    public function testEmptyLegendMeansNoLegend(): void
    {
        self::assertSame(
            1,
            preg_match("/endsWith\('\.legend'\) && nilai === undefined\) nilai = false;/", self::skrip()),
            'legenda kosong tersimpan sebagai nilai kosong, bukan sebagai tidak ada'
        );
    }

    /**
     * Mengganti jenis panel menyusun ulang formulirnya.
     *
     * Baris simbol tidak punya sumbu Y maupun garis acuan. Formulir yang tidak
     * disusun ulang menampilkan isian yang tidak berlaku, dan yang mengisinya
     * mengira isiannya diabaikan diam-diam.
     */
    public function testChangingPanelTypeRebuildsTheForm(): void
    {
        self::assertSame(
            1,
            preg_match('/\.type\$\/\.test\(el\.dataset\.path\)\) renderChartEditorForm\(\);/', self::skrip()),
            'mengganti jenis panel tidak menyusun ulang formulirnya'
        );
    }

    /** Tombol "Atur…" ada di kartu panel kanan. */
    public function testTheSettingsButtonIsOnThePanelCard(): void
    {
        self::assertSame(
            1,
            preg_match("/onclick=\"bukaChartEditor\(' \+ i \+ '\)/", self::skrip()),
            'tidak ada jalan membuka pengaturannya dari panel kanan'
        );
    }

    // ── Yang membuatnya mudah dipakai ────────────────────────────────────

    /**
     * Panel dilipat jadi daftar satu baris.
     *
     * Tiga belas panel terbuka sekaligus sepanjang tiga layar, dan mencari
     * satu baris di antaranya memakan lebih banyak waktu daripada mengubahnya.
     */
    public function testPanelsAreCollapsedIntoOneLineRows(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const terbuka = chartEditor\.buka === i;/', $skrip),
            'tidak ada yang menentukan panel mana yang terbuka'
        );
        self::assertSame(
            1,
            preg_match('/chartEditor\.buka = chartEditor\.buka === i \? -1 : i;/', $skrip),
            'baris ringkasnya tidak bisa dibuka-tutup'
        );
        self::assertSame(
            1,
            preg_match('/if \(terbuka\) \{[\s\S]{0,200}?renderChartPanelBody\(p, i\)/', $skrip),
            'isi panel digambar walau barisnya sedang tertutup'
        );
    }

    /**
     * Tombol di baris ringkas tidak ikut membuka-tutup panelnya.
     *
     * Yang menekan ✕ sedang menghapus, bukan melihat.
     */
    public function testRowButtonsDoNotToggleThePanel(): void
    {
        self::assertSame(
            1,
            preg_match("/dataset\.act === 'panel-toggle'[\s\S]{0,140}?closest\('\[data-no-toggle\]'\)\) return;/", self::skrip()),
            'menekan hapus juga membuka panelnya'
        );
    }

    /**
     * Klik satu baris di pratinjau membuka kartunya.
     *
     * Gambarnya sudah ada di sebelah; menunjuk baris yang mau diubah jauh lebih
     * langsung daripada menggulir mencari namanya.
     */
    public function testClickingThePreviewOpensThatPanel(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/if \(y >= panels\[i\]\.top && y <= panels\[i\]\.top \+ panels\[i\]\.height\)/', $skrip),
            'klik di pratinjau tidak dipetakan ke panel mana pun'
        );
        self::assertSame(
            1,
            preg_match('/\(ev\.clientY - r\.top\) \/ \(chartEditor\.skala \|\| 1\)/', $skrip),
            'pengecilan pratinjau tidak diperhitungkan — kliknya akan meleset'
        );
    }

    /** Dan panel yang sedang dibuka disorot di pratinjau. */
    public function testTheOpenPanelIsHighlightedInThePreview(): void
    {
        self::assertSame(
            1,
            preg_match('/function chartEditorSorotPanel\(\)[\s\S]{0,700}?kanvas\.appendChild\(sorot\)/', self::skrip()),
            'tidak ada sorotan di pratinjau'
        );
    }

    /**
     * Panel baru lahir dengan bawaan yang masuk akal.
     *
     * "Tambah panel" yang menghasilkan panel kosong langsung ditolak — nama
     * fieldnya belum ada — dan yang menambahkannya melihat pesan galat sebagai
     * sambutan.
     */
    public function testNewPanelsComeWithSensibleDefaults(): void
    {
        $skrip = self::skrip();

        foreach (['line', 'bar', 'symbol', 'text'] as $jenis) {
            self::assertSame(
                1,
                preg_match('/' . $jenis . ': function \(\) \{/', $skrip),
                'tidak ada bawaan untuk panel jenis ' . $jenis
            );
        }
        self::assertSame(
            1,
            preg_match('/const buat = CHART_BAWAAN\[path\] \|\| CHART_BAWAAN\.line;/', $skrip),
            'tombol tambah tidak memakai bawaan per jenis'
        );
    }

    /**
     * Pesan galat membuka panel yang salah, bukan menyuruh menghitung.
     *
     * Servernya menjawab "Panel ke-2: …" dan tanpa ini yang membacanya harus
     * menghitung panel untuk menemukannya.
     */
    public function testTheErrorOpensTheOffendingPanel(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/exec\(hasil\.message \|\| .{0,4}\);/', $skrip),
            'nomor panel tidak dibaca dari pesan galatnya'
        );
        self::assertSame(
            1,
            preg_match('/chartEditor\.buka = chartEditor\.salah;/', $skrip),
            'panel yang salah tidak dibuka sendiri'
        );
    }

    /**
     * Ringkasan menyebut muat atau tidaknya di kertas.
     *
     * Papan yang lebih lebar dari halaman sudah dua kali lolos sampai ke PDF —
     * sekali jadi tiga halaman. Angkanya membuat itu ketahuan sebelum dicetak.
     */
    public function testTheSummarySaysWhetherItFitsThePage(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const muat = lebarKertas > 0 && scale\.width <= lebarKertas;/', $skrip),
            'tidak ada yang membandingkan lebar papan dengan lebar kertas'
        );
        self::assertSame(
            1,
            preg_match('/const isiMm = mm - \(Number\(pad\.left\) \|\| 0\) - \(Number\(pad\.right\) \|\| 0\);/', $skrip),
            'lebar kertasnya dihitung tanpa memperhitungkan margin'
        );
    }

    /**
     * Seret untuk menukar urutan — dan tombol naik-turun tetap ada.
     *
     * Seretan tidak bisa dijangkau papan ketik; membuang tombolnya menutup
     * pintu bagi yang memakainya.
     */
    public function testDragReordersButArrowsRemain(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/data-panel-grip="\' \+ i \+ \'"/', $skrip),
            'baris panel tidak punya pegangan seret'
        );
        self::assertSame(
            1,
            preg_match("/const grip = ev\.target\.closest \? ev\.target\.closest\('\[data-panel-grip\]'\) : null;/", $skrip),
            'pegangan seretnya tidak dibaca saat tombol tetikus ditekan'
        );
        self::assertSame(
            1,
            preg_match('/chartTukarPanel\(seret\.dari, ke\);\s*seret\.dari = ke;/', $skrip),
            'seretannya tidak benar-benar menukar urutan panel'
        );
        self::assertSame(
            1,
            preg_match("/act === 'panel-up' \|\| act === 'panel-down'/", $skrip),
            'tombol naik-turun dibuang'
        );
    }

    /** Esc menutup, Ctrl+Enter menerapkan. */
    public function testKeyboardShortcuts(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/if \(ev\.key === 'Escape'\) \{[\s\S]{0,140}?close\('chartEditor'\)/", $skrip),
            'Esc tidak menutup modalnya'
        );
        self::assertSame(
            1,
            preg_match("/ev\.key === 'Enter' && \(ev\.ctrlKey \|\| ev\.metaKey\)/", $skrip),
            'Ctrl+Enter tidak menerapkan'
        );
    }

    /** Ada jalan kembali ke keadaan saat modal dibuka. */
    public function testThereIsAWayBack(): void
    {
        self::assertSame(
            1,
            preg_match("/act === 'reset'[\s\S]{0,200}?JSON\.parse\(chartEditor\.awal\)/", self::skrip()),
            'tidak ada cara membatalkan percobaan selain menutup modal'
        );
        self::assertStringContainsString("chartEditorAksi('reset', '')", self::halaman(), 'tombolnya tidak ada');
    }

    /**
     * Baris ringkas diperbarui tanpa menyusun ulang formulirnya.
     *
     * Menyusun ulang saat orang sedang mengetik judul merebut fokus pada huruf
     * kedua.
     */
    public function testTheSummaryRowUpdatesWithoutRebuildingTheForm(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/function chartEditorRingkasSegar\(path\)[\s\S]{0,1100}?rincian\.textContent =/', $skrip),
            'baris ringkasnya hanya ikut lewat penyusunan ulang seluruh formulir'
        );

        // Dan badannya tidak boleh memanggil penyusunan ulang sama sekali.
        // Menyelipkannya di awal fungsi tetap lolos dari pola di atas.
        self::assertSame(
            1,
            preg_match('/function chartEditorRingkasSegar\(path\) \{([\s\S]*?)\n        \}\n/', $skrip, $badan),
            'fungsi penyegar baris ringkas tidak ditemukan'
        );
        self::assertStringNotContainsString(
            'renderChartEditorForm',
            $badan[1],
            'menyegarkan baris ringkas menyusun ulang formulir — fokus pengetikan direbut'
        );
    }

    /** Semua kalimatnya ada di kedua bahasa. */
    public function testEveryLabelExistsInBothLanguages(): void
    {
        $id = require dirname(__DIR__, 2) . '/lang/id/designer.php';
        $en = require dirname(__DIR__, 2) . '/lang/en/designer.php';

        self::assertSame(
            array_keys($id['chart']),
            array_keys($en['chart']),
            'kunci chart di kedua bahasa tidak sama'
        );

        foreach (['editor_title', 'tab_form', 'open_editor', 'board_section', 'series_section',
                  'references_section', 'verticals_section', 'levels_section', 'add_panel'] as $kunci) {
            self::assertArrayHasKey($kunci, $id['chart'], 'id: ' . $kunci);
            self::assertNotSame('', $id['chart'][$kunci], 'id: ' . $kunci . ' kosong');
        }
    }
}
