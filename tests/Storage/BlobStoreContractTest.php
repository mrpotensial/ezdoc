<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Storage\Blob;
use Ezdoc\Storage\BlobStore;
use Ezdoc\Storage\DatabaseBlobStore;
use Ezdoc\Storage\DiskBlobStore;
use PHPUnit\Framework\TestCase;

/**
 * One contract, run against both backends.
 *
 * Written this way on purpose. The whole reason for the abstraction is that where
 * the bytes live becomes a config choice, and that promise only holds if both
 * implementations answer the same demands. A test suite per backend would drift,
 * and the drift would surface as "it worked before we switched".
 *
 * The shipped migrations are executed rather than hand-written here, so a column
 * added to one dialect and not the other shows up as a failure instead of
 * drifting quietly — the gap that left ezdoc_signatures without a SQLite
 * counterpart for five migrations.
 */
final class BlobStoreContractTest extends TestCase
{
    private \PDO $pdo;
    private PdoConnection $db;
    private string $diskRoot = '';

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

        $this->diskRoot = sys_get_temp_dir() . '/ezdoc-blob-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->diskRoot !== '' && is_dir($this->diskRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->diskRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->diskRoot);
        }
    }

    /** @return array<string, array{string}> */
    public static function backends(): array
    {
        return [
            'database' => ['database'],
            'disk'     => ['disk'],
        ];
    }

    private function store(string $backend): BlobStore
    {
        return $backend === 'disk'
            ? new DiskBlobStore($this->db, $this->diskRoot)
            : new DatabaseBlobStore($this->db);
    }

    // ─── put / get ───

    /** @dataProvider backends */
    public function testStoredBytesComeBackUnchanged(string $backend): void
    {
        $store = $this->store($backend);
        $bytes = random_bytes(2048);

        $blob = $store->put($bytes, 'image/png');

        self::assertSame($bytes, $store->get($blob->key()));
        self::assertSame(2048, $blob->byteSize());
        self::assertSame('image/png', $blob->contentType());
        self::assertSame($backend, $blob->backend());
    }

    /** @dataProvider backends */
    public function testTheKeyIsTheContentHash(string $backend): void
    {
        $bytes = 'tanda tangan';
        $blob = $this->store($backend)->put($bytes, 'image/png');

        self::assertSame(hash('sha256', $bytes), $blob->key());
    }

    /**
     * Content addressing means storing the same bytes twice is one object. Any
     * other behaviour would either duplicate storage or violate the unique key.
     *
     * @dataProvider backends
     */
    public function testStoringIdenticalBytesTwiceIsOneObject(string $backend): void
    {
        $store = $this->store($backend);
        $bytes = 'gambar yang sama';

        $a = $store->put($bytes, 'image/png');
        $b = $store->put($bytes, 'image/png');

        self::assertSame($a->key(), $b->key());
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM ezdoc_blobs')->fetchColumn(),
            'the same content must not produce a second row'
        );
        self::assertSame($bytes, $store->get($a->key()));
    }

    /** @dataProvider backends */
    public function testDifferentBytesGetDifferentKeys(string $backend): void
    {
        $store = $this->store($backend);

        self::assertNotSame(
            $store->put('a', 'image/png')->key(),
            $store->put('b', 'image/png')->key()
        );
    }

    /** @dataProvider backends */
    public function testAnUnknownKeyReadsAsAbsent(string $backend): void
    {
        $store = $this->store($backend);

        self::assertNull($store->get(str_repeat('0', 64)));
        self::assertNull($store->find(str_repeat('0', 64)));
        self::assertFalse($store->exists(str_repeat('0', 64)));
    }

    /**
     * Malformed keys must never reach a filesystem path or a query. This is the
     * only defence between document JSON and the storage root.
     *
     * @dataProvider backends
     */
    public function testMalformedKeysAreRejected(string $backend): void
    {
        $store = $this->store($backend);

        foreach (['', '../../etc/passwd', 'ZZZZ', str_repeat('a', 63), str_repeat('a', 65), 'A' . str_repeat('a', 63)] as $bad) {
            self::assertNull($store->get($bad), "get() accepted {$bad}");
            self::assertNull($store->find($bad), "find() accepted {$bad}");
            self::assertFalse($store->exists($bad), "exists() accepted {$bad}");
            self::assertFalse($store->delete($bad), "delete() accepted {$bad}");
        }
    }

    /** @dataProvider backends */
    public function testEmptyBytesAreRefused(string $backend): void
    {
        $this->expectException(EzdocException::class);
        $this->store($backend)->put('', 'image/png');
    }

    // ─── The property the whole design rests on ───

    /**
     * Content altered behind the store's back reads as *absent*, not as different
     * bytes. Without this the key being a hash proves nothing, and a swapped
     * signature image would be served as if it were the original.
     *
     * @dataProvider backends
     */
    public function testAlteredContentReadsAsAbsent(string $backend): void
    {
        $store = $this->store($backend);
        $blob = $store->put('tanda tangan asli', 'image/png');

        if ($backend === 'disk') {
            $path = $this->diskRoot . '/' . substr($blob->key(), 0, 2) . '/' . $blob->key();
            self::assertFileExists($path);
            file_put_contents($path, 'tanda tangan palsu');
        } else {
            $stmt = $this->pdo->prepare('UPDATE ezdoc_blobs SET content = ? WHERE "key" = ?');
            $stmt->execute(['tanda tangan palsu', $blob->key()]);
        }

        self::assertNull($store->get($blob->key()),
            'a checksum mismatch must read as absence — never as content');

        // The row is still there: this is a data-integrity problem to investigate,
        // not an object that quietly disappeared.
        self::assertTrue($store->exists($blob->key()));
    }

    // ─── delete ───

    /** @dataProvider backends */
    public function testDeleteRemovesTheObject(string $backend): void
    {
        $store = $this->store($backend);
        $blob = $store->put('sekali pakai', 'image/png');

        self::assertTrue($store->delete($blob->key()));
        self::assertNull($store->get($blob->key()));
        self::assertFalse($store->exists($blob->key()));
        self::assertFalse($store->delete($blob->key()), 'deleting twice reports nothing removed');
    }

    /**
     * Deletion is hard, not soft. A soft-deleted blob would keep the bytes while
     * claiming to be gone — the opposite of the point.
     *
     * @dataProvider backends
     */
    public function testDeleteLeavesNoRowBehind(string $backend): void
    {
        $store = $this->store($backend);
        $store->delete($store->put('x', 'image/png')->key());

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ezdoc_blobs')->fetchColumn());
    }

    public function testDeleteRemovesTheFileFromDisk(): void
    {
        $store = new DiskBlobStore($this->db, $this->diskRoot);
        $blob = $store->put('di disk', 'image/png');
        $path = $this->diskRoot . '/' . substr($blob->key(), 0, 2) . '/' . $blob->key();

        self::assertFileExists($path);
        $store->delete($blob->key());
        self::assertFileDoesNotExist($path);
    }

    // ─── Backend-specific guarantees ───

    /**
     * The database backend holds the bytes; the disk backend must not. `content`
     * is how the two are told apart, and getting it wrong would store everything
     * twice.
     */
    public function testDatabaseBackendKeepsBytesInTheColumn(): void
    {
        $blob = (new DatabaseBlobStore($this->db))->put('bytes', 'image/png');

        $row = $this->pdo->query('SELECT backend, content FROM ezdoc_blobs')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('database', $row['backend']);
        self::assertSame('bytes', $row['content']);
        self::assertSame($blob->key(), hash('sha256', 'bytes'));
    }

    public function testDiskBackendLeavesTheColumnNull(): void
    {
        (new DiskBlobStore($this->db, $this->diskRoot))->put('bytes', 'image/png');

        $row = $this->pdo->query('SELECT backend, content FROM ezdoc_blobs')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('disk', $row['backend']);
        self::assertNull($row['content']);
    }

    /**
     * Files fan out two characters deep. A flat directory of tens of thousands of
     * entries is slow on most filesystems and unusable on a support call.
     */
    public function testDiskBackendFansOutByKeyPrefix(): void
    {
        $store = new DiskBlobStore($this->db, $this->diskRoot);
        $blob = $store->put('fanned out', 'image/png');

        self::assertFileExists($this->diskRoot . '/' . substr($blob->key(), 0, 2) . '/' . $blob->key());
        self::assertFileDoesNotExist($this->diskRoot . '/' . $blob->key(),
            'a flat layout would put the file here');
    }

    /**
     * A row purged from the database means the object is gone, even if a file
     * survived — after a restore that lands the two at different points in time,
     * the database has to be the one that decides.
     */
    public function testDiskBackendTreatsAStrayFileAsAbsent(): void
    {
        $store = new DiskBlobStore($this->db, $this->diskRoot);
        $blob = $store->put('yatim', 'image/png');
        $path = $this->diskRoot . '/' . substr($blob->key(), 0, 2) . '/' . $blob->key();

        $this->pdo->exec('DELETE FROM ezdoc_blobs');

        self::assertFileExists($path, 'the file is deliberately left behind');
        self::assertNull($store->get($blob->key()));
    }

    /** A row without its file is reported as absent, not as an empty object. */
    public function testDiskBackendTreatsAMissingFileAsAbsent(): void
    {
        $store = new DiskBlobStore($this->db, $this->diskRoot);
        $blob = $store->put('hilang', 'image/png');
        @unlink($this->diskRoot . '/' . substr($blob->key(), 0, 2) . '/' . $blob->key());

        self::assertNull($store->get($blob->key()));
        self::assertTrue($store->exists($blob->key()), 'the row survives so the mismatch can be found');
    }

    public function testDiskStoreRefusesAnEmptyRoot(): void
    {
        $this->expectException(EzdocException::class);
        new DiskBlobStore($this->db, '   ');
    }

    /**
     * A partially written file must never appear under its final name: the name is
     * the content hash, so it would fail its own checksum forever.
     */
    public function testDiskBackendLeavesNoPartialFiles(): void
    {
        $store = new DiskBlobStore($this->db, $this->diskRoot);
        $store->put(random_bytes(4096), 'image/png');

        $stray = glob($this->diskRoot . '/*/*.part') ?: [];
        self::assertSame([], $stray);
    }

    // ─── Bytes can move between backends ───

    /**
     * The promise the abstraction makes: switching backends is a config change and
     * a copy. Same key on both sides, because the key is the content.
     */
    public function testTheSameBytesGetTheSameKeyInEitherBackend(): void
    {
        $bytes = random_bytes(1024);

        $inDb = (new DatabaseBlobStore($this->db))->put($bytes, 'image/png');

        $this->pdo->exec('DELETE FROM ezdoc_blobs');
        $onDisk = (new DiskBlobStore($this->db, $this->diskRoot))->put($bytes, 'image/png');

        self::assertSame($inDb->key(), $onDisk->key());
        self::assertSame($inDb->reference(), $onDisk->reference());
    }
}
