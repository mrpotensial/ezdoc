<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db;

use Ezdoc\Db\Grammar\Grammar;
use Ezdoc\Db\Grammar\MariaDbGrammar;
use Ezdoc\Db\Grammar\MysqlGrammar;
use Ezdoc\Db\Grammar\PostgresGrammar;
use Ezdoc\Db\Grammar\SqliteGrammar;
use Ezdoc\Db\Grammar\SqlServerGrammar;
use Ezdoc\Db\QueryBuilder;
use Ezdoc\Tests\Support\GrammarOnlyConnection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the same QueryBuilder call chain compiles to valid SQL on every
 * dialect.
 *
 * The grammar tests prove each dialect emits the right fragments. This proves
 * the builder assembles them into a statement the target database will accept
 * — which is a separate question, and the one that actually breaks. A fragment
 * can be perfectly correct and still land in the wrong place.
 *
 * SQL Server is where that shows up: its paging clause is part of ORDER BY, so
 * a limit without an ordering is a syntax error there and nowhere else.
 */
final class QueryBuilderDialectTest extends TestCase
{
    /**
     * @return array<string, array{0: Grammar}>
     */
    public static function grammars(): array
    {
        return [
            'mysql'     => [new MysqlGrammar()],
            'mariadb'   => [new MariaDbGrammar()],
            'sqlite'    => [new SqliteGrammar()],
            'postgres'  => [new PostgresGrammar()],
            'sqlserver' => [new SqlServerGrammar()],
        ];
    }

    private function builder(Grammar $grammar): QueryBuilder
    {
        return (new GrammarOnlyConnection($grammar))->query();
    }

    // ─── Identifiers reach the dialect ───

    /**
     * @dataProvider grammars
     */
    public function testTableAndColumnNamesAreQuotedForTheDialect(Grammar $grammar): void
    {
        [$sql] = $this->builder($grammar)
            ->select('title')
            ->from('ezdoc_documents')
            ->toSql();

        self::assertStringContainsString($grammar->wrapIdentifier('ezdoc_documents'), $sql);
        self::assertStringContainsString($grammar->wrapIdentifier('title'), $sql);
    }

    /**
     * @dataProvider grammars
     */
    public function testValuesTravelAsBoundParametersNotInlinedText(Grammar $grammar): void
    {
        [$sql, $params] = $this->builder($grammar)
            ->select('*')
            ->from('ezdoc_documents')
            ->where('status = ?', ['draft'])
            ->toSql();

        self::assertSame(['draft'], $params);
        self::assertStringContainsString('?', $sql);
        self::assertStringNotContainsString(
            "'draft'",
            $sql,
            'a value inlined into SQL is a value that was never escaped'
        );
    }

    // ─── Paging ───

    /**
     * @dataProvider grammars
     */
    public function testALimitIsAppliedOnEveryDialect(Grammar $grammar): void
    {
        [$sql] = $this->builder($grammar)
            ->select('*')
            ->from('ezdoc_documents')
            ->orderBy('id')
            ->limit(25)
            ->toSql();

        self::assertStringContainsString('25', $sql);
    }

    /**
     * Regression test for a portability defect.
     *
     * In T-SQL, OFFSET/FETCH is a clause of ORDER BY rather than a statement
     * suffix. `SELECT * FROM [t] OFFSET 0 ROWS FETCH NEXT 25 ROWS ONLY` is a
     * syntax error, and the same builder chain works on the other four
     * dialects — so the failure only appears on the target least likely to be
     * exercised during development.
     *
     * The fix follows what Laravel and Doctrine DBAL both do: emit a constant
     * ordering when the caller supplied none. `(SELECT 0)` is a scalar the
     * optimiser discards, so it satisfies the parser without imposing a sort.
     *
     * Note the ordering is arbitrary but the rows are not lost — an unordered
     * paged query has no defined row order on any database anyway.
     */
    public function testSqlServerPagingIsValidWithoutAnExplicitOrderBy(): void
    {
        [$sql] = $this->builder(new SqlServerGrammar())
            ->select('*')
            ->from('ezdoc_documents')
            ->limit(25)
            ->toSql();

        self::assertStringContainsString('OFFSET 0 ROWS FETCH NEXT 25 ROWS ONLY', $sql);
        self::assertStringContainsString(
            'ORDER BY',
            $sql,
            'T-SQL rejects OFFSET without ORDER BY'
        );
        self::assertMatchesRegularExpression(
            '/ORDER BY \(SELECT 0\) OFFSET/',
            $sql,
            'the placeholder ordering must sit directly before the paging clause'
        );
    }

    /** A caller's own ordering must win — the placeholder is a fallback only. */
    public function testSqlServerKeepsAnExplicitOrderBy(): void
    {
        [$sql] = $this->builder(new SqlServerGrammar())
            ->select('*')
            ->from('ezdoc_documents')
            ->orderBy('created_at', 'DESC')
            ->limit(25)
            ->toSql();

        self::assertStringContainsString('ORDER BY [created_at] DESC OFFSET 0 ROWS', $sql);
        self::assertStringNotContainsString('(SELECT 0)', $sql);
    }

