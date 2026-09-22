<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Format;

use Ezdoc\Format\DateFormatter;
use Ezdoc\Table\Formatter;
use Ezdoc\UI\Config;
use Ezdoc\UI\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Tanggal dan angka mengikuti setelan bahasa — di setiap tempat.
 *
 * Diminta pemakai: "format tanggal/angka pastikan ikut bahasa aturan". Sebelum
 * ini `'id'` tertanam di lima tempat, jadi mengganti `app.locale` mengganti
 * teks tombol tapi tidak nama bulannya, dan pustaka yang bawaannya `en` tetap
 * menulis "1.500".
 *
 * Dua setelan, dipilih pemakai:
 *
 * - **layar** ikut `app.locale` — daftar template, waktu relatif, `<html lang>`
 * - **isi dokumen** ikut `document.locale`, kosong = ikut layar — nilai bawaan
 *   `date:`, format tabledb, dan `<html lang>` kerangka PDF. Isinya tersimpan
 *   bersama dokumen dan masuk ke arsip PDF bekunya, jadi layar berbahasa Inggris
 *   tidak boleh mengubah dokumen berbahasa Indonesia.
 *
 * Format yang cuma angka (`d/m/Y`) tidak diubah: tidak ada kata yang perlu
 * diterjemahkan, dan menukar urutan hari/bulan justru membuat "03/04" terbaca
 * dua tanggal berbeda. Pola Filament dan `translatedFormat()` Carbon yang sama:
 * pola format tetap, hanya nama yang diterjemahkan.
 */
final class LocaleFollowsConfigTest extends TestCase
{
    /** @var mixed */
    private $penerjemahAsli;

    /** @var mixed */
    private $bahasaIsiAsli;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/lib/view_helpers.php';
        require_once $root . '/lib/doc_template_helpers.php';
        require_once $root . '/lib/pdf_compose.php';
        require_once $root . '/lib/list_helpers.php';

