<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db\Grammar;

use Ezdoc\Db\Exception\SchemaException;
use Ezdoc\Db\Grammar\Grammar;
use Ezdoc\Db\Grammar\MariaDbGrammar;
use Ezdoc\Db\Grammar\MysqlGrammar;
use Ezdoc\Db\Grammar\PostgresGrammar;
use Ezdoc\Db\Grammar\SqliteGrammar;
use Ezdoc\Db\Grammar\SqlServerGrammar;
use Ezdoc\Db\Schema\Blueprint;
use Ezdoc\Db\Schema\ColumnDef;
use Ezdoc\Db\Types\Type;
use PHPUnit\Framework\TestCase;

/**
 * Runs one set of assertions against every Grammar.
 *
 * Portability is the point of the Db layer, and portability is a property of
 * the set of grammars — not of any one of them. A rule proven only for MySQL
 * says nothing about whether a migration also lands on SQLite. So the rules
 * that must hold everywhere live here, driven by a provider, and a new dialect
 * inherits them the moment it is added to `grammars()`.
 *
 * Dialect-specific emission is asserted in the per-grammar test files.
 */
final class GrammarContractTest extends TestCase
{
    /**
     * Every type a Blueprint can produce.
     *
     * Pinned deliberately: adding a column helper to Blueprint without teaching
     * the grammars about it fails here first, with a message naming the type,
     * rather than at migration time on whichever database the user happens to
     * run.
     */
    private const CANONICAL_TYPES = [
        'bigint', 'blob', 'boolean', 'date', 'datetime', 'decimal', 'enum',
        'float', 'integer', 'json', 'string', 'text', 'time', 'uuid',
    ];

    /**
     * @return array<string, array{0: Grammar, 1: string, 2: string}>
     *   grammar, opening delimiter, closing delimiter
     */
    public static function grammars(): array
    {
        return [
            'mysql'     => [new MysqlGrammar(), '`', '`'],
            'mariadb'   => [new MariaDbGrammar(), '`', '`'],
            'sqlite'    => [new SqliteGrammar(), '"', '"'],
            'postgres'  => [new PostgresGrammar(), '"', '"'],
            'sqlserver' => [new SqlServerGrammar(), '[', ']'],
        ];
    }

    /** A Type carrying nothing but a name — what the grammars dispatch on. */
    private function type(string $name): Type
    {
        return new class ($name) implements Type {
            private string $n;
            public function __construct(string $n) { $this->n = $n; }
            public function name(): string { return $this->n; }
            /** @param mixed $v @return mixed */
            public function toPhp($v) { return $v; }
            /** @param mixed $v @return mixed */
            public function toDb($v) { return $v; }
        };
    }

    /** One table touching every column helper Blueprint exposes. */
    private function kitchenSink(string $name = 'ezdoc_kitchen_sink'): Blueprint
    {
        return new Blueprint($name, function (Blueprint $t): void {
            $t->id();
            $t->string('title', 120);
            $t->text('body');
            $t->longText('archive');
            $t->integer('revision');
            $t->bigint('bytes');
            $t->boolean('locked');
            $t->json('metadata');
            $t->uuid('public_id');
            $t->enum('status', ['draft', 'active']);
            $t->datetime('signed_at');
            $t->date('issued_on');
            $t->time('issued_at');
            $t->decimal('amount', 12, 2);
            $t->float('ratio');
            $t->binary('thumbnail');
        });
    }

    // ─── Identity ───

    public function testEveryGrammarAnnouncesADistinctName(): void
    {
        $names = [];
        foreach (self::grammars() as [$grammar]) {
            $names[] = $grammar->name();
        }

        self::assertSame(
            $names,
            array_values(array_unique($names)),
            'two grammars sharing a name would make connection routing ambiguous'
        );
        foreach ($names as $n) {
            self::assertSame(strtolower($n), $n, 'names are matched case-sensitively by callers');
        }
    }

    // ─── Identifier quoting ───

    /**
     * @dataProvider grammars
     */
    public function testIdentifiersAreWrappedInTheDialectDelimiters(
        Grammar $grammar,
        string $open,
        string $close
    ): void {
        self::assertSame($open . 'documents' . $close, $grammar->wrapIdentifier('documents'));
    }

