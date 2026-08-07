<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Storage\BlobStoreFactory;
use Ezdoc\Storage\DatabaseBlobStore;
use Ezdoc\Storage\DiskBlobStore;
use PHPUnit\Framework\TestCase;

/**
 * Ke mana dokumen beku disimpan.
 *
 * Bawaan umum `BlobStore` adalah database, dan itu benar untuk apa yang
 * disimpannya selama ini: gambar tanda tangan ~3,5 KB, jauh di bawah titik
 * silang ~256 KB. Dokumen yang sudah dirender ada di sisi lain titik silang itu
 * — ratusan KB sampai beberapa MB.
 *
 * Mewarisi satu pengaturan untuk dua jenis benda yang berbeda dua orde besaran
 * berarti salah satunya pasti salah tempat, dan yang salah tempat itu baru
 * terasa berbulan-bulan kemudian sebagai tabel yang membengkak.
 *
 * Berjalan lewat variabel lingkungan, bukan konstanta: konstanta tidak bisa
 * dicabut setelah didefinisikan, jadi satu kasus tes akan mengunci sisanya.
 */
final class ArtifactStorageDefaultTest extends TestCase
{
    private PdoConnection $db;

    /** @var array<string,string|false> */
    private array $saved = [];

    private string $diskRoot = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/sqlite/2026_07_30_000001_create_ezdoc_blobs.sql'
        ));
        $this->db = new PdoConnection($pdo);

        foreach ([
            'EZDOC_ARTIFACT_STORAGE_BACKEND',
            'EZDOC_ARTIFACT_STORAGE_PATH',
            'EZDOC_STORAGE_BACKEND',
            'EZDOC_STORAGE_PATH',
        ] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }

        $this->diskRoot = sys_get_temp_dir() . '/ezdoc-artifact-test-' . bin2hex(random_bytes(6));
        BlobStoreFactory::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if (is_string($value)) {
                putenv($name . '=' . $value);
            } else {
                putenv($name);
            }
        }

        BlobStoreFactory::reset();

        if ($this->diskRoot !== '' && is_dir($this->diskRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->diskRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->diskRoot);
        }
    }

    public function testArtifactsGoToDiskByDefault(): void
    {
        putenv('EZDOC_ARTIFACT_STORAGE_PATH=' . $this->diskRoot);

        self::assertInstanceOf(DiskBlobStore::class, BlobStoreFactory::forArtifacts($this->db));
    }

    /**
     * Bawaan umum tetap database — dokumen beku tidak boleh mengubah ke mana
     * gambar tanda tangan disimpan.
     */
    public function testTheGeneralDefaultIsUntouched(): void
    {
        self::assertInstanceOf(DatabaseBlobStore::class, BlobStoreFactory::make($this->db));
    }

    /** Jatuh ke pengaturan umum kalau yang khusus tidak disetel. */
    public function testItFallsBackToTheGeneralDiskPath(): void
    {
        putenv('EZDOC_STORAGE_PATH=' . $this->diskRoot);

        self::assertInstanceOf(DiskBlobStore::class, BlobStoreFactory::forArtifacts($this->db));
    }

    /**
     * Database tetap boleh — ada pemasangan tanpa berkas bersama antar-server,
     * atau yang ingin cadangannya satu transaksi dengan datanya. Yang tidak
     * boleh adalah itu terjadi tanpa ada yang memilihnya.
     */
    public function testTheDatabaseIsStillAllowedWhenAskedFor(): void
    {
        putenv('EZDOC_ARTIFACT_STORAGE_BACKEND=database');

        self::assertInstanceOf(DatabaseBlobStore::class, BlobStoreFactory::forArtifacts($this->db));
    }

    /**
     * Menolak, bukan diam-diam jatuh ke database. Jatuh diam-diam akan menaruh
     * dokumen di tempat yang tidak diniatkan operator dan tidak ia cadangkan,
     * dan kekeliruannya baru muncul berbulan-bulan kemudian.
     */
    public function testDiskWithNowhereToWriteIsRefused(): void
    {
        $this->expectException(EzdocException::class);
        BlobStoreFactory::forArtifacts($this->db);
    }

    /** Pesannya harus menyebutkan apa yang perlu disetel, bukan cuma bahwa ada yang salah. */
    public function testTheRefusalNamesTheSettingToChange(): void
    {
        try {
            BlobStoreFactory::forArtifacts($this->db);
            self::fail('seharusnya menolak');
        } catch (EzdocException $e) {
            self::assertStringContainsString('EZDOC_ARTIFACT_STORAGE_PATH', $e->getMessage());
            self::assertStringContainsString('EZDOC_ARTIFACT_STORAGE_BACKEND', $e->getMessage());
        }
    }
}
