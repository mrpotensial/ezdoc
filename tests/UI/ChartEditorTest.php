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
final class ChartEditorTest extends TestCase
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
            preg_match('/function chartEditorHighlightPanel\(\)[\s\S]{0,700}?kanvas\.appendChild\(sorot\)/', self::skrip()),
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
            preg_match('/const buat = CHART_DEFAULTS\[path\] \|\| CHART_DEFAULTS\.line;/', $skrip),
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
            preg_match('/chartSwapPanels\(seret\.dari, ke\);\s*seret\.dari = ke;/', $skrip),
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

    // ── Baris jam dan data contoh ────────────────────────────────────────

    /**
     * Baris jam bisa diatur per panel, dan di dasar papan.
     *
     * Lembar partograf lama menaruh jamnya tepat di bawah pembukaan serviks.
     * Tanpa dua saklar ini, satu-satunya jalan ke sana adalah mengetik JSON.
     */
    public function testTheHourRowCanBePlacedPerPanel(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/fSelect\('time_axis', t\('chart\.time_axis'/", $skrip),
            'letak baris jam di dasar papan tidak bisa diatur'
        );
        self::assertSame(
            1,
            preg_match("/fCheck\(sel \+ '\.time_axis', t\('chart\.panel_time_axis'/", $skrip),
            'panel tidak punya saklar baris jamnya sendiri'
        );
        self::assertSame(
            1,
            preg_match("/CHART_TIME_AXIS_PLACES = \[\['bottom',[^\]]*\], \['none',/", $skrip),
            'pilihannya bukan bottom/none'
        );
    }

    /** Dan baris ringkasnya menyebutkan panel mana yang memuatnya. */
    public function testTheSummaryRowMentionsTheHourRow(): void
    {
        self::assertSame(
            2,
            preg_match_all("/p\.time_axis \? ' (?:&middot;|\xc2\xb7) ' \+/", self::skrip()),
            'baris ringkas tidak menyebut baris jam di kedua tempat penulisannya'
        );
    }

    /**
     * Data contoh mati kecuali diminta.
     *
     * Angka karangan yang tampil sendiri pernah dikira data sungguhan. Yang
     * membuka penyunting harus melihat papannya kosong lebih dulu.
     */
    public function testSampleDataIsOffUntilAsked(): void
    {
        $skrip = self::skrip();

        self::assertSame(1, preg_match('/sampleData: false,/', $skrip), 'bawaannya bukan mati');
        self::assertSame(
            1,
            preg_match('/if \(saklar\) saklar\.checked = false;/', $skrip),
            'saklarnya tidak dikembalikan ke mati saat papan lain dibuka'
        );
        self::assertStringContainsString(
            'id="chartEditorSample"',
            self::halaman(),
            'kotak centang data contohnya tidak ada di halaman'
        );
        self::assertSame(
            0,
            preg_match('/id="chartEditorSample"[^>]*checked/', self::halaman()),
            'kotak centangnya sudah tercentang di markup'
        );
    }

    /** Angkanya cuma untuk pratinjau — Terapkan tetap mengirim papan tanpa nilai. */
    public function testSampleValuesNeverLeaveThePreview(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match(
                '/chartEditor\.sampleData \? chartSampleValues\(chartEditor\.spec\) : \{\}/',
                $skrip
            ),
            'pratinjau tidak memakai angka contoh'
        );
        self::assertSame(
            1,
            preg_match(
                '/async function terapkanChartEditor\(\)[\s\S]{0,1200}?chartRenderRequest\(chartEditor\.spec, \{\}\)/',
                $skrip
            ),
            'Terapkan ikut mengirim angka contoh — angka karangan bisa ikut tersimpan'
        );
    }

    /**
     * Angkanya sama di tiap gambar ulang.
     *
     * `Math.random()` membuat gambarnya berubah pada tiap ketikan, dan
     * pratinjau yang bergoyang sendiri menyembunyikan akibat dari perubahan
     * yang sedang dicoba.
     */
    public function testSampleValuesAreStableAcrossRedraws(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const acak = chartAcak\(d\.field\);/', $skrip),
            'semainya bukan nama field'
        );
        self::assertSame(
            1,
            preg_match('/function chartSampleValues\(spec\) \{([\s\S]*?)\n        \}\n/', $skrip, $badan),
            'pembangkitnya tidak ditemukan'
        );
        self::assertStringNotContainsString(
            'Math.random',
            $badan[1],
            'angkanya dibangkitkan acak murni — pratinjaunya akan bergoyang tiap ketikan'
        );
    }

    /**
     * Data contoh ikut mengisi field jam mulai.
     *
     * Tanpa jam mulai, perender tidak menulis satu angka jam pun — dan
     * pratinjau contoh yang tidak memperlihatkan baris jam justru menyembunyikan
     * pengaturan yang baru saja ditambahkan.
     */
    public function testSampleDataFillsTheStartField(): void
    {
        self::assertSame(
            1,
            preg_match('/if \(spec\.start_field\) nilai\[spec\.start_field\] = MULAI;/', self::skrip()),
            'field jam mulai tidak ikut diisi contoh'
        );
    }

    /**
     * Tangga dan arsiran bisa dipilih di penyunting.
     *
     * Perender menerimanya sejak fase 2, tapi cara gambar yang cuma bisa
     * diminta dengan mengetik JSON sama saja dengan tidak ada — dan formulir
     * ini justru dibuat supaya JSON tidak perlu diketik.
     */
    public function testTheNewDrawModesAreOfferedInTheForm(): void
    {
        $skrip = self::skrip();

        foreach (['point+step', 'step', 'point+area', 'area'] as $draw) {
            self::assertSame(
                1,
                preg_match("/\['" . preg_quote($draw, '/') . "', '/", $skrip),
                'cara gambar ' . $draw . ' tidak ditawarkan formulirnya'
            );
        }
    }

    /**
     * Pita nilai bisa diatur di penyunting, di panel jenis apa pun.
     *
     * Bagiannya sengaja di LUAR blok yang cuma berlaku untuk panel bersumbu
     * Y: kuncinya satu, dan yang berbeda cuma tempat melukisnya.
     */
    public function testValueBandsCanBeEditedOnEveryPanelType(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/escapeHtml\(t\('chart\.bands_section'/", $skrip),
            'tidak ada bagian pita nilai di formulirnya'
        );
        // Diperiksa letaknya, bukan dengan pola "ada ... lalu ada ...".
        // Pola begitu tetap cocok walau bagiannya dipindah ke dalam blok
        // `if (!isSel)`, karena ada `if (!isSel)` LAIN di bawahnya — uji
        // mutasi menunjukkannya lolos.
        $tombolDeret = strpos($skrip, "fButton('series-add'");
        $bagianPita = strpos($skrip, "t('chart.bands_section'");

        self::assertNotFalse($tombolDeret);
        self::assertNotFalse($bagianPita);
        self::assertLessThan($bagianPita, $tombolDeret, 'bagian pitanya sebelum daftar deret');
        self::assertStringNotContainsString(
            'if (!isSel) {',
            substr($skrip, $tombolDeret, $bagianPita - $tombolDeret),
            'bagian pitanya dibungkus syarat jenis panel — baris sel tidak kebagian'
        );
    }

    /**
     * Pita baru lahir di atas yang terakhir, bukan di 0–2.
     *
     * Pita yang bertumpang tindih ditolak server, jadi tombol yang selalu
     * menambahkan 0–2 membuat penambahan kedua langsung gagal — dan yang
     * menekannya melihat pesan galat sebagai sambutan. Kesalahan yang sama
     * pernah terjadi pada "Tambah panel".
     */
    public function testANewBandIsPlacedAboveTheLastOne(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const akhir = p\.bands\.length > 0 \? p\.bands\[p\.bands\.length - 1\] : null;/', $skrip),
            'pita baru tidak melihat pita sebelumnya'
        );
        self::assertSame(
            1,
            preg_match('/p\.bands\.push\(\{ from: dari, to: dari \+ 2,/', $skrip),
            'pita baru memakai angka tetap — penambahan kedua akan bertumpang tindih'
        );
    }

    /**
     * Batang bertumpuk bisa dinyalakan di penyunting, dan cuma di panel batang.
     *
     * Centang yang muncul di panel garis akan ditolak server — dan yang
     * mencentangnya melihat pesan galat sebagai sambutan.
     */
    public function testStackedBarsAreOfferedOnBarPanelsOnly(): void
    {
        self::assertSame(
            1,
            preg_match(
                "/jenis === 'bar'\s*\?\s*fCheck\(sel \+ '\.stacked', t\('chart\.stacked'/",
                self::skrip()
            ),
            'centang batang bertumpuk tidak ada, atau muncul di panel jenis lain'
        );
    }

    /**
     * Jenis timeline ada di penyunting, lengkap dengan bawaan dan contohnya.
     *
     * Jenis panel yang cuma bisa diminta dengan mengetik JSON sama saja dengan
     * tidak ada — dan formulir ini justru dibuat supaya JSON tidak perlu
     * diketik.
     */
    public function testTheTimelineTypeIsOfferedInTheForm(): void
    {
        $skrip = self::skrip();

        self::assertSame(1, preg_match("/\['timeline', '/", $skrip), 'jenis timeline tidak ditawarkan');
        self::assertSame(1, preg_match('/timeline: function \(\) \{/', $skrip), 'tidak ada panel bawaan timeline');
        self::assertSame(
            1,
            preg_match("/type: 'timeline'[\s\S]{0,200}?title: 'Jalur 1'/", $skrip),
            'panel timeline baru lahir tanpa jalur bernama'
        );
    }

    /**
     * Data contoh mengisi jalur dengan RENTANG, bukan titik.
     *
     * Titik di panel timeline tidak menggambar apa pun. Papan yang tetap
     * kosong saat data contoh menyala terbaca sebagai rusak — padahal
     * contohnya yang salah bentuk.
     */
    public function testSampleDataFillsTimelineLanesWithRanges(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/if \(isJalur\) \{\s*nilai\[d\.field\] = JSON\.stringify\(chartSampleRanges\(/', $skrip),
            'jalur diisi titik, bukan rentang'
        );
        self::assertSame(
            1,
            preg_match('/x2: terbuka \? false :/', $skrip),
            'contohnya tidak pernah memperlihatkan rentang terbuka'
        );
    }

    /**
     * Acuan berkurva tidak menawarkan isian dua ujung.
     *
     * Kurvanya disetel lewat daftar titik. Isian "dari jam / sampai jam" di
     * situ mengundang orang mengisinya, dan isiannya diabaikan diam-diam —
     * yang terbaca sebagai formulir yang rusak, bukan sebagai pilihan.
     */
    public function testACurveReferenceOffersNoFromAndToFields(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match('/const berkurva = Array\.isArray\(a\.points\) && a\.points\.length >= 2;/', $skrip),
            'formulirnya tidak membedakan acuan berkurva'
        );
        self::assertSame(
            1,
            preg_match('/\? renderChartReferencePoints\(a\.points, as\)/', $skrip),
            'acuan berkurva tidak menampilkan daftar titiknya'
        );
        // Isian dua ujung cuma dipasang di cabang `: ` — yaitu acuan lurus.
        self::assertSame(
            1,
            preg_match('/: .<div class="grid grid-cols-4 gap-2">.\s*\+ fNum\(as \+ .\.from\.0./', $skrip),
            'isian dua ujung tidak lagi dibatasi untuk acuan lurus'
        );
    }

    /**
     * Tidak ada lagi kunci spesifikasi yang cuma bisa diatur lewat JSON.
     *
     * Tab JSON tetap ada dan tidak dihapus — ia menutupi yang jarang. Tapi
     * yang lazim harus ada di formulirnya, dan lima kunci ini sempat tertinggal:
     * bentuk label kolom, angka sumbu Y, jarak atas panel, nama pita, dan
     * daftar titik acuan berkurva.
     */
    public function testEverySpecKeyCanBeSetWithoutTypingJson(): void
    {
        $skrip = self::skrip();

        $wajib = [
            "fText('label_format'" => 'bentuk label kolom',
            "fCheck(sel + '.y_axis'" => 'angka sumbu Y',
            "fNum(sel + '.gap_before'" => 'jarak atas panel',
            "fText(bs + '.label'" => 'nama pita',
            "fNum(ts + '.0'" => 'kolom titik acuan',
            "fNum(ts + '.1'" => 'nilai titik acuan',
        ];

        foreach ($wajib as $potongan => $apa) {
            self::assertStringContainsString($potongan, $skrip, $apa . ' tidak ada di formulirnya');
        }
    }

    /**
     * Jarak atas yang dikosongkan berarti "ikut jarak papan", bukan nol.
     *
     * Nol merapatkan panelnya sampai bersentuhan — pilihan yang sah, tapi
     * berbeda. Isian kosong yang diam-diam berarti nol membuat yang
     * mengosongkannya mendapat papan yang bukan dimintanya.
     */
    public function testAnEmptyGapMeansAutoNotZero(): void
    {
        self::assertSame(
            1,
            preg_match("/fNum\(sel \+ '\.gap_before', t\('chart\.gap_before'[^
]*
\s*p\.gap_before == null \? '' : p\.gap_before\)/", self::skrip()),
            'jarak atas yang belum disetel ditampilkan sebagai angka'
        );
    }

    /**
     * Acuan lurus bisa dijadikan kurva, dan kurvanya lahir dengan dua titik.
     *
     * Kurva berisi satu titik ditolak server, dan yang menekan tombolnya akan
     * melihat pesan galat sebagai sambutan — kesalahan yang sama pernah
     * terjadi pada "Tambah panel" dan "Tambah pita".
     */
    public function testAStraightReferenceCanBecomeACurve(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/act === 'ref-curve'[\s\S]{0,600}?a\.points = \[\[Number\(dari\[0\]\)/", $skrip),
            'tidak ada cara menjadikan acuan lurus sebagai kurva'
        );
        self::assertSame(
            1,
            preg_match('/if \(a\.points\[1\]\[0\] <= a\.points\[0\]\[0\]\) a\.points\[1\]\[0\] = a\.points\[0\]\[0\] \+ 1;/', $skrip),
            'kurva baru bisa lahir dengan kolom yang tidak naik — langsung ditolak server'
        );
    }

    /**
     * Titik tidak bisa dihapus sampai tersisa kurang dari dua.
     *
     * Tombol yang pasti ditolak lebih baik tidak ada daripada ada lalu
     * mengeluarkan galat.
     */
    public function testTheLastTwoPointsCannotBeRemoved(): void
    {
        $skrip = self::skrip();

        self::assertSame(
            1,
            preg_match("/points\.length > 2\s*\?\s*fButton\('point-del'/", $skrip),
            'tombol hapus titik tetap muncul saat tinggal dua'
        );
        self::assertSame(
            1,
            preg_match('/if \(a && Array\.isArray\(a\.points\) && a\.points\.length > 2\) \{/', $skrip),
            'menghapus titik tidak dijaga di sisi aksinya'
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