    /**
     * A closing delimiter inside a name must be escaped, or the identifier
     * terminates early and the remainder is parsed as SQL. This is injection
     * through the one channel bind parameters cannot cover — table and column
     * names are never bindable.
     *
     * @dataProvider grammars
     */
    public function testAClosingDelimiterInsideAnIdentifierIsEscaped(
        Grammar $grammar,
        string $open,
        string $close
    ): void {
        $wrapped = $grammar->wrapIdentifier('col' . $close . ' ; DROP TABLE users --');

        // Doubling is the escape every one of these dialects uses.
        self::assertStringContainsString(
            $close . $close,
            $wrapped,
            'the delimiter should be doubled, not passed through'
        );

        // With every doubled pair removed, exactly two delimiters remain: the
        // ones this method added. Anything more means the identifier can be
        // closed from inside.
        $stripped = str_replace($close . $close, '', $wrapped);
        self::assertSame(
            $open === $close ? 2 : 1,
            substr_count($stripped, $close),
            "identifier can be terminated early: {$wrapped}"
        );
    }

    /**
     * `schema.table` names one thing in two parts. Quoting it whole would ask
     * the database for a table literally called "schema.table".
     *
     * @dataProvider grammars
     */
    public function testDotNotationIsWrappedPerSegment(
        Grammar $grammar,
        string $open,
        string $close
    ): void {
        self::assertSame(
            $open . 'ezdoc' . $close . '.' . $open . 'documents' . $close,
            $grammar->wrapIdentifier('ezdoc.documents')
        );
    }

    // ─── String literals ───

    /**
     * @dataProvider grammars
     */
    public function testStringLiteralsAreDelimitedBySingleQuotes(Grammar $grammar): void
    {
        self::assertSame("'draft'", $grammar->quoteString('draft'));
    }

    /**
     * Literals reach SQL only where binding is impossible — DEFAULT clauses,
     * CHECK constraint value lists. A quote that survives unescaped there ends
     * the literal and starts a statement.
     *
     * @dataProvider grammars
     */
    public function testAStringLiteralCannotBeTerminatedFromInside(Grammar $grammar): void
    {
        $quoted = $grammar->quoteString("'; DROP TABLE ezdoc_documents; --");

        self::assertSame("'", $quoted[0]);
        self::assertSame("'", $quoted[strlen($quoted) - 1]);

        // Strip the two outer quotes and every escaped pair; nothing may remain.
        $inner = substr($quoted, 1, -1);
        $inner = str_replace(["\\'", "''"], '', $inner);
        self::assertStringNotContainsString(
            "'",
            $inner,
            "literal can be closed from inside: {$quoted}"
        );
    }

    /**
     * Backslash handling is genuinely dialect-specific, and getting it backwards
     * corrupts data in one direction and invites injection in the other.
     *
     * MySQL treats `\` as an escape character inside literals, so it must be
     * doubled. Postgres (`standard_conforming_strings=on`, the default since
     * 9.1), SQLite and T-SQL treat it as an ordinary character — doubling it
     * there would store two backslashes where the caller wrote one.
     */
    public function testBackslashEscapingFollowsTheDialect(): void
    {
        $input = 'C:\\dokumen';

        self::assertSame("'C:\\\\dokumen'", (new MysqlGrammar())->quoteString($input), 'MySQL escapes backslash');
        self::assertSame("'C:\\\\dokumen'", (new MariaDbGrammar())->quoteString($input), 'MariaDB follows MySQL');

        foreach ([new SqliteGrammar(), new PostgresGrammar(), new SqlServerGrammar()] as $g) {
            self::assertSame(
                "'C:\\dokumen'",
                $g->quoteString($input),
                $g->name() . ' must leave backslash alone — it is not an escape character there'
            );
        }
    }

    // ─── Type mapping ───

    /**
     * Guards the list above against drift. If a Blueprint helper starts
     * producing a type not in CANONICAL_TYPES, the totality test below stops
     * covering it and the gap goes unnoticed.
     */
    public function testBlueprintProducesOnlyCanonicalTypes(): void
    {
        $seen = [];
        foreach ($this->kitchenSink()->getColumns() as $col) {
            $seen[$col->getType()] = true;
        }
        $seen = array_keys($seen);
        sort($seen);

        self::assertSame(
            self::CANONICAL_TYPES,
            $seen,
            'Blueprint gained or lost a type — update CANONICAL_TYPES and every grammar'
        );
    }

    /**
     * Totality: no dialect may quietly lack a type. A grammar that cannot map
     * one is only discovered when someone migrates against that database.
     *
     * @dataProvider grammars
     */
    public function testEveryCanonicalTypeIsMapped(Grammar $grammar): void
    {
        foreach (self::CANONICAL_TYPES as $typeName) {
            $col = new ColumnDef('c', $typeName);
            if ($typeName === 'enum') {
                $col->enumValues(['a', 'b']); // MySQL refuses an enum with no values
            }

            $sql = $grammar->mapType($this->type($typeName), $col);

            self::assertNotSame(
                '',
                $sql,
                "{$grammar->name()} maps '{$typeName}' to an empty declaration"
            );
        }
    }

