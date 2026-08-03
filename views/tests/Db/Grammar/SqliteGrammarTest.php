<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db\Grammar;

use Ezdoc\Db\Grammar\SqliteGrammar;
use Ezdoc\Db\Schema\Blueprint;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * SQLite is the one target that can be checked properly here: it needs no
 * server, so the emitted DDL is executed against a real engine rather than
 * matched as a string.
 *
 * That distinction matters. String assertions prove the grammar still produces
 * what it produced yesterday; executing proves SQLite accepts it, and querying
 * afterwards proves the constraints do what they were asked to. A CHECK
 * constraint that parses but never rejects anything passes every string test
 * ever written.
 */
final class SqliteGrammarTest extends TestCase
{
    private SqliteGrammar $grammar;
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->grammar = new SqliteGrammar();
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON'); // off by default in SQLite
    }

    /** Compile a Blueprint and run every statement it produced. */
    private function create(Blueprint $bp): array
    {
        $sqls = $this->grammar->compileCreateTable($bp);
        foreach ($sqls as $sql) {
            $this->db->exec($sql);
        }
        return $sqls;
    }

    /** @return array<string, array<string,mixed>> keyed by column name */
    private function columns(string $table): array
    {
        $out = [];
        $rows = $this->db->query('PRAGMA table_info(' . $this->grammar->wrapIdentifier($table) . ')');
        foreach ($rows as $r) {
            $out[$r['name']] = $r;
        }
        return $out;
    }

    private function documentsTable(): Blueprint
    {
        return new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('title', 120);
            $t->text('body')->nullable();
            $t->json('metadata')->nullable();
            $t->enum('status', ['draft', 'active'])->default('draft');
            $t->boolean('locked')->default(false);
            $t->decimal('amount', 12, 2);
            $t->index(['status', 'title'], 'idx_status_title');
        });
    }

    // ─── The DDL runs ───

    public function testTheEmittedDdlIsAcceptedBySqlite(): void
    {
        $sqls = $this->create($this->documentsTable());

        self::assertNotEmpty($sqls);
        self::assertNotFalse(
            $this->db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='ezdoc_documents'")->fetch()
        );
    }

    /**
     * SQLite cannot declare a non-unique index inside CREATE TABLE, so the
     * grammar has to split. A grammar that folded them back in would emit SQL
     * SQLite rejects outright.
     */
    public function testNonUniqueIndexesAreEmittedAsSeparateStatements(): void
    {
        $sqls = $this->create($this->documentsTable());

        self::assertCount(2, $sqls, 'one CREATE TABLE plus one CREATE INDEX');
        self::assertStringStartsWith('CREATE TABLE', $sqls[0]);
        self::assertStringStartsWith('CREATE INDEX', $sqls[1]);

        $idx = $this->db
            ->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_status_title'")
            ->fetch();
        self::assertNotFalse($idx, 'the index should exist after the statements ran');
    }

    /** UNIQUE is inline, so it does not need its own statement. */
    public function testUniqueConstraintsStayInsideCreateTable(): void
    {
        $sqls = $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->unique();
        }));

        self::assertCount(1, $sqls);
        self::assertStringContainsString('UNIQUE ("email")', $sqls[0]);
    }

    // ─── Autoincrement ───

    /**
     * SQLite only makes a column an alias for the rowid when it is declared
     * exactly `INTEGER PRIMARY KEY`. `BIGINT PRIMARY KEY` — which is what
     * Blueprint::id() asks for — creates an ordinary column that does not
     * autofill, so the grammar narrows the type on purpose.
     */
    public function testTheIdColumnIsNarrowedToIntegerSoItBecomesTheRowid(): void
    {
        $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        }));

        $id = $this->columns('t')['id'];
        self::assertSame('INTEGER', $id['type'], 'BIGINT would not alias the rowid');
        self::assertSame(1, (int) $id['pk']);
    }

    public function testInsertingWithoutAnIdAssignsOne(): void
    {
        $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        }));

        $this->db->exec("INSERT INTO \"t\" (\"name\") VALUES ('satu')");
        $this->db->exec("INSERT INTO \"t\" (\"name\") VALUES ('dua')");

        self::assertSame(
            ['1', '2'],
            array_map('strval', $this->db->query('SELECT "id" FROM "t" ORDER BY "id"')->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    /**
     * AUTOINCREMENT is not the same as a bare rowid alias: it forbids reusing
     * an id after a delete. SQLite records that promise in `sqlite_sequence`,
     * which is created only when the keyword is present — so its existence is
     * proof the keyword survived compilation.
     */
    public function testAutoincrementPreventsIdReuse(): void
    {
        $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        }));

        self::assertNotFalse(
            $this->db->query("SELECT 1 FROM sqlite_master WHERE name='sqlite_sequence'")->fetch(),
            'AUTOINCREMENT missing — ids would be reused after deletion'
        );

        $this->db->exec("INSERT INTO \"t\" (\"name\") VALUES ('satu')");
        $this->db->exec('DELETE FROM "t"');
        $this->db->exec("INSERT INTO \"t\" (\"name\") VALUES ('dua')");

        self::assertSame(
            '2',
            (string) $this->db->query('SELECT "id" FROM "t"')->fetchColumn(),
            'the deleted id must not come back'
        );
    }

    // ─── Constraints actually bite ───

    public function testNotNullIsEnforced(): void
    {
        $this->create($this->documentsTable());

        $this->expectException(PDOException::class);
        $this->db->exec('INSERT INTO "ezdoc_documents" ("public_id", "amount") VALUES (\'u-1\', 1)');
    }

    public function testDefaultsAreApplied(): void
    {
        $this->create($this->documentsTable());
        $this->db->exec(
            'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount") VALUES (\'u-1\', \'Surat\', 10)'
        );

        $row = $this->db->query('SELECT "status", "locked" FROM "ezdoc_documents"')->fetch(PDO::FETCH_ASSOC);

        self::assertSame('draft', $row['status']);
        self::assertSame('0', (string) $row['locked'], 'boolean false is stored as 0');
    }

    public function testUniqueIsEnforced(): void
    {
        $this->create($this->documentsTable());
        $insert = 'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount") VALUES (\'sama\', \'A\', 1)';
        $this->db->exec($insert);

        $this->expectException(PDOException::class);
        $this->db->exec($insert);
    }

    /**
     * SQLite has no ENUM. The grammar substitutes TEXT plus a CHECK, and this
     * asserts the substitution is equivalent where it counts: a value outside
     * the list is refused.
     */
    public function testAnEnumValueOutsideTheListIsRejected(): void
    {
        $this->create($this->documentsTable());

        $this->expectException(PDOException::class);
        $this->db->exec(
            'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount", "status")'
            . " VALUES ('u-1', 'A', 1, 'dibatalkan')"
        );
    }

    public function testListedEnumValuesAreAccepted(): void
    {
        $this->create($this->documentsTable());

        foreach (['draft', 'active'] as $i => $status) {
            $this->db->exec(
                'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount", "status")'
                . " VALUES ('u-{$i}', 'A', 1, '{$status}')"
            );
        }

        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM "ezdoc_documents"')->fetchColumn());
    }

    /** The json_valid() CHECK is what stands in for a JSON column type. */
    public function testMalformedJsonIsRejected(): void
    {
        $this->create($this->documentsTable());

        $this->expectException(PDOException::class);
        $this->db->exec(
            'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount", "metadata")'
            . " VALUES ('u-1', 'A', 1, 'bukan json')"
        );
    }

    public function testWellFormedJsonIsAccepted(): void
    {
        $this->create($this->documentsTable());
        $this->db->exec(
            'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount", "metadata")'
            . ' VALUES (\'u-1\', \'A\', 1, \'{"revisi": 2}\')'
        );

        self::assertSame(
            '2',
            (string) $this->db->query('SELECT json_extract("metadata", \'$.revisi\') FROM "ezdoc_documents"')->fetchColumn()
        );
    }

    /**
     * A nullable JSON column must still accept NULL. `json_valid(NULL)` returns
     * NULL, and a CHECK evaluating to NULL passes in SQLite — but relying on
     * that would be accidental, so the grammar spells out the null branch.
     */
    public function testNullIsAcceptedInANullableJsonColumn(): void
    {
        $this->create($this->documentsTable());
        $this->db->exec(
            'INSERT INTO "ezdoc_documents" ("public_id", "title", "amount", "metadata")'
            . " VALUES ('u-1', 'A', 1, NULL)"
        );

        self::assertNull($this->db->query('SELECT "metadata" FROM "ezdoc_documents"')->fetchColumn());
    }

    // ─── Foreign keys ───

    private function createRelatedTables(): void
    {
        $this->create(new Blueprint('ezdoc_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        }));
        $this->create(new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->references('id')->on('ezdoc_templates')->cascadeOnDelete();
        }));
    }

    public function testAForeignKeyRejectsAnOrphanRow(): void
    {
        $this->createRelatedTables();

        $this->expectException(PDOException::class);
        $this->db->exec('INSERT INTO "ezdoc_documents" ("template_id") VALUES (999)');
    }

    public function testOnDeleteCascadeActuallyCascades(): void
    {
        $this->createRelatedTables();
        $this->db->exec('INSERT INTO "ezdoc_templates" ("name") VALUES (\'Surat\')');
        $this->db->exec('INSERT INTO "ezdoc_documents" ("template_id") VALUES (1)');

        $this->db->exec('DELETE FROM "ezdoc_templates" WHERE "id" = 1');

        self::assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM "ezdoc_documents"')->fetchColumn(),
            'the child row should have gone with its parent'
        );
    }

    // ─── Composite primary key ───

    public function testACompositePrimaryKeyIsDeclaredAndEnforced(): void
    {
        $this->create(new Blueprint('ezdoc_document_tags', function (Blueprint $t): void {
            $t->bigint('document_id');
            $t->string('tag', 40);
            $t->primary(['document_id', 'tag']);
        }));

        $this->db->exec('INSERT INTO "ezdoc_document_tags" VALUES (1, \'urgent\')');

        $this->expectException(PDOException::class);
        $this->db->exec('INSERT INTO "ezdoc_document_tags" VALUES (1, \'urgent\')');
    }

    /**
     * Known limitation, pinned so it is a decision rather than a surprise.
     *
     * SQLite requires the rowid-alias primary key to be declared inline on the
     * column, which leaves no room for a second PRIMARY KEY clause. When a
     * table has both `id()` and a composite `primary()`, the grammar keeps the
     * inline one and the composite is not emitted — so the uniqueness the
     * caller asked for is not enforced.
     *
     * The portable way to express that intent is `unique()` on the pair, which
     * every dialect emits.
     */
    public function testACompositePrimaryKeyIsDroppedWhenAnAutoIncrementIdExists(): void
    {
        $sqls = $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->bigint('document_id');
            $t->string('tag', 40);
            $t->primary(['document_id', 'tag']);
        }));

        self::assertStringNotContainsString(
            'PRIMARY KEY ("document_id", "tag")',
            $sqls[0],
            'if this starts appearing, SQLite gained multiple-PK support and the note above is stale'
        );

        // The consequence, stated plainly: duplicates get in.
        $this->db->exec('INSERT INTO "t" ("document_id", "tag") VALUES (1, \'urgent\')');
        $this->db->exec('INSERT INTO "t" ("document_id", "tag") VALUES (1, \'urgent\')');

        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM "t"')->fetchColumn());
    }

    // ─── Identifiers ───

    /**
     * A column name containing the delimiter has to survive quoting. If it did
     * not, the CREATE would either fail or — worse — succeed as something else.
     */
    public function testIdentifiersContainingADoubleQuoteRoundTrip(): void
    {
        $weird = 'kolom"aneh';
        $this->create(new Blueprint('t', function (Blueprint $t) use ($weird): void {
            $t->id();
            $t->string($weird, 20);
        }));

        self::assertArrayHasKey($weird, $this->columns('t'));
    }

    /** SQL keywords are legal table names once quoted, and users do use them. */
    public function testAReservedWordIsUsableAsATableName(): void
    {
        $this->create(new Blueprint('order', function (Blueprint $t): void {
            $t->id();
            $t->string('note', 20);
        }));

        $this->db->exec('INSERT INTO "order" ("note") VALUES (\'ok\')');
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM "order"')->fetchColumn());
    }

    // ─── Paging ───

    /**
     * SQLite has no bare OFFSET: skipping rows without capping them needs
     * `LIMIT -1`, where -1 reads as "unlimited". Emitting `OFFSET 2` alone is a
     * syntax error, so this is checked by running it.
     */
    public function testABareOffsetPagesCorrectly(): void
    {
        $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 20);
        }));
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $this->db->exec("INSERT INTO \"t\" (\"name\") VALUES ('{$n}')");
        }

        $tail = $this->grammar->compileLimit(null, 2);
        $rows = $this->db->query('SELECT "name" FROM "t" ORDER BY "id" ' . $tail)->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['c', 'd'], $rows, "emitted: {$tail}");
    }

    public function testLimitAndOffsetPageCorrectly(): void
    {
        $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 20);
        }));
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $this->db->exec("INSERT INTO \"t\" (\"name\") VALUES ('{$n}')");
        }

        $tail = $this->grammar->compileLimit(2, 1);
        $rows = $this->db->query('SELECT "name" FROM "t" ORDER BY "id" ' . $tail)->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['b', 'c'], $rows, "emitted: {$tail}");
    }

    // ─── Table options ───

    public function testIfNotExistsMakesTheCreateRepeatable(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->id();
        });
        $bp->ifNotExists();

        $this->create($bp);
        $this->create($bp); // would throw without the guard

        self::assertTrue(true);
    }

    public function testATemporaryTableIsMarkedTemporary(): void
    {
        $bp = new Blueprint('t_sementara', function (Blueprint $t): void {
            $t->id();
        });
        $bp->temporary();

        $sqls = $this->create($bp);

        self::assertStringContainsString('CREATE TEMPORARY TABLE', $sqls[0]);
        // Temporary tables live in the `temp` schema, not `main`.
        self::assertFalse(
            $this->db->query("SELECT 1 FROM main.sqlite_master WHERE name='t_sementara'")->fetch()
        );
    }

    // ─── Type mapping ───

    public function testDecimalBecomesNumericAffinity(): void
    {
        $this->create($this->documentsTable());

        self::assertSame('NUMERIC(12, 2)', $this->columns('ezdoc_documents')['amount']['type']);
    }

    public function testBooleanBecomesInteger(): void
    {
        $this->create($this->documentsTable());

        self::assertSame(
            'INTEGER',
            $this->columns('ezdoc_documents')['locked']['type'],
            'SQLite has no BOOLEAN; 0/1 in an INTEGER is the convention'
        );
    }

    public function testUuidBecomesText(): void
    {
        $this->create($this->documentsTable());

        self::assertSame('TEXT', $this->columns('ezdoc_documents')['public_id']['type']);
    }

    /**
     * MySQL narrows a long TEXT to LONGTEXT; SQLite has one unbounded text type
     * and ignores the hint. Both are correct — the point is that neither drops
     * the column or invents a length.
     */
    public function testTheLongTextHintIsIgnoredBecauseSqliteTextIsUnbounded(): void
    {
        $this->create(new Blueprint('t', function (Blueprint $t): void {
            $t->id();
            $t->longText('archive');
        }));

        self::assertSame('TEXT', $this->columns('t')['archive']['type']);
    }

    // ─── Standalone compilation ───

    public function testCreateIndexNamesItselfWhenNotToldOne(): void
    {
        $bp = new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 20)->index();
        });

        $sqls = $this->grammar->compileCreateTable($bp);

        self::assertStringContainsString('idx_ezdoc_documents_status', $sqls[1]);
    }

    public function testDropTable(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS "ezdoc_documents"',
            $this->grammar->compileDropTable('ezdoc_documents')
        );
        self::assertSame(
            'DROP TABLE "ezdoc_documents"',
            $this->grammar->compileDropTable('ezdoc_documents', false)
        );
    }

    public function testFeatureFlags(): void
    {
        self::assertFalse($this->grammar->supportsNativeJson(), 'TEXT + json_valid()');
        self::assertFalse($this->grammar->supportsNativeUuid(), 'TEXT');
        self::assertFalse($this->grammar->supportsNativeEnum(), 'TEXT + CHECK');
        self::assertTrue($this->grammar->supportsSavepoints());
    }

    public function testName(): void
    {
        self::assertSame('sqlite', $this->grammar->name());
    }
}
