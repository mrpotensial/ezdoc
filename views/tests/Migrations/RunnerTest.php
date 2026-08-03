<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Migrations;

use Ezdoc\Migrations\Runner;
use Ezdoc\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Covers the migration runner's contract: apply each file once, never record a
 * failure as success, and notice when the registry claims work that the schema
 * does not reflect.
 *
 * Runs against a throwaway MySQL database, never the application's — `reset()`
 * and the fixtures here drop tables. See {@see TestDatabase}.
 *
 * `Runner` takes `mysqli` directly rather than the `Connection` abstraction, so
 * SQLite is not an option here the way it is for the OTP repository tests.
 */
final class RunnerTest extends TestCase
{
    private string $dir;

    /** @var \mysqli */
    private $db;

    protected function setUp(): void
    {
        $db = TestDatabase::connect();
        if ($db === null) {
            self::markTestSkipped('No MySQL server reachable for tests.');
        }

        TestDatabase::reset();
        $this->db = $db;

        $this->dir = sys_get_temp_dir() . '/ezdoc_mig_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    /**
     * Write a migration file that creates one table.
     */
    private function writeMigration(string $name, string $table): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', <<<PHP
<?php
return [
    'name' => '{$name}',
    'up' => function (\$conn): void {
        \$ok = \$conn->query('CREATE TABLE IF NOT EXISTS `{$table}` (id INT PRIMARY KEY)');
        if (\$ok === false) throw new \RuntimeException(\$conn->error);
    },
];
PHP);
    }

    private function writeRawFile(string $filename, string $contents): void
    {
        file_put_contents($this->dir . '/' . $filename, $contents);
    }

    private function tableExists(string $table): bool
    {
        $rs = $this->db->query("SHOW TABLES LIKE '{$table}'");
        return $rs !== false && $rs->num_rows > 0;
    }

    private function runner(): Runner
    {
        return new Runner($this->db, $this->dir);
    }

    // ─── Discovery ───

    public function testScanFindsPhpFilesInChronologicalOrder(): void
    {
        $this->writeMigration('2026_01_02_000001_second', 't_second');
        $this->writeMigration('2026_01_01_000001_first', 't_first');

        $files = array_map('basename', $this->runner()->scanFiles());

        self::assertSame(
            ['2026_01_01_000001_first.php', '2026_01_02_000001_second.php'],
            $files,
            'timestamp-prefixed names must sort chronologically'
        );
    }

    /**
     * The runner globs `*.php` only. A `.sql` file dropped in this directory is
     * silently ignored — which is exactly how a duplicate `010_*.sql` migration
     * came to look applied while never running. Pinned so the limitation stays
     * visible rather than being rediscovered.
     */
    public function testScanIgnoresNonPhpFiles(): void
    {
        $this->writeMigration('2026_01_01_000001_real', 't_real');
        $this->writeRawFile('010_create_something.sql', 'CREATE TABLE nope (id INT);');

        $files = array_map('basename', $this->runner()->scanFiles());

        self::assertSame(['2026_01_01_000001_real.php'], $files);
    }

    public function testScanReturnsEmptyForMissingDirectory(): void
    {
        $runner = new Runner($this->db, $this->dir . '/does_not_exist');

        self::assertSame([], $runner->scanFiles());
    }

    // ─── File validation ───

    public function testMalformedMigrationFilesAreRejected(): void
    {
        $runner = $this->runner();

        $this->writeRawFile('a.php', '<?php return "not an array";');
        $this->writeRawFile('b.php', '<?php return ["up" => function () {}];');          // no name
        $this->writeRawFile('c.php', '<?php return ["name" => "c"];');                    // no up
        $this->writeRawFile('d.php', '<?php return ["name" => "d", "up" => "nope"];');    // up not callable

        foreach (['a', 'b', 'c', 'd'] as $f) {
            self::assertNull(
                $runner->loadFile($this->dir . '/' . $f . '.php'),
                "malformed migration '{$f}' must not load"
            );
        }
    }

    public function testLoadFileAcceptsAWellFormedMigration(): void
    {
        $this->writeMigration('2026_01_01_000001_ok', 't_ok');

        $spec = $this->runner()->loadFile($this->dir . '/2026_01_01_000001_ok.php');

        self::assertIsArray($spec);
        self::assertSame('2026_01_01_000001_ok', $spec['name']);
        self::assertIsCallable($spec['up']);
    }

    // ─── Applying ───

    public function testMigrateAppliesPendingFiles(): void
    {
        $this->writeMigration('2026_01_01_000001_one', 't_one');
        $this->writeMigration('2026_01_02_000001_two', 't_two');

        $result = $this->runner()->migrate(false);

        self::assertSame(
            ['2026_01_01_000001_one', '2026_01_02_000001_two'],
            $result['applied']
        );
        self::assertTrue($this->tableExists('t_one'));
        self::assertTrue($this->tableExists('t_two'));
    }

    /**
     * Running twice must not run anything twice.
     *
     * Self-heal is off here so the registry is the only thing under test.
     * Leaving it on would wipe the registry — see
     * {@see testSelfHealWipesRegistryWhenMigrationsDoNotCreateCoreTables()}.
     */
    public function testMigrateIsIdempotent(): void
    {
        $this->writeMigration('2026_01_01_000001_one', 't_one');

        $this->runner()->migrate(false);
        $second = $this->runner()->migrate(false);

        self::assertSame([], $second['applied']);
        self::assertSame(['2026_01_01_000001_one'], $second['skipped']);
    }

    /**
     * Sharp edge worth knowing about: self-heal decides the registry is stale
     * by looking for three hardcoded tables — ezdoc_templates, ezdoc_documents,
     * ezdoc_signatures. A migration set that does not create them looks
     * permanently orphaned, so every run truncates the registry and replays
     * everything.
     *
     * Harmless for ezdoc itself, whose own migrations create all three. It
     * bites anyone pointing this Runner at their own migrations, and it is why
     * the tests above pass `false`.
     */
    public function testSelfHealWipesRegistryWhenMigrationsDoNotCreateCoreTables(): void
    {
        $this->writeMigration('2026_01_01_000001_unrelated', 't_unrelated');

        $first = $this->runner()->migrate();
        $second = $this->runner()->migrate();

        self::assertSame(['2026_01_01_000001_unrelated'], $first['applied']);
        self::assertTrue($second['healed']);
        self::assertSame(
            ['2026_01_01_000001_unrelated'],
            $second['applied'],
            'registry was wiped, so the migration ran a second time'
        );
    }

    /**
     * A migration that throws must be reported as failed and left out of the
     * registry — recording it would mean the schema change silently never
     * happens and never gets retried.
     */
    public function testFailedMigrationIsNotRecordedAsApplied(): void
    {
        $this->writeRawFile('2026_01_01_000001_boom.php', <<<'PHP'
<?php
return [
    'name' => '2026_01_01_000001_boom',
    'up' => function ($conn): void { throw new \RuntimeException('deliberate failure'); },
];
PHP);

        $result = $this->runner()->migrate(false);

        self::assertSame([], $result['applied']);
        self::assertArrayHasKey('2026_01_01_000001_boom', $result['failed']);

        // Still pending on the next run.
        $again = $this->runner()->migrate(false);
        self::assertArrayHasKey('2026_01_01_000001_boom', $again['failed']);
        self::assertNotContains('2026_01_01_000001_boom', $again['skipped']);
    }

    /** A failure must not stop the migrations that follow it. */
    public function testMigrationContinuesPastAFailure(): void
    {
        $this->writeRawFile('2026_01_01_000001_boom.php', <<<'PHP'
<?php
return [
    'name' => '2026_01_01_000001_boom',
    'up' => function ($conn): void { throw new \RuntimeException('deliberate failure'); },
];
PHP);
        $this->writeMigration('2026_01_02_000001_after', 't_after');

        $result = $this->runner()->migrate(false);

        self::assertArrayHasKey('2026_01_01_000001_boom', $result['failed']);
        self::assertSame(['2026_01_02_000001_after'], $result['applied']);
        self::assertTrue($this->tableExists('t_after'));
    }

    // ─── Self-heal ───

    /**
     * If the core tables are dropped but the registry still lists them as
     * applied, nothing would ever recreate them. The runner clears the registry
     * so the next pass rebuilds from scratch.
     */
    public function testOrphanRegistryIsClearedWhenCoreTablesVanish(): void
    {
        $this->writeMigration('2026_01_01_000001_core', 'ezdoc_templates');
        $this->runner()->migrate();

        // Simulate someone dropping the schema without touching the registry.
        $this->db->query('DROP TABLE ezdoc_templates');

        $result = $this->runner()->migrate();

        self::assertTrue($result['healed'], 'orphan registry should be detected');
        self::assertSame(['2026_01_01_000001_core'], $result['applied']);
        self::assertTrue($this->tableExists('ezdoc_templates'));
    }

    public function testHealthyRegistryIsLeftAlone(): void
    {
        $this->writeMigration('2026_01_01_000001_core', 'ezdoc_templates');
        $this->writeMigration('2026_01_01_000002_docs', 'ezdoc_documents');
        $this->writeMigration('2026_01_01_000003_sigs', 'ezdoc_signatures');
        $this->runner()->migrate();

        $result = $this->runner()->migrate();

        self::assertFalse($result['healed']);
        self::assertSame([], $result['applied']);
    }

    public function testSelfHealCanBeDisabled(): void
    {
        $this->writeMigration('2026_01_01_000001_core', 'ezdoc_templates');
        $this->runner()->migrate();
        $this->db->query('DROP TABLE ezdoc_templates');

        $result = $this->runner()->migrate(false);

        self::assertFalse($result['healed']);
        self::assertSame([], $result['applied'], 'without self-heal the gap stays');
    }

    // ─── Registry ───

    public function testResetForcesEverythingToRunAgain(): void
    {
        $this->writeMigration('2026_01_01_000001_one', 't_one');
        $this->runner()->migrate(false);

        $this->runner()->reset();
        $result = $this->runner()->migrate(false);

        self::assertSame(['2026_01_01_000001_one'], $result['applied']);
    }

    public function testGetAppliedReflectsWhatRan(): void
    {
        $this->writeMigration('2026_01_01_000001_one', 't_one');
        $runner = $this->runner();

        // getApplied() queries the registry directly and does not create it.
        // migrate() always calls ensureRegistryTable() first, so this only
        // matters when reading the registry on its own.
        $runner->ensureRegistryTable();
        self::assertSame([], $runner->getApplied());

        $runner->migrate(false);

        self::assertArrayHasKey('2026_01_01_000001_one', $runner->getApplied());
    }

    /** Marking by hand lets an operator adopt a schema that already exists. */
    public function testMarkAppliedSkipsWithoutRunning(): void
    {
        $this->writeMigration('2026_01_01_000001_one', 't_one');
        $runner = $this->runner();

        $runner->ensureRegistryTable();
        $runner->markApplied('2026_01_01_000001_one');

        $result = $runner->migrate(false);

        self::assertSame(['2026_01_01_000001_one'], $result['skipped']);
        self::assertFalse($this->tableExists('t_one'), 'the migration body must not have run');
    }
}