    /**
     * An unknown type must fail loudly. Falling back to TEXT would create a
     * column silently unlike the one asked for.
     *
     * @dataProvider grammars
     */
    public function testAnUnknownTypeIsRejected(Grammar $grammar): void
    {
        $this->expectException(SchemaException::class);
        $grammar->mapType($this->type('geometry'), new ColumnDef('area', 'geometry'));
    }

    /**
     * Length is a hint, not a promise. Some dialects honour it, others have one
     * unbounded text type — but none may drop the column.
     *
     * @dataProvider grammars
     */
    public function testAllTypesSurviveIntoCreateTable(Grammar $grammar): void
    {
        $sql = implode("\n", $grammar->compileCreateTable($this->kitchenSink()));

        foreach ($this->kitchenSink()->getColumns() as $col) {
            self::assertStringContainsString(
                $grammar->wrapIdentifier($col->getName()),
                $sql,
                "{$grammar->name()} dropped column '{$col->getName()}'"
            );
        }
    }

    // ─── CREATE TABLE shape ───

    /**
     * Statements are handed to the driver one at a time. A trailing semicolon
     * makes some drivers reject the batch, and an empty string is always a
     * bug — callers run whatever comes back.
     *
     * @dataProvider grammars
     */
    public function testCreateTableReturnsRunnableStatements(Grammar $grammar): void
    {
        $sqls = $grammar->compileCreateTable($this->kitchenSink());

        self::assertNotEmpty($sqls);
        self::assertStringStartsWith('CREATE', $sqls[0]);

        foreach ($sqls as $i => $sql) {
            self::assertNotSame('', trim($sql), "statement {$i} is empty");
            self::assertStringEndsNotWith(';', rtrim($sql), "statement {$i} carries a terminator");
        }
    }

    /**
     * A table with no columns is a caller mistake that would otherwise emit
     * `CREATE TABLE "t" ()` and fail at the database with an opaque parse error.
     *
     * Documents current behaviour: the grammars emit it rather than refuse.
     *
     * @dataProvider grammars
     */
    public function testAnEmptyTableStillCompilesToSomething(Grammar $grammar): void
    {
        $sqls = $grammar->compileCreateTable(new Blueprint('kosong', function (): void {}));

        self::assertNotEmpty($sqls, 'no statement at all would be worse than an invalid one');
    }

    // ─── DROP TABLE ───

    /**
     * @dataProvider grammars
     */
    public function testDropTableHonoursIfExists(Grammar $grammar): void
    {
        $withGuard = $grammar->compileDropTable('ezdoc_documents');
        $without = $grammar->compileDropTable('ezdoc_documents', false);

        self::assertStringContainsString('IF EXISTS', $withGuard, 'the guard defaults to on');
        self::assertStringNotContainsString(
            'IF EXISTS',
            $without,
            'an unguarded drop must actually be unguarded — callers rely on the error'
        );
        self::assertStringContainsString($grammar->wrapIdentifier('ezdoc_documents'), $without);
    }

    // ─── LIMIT / OFFSET ───

    /**
     * @dataProvider grammars
     */
    public function testNoLimitAndNoOffsetAddsNothing(Grammar $grammar): void
    {
        self::assertSame('', $grammar->compileLimit(null, 0));
    }

    /**
     * @dataProvider grammars
     */
    public function testALimitIsCarriedIntoTheClause(Grammar $grammar): void
    {
        self::assertStringContainsString('25', $grammar->compileLimit(25));
    }

    /**
     * @dataProvider grammars
     */
    public function testLimitAndOffsetAreBothCarried(Grammar $grammar): void
    {
        $sql = $grammar->compileLimit(25, 50);

        self::assertStringContainsString('25', $sql);
        self::assertStringContainsString('50', $sql);
    }

    /**
     * Paging past the last page asks for an offset with no limit. Dialects
     * express it differently — SQLite needs `LIMIT -1`, Postgres allows a bare
     * OFFSET — but none may drop the offset and return the first page instead.
     *
     * @dataProvider grammars
     */
    public function testAnOffsetWithoutALimitStillSkipsRows(Grammar $grammar): void
    {
        $sql = $grammar->compileLimit(null, 100);

        self::assertNotSame('', $sql, "{$grammar->name()} ignored a bare offset");
        self::assertStringContainsString('100', $sql);
    }

