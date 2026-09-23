<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Storage\BlobMaintenance;
use Ezdoc\Storage\DatabaseBlobStore;
use Ezdoc\Storage\DiskBlobStore;
use Ezdoc\Storage\MirrorBlobStore;
use PHPUnit\Framework\TestCase;

/**
 * Pekerjaan pemeliharaan objek: menyalin, memeriksa, mencari yatim.
 *
 * Satu tempat untuk tiga pemakai (dua CLI dan halaman admin), supaya "objek
 * mana yang masih dirujuk" tidak ditulis tiga kali — versi purge yang menulis
 * sendiri melewatkan arsip PDF beku, dan `apply` akan menghapus buktinya.
 */
final class BlobMaintenanceTest extends TestCase
{
    private \PDO $pdo;
    private PdoConnection $db;
    private string $root = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ([
            '2026_01_01_000001_create_ezdoc_templates.sql',
            '2026_01_01_000002_create_ezdoc_documents.sql',
            '2026_07_30_000001_create_ezdoc_blobs.sql',
            '2026_01_01_000005_create_ezdoc_signatures.sql',
            '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
        ] as $f) {
            $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/sqlite/' . $f));
        }
        $this->db = new PdoConnection($this->pdo);
        $this->root = sys_get_temp_dir() . '/ezdoc-rawat-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->root);
        }
    }

    private function rawat(): BlobMaintenance
    {
        return new BlobMaintenance($this->db);
    }

    private function files(): DiskBlobStore
    {
        return new DiskBlobStore($this->db, $this->root);
    }

    /** Dokumen dengan satu slot yang merujuk objek, dan satu gambar inline. */
    private function dokumen(string $ref): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
             VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', 1)"
        );
        $this->db->execute(
            "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                          norm, nopen, subject_id, context_id, label, version, signature_values)
             VALUES (1, 'dok-1', 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', 1, ?)",
            [json_encode(['a' => $ref, 'b' => 'data:image/png;base64,iVBORw0KGgo='])]
        );
    }

    private function artefak(string $key): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_document_artifacts (uuid, document_id, cycle, blob_key, frozen_at)
             VALUES ('art-1', 1, 1, ?, '2026-01-01 00:00:00')",
            [$key]
        );
    }

    // ── Rujukan ──────────────────────────────────────────────────────────

    /** Arsip PDF beku ikut dihitung — inilah yang dulu terlewat. */
    public function testArsipBekuTerhitungSebagaiRujukan(): void
    {
        $ttd = (new DatabaseBlobStore($this->db))->put('ttd', 'image/png');
        $pdf = (new DatabaseBlobStore($this->db))->put('%PDF', 'application/pdf');
        $this->dokumen($ttd->reference());
        $this->artefak($pdf->key());

        $dirujuk = $this->rawat()->referencedKeys();

        self::assertArrayHasKey($ttd->key(), $dirujuk);
        self::assertArrayHasKey($pdf->key(), $dirujuk, 'arsip beku harus dihitung dirujuk');
    }

    public function testYatimHanyaYangTidakDirujukDanSudahLewatTenggang(): void
    {
        $dipakai = (new DatabaseBlobStore($this->db))->put('dipakai', 'image/png');
        $yatim   = (new DatabaseBlobStore($this->db))->put('yatim', 'image/png');
        $this->dokumen($dipakai->reference());
        $this->pdo->exec("UPDATE ezdoc_blobs SET created_at = '2026-01-01 00:00:00'");
        $baru = (new DatabaseBlobStore($this->db))->put('baru saja', 'image/png');

        $hasil = $this->rawat()->orphans(48);
        $kunci = array_column($hasil['objek'], 'key');

        self::assertSame([$yatim->key()], $kunci);
        self::assertNotContains($baru->key(), $kunci, 'yang masih dalam tenggang tidak ikut');
        self::assertSame(5, $hasil['byte']);
    }

    // ── Menyalin ─────────────────────────────────────────────────────────

    public function testMenyalinPerBatchSampaiSelesai(): void
    {
        $db = new DatabaseBlobStore($this->db);
        foreach (['satu', 'dua', 'tiga'] as $isi) {
            $db->put($isi, 'image/png');
        }

        $rawat = $this->rawat();
        $files = $this->files();

        $batch1 = $rawat->copyToFilesBatch($files, 0, 2);
        self::assertSame(2, $batch1['disalin']);
        self::assertFalse($batch1['selesai']);

        $batch2 = $rawat->copyToFilesBatch($files, $batch1['after_id'], 2);
        self::assertSame(1, $batch2['disalin']);

        // Batch berikutnya kosong: itu tanda selesainya.
        self::assertTrue($rawat->copyToFilesBatch($files, $batch2['after_id'], 2)['selesai']);
        self::assertSame(3, $rawat->summary($files)['berkas']);
    }

    /** Syarat pemakai: tabel tidak berubah sedikit pun. */
    public function testMenyalinTidakMengubahBasisData(): void
    {
        (new DatabaseBlobStore($this->db))->put('ttd', 'image/png');
        $sebelum = $this->pdo->query('SELECT * FROM ezdoc_blobs')->fetchAll(\PDO::FETCH_ASSOC);

        $this->rawat()->copyToFilesBatch($this->files(), 0, 100);

        self::assertSame($sebelum, $this->pdo->query('SELECT * FROM ezdoc_blobs')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testBarisRusakDilaporkanDenganKodeDanTidakDisalin(): void
    {
        $blob = (new DatabaseBlobStore($this->db))->put('asli', 'image/png');
        $this->pdo->prepare('UPDATE ezdoc_blobs SET content = ? WHERE "key" = ?')->execute(['palsu', $blob->key()]);

        $hasil = $this->rawat()->copyToFilesBatch($this->files(), 0, 100);

        self::assertSame(1, $hasil['rusak']);
        self::assertSame([['key' => $blob->key(), 'sebab' => 'checksum_kolom', 'detail' => '']], $hasil['masalah']);
        self::assertSame(-1, $this->rawat()->summary($this->files())['berkas'], 'tidak ada berkas yang ditulis');
    }

    public function testMelihatDuluTidakMenulisBerkas(): void
    {
        (new DatabaseBlobStore($this->db))->put('ttd', 'image/png');

        $hasil = $this->rawat()->copyToFilesBatch($this->files(), 0, 100, false);

        self::assertSame(1, $hasil['disalin'], 'dihitung sebagai akan disalin');
        self::assertSame(-1, $this->rawat()->summary($this->files())['berkas'], 'foldernya belum dibuat');
    }

    // ── Memeriksa ────────────────────────────────────────────────────────

    public function testPemeriksaanMenemukanSalinanBerkasYangBelumAda(): void
    {
        $blob = (new DatabaseBlobStore($this->db))->put('ttd', 'image/png');

        $hasil = $this->rawat()->checkBatch(new DatabaseBlobStore($this->db), $this->files(), 0, 100);

        self::assertSame(1, $hasil['diperiksa']);
        self::assertSame([['key' => $blob->key(), 'sebab' => 'berkas_hilang']], $hasil['masalah']);
    }

    public function testPemeriksaanMenemukanByteYangTidakCocokKuncinya(): void
    {
        $blob = (new DatabaseBlobStore($this->db))->put('asli', 'image/png');
        $this->pdo->prepare('UPDATE ezdoc_blobs SET content = ? WHERE "key" = ?')->execute(['palsu', $blob->key()]);

        $hasil = $this->rawat()->checkBatch(new DatabaseBlobStore($this->db), null, 0, 100);

        self::assertSame([['key' => $blob->key(), 'sebab' => 'tak_terbaca']], $hasil['masalah']);
    }

    /** Objek yang memang hanya di disk tidak dianggap kehilangan salinan kolom. */
    public function testObjekDiskSajaLolosPemeriksaan(): void
    {
        $files = $this->files();
        $files->put('%PDF beku', 'application/pdf');

        $hasil = $this->rawat()->checkBatch(
            new MirrorBlobStore(new DatabaseBlobStore($this->db), $files),
            $files,
            0,
            100
        );

        self::assertSame(1, $hasil['utuh']);
        self::assertSame([], $hasil['masalah']);
    }

    /**
     * Dokumen beku boleh tinggal di folder lain, dan itu bukan masalah.
     *
     * Pemasangan nyata memisahkannya: gambar tanda tangan di satu folder,
     * dokumen beku di folder arsip. Objek yang hanya ada di folder kedua tidak
     * terbaca store utama — melaporkannya "hilang" tiap kali diperiksa akan
     * mengajari orang mengabaikan peringatan yang benar.
     */
    public function testObjekDiFolderLainTidakDilaporkanHilang(): void
    {
        $folderArsip = $this->root . '-arsip';
        $arsip = new DiskBlobStore($this->db, $folderArsip);
        $arsip->put('%PDF beku', 'application/pdf');

        try {
            $utama = new MirrorBlobStore(new DatabaseBlobStore($this->db), $this->files());

            $tanpa = $this->rawat()->checkBatch($utama, $this->files(), 0, 100);
            self::assertSame([['key' => hash('sha256', '%PDF beku'), 'sebab' => 'tak_terbaca']], $tanpa['masalah']);

            $dengan = $this->rawat()->checkBatch($utama, $this->files(), 0, 100, $arsip);
            self::assertSame([], $dengan['masalah']);
            self::assertSame(1, $dengan['utuh']);
        } finally {
            foreach ((array) glob($folderArsip . '/*/*') as $f) {
                @unlink($f);
            }
            foreach ((array) glob($folderArsip . '/*') as $d) {
                @rmdir($d);
            }
            @rmdir($folderArsip);
        }
    }

    // ── Ringkasan ────────────────────────────────────────────────────────

    // ── Memindahkan gambar yang masih di dalam dokumen ───────────────────

    /** @return array{0:string,1:string} [data URI, byte] */
    private function gambar(string $isi): array
    {
        return ['data:image/png;base64,' . base64_encode($isi), $isi];
    }

    private function dokumenInline(int $id, array $nilai): void
    {
        $this->db->execute(
            "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
             VALUES (?, ?, ?, 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', 1)",
            [$id, 'tpl-' . $id, 'uji-' . $id]
        );
        $this->db->execute(
            "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                          norm, nopen, subject_id, context_id, label, version, signature_values)
             VALUES (?, ?, ?, ?, 1, 'Uji', ?, '11', ?, '11', '-', 1, ?)",
            [$id, 'dok-' . $id, $id, 'tpl-' . $id, (string) $id, (string) $id, json_encode($nilai)]
        );
    }

    private function nilaiSlot(int $docId): array
    {
        $row = $this->db->fetchOne('SELECT signature_values FROM ezdoc_documents WHERE id = ?', [$docId]);

        return (array) json_decode((string) ($row['signature_values'] ?? ''), true);
    }

    public function testGambarInlineJadiObjekDanDokumenMenunjukKeSana(): void
    {
        [$uri, $byte] = $this->gambar('gambar-ttd');
        $this->dokumenInline(1, ['a' => $uri]);
        $images = new \Ezdoc\Storage\SignatureImageStore(new DatabaseBlobStore($this->db));

        $hasil = $this->rawat()->migrateInlineBatch($images, 0, 10);

        self::assertSame(1, $hasil['dipindah']);
        self::assertSame(1, $hasil['dokumen']);
        self::assertSame('blob:sha256-' . hash('sha256', $byte), $this->nilaiSlot(1)['a']);
        self::assertSame($byte, (new DatabaseBlobStore($this->db))->get(hash('sha256', $byte)));
    }

    /**
     * Slot yang sudah ditandatangani tidak disentuh.
     *
     * `content_hash` tanda tangan mencakup nilai slotnya. Menukar gambar inline
     * dengan referensi mengubah nilai itu, dan verifikasi akan melaporkannya
     * `tampered` — aplikasi membatalkan buktinya sendiri demi beberapa kilobita.
     */
    public function testSlotBertandaTanganDibiarkanApaAdanya(): void
    {
        [$uri] = $this->gambar('sudah-ttd');
        $this->dokumenInline(1, ['a' => $uri]);
        $this->db->execute(
            "INSERT INTO ezdoc_signatures (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                                           envelope_format, envelope, content_hash, signed_at, verify_status, metadata)
             VALUES ('sig-1', 1, 1, '7', 'hmac', 1, 'hmac', 'e', 'h', '2026-09-01 10:00:00', 'valid', ?)",
            [json_encode(['ttd_id' => 'a'])]
        );

        $hasil = $this->rawat()->migrateInlineBatch(
            new \Ezdoc\Storage\SignatureImageStore(new DatabaseBlobStore($this->db)),
            0,
            10
        );

        self::assertSame(0, $hasil['dipindah']);
        self::assertSame(1, $hasil['dilewati']);
        self::assertSame($uri, $this->nilaiSlot(1)['a'], 'nilainya harus tetap sama persis');
    }

    public function testMelihatDuluTidakMengubahDokumen(): void
    {
        [$uri] = $this->gambar('gambar-ttd');
        $this->dokumenInline(1, ['a' => $uri]);
        $sebelum = $this->pdo->query('SELECT * FROM ezdoc_documents')->fetchAll(\PDO::FETCH_ASSOC);

        $hasil = $this->rawat()->migrateInlineBatch(
            new \Ezdoc\Storage\SignatureImageStore(new DatabaseBlobStore($this->db)),
            0,
            10,
            false
        );

        self::assertSame(1, $hasil['dipindah'], 'dihitung sebagai akan dipindah');
        self::assertSame(0, $hasil['dokumen']);
        self::assertSame($sebelum, $this->pdo->query('SELECT * FROM ezdoc_documents')->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Objek yang tidak terbaca kembali sama persis: dokumennya tidak diubah.
     *
     * Menulis ulang dokumen lebih dulu lalu menemukan masalahnya berarti sudah
     * kehilangan satu-satunya salinan gambar itu.
     */
    public function testGagalBacaUlangMembatalkanPemindahan(): void
    {
        [$uri] = $this->gambar('gambar-ttd');
        $this->dokumenInline(1, ['a' => $uri]);

        // Penyimpan yang menerima byte lalu mengembalikan yang lain.
        $bohong = new class($this->db) implements \Ezdoc\Storage\BlobStore {
            /** @var DatabaseBlobStore */
            private $asli;

            public function __construct($db)
            {
                $this->asli = new DatabaseBlobStore($db);
            }

            public function put(string $bytes, string $contentType): \Ezdoc\Storage\Blob
            {
                return $this->asli->put($bytes, $contentType);
            }

            public function get(string $key): ?string
            {
                return 'byte lain';
            }

            public function find(string $key): ?\Ezdoc\Storage\Blob
            {
                return $this->asli->find($key);
            }

            public function exists(string $key): bool
            {
                return $this->asli->exists($key);
            }

            public function delete(string $key): bool
            {
                return $this->asli->delete($key);
            }

            public function name(): string
            {
                return 'bohong';
            }
        };

        $hasil = $this->rawat()->migrateInlineBatch(new \Ezdoc\Storage\SignatureImageStore($bohong), 0, 10);

        self::assertSame(1, $hasil['gagal']);
        self::assertSame(0, $hasil['dokumen'], 'dokumen tidak boleh ditulis ulang');
        self::assertSame($uri, $this->nilaiSlot(1)['a']);
    }

    public function testDokumenTanpaGambarInlineTidakDisentuh(): void
    {
        $this->dokumenInline(1, ['a' => 'blob:sha256-' . str_repeat('b', 64), 'b' => 'qr:v1']);
        $sebelum = $this->pdo->query('SELECT * FROM ezdoc_documents')->fetchAll(\PDO::FETCH_ASSOC);

        $hasil = $this->rawat()->migrateInlineBatch(
            new \Ezdoc\Storage\SignatureImageStore(new DatabaseBlobStore($this->db)),
            0,
            10
        );

        self::assertTrue($hasil['selesai']);
        self::assertSame(0, $hasil['dipindah']);
        self::assertSame($sebelum, $this->pdo->query('SELECT * FROM ezdoc_documents')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testRingkasanMenghitungKolomDiskInlineDanRujukan(): void
    {
        $kolom = (new DatabaseBlobStore($this->db))->put('di kolom', 'image/png');
        $this->files()->put('%PDF', 'application/pdf');
        $this->dokumen($kolom->reference());

        $ringkas = $this->rawat()->summary($this->files());

        self::assertSame(2, $ringkas['objek']);
        self::assertSame(1, $ringkas['di_kolom']);
        self::assertSame(1, $ringkas['di_disk']);
        self::assertSame(1, $ringkas['berkas'], 'baru yang di disk yang punya berkas');
        self::assertSame(1, $ringkas['inline'], 'gambar yang masih di dalam dokumen');
        self::assertSame(1, $ringkas['dirujuk']);
    }
}
