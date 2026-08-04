<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Migrations;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Every table that ships a SQLite dialect file must offer the same columns as
 * its MySQL original.
 *
 * ## The failure this exists for
 *
 * `ezdoc_audit_log` shipped a SQLite file describing a completely different
 * table — entity_type/created_at against the MySQL target_type/occurred_at. The
 * INSERT raised "no such column" on every SQLite host, and `Ezdoc\Audit\Logger`
 * swallows errors deliberately, because auditing must never take down the
 * operation it is recording. So the audit log recorded nothing, quietly, for as
 * long as the file existed.
 *
 * A *missing* dialect file fails on the first query and gets noticed. A dialect
 * file that lies keeps working until somebody needs the data.
 *
 * ## How it checks
 *
 * The SQLite side is executed rather than parsed: the table is created in
 * memory and `PRAGMA table_info` is asked what exists. Only the MySQL side is
 * read as text, and only for column names.
 *
 * Extra columns on the SQLite side are allowed — the direction that matters is
 * that everything the shared code writes has somewhere to go.
 */
final class DialectParityTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../migrations';

    /**
     * Every MySQL migration that has a SQLite twin.
     *
     * @return array<string, array{string, string}>
     */
    public static function dialectPairs(): array
    {
        $pairs = [];

        foreach ((array) glob(self::MIGRATIONS . '/*.php') as $mysql) {
            $name = basename((string) $mysql, '.php');
            $sqlite = self::MIGRATIONS . '/sqlite/' . $name . '.sql';

            if (is_file($sqlite)) {
                $pairs[$name] = [(string) $mysql, $sqlite];
            }
        }

        return $pairs;
    }

    /**
     * Column names declared in a MySQL CREATE TABLE.
     *
     * Index and constraint lines are skipped; so is anything that does not look
     * like `name TYPE`, which is what a column line looks like in every one of
     * these files.
     *
     * @return list<string>
     */
    private static function mysqlColumns(string $ddl): array
    {
        $types = 'BIGINT|INT|INTEGER|SMALLINT|TINYINT|MEDIUMINT|VARCHAR|CHAR|TEXT'
            . '|MEDIUMTEXT|LONGTEXT|TINYTEXT|DATETIME|TIMESTAMP|DATE|TIME|YEAR'
            . '|JSON|ENUM|SET|BLOB|LONGBLOB|MEDIUMBLOB|TINYBLOB|DECIMAL|NUMERIC'
            . '|FLOAT|DOUBLE|BOOLEAN|BOOL|BIT|BINARY|VARBINARY';

        $columns = [];

        foreach (preg_split('/\r?\n/', $ddl) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || strncmp($line, '--', 2) === 0) {
                continue;
            }
            if (preg_match('/^(PRIMARY|UNIQUE|INDEX|KEY|CONSTRAINT|FOREIGN|FULLTEXT|SPATIAL|CHECK)\b/i', $line) === 1) {
                continue;
            }
            if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(' . $types . ')\b/i', $line, $m) === 1) {
                $columns[] = strtolower($m[1]);
            }
        }

        return $columns;
    }

    /**
     * Column names SQLite actually ends up with, by running the file.
     *
     * @return list<string>
     */
    private static function sqliteColumns(string $sqlFile, string $table): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents($sqlFile));

        $rows = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($rows === false) {
            return [];
        }

        $columns = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = strtolower((string) $row['name']);
        }

        return $columns;
    }

    /** The table a migration is named after: `..._create_<table>`. */
    private static function tableFor(string $migrationName): ?string
    {
        return preg_match('/_create_(ezdoc_[a-z0-9_]+)$/', $migrationName, $m) === 1 ? $m[1] : null;
    }

    /**
     * @dataProvider dialectPairs
     */
    public function testTheSqliteTwinOffersEveryMysqlColumn(string $mysqlFile, string $sqliteFile): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $name = basename($mysqlFile, '.php');
        $table = self::tableFor($name);

        if ($table === null) {
            self::markTestSkipped("{$name} does not name a table.");
        }

        $expected = self::mysqlColumns((string) file_get_contents($mysqlFile));

        // If the regex found nothing, the shape of these files changed and this
        // test would pass by vacuum from here on.
        self::assertNotEmpty($expected, "no columns parsed out of {$name} — the check is broken, not the file");

        $actual = self::sqliteColumns($sqliteFile, $table);
        $missing = array_values(array_diff($expected, $actual));

        self::assertSame(
            [],
            $missing,
            "migrations/sqlite/{$name}.sql is missing columns the MySQL version declares: "
            . implode(', ', $missing)
            . ".\nAn INSERT naming them raises \"no such column\", and the audit logger swallows"
            . ' that on purpose — so the symptom is silence, not an error.'
        );
    }
}
