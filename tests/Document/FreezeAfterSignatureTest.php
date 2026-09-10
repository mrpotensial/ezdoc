<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Rendering\PdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tahap 2b langkah 3 — tampilan dokumen dibekukan tiap ada tanda tangan baru.
 *
 * Ini yang selama ini diminta dan belum pernah ada: sampai hari tes ini ditulis,
 * pemasangan ini punya seribu lebih tanda tangan dan **nol** artefak. Lapisan
 * pembekuannya lengkap sejak Agustus, tapi tidak ada satu pun kode yang
 * memanggilnya.
 *
 * Yang diuji di sini rangkaiannya benar-benar berjalan sampai ke baris artefak —
 * bukan bahwa fungsinya ada. Wiring yang tidak pernah dijalankan bukan bukti.
 *
 * Butuh pdo_sqlite. Perender PDF-nya dipalsukan: yang diuji rangkaian
 * pembekuannya, bukan mesin PDF-nya.
 *
 * Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md §2 dan §4.
 *
 * Dijalankan di proses terpisah. Tes ini menyetel EZDOC_ARTIFACT_STORAGE_PATH,
 * dan konstanta berlaku se-proses: ArtifactStorageDefaultTest justru menguji
 * PENOLAKAN ketika setelan itu tidak ada, dan jadi hijau palsu kalau kami
 * berbagi proses.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FreezeAfterSignatureTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var PdoConnection */
    private $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        // Artefak disimpan sebagai berkas, sama seperti di pemasangan sungguhan.
        // Diarahkan ke folder sementara supaya tesnya tidak menulis ke storage
        // milik siapa pun.
        if (!defined('EZDOC_ARTIFACT_STORAGE_PATH')) {
            $tmp = sys_get_temp_dir() . '/ezdoc-uji-artefak';
            if (!is_dir($tmp)) {
                mkdir($tmp, 0775, true);
            }
            define('EZDOC_ARTIFACT_STORAGE_PATH', $tmp);
        }

        require_once dirname(__DIR__, 2) . '/lib/view_helpers.php';
        require_once dirname(__DIR__, 2) . '/lib/doc_template_helpers.php';
        require_once dirname(__DIR__, 2) . '/lib/signature_image.php';
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';

        // Berkas action-nya memuat fungsinya saja, tidak menjalankan
        // penyimpanannya — lihat gerbang EZDOC_SAVE_SELFTEST di kepalanya.
        if (!defined('EZDOC_SAVE_SELFTEST')) {
            define('EZDOC_SAVE_SELFTEST', true);
        }
        require_once dirname(__DIR__, 2) . '/actions/document/save_document.php';

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dir = dirname(__DIR__, 2) . '/migrations/sqlite/';
        foreach ([
            '2026_01_01_000001_create_ezdoc_templates.sql',
            '2026_01_01_000002_create_ezdoc_documents.sql',
            '2026_01_01_000005_create_ezdoc_signatures.sql',
            '2026_07_30_000001_create_ezdoc_blobs.sql',
            '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
        ] as $f) {
            $this->pdo->exec((string) file_get_contents($dir . $f));
        }

        $this->db = new PdoConnection($this->pdo);

        Context::setDefault(
            (new Context(
                $this->db,
                new CallableRoleProvider(
                    static function () { return true; },
                    static function () { return 7; },
                    static function () { return ['superadmin']; }
                )
            ))->withPdf(new PdfPalsu())
        );

        $this->siapkanData();
    }

    protected function tearDown(): void
    {
        Context::resetDefault();
    }

    private function siapkanData(): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content,
                                          signature_config, layout_config, is_active)
             VALUES (1, 'tpl-uuid-1', 'uji', 1, 1, 'Surat Uji', 'umum', 'patient', ?, ?, ?, 1)",
            [
                '<p>Halo {{nama}}.</p>'
                . '<div class="ttd-placeholder" data-ttd="ttd_1" data-label="Dokter"'
                . ' data-nama-field="nama_dokter" data-ttd-modes="image"></div>',
                '[]',
                '{}',
            ]
        );

        $this->db->execute(
            "INSERT INTO ezdoc_documents (id, uuid, template_uuid, template_version, title, norm, nopen, label,
                                          version, field_values, signature_values)
             VALUES (5, 'dok-uuid-5', 'tpl-uuid-1', 1, 'Surat Uji', '12345', '678', '-', 1, ?, ?)",
            [
                json_encode(['nama' => 'Budi', 'nama_dokter' => 'dr. Sari']),
                json_encode(['ttd_1' => '']),
            ]
        );
    }

    public function testTandaTanganPertamaMenghasilkanArtefak(): void
    {
        self::assertSame(0, $this->jumlahArtefak(), 'seharusnya belum ada artefak');

        $artefak = ezdoc_freeze_after_signature($this->db, 5, 1, '7');

        self::assertSame(1, $artefak->getCycle(), 'siklus pertama harus 1');
        self::assertSame(1, $this->jumlahArtefak());
    }

    /**
     * Tiap tanda tangan berikutnya menghasilkan siklus baru, dan yang lama
     * ditandai digantikan — bukan dihapus.
     *
     * Ini inti §2 rencananya. Tanda tangan ke-N menyatakan sesuatu tentang
     * dokumen SEPERTI TAMPAK SAAT ITU; kalau siklus lama dibuang, pernyataan
     * penanda tangan sebelumnya kehilangan barang buktinya.
     */
    public function testTiapTandaTanganMenghasilkanSiklusBaruTanpaMembuangYangLama(): void
    {
        $satu = ezdoc_freeze_after_signature($this->db, 5, 1, '7');

        // Tanda tangan kedua masuk: isinya berubah.
        $this->db->execute(
            'UPDATE ezdoc_documents SET signature_values = ? WHERE id = 5',
            [json_encode(['ttd_1' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='])]
        );

        $dua = ezdoc_freeze_after_signature($this->db, 5, 1, '7');

        self::assertSame(1, $satu->getCycle());
        self::assertSame(2, $dua->getCycle(), 'tanda tangan kedua harus jadi siklus 2');
        self::assertSame(2, $this->jumlahArtefak(), 'siklus lama tidak boleh dihapus');
    }

    /** Isi yang berbeda menghasilkan byte yang berbeda — arsipnya bukan hiasan. */
    public function testArtefakMerekamTampilanSAATITU(): void
    {
        ezdoc_freeze_after_signature($this->db, 5, 1, '7');
        $kunciSatu = $this->kunciTerakhir();

        $this->db->execute(
            'UPDATE ezdoc_documents SET field_values = ? WHERE id = 5',
            [json_encode(['nama' => 'Siti', 'nama_dokter' => 'dr. Sari'])]
        );
        ezdoc_freeze_after_signature($this->db, 5, 1, '7');

        self::assertNotSame(
            $kunciSatu,
            $this->kunciTerakhir(),
            'isi berubah tapi artefaknya sama — yang dibekukan bukan tampilannya'
        );
    }

    private function jumlahArtefak(): int
    {
        return (int) $this->db->fetchScalar('SELECT COUNT(*) FROM ezdoc_document_artifacts');
    }

    private function kunciTerakhir(): string
    {
        return (string) $this->db->fetchScalar(
            'SELECT blob_key FROM ezdoc_document_artifacts ORDER BY cycle DESC LIMIT 1'
        );
    }
}

/** Perender palsu: yang diuji rangkaian pembekuannya, bukan mesin PDF-nya. */
final class PdfPalsu implements PdfRenderer
{
    public function render(string $html, array $paperMm, string $orientation): string
    {
        // Byte-nya diturunkan dari HTML-nya, supaya isi berbeda tetap
        // menghasilkan artefak berbeda — seperti PDF sungguhan.
        return "%PDF-1.4\n% palsu\n" . hash('sha256', $html) . "\n%%EOF";
    }

    public function stream(string $html, string $filename, array $paperMm, string $orientation): void
    {
        // Tidak dipakai di sini.
    }
}