        $this->penerjemahAsli = $GLOBALS['translator'] ?? null;
        $this->bahasaIsiAsli  = $GLOBALS['ezdoc_document_locale'] ?? null;
        unset($GLOBALS['translator'], $GLOBALS['ezdoc_document_locale']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['translator'], $GLOBALS['ezdoc_document_locale']);
        if ($this->penerjemahAsli !== null) {
            $GLOBALS['translator'] = $this->penerjemahAsli;
        }
        if ($this->bahasaIsiAsli !== null) {
            $GLOBALS['ezdoc_document_locale'] = $this->bahasaIsiAsli;
        }
    }

    private function layar(string $locale): void
    {
        $GLOBALS['translator'] = Translator::forView('generate', $locale);
    }

    // ── DateFormatter ────────────────────────────────────────────────────

    public function testBawaanDateFormatterBahasaInggris(): void
    {
        self::assertSame('Wednesday, 19 August 2026', DateFormatter::localize('Wednesday, 19 August 2026'));
    }

    public function testNamaLengkapIndonesia(): void
    {
        self::assertSame('Rabu, 19 Agustus 2026', DateFormatter::localize('Wednesday, 19 August 2026', 'id'));
    }

    /**
     * Singkatan (`D`, `M`) ikut diterjemahkan.
     *
     * Sebelumnya hanya nama lengkap yang ada di tabelnya, jadi "19 Aug 2026"
     * tetap berbahasa Inggris di layar yang berbahasa Indonesia.
     */
    public function testSingkatanIndonesia(): void
    {
        self::assertSame('Rab, 19 Agu 2026', DateFormatter::localize('Wed, 19 Aug 2026', 'id'));
        self::assertSame('Sen, 07 Des 2026', DateFormatter::localize('Mon, 07 Dec 2026', 'id'));
        self::assertSame('Jum, 02 Okt 2026', DateFormatter::localize('Fri, 02 Oct 2026', 'id'));
    }

    /** Kunci terpanjang menang: nama lengkap tidak terpotong oleh singkatannya. */
    public function testNamaLengkapTidakTerpotongSingkatan(): void
    {
        self::assertSame('Kamis Desember Oktober Agustus', DateFormatter::localize('Thursday December October August', 'id'));
    }

    public function testKodeRegionalJatuhKeBahasanya(): void
    {
        self::assertSame('Rabu', DateFormatter::localize('Wednesday', 'id-ID'));
        self::assertSame('Rabu', DateFormatter::localize('Wednesday', 'id_ID'));
        self::assertSame('Wednesday', DateFormatter::localize('Wednesday', 'xx'));
    }

    // ── Table\Formatter ──────────────────────────────────────────────────

    public function testBawaanAngkaBahasaInggris(): void
    {
        self::assertSame('1,500.5', Formatter::formatNumber(1500.5, 1));
        self::assertSame('1,500', Formatter::format(1500, 'number'));
        self::assertSame('50.00%', Formatter::formatPercent(0.5));
    }

    public function testAngkaIndonesia(): void
    {
        self::assertSame('1.500,5', Formatter::formatNumber(1500.5, 1, 'id'));
        self::assertSame('1.500,5', Formatter::formatNumber(1500.5, 1, 'id-ID'));
        self::assertSame('1.500', Formatter::format(1500, 'number', ['locale' => 'id']));
        self::assertSame('50,00%', Formatter::formatPercent(0.5, 2, true, 'id'));
    }

    public function testTanggalFormatter(): void
    {
        self::assertSame('19 August 2026', Formatter::formatDate('2026-08-19', 'd F Y'));
        self::assertSame('19 Agustus 2026', Formatter::formatDate('2026-08-19', 'd F Y', 'id'));
    }

    // ── Dua setelan ──────────────────────────────────────────────────────

    public function testTanpaPenerjemahBawaanPustaka(): void
    {
        self::assertSame('en', ezdoc_locale());
        self::assertSame('en', ezdoc_document_locale());
    }

    public function testIsiDokumenKosongIkutLayar(): void
    {
        $this->layar('id');
        ezdoc_set_document_locale('');

        self::assertSame('id', ezdoc_document_locale());
    }

    /** Pemasangan v2: layar diuji dalam bahasa Inggris, dokumen tetap Indonesia. */
    public function testIsiDokumenBolehBerbedaDariLayar(): void
    {
        $this->layar('en');
        ezdoc_set_document_locale('id');

        self::assertSame('en', ezdoc_locale());
        self::assertSame('id', ezdoc_document_locale());
    }

    // ── Isi dokumen ──────────────────────────────────────────────────────

    /**
     * Nilai bawaan `date:` ikut bahasa ISI dokumen, bukan bahasa layar.
     *
     * Formatnya memuat `l` karena nama hari selalu berbeda di kedua bahasa —
     * nama bulan tidak (hari ini September, sama di keduanya).
     */
    public function testNilaiBawaanTanggalIkutBahasaIsi(): void
    {
        $this->layar('en');
        ezdoc_set_document_locale('id');

        self::assertSame(DateFormatter::localize(date('l, d F Y'), 'id'), resolveDefault('date:l, d F Y'));
    }

    public function testNilaiBawaanTanggalTanpaSetelanIkutLayar(): void
    {
        $this->layar('en');

        self::assertSame(date('l, d F Y'), resolveDefault('date:l, d F Y'));
    }

    public function testAngkaTabledbIkutBahasaIsi(): void
    {
        $this->layar('en');
        ezdoc_set_document_locale('id');
        self::assertSame('1.500', _tabledbFormatValue(1500, ['type' => 'number', 'args' => []]));
        self::assertSame('19 Agustus 2026', _tabledbFormatValue('2026-08-19', ['type' => 'date', 'args' => ['d F Y']]));

        ezdoc_set_document_locale('en');
        self::assertSame('1,500', _tabledbFormatValue(1500, ['type' => 'number', 'args' => []]));
    }

    public function testLangKerangkaPdfIkutBahasaIsi(): void
    {
        $this->layar('en');
        ezdoc_set_document_locale('id');

        $html = ezdoc_compose_pdf_html([
            'template'             => ['nama_template' => 'Uji'],
            'doc_id'               => 1,
            'paper'                => ['width' => 210, 'height' => 297],
            'pad_top'              => 20,
            'pad_right'            => 20,
            'pad_bottom'           => 20,
            'pad_left'             => 20,
            'content'              => '<p>x</p>',
            'config_ttd'           => [],
            'ttd_values'           => [],
            'pdf_fields'           => [],
            'has_ttd_placeholders' => true,
            'conn'                 => null,
        ]);

        self::assertStringContainsString('<html lang="id">', $html);
    }

    // ── Layar ────────────────────────────────────────────────────────────

    /**
     * Waktu relatif lewat katalog bahasa.
     *
     * "5d" berarti lima detik dalam singkatan lamanya, dan lima HARI dalam
     * singkatan Inggris — teks tertanam tidak bisa benar di kedua bahasa.
     */
    public function testWaktuRelatifIkutLayar(): void
    {
        $duaJamLalu = date('Y-m-d H:i:s', time() - 7300);

        $this->layar('en');
        self::assertSame('2h ago', ezdoc_relative_time($duaJamLalu));

        $this->layar('id');
        self::assertSame('2j lalu', ezdoc_relative_time($duaJamLalu));
    }

    /**
     * Tanpa Translator: teks bawaan, dengan {param} tetap disisipkan.
     *
     * Lolos dari uji di atas — yang selalu memasang Translator — dan baru
     * tertangkap saat dijalankan di PHP 7.4 tanpa view: "{n}h ago". Sebabnya
     * t() cadangan mengembalikan teks bawaan apa adanya. Kena juga pesan server
     * seperti `Not authorized to sign as "{label}"`.
     */
    public function testTanpaPenerjemahParameterTetapDisisipkan(): void
    {
        self::assertSame("2h ago", ezdoc_relative_time(date("Y-m-d H:i:s", time() - 7300)));
        self::assertSame(
            "Not authorized to sign as \"Dokter\"",
            t("kunci.tidak.ada", ["label" => "Dokter"], "Not authorized to sign as \"{label}\"")
        );
    }

    /**
     * Keempat salinan t() cadangan menyisipkan parameter dengan cara yang sama.
     *
     * Salinan mana yang menang tergantung berkas mana yang termuat lebih dulu,
     * jadi satu yang tertinggal cukup untuk membuat cacatnya kembali.
     *
     * @return iterable<string,array{0:string}>
     */
    public static function salinanT(): iterable
    {
        foreach (["lib/view_helpers.php", "actions/_dispatcher.php", "views/document/designer.php", "views/document/generate.php"] as $f) {
            yield $f => [$f];
        }
    }

    /** @dataProvider salinanT */
    public function testSalinanTMenyisipkanParameter(string $berkas): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . "/" . $berkas);

        self::assertStringNotContainsString("return \$default !== null ? \$default : \$key;", $sumber);
        self::assertStringContainsString("\$translator = new \Ezdoc\UI\Translator(new \Ezdoc\UI\Config([]));", $sumber);
    }

    public function testDaftarTemplateMenerjemahkanNamaBulan(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/template_list.php');

        self::assertStringContainsString(
            "DateFormatter::localize(date('d M Y H:i', strtotime(\$t['updated_at'])), ezdoc_locale())",
            $sumber
        );
    }

    /**
     * Halaman tidak lagi mengaku berbahasa Indonesia apa pun setelannya.
     *
     * @return iterable<string,array{0:string}>
     */
    public static function halaman(): iterable
    {
        yield 'generate' => ['views/document/generate.php'];
        yield 'designer' => ['views/document/designer.php'];
        yield 'generate_list' => ['views/document/generate_list.php'];
    }

    /** @dataProvider halaman */
    public function testLangHalamanIkutLayar(string $berkas): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $berkas);

        self::assertStringNotContainsString('<html lang="id">', $sumber);
        self::assertStringContainsString('<html lang="<?= h(ezdoc_locale()) ?>">', $sumber);
    }

    // ── Pemasangan dari setelan ──────────────────────────────────────────

    /**
     * Dispatcher action memasang `document.locale` dari config-nya.
     *
     * Jalur pembekuan lewat sini. Kalau tidak dipasang, arsip PDF bekunya akan
     * dirender dengan bahasa layar sementara cetakannya dengan bahasa isi.
     *
     * @return iterable<string,array{0:array<string,string>,1:string,2:string}>
     */
    public static function setelan(): iterable
    {
        yield 'v2: layar en, isi id' => [['app.locale' => 'en', 'document.locale' => 'id'], 'en', 'id'];
        yield 'isi kosong ikut layar' => [['app.locale' => 'id'], 'id', 'id'];
        yield 'tanpa setelan' => [[], 'en', 'en'];
    }

    /**
     * @dataProvider setelan
     * @param array<string,string> $setelan
     */
    public function testDispatcherMemasangSetelan(array $setelan, string $layar, string $isi): void
    {
        $keluaran = (string) shell_exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_locale.php'),
            escapeshellarg(base64_encode((string) json_encode($setelan)))
        ));

        self::assertSame(['layar' => $layar, 'isi' => $isi], json_decode($keluaran, true), $keluaran);
    }

    /** View generate memasangnya di tempat yang sama dengan penerjemahnya. */
    public function testGenerateMemasangSetelan(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertMatchesRegularExpression(
            '/\$GLOBALS\[\'translator\'\] = \$translator;\s*(?:\/\/[^\n]*\n\s*)*'
            . 'ezdoc_set_document_locale\(\(string\) \$config->get\(\'document\.locale\', \'\'\)\);/',
            $sumber
        );
    }

    /** Tanpa sisa: tidak ada lagi `'id'` tertanam sebagai bahasa format. */
    public function testTidakAdaBahasaFormatTertanam(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/Format/DateFormatter.php',
            'src/Table/Formatter.php',
            'lib/doc_template_helpers.php',
            'lib/pdf_compose.php',
        ] as $berkas) {
            // Kode saja — contoh di docblock boleh menyebut 'id'.
            $sumber = '';
            foreach (token_get_all((string) file_get_contents($root . '/' . $berkas)) as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sumber .= is_array($tok) ? $tok[1] : $tok;
            }
            self::assertDoesNotMatchRegularExpression(
                "/(string \\\$locale = 'id'|\\?\\? 'id'|localize\\([^)]*, 'id'\\))/",
                $sumber,
                "{$berkas} masih menanam 'id'"
            );
        }
    }
}
