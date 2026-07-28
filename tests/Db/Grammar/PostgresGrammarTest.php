<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db\Grammar;

use Ezdoc\Db\Grammar\PostgresGrammar;
use Ezdoc\Db\Schema\Blueprint;
use Ezdoc\Db\Schema\ForeignKeyDef;
use Ezdoc\Db\Schema\IndexDef;
use PHPUnit\Framework\TestCase;

/**
 * Covers the SQL PostgresGrammar emits.
 *
 * No Postgres server runs in this suite, so these are assertions about the
 * text. They are chosen for the places where Postgres genuinely differs from
 * MySQL — the ones where copying MySQL's output would produce SQL Postgres
 * rejects, or worse, accepts with different meaning.
 *
 * The cross-dialect rules every grammar obeys live in GrammarContractTest.
 */
final class PostgresGrammarTest extends TestCase
{
    private PostgresGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new PostgresGrammar();
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
            $t->timestamps();
        }))[0];
    }

    public function testName(): void
    {
        self::assertSame('postgres', $this->grammar->name());
    }

    // ─── Identifiers ───

    public function testIdentifiersUseDoubleQuotes(): void
    {
        self::assertSame('"documents"', $this->grammar->wrapIdentifier('documents'));
    }

    /**
     * Unquoted identifiers are folded to lower case by Postgres, so a column
     * created as `createdAt` would answer to `createdat` and nothing else.
     * Quoting everywhere is what keeps a mixed-case name usable.
     */
    public function testMixedCaseNamesAreQuotedSoPostgresDoesNotFoldThem(): void
    {
        self::assertSame('"createdAt"', $this->grammar->wrapIdentifier('createdAt'));
    }

    public function testDoubleQuotesInsideAnIdentifierAreDoubled(): void
    {
        self::assertSame('"a""b"', $this->grammar->wrapIdentifier('a"b'));
    }

    /**
     * With standard_conforming_strings on — the default since 9.1 — a backslash
     * inside a literal is just a backslash. Escaping it the way MySQL does
     * would store two where the caller wrote one.
     */
    public function testBackslashesAreLeftAlone(): void
    {
        self::assertSame("'C:\\dokumen'", $this->grammar->quoteString('C:\\dokumen'));
    }

    public function testSingleQuotesAreDoubledNotBackslashed(): void
    {
        self::assertSame("'it''s'", $this->grammar->quoteString("it's"));
    }

    // ─── Types ───

    public function testJsonBecomesJsonbForIndexability(): void
    {
        self::assertStringContainsString(
            '"metadata" JSONB',
            $this->documentsSql(),
            'JSONB is binary, deduplicated and indexable; JSON is stored text'
        );
    }

    public function testUuidUsesTheNativeType(): void
    {
        self::assertStringContainsString('"public_id" UUID NOT NULL', $this->documentsSql());
    }

    public function testBooleanUsesTheNativeTypeWithKeywordDefaults(): void
    {
        self::assertStringContainsString(
            '"locked" BOOLEAN NOT NULL DEFAULT FALSE',
            $this->documentsSql(),
            'Postgres BOOLEAN will not accept 0/1 as a default the way TINYINT does'
        );
    }

    public function testTrueDefaultsUseTheKeyword(): void
    {
        $sql = $this->compile(new Blueprint('t', function (Blueprint $t): void {
            $t->boolean('active')->default(true);
        }))[0];

        self::assertStringContainsString('DEFAULT TRUE', $sql);
    }

    public function testBlobBecomesBytea(): void
    {
        $sql = $this->compile(new Blueprint('t', function (Blueprint $t): void {
            $t->binary('thumbnail');
        }))[0];

        self::assertStringContainsString('"thumbnail" BYTEA', $sql);
    }

    public function testFloatBecomesDoublePrecision(): void
    {
        $sql = $this->compile(new Blueprint('t', function (Blueprint $t): void {
            $t->float('ratio');
        }))[0];

        self::assertStringContainsString('"ratio" DOUBLE PRECISION', $sql);
    }

    /**
     * `DATETIME` is not a Postgres type. The mapping also declines a time zone,
     * matching what MySQL DATETIME stores — a wall-clock reading, interpreted
     * by the application rather than the server.
     */
    public function testDatetimeBecomesTimestampWithoutTimeZone(): void
    {
        self::assertStringContainsString(
            '"created_at" TIMESTAMP(6) WITHOUT TIME ZONE',
            $this->documentsSql()
        );
    }

    /** Postgres TEXT is unbounded, so the longText hint has nothing to change. */
    public function testTextIsUnboundedRegardlessOfLengthHint(): void
    {
        $sql = $this->compile(new Blueprint('t', function (Blueprint $t): void {
            $t->text('body');
            $t->longText('archive');
        }))[0];

        self::assertStringContainsString('"body" TEXT', $sql);
        self::assertStringContainsString('"archive" TEXT', $sql);
        self::assertStringNotContainsString('LONGTEXT', $sql, 'that is a MySQL type');
    }

    public function testEnumBecomesTextWithACheckConstraint(): void
    {
        self::assertStringContainsString(
            '"status" TEXT NOT NULL DEFAULT \'draft\' CHECK ("status" IN (\'draft\', \'active\'))',
            $this->documentsSql()
        );
    }

    /**
     * Postgres has no unsigned integers. Blueprint::id() asks for one; the
     * grammar drops the flag rather than failing.
     *
     * Consequence worth knowing: a MySQL BIGINT UNSIGNED holds values up to
     * 2^64-1, a Postgres BIGINT only to 2^63-1. Ids above that do not port.
     */
    public function testUnsignedIsDroppedBecausePostgresHasNoSuchThing(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('"id" BIGINT', $sql);
        self::assertStringNotContainsString('UNSIGNED', $sql);
    }

    // ─── Autoincrement ───

    /**
     * `AUTO_INCREMENT` is MySQL syntax and `SERIAL` is the legacy Postgres
     * spelling that leaves an orphaned sequence behind on DROP COLUMN. Identity
     * columns are the SQL-standard successor and are what PG 10+ recommends.
     */
    public function testAutoIncrementBecomesAnIdentityColumn(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('"id" BIGINT GENERATED ALWAYS AS IDENTITY', $sql);
        self::assertStringNotContainsString('AUTO_INCREMENT', $sql);
        self::assertStringNotContainsString('SERIAL', $sql);
    }

    /**
     * Pinned because it is a portability edge, not an accident.
     *
     * `GENERATED ALWAYS` refuses an INSERT that supplies its own id — the same
     * statement that works on MySQL and SQLite errors here unless the caller
     * writes `OVERRIDING SYSTEM VALUE`. That bites on data imports and on
     * fixtures that pin ids.
     *
     * `GENERATED BY DEFAULT` would accept both, at the cost of letting an
     * application desynchronise the sequence. SQL Server's IDENTITY behaves
     * like ALWAYS, so the dialects do not agree either way.
     */
    public function testIdentityIsAlwaysNotByDefault(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('GENERATED ALWAYS AS IDENTITY', $sql);
        self::assertStringNotContainsString('GENERATED BY DEFAULT', $sql);
    }

    // ─── Nullability ───

    /**
     * Columns are nullable unless said otherwise, so Postgres needs no NULL
     * keyword — and adding one where a DEFAULT follows changes nothing but
     * noise.
     */
    public function testNullableColumnsCarryNoNullKeyword(): void
    {
        $sql = $this->documentsSql();

        self::assertStringContainsString('"body" TEXT,', $sql);
        self::assertStringNotContainsString('TEXT NULL', $sql);
    }

    public function testNotNullIsStillSpelledOut(): void
    {
        self::assertStringContainsString('"title" VARCHAR(120) NOT NULL', $this->documentsSql());
    }

    // ─── Comments ───

    /**
     * Postgres has no inline COMMENT clause — MySQL's `... COMMENT 'x'` is a
     * syntax error here. Comments travel as their own statements, which is why
     * compileCreateTable returns a list.
     */
    public function testCommentsBecomeSeparateStatements(): void
    {
        $bp = new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 120)->comment('Judul dokumen');
        });
        $bp->comment('Dokumen yang dihasilkan');

        $sqls = $this->compile($bp);

        self::assertStringNotContainsString('COMMENT', $sqls[0], 'not inline');
        self::assertContains(
            'COMMENT ON TABLE "ezdoc_documents" IS \'Dokumen yang dihasilkan\'',
            $sqls
        );
        self::assertContains(
            'COMMENT ON COLUMN "ezdoc_documents"."title" IS \'Judul dokumen\'',
            $sqls
        );
    }

    public function testACommentContainingAQuoteIsEscaped(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->id();
        });
        $bp->comment("Milik user's");

        self::assertContains('COMMENT ON TABLE "t" IS \'Milik user\'\'s\'', $this->compile($bp));
    }

    // ─── Keys and indexes ───

    public function testThePrimaryKeyIsADeclaredConstraint(): void
    {
        self::assertStringContainsString('PRIMARY KEY ("id")', $this->documentsSql());
    }

    public function testUniqueIsInlineWithANamedConstraint(): void
    {
        self::assertStringContainsString(
            'CONSTRAINT "uniq_ezdoc_documents_public_id" UNIQUE ("public_id")',
            $this->documentsSql()
        );
    }

    /** Postgres has no inline index syntax; KEY(...) inside CREATE TABLE fails. */
    public function testNonUniqueIndexesAreEmittedSeparately(): void
    {
        $sqls = $this->compile(new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 20);
            $t->string('title', 120);
            $t->index(['status', 'title'], 'idx_status_title');
        }));

        self::assertCount(2, $sqls);
        self::assertStringNotContainsString('KEY "idx_status_title"', $sqls[0]);
        self::assertSame(
            'CREATE INDEX "idx_status_title" ON "ezdoc_documents" ("status", "title")',
            $sqls[1]
        );
    }

    public function testCompositePrimaryKeysAreEmitted(): void
    {
        $sql = $this->compile(new Blueprint('ezdoc_document_tags', function (Blueprint $t): void {
            $t->bigint('document_id');
            $t->string('tag', 40);
            $t->primary(['document_id', 'tag']);
        }))[0];

        self::assertStringContainsString('PRIMARY KEY ("document_id", "tag")', $sql);
    }

    public function testAUniqueIndexCompiledOnItsOwnCarriesTheUniqueKeyword(): void
    {
        $sql = $this->grammar->compileCreateIndex(
            'ezdoc_documents',
            new IndexDef(['public_id'], IndexDef::KIND_UNIQUE, 'uniq_public_id')
        );

        self::assertSame('CREATE UNIQUE INDEX "uniq_public_id" ON "ezdoc_documents" ("public_id")', $sql);
    }

    // ─── Foreign keys ───

    public function testForeignKeysAreInlineWithReferentialActions(): void
    {
        $sql = $this->compile(new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->references('id')->on('ezdoc_templates')->cascadeOnDelete();
        }))[0];

        self::assertStringContainsString(
            'FOREIGN KEY ("template_id") REFERENCES "ezdoc_templates" ("id") ON DELETE CASCADE',
            $sql
        );
    }

    public function testANamedForeignKeyBecomesANamedConstraint(): void
    {
        $fk = (new ForeignKeyDef(['template_id'], 'ezdoc_templates', ['id']))
            ->name('fk_dokumen_template')
            ->nullOnDelete()
            ->cascadeOnUpdate();

        self::assertSame(
            'CONSTRAINT "fk_dokumen_template" FOREIGN KEY ("template_id") '
            . 'REFERENCES "ezdoc_templates" ("id") ON DELETE SET NULL ON UPDATE CASCADE',
            $this->grammar->compileForeignKey($fk)
        );
    }

    // ─── Paging ───

    /** Postgres accepts OFFSET on its own, unlike SQLite. */
    public function testABareOffsetNeedsNoLimit(): void
    {
        self::assertSame('OFFSET 100', $this->grammar->compileLimit(null, 100));
    }

    public function testLimitAndOffsetUseTheStandardSpelling(): void
    {
        self::assertSame('LIMIT 25', $this->grammar->compileLimit(25));
        self::assertSame('LIMIT 25 OFFSET 50', $this->grammar->compileLimit(25, 50));
    }

    // ─── Table options ───

    public function testIfNotExistsIsSupported(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->id();
        });
        $bp->ifNotExists();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "t"', $this->compile($bp)[0]);
    }

    /**
     * ENGINE and CHARSET are MySQL storage options with no Postgres equivalent.
     * Emitting them would make the statement unparseable.
     */
    public function testMysqlTableOptionsAreNotEmitted(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->id();
        });
        $bp->engine('InnoDB')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');

        $sql = $this->compile($bp)[0];

        self::assertStringNotContainsString('ENGINE', $sql);
        self::assertStringNotContainsString('CHARSET', $sql);
    }

    public function testDropTable(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS "ezdoc_documents"',
            $this->grammar->compileDropTable('ezdoc_documents')
        );
    }

    public function testFeatureFlags(): void
    {
        self::assertTrue($this->grammar->supportsNativeJson());
        self::assertTrue($this->grammar->supportsNativeUuid());
        self::assertTrue($this->grammar->supportsSavepoints());
    }
}
