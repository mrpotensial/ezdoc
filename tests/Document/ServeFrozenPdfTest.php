<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Document\FreezeService;
use Ezdoc\Rendering\PdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tahap 2b langkah 4 — dokumen lengkap disajikan dari artefaknya.
 *
 * Tanpa langkah ini artefaknya tersimpan tapi tidak pernah dipakai: cetakan
 * masih dirender ulang dari template saat ini, jadi menyunting template masih
 * mengubah dokumen yang sudah ditandatangani. §5 rencananya: "yang menyajikan
 * dokumen beku tidak boleh merender ulang. Kalau ia merender ulang, seluruh
 * rencana ini tidak ada gunanya."
 *
 * Butuh pdo_sqlite. Proses terpisah karena menyetel
 * EZDOC_ARTIFACT_STORAGE_PATH, yang tes lain justru menguji ketiadaannya.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ServeFrozenPdfTest extends TestCase
{
    /** @var PdoConnection */
    private $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        if (!defined('EZDOC_ARTIFACT_STORAGE_PATH')) {
            $tmp = sys_get_temp_dir() . '/ezdoc-uji-sajikan-' . getmypid();
            if (!is_dir($tmp)) {
                mkdir($tmp, 0775, true);
            }
            define('EZDOC_ARTIFACT_STORAGE_PATH', $tmp);
        }

        require_once dirname(__DIR__, 2) . '/lib/view_helpers.php';
        require_once dirname(__DIR__, 2) . '/lib/doc_template_helpers.php';
        require_once dirname(__DIR__, 2) . '/lib/signature_image.php';
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dir = dirname(__DIR__, 2) . '/migrations/sqlite/';
        foreach ([
            '2026_01_01_000001_create_ezdoc_templates.sql',
            '2026_01_01_000002_create_ezdoc_documents.sql',
            '2026_01_01_000005_create_ezdoc_signatures.sql',
            '2026_07_30_000001_create_ezdoc_blobs.sql',
            '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
        ] as $f) {
            $pdo->exec((string) file_get_contents($dir . $f));
        }
        $this->db = new PdoConnection($pdo);

        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
             VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', 1)"
        );
        $this->db->execute(
            "INSERT INTO ezdoc_documents (id, uuid, template_uuid, template_version, title, norm, nopen, label, version)
             VALUES (5, 'dok-5', 'tpl-1', 1, 'Uji', '1', '1', '-', 1)"
        );

        Context::setDefault(new Context(
            $this->db,
            new CallableRoleProvider(
                static function () { return true; },
                static function () { return 7; },
                static function () { return ['superadmin']; }
            )
        ));
    }

    protected function tearDown(): void
    {
        Context::resetDefault();
    }

    public function testTanpaArtefakMengembalikanNull(): void
    {
        self::assertNull(ezdoc_frozen_pdf($this->db, 5), 'tanpa artefak harus render seperti biasa');
    }

    public function testArtefakAktifDisajikanApaAdanya(): void
    {
        $asli = "%PDF-1.4\n% arsip siklus satu\n%%EOF";
        (new FreezeService($this->db))->freeze(5, $asli, '7');

        $beku = ezdoc_frozen_pdf($this->db, 5);

        self::assertNotNull($beku);
        self::assertSame($asli, $beku['bytes'], 'yang disajikan harus byte yang dibekukan, bukan render ulang');
        self::assertSame(1, $beku['cycle']);
    }

    /**
     * Yang disajikan siklus TERAKHIR, bukan yang pertama.
     *
     * Dokumen dua penanda tangan punya dua siklus. Menyajikan yang pertama berarti
     * mencetak dokumen tanpa tanda tangan kedua — tanda tangan yang sah dan
     * tersimpan, tapi hilang dari kertasnya.
     */
    public function testYangDisajikanSiklusTerakhir(): void
    {
        $beku = new FreezeService($this->db);
        $beku->freeze(5, "%PDF siklus 1", '7');
        $beku->unfreeze(5, 'tanda tangan kedua masuk', '7');
        $beku->freeze(5, "%PDF siklus 2", '7');

        $hasil = ezdoc_frozen_pdf($this->db, 5);

        self::assertSame("%PDF siklus 2", $hasil['bytes']);
        self::assertSame(2, $hasil['cycle']);
    }

    /**
     * Berkas bukti yang hilang tidak menjatuhkan halaman cetak.
     *
     * Orang yang sedang mencetak tidak bisa berbuat apa-apa atas penyimpanan yang
     * rusak. Null berarti render seperti biasa, dan kejadiannya dicatat keras.
     */
    public function testBerkasHilangTidakMelempar(): void
    {
        $artefak = (new FreezeService($this->db))->freeze(5, "%PDF akan hilang", '7');

        // Hapus berkasnya dari penyimpanan, biarkan barisnya.
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(EZDOC_ARTIFACT_STORAGE_PATH, \FilesystemIterator::SKIP_DOTS)
        ) as $berkas) {
            @unlink((string) $berkas);
        }

        self::assertNotNull($artefak);
        self::assertNull(ezdoc_frozen_pdf($this->db, 5));
    }

    public function testIdTidakSahTidakMenyentuhBasisData(): void
    {
        self::assertNull(ezdoc_frozen_pdf($this->db, 0));
        self::assertNull(ezdoc_frozen_pdf($this->db, -3));
    }

    // ─── Satu perender untuk semua jalur ───

    public function testPerenderSuntikanHostDidahulukan(): void
    {
        $palsu = new class implements PdfRenderer {
            public function render(string $html, array $paperMm, string $orientation): string { return ''; }
            public function stream(string $html, string $filename, array $paperMm, string $orientation): void {}
        };
        Context::setDefault(Context::default()->withPdf($palsu));

        self::assertSame($palsu, ezdoc_pdf_renderer());
    }

    /**
     * Jalur cetak dan jalur beku menanyakan pembantu yang sama.
     *
     * Dulu jalur beku membuat DompdfRenderer sendiri tanpa basePath, sementara
     * jalur cetak memberinya akar library. Aset relatif bisa terselesaikan
     * berbeda, dan yang tersimpan sebagai bukti berhenti sama dengan yang dicetak.
     */
    public function testKeduaJalurMemakaiPembantuYangSama(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['views/document/generate.php', 'actions/document/save_document.php'] as $rel) {
            $kode = '';
            foreach (token_get_all((string) file_get_contents($root . '/' . $rel)) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $kode .= is_array($t) ? $t[1] : $t;
            }

            self::assertStringContainsString('ezdoc_pdf_renderer()', $kode, "{$rel} tidak memakai perender tunggal");
            self::assertStringNotContainsString(
                'new \\Ezdoc\\Rendering\\DompdfRenderer(',
                $kode,
                "{$rel} membuat DompdfRenderer sendiri — basePath-nya bisa menyimpang dari jalur lain"
            );
        }
    }

    // ─── Jalur cetak: syarat penyajiannya ───

    /**
     * Artefak disajikan HANYA untuk dokumen yang lengkap.
     *
     * Selama penandatanganan berjalan, penanda tangan berikutnya masih mengisi
     * field miliknya. Menyajikan artefak siklus sebelumnya berarti isian itu tidak
     * pernah tampak di cetakan, dan orang menandatangani sesuatu yang tidak bisa
     * mereka lihat.
     */
    public function testCetakMenyajikanArtefakHanyaSaatLengkap(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertMatchesRegularExpression(
            '/if \(\$docSigningComplete && \(int\) \$doc_id > 0 && !isset\(\$_GET\[\'debug\'\]\) && !\$__mintaMentah\)/',
            $view,
            'syarat penyajian artefak berubah — pastikan ia masih menunggu semua slot terisi'
        );

        // Artefaknya disajikan sebelum perakitan HTML — kalau tidak, render ulang
        // tetap terjadi dan cuma dibuang.
        self::assertLessThan(
            strpos($view, '$pdfHtml = ezdoc_document_pdf_html('),
            strpos($view, 'ezdoc_frozen_pdf('),
            'artefak harus diperiksa SEBELUM HTML dirakit'
        );
    }

    /** "PDF Raw" meminta render mentah, dan hanya superadmin yang boleh. */
    public function testPdfRawMelewatiArtefakHanyaUntukSuperadmin(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');
        $js   = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertStringContainsString("params.set('raw', '1');", $js);
        self::assertStringContainsString(
            "\$__mintaMentah = isset(\$_GET['raw']) && \$_GET['raw'] === '1' && \$isSuperadmin;",
            $view,
            'raw=1 harus dibatasi superadmin'
        );
    }
}
