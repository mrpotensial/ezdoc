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
 * Covers backend selection.
 *
 * Constants cannot be unset once defined, so the cases that need a *different*
 * configuration run in subprocesses. Faking the resolution with an injectable
 * setting would test the fake; these run the real path, including reading the
 * real constant.
 */
final class BlobStoreFactoryTest extends TestCase
{
    private PdoConnection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }
        BlobStoreFactory::reset();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db = new PdoConnection($pdo);
    }

    protected function tearDown(): void
    {
        BlobStoreFactory::reset();
    }

    /**
     * The default is a decision, not an accident of ordering: at ~3.5 KB the
     * database backend is the one that measures better.
     */
    public function testTheDefaultBackendIsTheDatabase(): void
    {
        self::assertSame('database', BlobStoreFactory::configuredBackend());
        self::assertInstanceOf(DatabaseBlobStore::class, BlobStoreFactory::make($this->db));
    }

    public function testAnExplicitBackendWins(): void
    {
        self::assertInstanceOf(
            DiskBlobStore::class,
            BlobStoreFactory::make($this->db, 'disk', sys_get_temp_dir() . '/ezdoc-factory-test')
        );
        self::assertInstanceOf(DatabaseBlobStore::class, BlobStoreFactory::make($this->db, 'database'));
    }

    /**
     * Disk without a path must fail loudly. Falling back to the database would
     * put images somewhere the operator did not intend and did not back up, and
     * the mistake would only surface much later as a size surprise.
     */
    public function testDiskWithoutAPathIsRefused(): void
    {
        $this->expectException(EzdocException::class);
        $this->expectExceptionMessageMatches('/EZDOC_STORAGE_PATH/');
        BlobStoreFactory::make($this->db, 'disk');
    }

    /** The error has to say where the directory should go, not just that it is missing. */
    public function testTheRefusalExplainsWhereTheDirectoryBelongs(): void
    {
        try {
            BlobStoreFactory::make($this->db, 'disk');
            self::fail('expected an EzdocException');
        } catch (EzdocException $e) {
            self::assertStringContainsString('OUTSIDE the document root', $e->getMessage());
        }
    }

    public function testAnUnknownBackendIsRefused(): void
    {
        $this->expectException(EzdocException::class);
        $this->expectExceptionMessageMatches('/Unknown storage backend "s3"/');
        BlobStoreFactory::make($this->db, 's3');
    }

    /** One request builds one store — that is what the cache is for. */
    public function testTheStoreIsReusedWithinAProcess(): void
    {
        self::assertSame(
            BlobStoreFactory::make($this->db, 'database'),
            BlobStoreFactory::make($this->db, 'database')
        );
    }

    /** Two disk roots are two stores; caching them together would cross the wires. */
    public function testDifferentDiskRootsGetDifferentStores(): void
    {
        $a = BlobStoreFactory::make($this->db, 'disk', sys_get_temp_dir() . '/ezdoc-a');
        $b = BlobStoreFactory::make($this->db, 'disk', sys_get_temp_dir() . '/ezdoc-b');

        self::assertNotSame($a, $b);
    }

    // ─── Configuration read from the real constants ───

    /**
     * @dataProvider constantCases
     */
    public function testConfigurationIsReadFromConstants(string $define, string $expectation): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<PHP
<?php
require '{$root}/autoload.php';
{$define}
echo BlobStoreFactory::configuredBackend() . '|' . (BlobStoreFactory::configuredDiskRoot() ?? 'NULL');
PHP;

        $file = tempnam(sys_get_temp_dir(), 'ezdoc-factory-') . '.php';
        file_put_contents($file, str_replace(
            'BlobStoreFactory::',
            '\\Ezdoc\\Storage\\BlobStoreFactory::',
            $script
        ));

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);

        self::assertSame($expectation, trim((string) $out));
    }

    /** @return array<string, array{string, string}> */
    public static function constantCases(): array
    {
        return [
            'nothing defined'   => ['', 'database|NULL'],
            'backend disk'      => ["define('EZDOC_STORAGE_BACKEND', 'disk');", 'disk|NULL'],
            'backend + path'    => [
                "define('EZDOC_STORAGE_BACKEND', 'disk'); define('EZDOC_STORAGE_PATH', '/srv/sig');",
                'disk|/srv/sig',
            ],
            'case is forgiving' => ["define('EZDOC_STORAGE_BACKEND', 'DISK');", 'disk|NULL'],
            'empty falls back'  => ["define('EZDOC_STORAGE_BACKEND', '');", 'database|NULL'],
            'path is trimmed'   => ["define('EZDOC_STORAGE_PATH', '  /srv/sig  ');", 'database|/srv/sig'],
            'blank path is null' => ["define('EZDOC_STORAGE_PATH', '   ');", 'database|NULL'],
        ];
    }
}
