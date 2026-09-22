<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Storage\BlobStoreFactory;
use Ezdoc\Storage\DatabaseBlobStore;
use Ezdoc\Storage\DiskBlobStore;
use Ezdoc\Storage\MirrorBlobStore;
use PHPUnit\Framework\TestCase;

/**
 * Tanda tangan ke berkas, tanpa mengubah apa pun yang dibaca pemasangan lama.
 *
 * Diminta pemakai: "aku perlu fitur untuk migrasikan ttd ke file juga, tapi
 * tanpa merusak tabel, karena v1 masih pakai tabel". Di server, 2.844 gambar
 * tanda tangan ada di kolom `ezdoc_blobs.content`, dan pemasangan lama yang
 * memakai basis data yang sama membacanya dari sana.
 *
 * `DatabaseBlobStore` di sini berperan sebagai pemasangan lama: ia hanya
 * mengenal kolomnya. Setiap tes memeriksa apa yang ia lihat.
 */
final class MirrorBlobStoreTest extends TestCase
{
    private \PDO $pdo;
    private PdoConnection $db;
    private string $root = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec((string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/sqlite/2026_07_30_000001_create_ezdoc_blobs.sql'
        ));
        $this->db = new PdoConnection($this->pdo);
        $this->root = sys_get_temp_dir() . '/ezdoc-mirror-test-' . bin2hex(random_bytes(6));
        BlobStoreFactory::reset();
    }

    protected function tearDown(): void
    {
        BlobStoreFactory::reset();
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

    private function mirror(?string $root = null): MirrorBlobStore
    {
        return new MirrorBlobStore(new DatabaseBlobStore($this->db), new DiskBlobStore($this->db, $root ?? $this->root));
    }

    /** Pemasangan lama: hanya kolomnya. */
    private function v1(): DatabaseBlobStore
    {
        return new DatabaseBlobStore($this->db);
    }

    private function path(string $key): string
    {
        return $this->root . '/' . substr($key, 0, 2) . '/' . $key;
    }

    /** @return array<string,mixed> */
    private function row(string $key): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ezdoc_blobs WHERE "key" = ?');
        $st->execute([$key]);

        $row = $st->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    // ── Yang dilihat pemasangan lama ─────────────────────────────────────

    /** Tanda tangan baru dari v2 tetap terbaca oleh yang hanya mengenal kolom. */
    public function testTandaTanganBaruTerbacaOlehPemasanganLama(): void
    {
        $blob = $this->mirror()->put('ttd baru', 'image/png');

        self::assertSame('ttd baru', $this->v1()->get($blob->key()));
    }

    /**
     * Barisnya persis seperti yang ditulis backend database.
     *
     * `backend = 'database'` dan `content` terisi: arti kolom itu ("NULL berarti
     * di disk") tidak berubah untuk pembaca mana pun.
     */
    public function testBarisnyaSamaDenganBackendDatabase(): void
    {
        $key = $this->mirror()->put('ttd', 'image/png')->key();
        $row = $this->row($key);

        self::assertSame('database', $row['backend']);
        self::assertSame('ttd', $row['content']);
        self::assertSame('image/png', $row['content_type']);
    }

    public function testSalinanBerkasnyaDitulis(): void
    {
        $key = $this->mirror()->put('ttd', 'image/png')->key();

        self::assertSame('ttd', @file_get_contents($this->path($key)));
    }

    /** Objek yang sudah ada tidak ditulis ulang — barisnya tetap barisnya. */
    public function testBarisYangSudahAdaTidakDisentuh(): void
    {
        $key = $this->v1()->put('ttd lama', 'image/png')->key();
        $sebelum = $this->row($key);

        $this->mirror()->put('ttd lama', 'image/png');

        self::assertSame($sebelum, $this->row($key));
    }

    // ── Membaca ──────────────────────────────────────────────────────────

    /** Objek yang ditulis pemasangan lama — hanya di kolom — terbaca, lalu disalin. */
    public function testObjekHanyaDiKolomTerbacaDanDisalin(): void
    {
        $key = $this->v1()->put('ditulis v1', 'image/png')->key();
        self::assertFileDoesNotExist($this->path($key));

        self::assertSame('ditulis v1', $this->mirror()->get($key));
        self::assertSame('ditulis v1', @file_get_contents($this->path($key)), 'baca harus menyalin ke berkas');
    }

    /** Berkas dibaca lebih dulu: kolom yang rusak tidak mengganggu selama berkasnya utuh. */
    public function testBerkasDibacaLebihDulu(): void
    {
        $key = $this->mirror()->put('asli', 'image/png')->key();
        $this->pdo->prepare('UPDATE ezdoc_blobs SET content = ? WHERE "key" = ?')->execute(['rusak', $key]);

        self::assertSame('asli', $this->mirror()->get($key));
    }

    /** Berkas yang rusak jatuh ke kolom, dan berkasnya diperbaiki. */
    public function testBerkasRusakJatuhKeKolomDanDiperbaiki(): void
    {
        $key = $this->mirror()->put('asli', 'image/png')->key();
        file_put_contents($this->path($key), 'palsu');

        self::assertSame('asli', $this->mirror()->get($key));
        self::assertSame('asli', file_get_contents($this->path($key)));
    }

    /** Baris yang menentukan: objek yang barisnya dihapus tetap hilang walau berkasnya tersisa. */
    public function testBarisTerhapusTetapHilang(): void
    {
        $key = $this->mirror()->put('dihapus', 'image/png')->key();
        $this->pdo->prepare('DELETE FROM ezdoc_blobs WHERE "key" = ?')->execute([$key]);

        self::assertFileExists($this->path($key));
        self::assertNull($this->mirror()->get($key));
    }

    /** Objek yang memang hanya di disk (arsip PDF beku) tetap terbaca. */
    public function testObjekDiskSajaTetapTerbaca(): void
    {
        $key = (new DiskBlobStore($this->db, $this->root))->put('%PDF beku', 'application/pdf')->key();

        self::assertSame('%PDF beku', $this->mirror()->get($key));
    }

    // ── Gagal menulis berkas ─────────────────────────────────────────────

    /**
     * Tanda tangan tidak ditolak karena salinan keduanya gagal ditulis.
     *
     * Root-nya sebuah BERKAS, jadi direktori di bawahnya tidak bisa dibuat.
     */
    public function testGagalMenulisBerkasTidakMenggagalkanSimpan(): void
    {
        $bukanDir = $this->root . '-berkas';
        file_put_contents($bukanDir, 'x');

        try {
            $blob = @$this->mirror($bukanDir)->put('tetap tersimpan', 'image/png');
            self::assertSame('tetap tersimpan', $this->v1()->get($blob->key()));
        } finally {
            @unlink($bukanDir);
        }
    }

    public function testHapusMenghapusKeduanya(): void
    {
        $key = $this->mirror()->put('sekali', 'image/png')->key();

        self::assertTrue($this->mirror()->delete($key));
        self::assertFileDoesNotExist($this->path($key));
        self::assertSame([], $this->row($key));
    }

    // ── Setelan ──────────────────────────────────────────────────────────

    public function testPabrikMembangunCermin(): void
    {
        $store = BlobStoreFactory::make($this->db, 'mirror', $this->root);

        self::assertInstanceOf(MirrorBlobStore::class, $store);
        self::assertSame('mirror', $store->name());
    }

    /** Tanpa folder: ditolak, bukan diam-diam jatuh ke kolom saja. */
    public function testCerminTanpaFolderDitolak(): void
    {
        $this->expectException(EzdocException::class);
        $this->expectExceptionMessage('"mirror"');

        BlobStoreFactory::make($this->db, 'mirror');
    }
}