    /**
     * `LIMIT 0` means "no rows", which is a real query — it must not be
     * confused with "no limit".
     *
     * @dataProvider grammars
     */
    public function testAZeroLimitIsNotTreatedAsAbsent(Grammar $grammar): void
    {
        self::assertNotSame('', $grammar->compileLimit(0));
    }

    // ─── Feature flags ───

    /**
     * The flags exist so callers can branch. A flag that disagrees with what
     * the grammar emits sends them down the wrong branch.
     *
     * @dataProvider grammars
     */
    public function testTheJsonFlagMatchesWhatIsEmitted(Grammar $grammar): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->json('metadata');
        });
        $sql = implode("\n", $grammar->compileCreateTable($bp));

        if ($grammar->supportsNativeJson()) {
            self::assertMatchesRegularExpression(
                '/\bJSONB?\b/',
                $sql,
                "{$grammar->name()} claims native JSON but emits none"
            );
        } else {
            self::assertStringContainsString(
                'CHECK',
                $sql,
                "{$grammar->name()} has no native JSON, so it owes a validity constraint"
            );
        }
    }

    /**
     * @dataProvider grammars
     */
    public function testTheUuidFlagMatchesWhatIsEmitted(Grammar $grammar): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->uuid('public_id');
        });
        $sql = implode("\n", $grammar->compileCreateTable($bp));

        if ($grammar->supportsNativeUuid()) {
            self::assertMatchesRegularExpression('/UUID|UNIQUEIDENTIFIER/', $sql);
        } else {
            // No native type, so it lands in a string column wide enough for
            // the 36-character canonical form.
            self::assertMatchesRegularExpression('/CHAR\(36\)|TEXT/', $sql);
        }
    }

    /**
     * Known mismatch, pinned rather than hidden.
     *
     * PostgresGrammar returns true for `supportsNativeEnum()` because Postgres
     * has `CREATE TYPE ... AS ENUM`, but v0.9.9 deliberately emits TEXT + CHECK
     * instead — managing an enum type's lifecycle across migrations is its own
     * problem. So the flag describes the database, while every other flag here
     * describes this grammar's output.
     *
     * Nothing branches on it today. If something starts to, the flag is the
     * thing to change, and this test will say so.
     */
    public function testEnumIsEmittedAsACheckConstraintEverywhereExceptMysql(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->enum('status', ['draft', 'active']);
        });

        $native = [new MysqlGrammar(), new MariaDbGrammar()];
        foreach ($native as $g) {
            $sql = implode("\n", $g->compileCreateTable($bp));
            self::assertStringContainsString("ENUM('draft', 'active')", $sql, $g->name());
        }

        $viaCheck = [new SqliteGrammar(), new PostgresGrammar(), new SqlServerGrammar()];
        foreach ($viaCheck as $g) {
            $sql = implode("\n", $g->compileCreateTable($bp));
            self::assertStringContainsString('CHECK', $sql, $g->name() . ' must constrain the values');
            self::assertStringContainsString("'draft'", $sql);
            self::assertStringContainsString("'active'", $sql);
        }

        self::assertTrue(
            (new PostgresGrammar())->supportsNativeEnum(),
            'flag describes Postgres itself; the grammar still emits CHECK — see this test name'
        );
    }

    /**
     * @dataProvider grammars
     */
    public function testEveryTargetSupportsSavepoints(Grammar $grammar): void
    {
        self::assertTrue(
            $grammar->supportsSavepoints(),
            'nested transactions in the Db layer assume savepoints on every T2 target'
        );
    }

    /**
     * Only T-SQL folds paging into ORDER BY. Answering true elsewhere would
     * make QueryBuilder attach a constant ordering to queries that never
     * needed one, changing their plans for nothing.
     */
    public function testOnlySqlServerNeedsAnOrderByBeforePaging(): void
    {
        foreach (self::grammars() as $label => [$grammar]) {
            self::assertSame(
                $label === 'sqlserver',
                $grammar->requiresOrderByForPaging(),
                "{$label} answered the wrong way"
            );
        }
    }

    /**
     * A dialect that needs an ordering must emit a paging clause that reads as
     * a continuation of one — otherwise the flag describes a rule the emitted
     * SQL does not follow.
     *
     * @dataProvider grammars
     */
    public function testTheOrderByFlagMatchesTheShapeOfThePagingClause(Grammar $grammar): void
    {
        $tail = $grammar->compileLimit(25, 50);

        if ($grammar->requiresOrderByForPaging()) {
            self::assertStringStartsWith('OFFSET', $tail);
            self::assertStringNotContainsString('LIMIT', $tail, 'T-SQL has no LIMIT');
        } else {
            self::assertStringStartsWith('LIMIT', $tail, 'a standalone clause starts with LIMIT');
        }
    }
}