    /** No paging, no reason to invent an ordering. */
    public function testSqlServerAddsNoOrderingToAnUnpagedQuery(): void
    {
        [$sql] = $this->builder(new SqlServerGrammar())
            ->select('*')
            ->from('ezdoc_documents')
            ->toSql();

        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    /**
     * The placeholder is a T-SQL workaround. Adding it elsewhere would change
     * plans and confuse anyone reading the query log.
     *
     * @dataProvider grammars
     */
    public function testOnlySqlServerGetsAPlaceholderOrdering(Grammar $grammar): void
    {
        [$sql] = $this->builder($grammar)
            ->select('*')
            ->from('ezdoc_documents')
            ->limit(25)
            ->toSql();

        if ($grammar->name() === 'sqlserver') {
            self::assertStringContainsString('(SELECT 0)', $sql);
        } else {
            self::assertStringNotContainsString('(SELECT 0)', $sql, $grammar->name() . ' needs no such thing');
        }
    }

    /**
     * @dataProvider grammars
     */
    public function testAnOffsetWithoutALimitIsStillApplied(Grammar $grammar): void
    {
        [$sql] = $this->builder($grammar)
            ->select('*')
            ->from('ezdoc_documents')
            ->orderBy('id')
            ->offset(100)
            ->toSql();

        self::assertStringContainsString('100', $sql, $grammar->name() . ' dropped the offset');
    }

    // ─── Executed for real, where possible ───

    /**
     * SQLite needs no server, so its compiled SQL is run rather than inspected.
     * That is the only assertion here that can fail for a reason a string
     * comparison would miss — a clause in the wrong order, or a keyword the
     * parser does not accept in that position.
     */
    public function testTheCompiledSqliteQueryRunsAndPagesCorrectly(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE "ezdoc_documents" ("id" INTEGER PRIMARY KEY, "title" TEXT, "status" TEXT)');
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $title) {
            $db->exec("INSERT INTO \"ezdoc_documents\" VALUES (" . ($i + 1) . ", '{$title}', 'draft')");
        }

        [$sql, $params] = $this->builder(new SqliteGrammar())
            ->select('title')
            ->from('ezdoc_documents')
            ->where('status = ?', ['draft'])
            ->orderBy('id')
            ->limit(2)
            ->offset(1)
            ->toSql();

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        self::assertSame(['b', 'c'], $stmt->fetchAll(PDO::FETCH_COLUMN), "compiled: {$sql}");
    }

    /**
     * A bare offset compiles differently on SQLite (`LIMIT -1 OFFSET n`) than
     * anywhere else, and the difference only matters once it runs.
     */
    public function testTheCompiledSqliteBareOffsetRuns(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "name" TEXT)');
        foreach (['a', 'b', 'c'] as $i => $n) {
            $db->exec("INSERT INTO \"t\" VALUES (" . ($i + 1) . ", '{$n}')");
        }

        [$sql, $params] = $this->builder(new SqliteGrammar())
            ->select('name')
            ->from('t')
            ->orderBy('id')
            ->offset(1)
            ->toSql();

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        self::assertSame(['b', 'c'], $stmt->fetchAll(PDO::FETCH_COLUMN), "compiled: {$sql}");
    }

    // ─── Writes ───

    /**
     * @dataProvider grammars
     */
    public function testInsertsQuoteColumnsAndBindValues(Grammar $grammar): void
    {
        [$sql, $params] = $this->builder($grammar)
            ->insertInto('ezdoc_documents')
            ->values(['title' => 'Surat', 'status' => 'draft'])
            ->toSql();

        self::assertStringStartsWith('INSERT INTO ' . $grammar->wrapIdentifier('ezdoc_documents'), $sql);
        self::assertStringContainsString($grammar->wrapIdentifier('title'), $sql);
        self::assertSame(['Surat', 'draft'], $params);
    }

    /**
     * @dataProvider grammars
     */
    public function testUpdatesBindTheSetValuesBeforeTheWhereValues(Grammar $grammar): void
    {
        [$sql, $params] = $this->builder($grammar)
            ->update('ezdoc_documents')
            ->set('status', 'active')
            ->where('id = ?', [7])
            ->toSql();

        self::assertStringStartsWith('UPDATE ' . $grammar->wrapIdentifier('ezdoc_documents'), $sql);
        self::assertSame(
            ['active', 7],
            $params,
            'positional binding depends on SET values coming before WHERE values'
        );
    }

    /**
     * @dataProvider grammars
     */
    public function testDeletesCarryTheirWhereClause(Grammar $grammar): void
    {
        [$sql, $params] = $this->builder($grammar)
            ->deleteFrom('ezdoc_documents')
            ->where('id = ?', [7])
            ->toSql();

        self::assertStringStartsWith('DELETE FROM ' . $grammar->wrapIdentifier('ezdoc_documents'), $sql);
        self::assertSame([7], $params);
    }
}
