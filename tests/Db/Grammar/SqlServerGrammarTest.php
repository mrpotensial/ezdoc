<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db\Grammar;

use Ezdoc\Db\Grammar\SqlServerGrammar;
use Ezdoc\Db\Schema\Blueprint;
use Ezdoc\Db\Schema\ForeignKeyDef;
use PHPUnit\Framework\TestCase;

/**
 * Covers the T-SQL SqlServerGrammar emits.
 *
 * SQL Server is the furthest of the five targets from the MySQL baseline —
 * different delimiters, different autoincrement, different paging, and no
 * `IF NOT EXISTS` on CREATE TABLE at all. It is also the target least likely
 * to be exercised by hand during development, which is exactly why the
 * divergences are pinned here.
 *
 * No SQL Server instance runs in this suite, so these assert the emitted text.
 * The rules shared by every dialect live in GrammarContractTest.
 */
final class SqlServerGrammarTest extends TestCase
{
    private SqlServerGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new SqlServerGrammar();
    }

    private function compile(Blueprint $bp): array
    {
        return $this->grammar->compileCreateTable($bp);
    }

    private function documentsSql(): string
    {
        return $this->compile(new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('title', 120);
            $t->text('body')->nullable();
            $t->json('metadata')->nullable();
            $t->enum('status', ['draft', 'active'])->default('draft');
            $t->boolean('locked')->default(false);
            $t->decimal('amount', 12, 2);
            $t->binary('thumbnail')->nullable();
            $t->timestamps();
        }))[0];
    }

    public function testName(): void
    {
        self::assertSame('sqlserver', $this->grammar->name());
    }

    // ─── Identifiers ───

    /**
     * Brackets work whether or not QUOTED_IDENTIFIER is on; double quotes only
     * work when it is. Since that setting is per-connection and outside this
     * library's control, brackets are the safe choice.
     */
    public function testIdentifiersUseBrackets(): void
    {
        self::assertSame('[documents]', $this->grammar->wrapIdentifier('documents'));
    }

    /** Only the closing bracket is special inside a bracketed identifier. */
    public function testAClosingBracketIsDoubled(): void
    {
        self::assertSame('[a]]b]', $this->grammar->wrapIdentifier('a]b'));
    }

    public function testAnOpeningBracketNeedsNoEscaping(): void
    {
        self::assertSame('[a[b]', $this->grammar->wrapIdentifier('a[b'));
    }

    public function testDotNotationIsBracketedPerSegment(): void
    {
        self::assertSame('[dbo].[documents]', $this->grammar->wrapIdentifier('dbo.documents'));
    }

    /** T-SQL has no backslash escape; doubling one would store two. */
    public function testBackslashesAreLeftAlone(): void
    {
        self::assertSame("'C:\\dokumen'", $this->grammar->quoteString('C:\\dokumen'));
    }

    public function testSingleQuotesAreDoubled(): void
    {
        self::assertSame("'it''s'", $this->grammar->quoteString("it's"));
    }

    // ─── Types ───

    /**
     * NVARCHAR stores UTF-16 and holds any character; VARCHAR is limited to the
     * database collation's code page, which silently mangles text outside it.
     */
    public function testStringsUseNvarcharSoUnicodeSurvives(): void
    {
        self::assertStringContainsString('[title] NVARCHAR(120) NOT NULL', $this->documentsSql());
    }

    /**
     * TEXT was deprecated in SQL Server 2005 and cannot be used with most
     * string functions. NVARCHAR(MAX) is its replacement.
     */
    public function testTextBecomesNvarcharMaxNotTheDeprecatedTextType(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('[body] NVARCHAR(MAX)', $sql);
        self::assertStringNotContainsString('[body] TEXT', $sql);
    }

    public function testBooleanBecomesBit(): void
    {
        self::assertStringContainsString(
            '[locked] BIT NOT NULL DEFAULT 0',
            $this->documentsSql(),
            'T-SQL has no BOOLEAN; BIT holds 0/1'
        );
    }

    public function testUuidUsesTheNativeType(): void
    {
        self::assertStringContainsString('[public_id] UNIQUEIDENTIFIER NOT NULL', $this->documentsSql());
    }

    /**
     * DATETIME2 supersedes DATETIME: wider range and finer precision. DATETIME
     * rounds to 1/300 of a second, which loses information Blueprint's
     * datetime does not expect to lose.
     */
    public function testDatetimeBecomesDatetime2(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('[created_at] DATETIME2', $sql);
        self::assertStringNotContainsString('DATETIME NULL', $sql);
    }

    public function testBlobBecomesVarbinaryMax(): void
    {
        self::assertStringContainsString('[thumbnail] VARBINARY(MAX)', $this->documentsSql());
    }

    /**
     * SQL Server has no JSON column type through 2022. The value lives in
     * NVARCHAR(MAX) and ISJSON() supplies the validation the type would.
     */
    public function testJsonBecomesNvarcharMaxGuardedByIsjson(): void
    {
        self::assertStringContainsString(
            '[metadata] NVARCHAR(MAX) NULL CHECK ([metadata] IS NULL OR ISJSON([metadata]) = 1)',
            $this->documentsSql()
        );
    }

    /**
     * ISJSON returns 1 or 0, never a boolean — `CHECK (ISJSON(col))` is a
     * syntax error in T-SQL. The comparison is required.
     */
    public function testTheIsjsonCheckComparesAgainstOne(): void
    {
        self::assertMatchesRegularExpression(
            '/ISJSON\(\[metadata\]\) = 1/',
            $this->documentsSql()
        );
    }

    /** A NOT NULL json column has nothing to exempt, so no null branch. */
    public function testANotNullJsonColumnGetsNoNullBranchInItsCheck(): void
    {
        $sql = $this->compile(new Blueprint('t', function (Blueprint $t): void {
            $t->json('payload');
        }))[0];

        self::assertStringContainsString('CHECK (ISJSON([payload]) = 1)', $sql);
        self::assertStringNotContainsString('IS NULL OR', $sql);
    }

    public function testEnumBecomesNvarcharWithACheckConstraint(): void
    {
        self::assertStringContainsString(
            "[status] NVARCHAR(255) NOT NULL DEFAULT 'draft' CHECK ([status] IN ('draft', 'active'))",
            $this->documentsSql()
        );
    }

    // ─── Autoincrement ───

    public function testAutoIncrementBecomesIdentity(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('IDENTITY(1,1)', $sql);
        self::assertStringNotContainsString('AUTO_INCREMENT', $sql);
    }

    /**
     * IDENTITY is the whole autoincrement mechanism here — there is no
     * sequence object to fall back on, and no `GENERATED ... AS IDENTITY`
     * spelling before SQL Server 2022's limited support.
     */
    public function testTheIdentitySeedAndIncrementAreBothOne(): void
    {
        self::assertMatchesRegularExpression('/\[id\] BIGINT NOT NULL IDENTITY\(1,1\)/', $this->documentsSql());
    }

    // ─── Keys ───

    /**
     * An unnamed primary key gets a server-generated name like
     * `PK__ezdoc_do__3213E83F...`, which differs on every database it is
     * created on and cannot be dropped by name in a later migration.
     */
    public function testThePrimaryKeyConstraintIsNamedDeterministically(): void
    {
        self::assertStringContainsString(
            'CONSTRAINT [pk_ezdoc_documents] PRIMARY KEY ([id])',
            $this->documentsSql()
        );
    }

    public function testUniqueIsInlineWithANamedConstraint(): void
    {
        self::assertStringContainsString(
            'CONSTRAINT [uniq_ezdoc_documents_public_id] UNIQUE ([public_id])',
            $this->documentsSql()
        );
    }

    public function testNonUniqueIndexesAreEmittedSeparately(): void
    {
        $sqls = $this->compile(new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 20);
            $t->string('title', 120);
            $t->index(['status', 'title'], 'idx_status_title');
        }));

        self::assertCount(2, $sqls);
        self::assertSame(
            'CREATE INDEX [idx_status_title] ON [ezdoc_documents] ([status], [title])',
            $sqls[1]
        );
    }

    public function testForeignKeysAreInlineWithReferentialActions(): void
    {
        $sql = $this->compile(new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->references('id')->on('ezdoc_templates')->cascadeOnDelete();
        }))[0];

        self::assertStringContainsString(
            'FOREIGN KEY ([template_id]) REFERENCES [ezdoc_templates] ([id]) ON DELETE CASCADE',
            $sql
        );
    }

    public function testANamedForeignKeyBecomesANamedConstraint(): void
    {
        $fk = (new ForeignKeyDef(['template_id'], 'ezdoc_templates', ['id']))
            ->name('fk_dokumen_template')
            ->restrictOnDelete();

        self::assertSame(
            'CONSTRAINT [fk_dokumen_template] FOREIGN KEY ([template_id]) '
            . 'REFERENCES [ezdoc_templates] ([id]) ON DELETE RESTRICT',
            $this->grammar->compileForeignKey($fk)
        );
    }

    // ─── Paging ───

    /**
     * T-SQL has no LIMIT. The SQL:2008 form is the portable one from SQL Server
     * 2012 onward; TOP cannot express an offset at all.
     */
    public function testPagingUsesOffsetFetchNotLimit(): void
    {
        $sql = $this->grammar->compileLimit(25, 50);

        self::assertSame('OFFSET 50 ROWS FETCH NEXT 25 ROWS ONLY', $sql);
        self::assertStringNotContainsString('LIMIT', $sql);
    }

    /** A limit with no offset still has to say ROWS — OFFSET is not optional. */
    public function testALimitWithoutAnOffsetStillEmitsOffsetZero(): void
    {
        self::assertSame('OFFSET 0 ROWS FETCH NEXT 25 ROWS ONLY', $this->grammar->compileLimit(25));
    }

    public function testABareOffsetOmitsTheFetchClause(): void
    {
        self::assertSame('OFFSET 100 ROWS', $this->grammar->compileLimit(null, 100));
    }

    /**
     * OFFSET is part of ORDER BY in T-SQL, so the clause is invalid on its own:
     * SQL Server raises "Invalid usage of the option NEXT in the FETCH
     * statement" when no ORDER BY precedes it.
     *
     * The grammar emits only the tail, so supplying the ORDER BY is the query
     * builder's job — see QueryBuilderDialectTest, which checks it does.
     */
    public function testThePagingClauseIsOnlyValidAfterAnOrderBy(): void
    {
        self::assertStringStartsWith(
            'OFFSET',
            $this->grammar->compileLimit(25, 50),
            'the emitted fragment presumes an ORDER BY was written before it'
        );
        self::assertTrue(
            $this->grammar->requiresOrderByForPaging(),
            'the flag is how QueryBuilder learns to supply one'
        );
    }

    // ─── Table options ───

    /**
     * Pinned because it is a silent behavioural difference, not a formatting
     * one.
     *
     * T-SQL has no `CREATE TABLE IF NOT EXISTS`. A migration that relies on the
     * flag to be re-runnable is idempotent on the other four dialects and
     * throws here on the second run. Making it work needs an
     * `IF NOT EXISTS (SELECT ... FROM sys.tables)` wrapper, which is a
     * SchemaManager concern — it has hasTable() and the grammar does not.
     *
     * Nothing in ezdoc's own migrations sets the flag today.
     */
    public function testIfNotExistsIsSilentlyDroppedBecauseTsqlHasNoSuchClause(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->id();
        });
        $bp->ifNotExists();

        $sql = $this->compile($bp)[0];

        self::assertStringStartsWith('CREATE TABLE [t]', $sql);
        self::assertStringNotContainsString('IF NOT EXISTS', $sql);
    }

    /**
     * DROP TABLE IF EXISTS does exist, from SQL Server 2016. Below that it is a
     * syntax error — which matches the 2019+ baseline this grammar targets.
     */
    public function testDropTableUsesTheModernIfExistsForm(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS [ezdoc_documents]',
            $this->grammar->compileDropTable('ezdoc_documents')
        );
        self::assertSame(
            'DROP TABLE [ezdoc_documents]',
            $this->grammar->compileDropTable('ezdoc_documents', false)
        );
    }

    public function testMysqlTableOptionsAreNotEmitted(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->id();
        });
        $bp->engine('InnoDB')->charset('utf8mb4');

        $sql = $this->compile($bp)[0];

        self::assertStringNotContainsString('ENGINE', $sql);
        self::assertStringNotContainsString('CHARSET', $sql);
    }

    public function testFeatureFlags(): void
    {
        self::assertFalse($this->grammar->supportsNativeJson(), 'NVARCHAR(MAX) + ISJSON');
        self::assertTrue($this->grammar->supportsNativeUuid(), 'UNIQUEIDENTIFIER');
        self::assertFalse($this->grammar->supportsNativeEnum(), 'NVARCHAR + CHECK');
        self::assertTrue($this->grammar->supportsSavepoints(), 'SAVE TRANSACTION');
    }
}
