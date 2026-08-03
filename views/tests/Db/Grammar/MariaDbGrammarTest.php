<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db\Grammar;

use Ezdoc\Db\Grammar\MariaDbGrammar;
use Ezdoc\Db\Grammar\MysqlGrammar;
use Ezdoc\Db\Schema\Blueprint;
use Ezdoc\Db\Schema\ForeignKeyDef;
use Ezdoc\Db\Schema\IndexDef;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * MariaDbGrammar exists to answer to a different name while emitting the same
 * SQL as MysqlGrammar. That is the whole contract, and it is worth testing
 * precisely because it looks like it needs no test.
 *
 * The risk is one-directional. A MariaDB-specific override added later — for
 * the JSON storage difference, or native UUID in 10.7+ — is a real change with
 * real consequences, and it should be a decision someone makes on purpose.
 * These tests fail when the two diverge, so the divergence has to be declared
 * here rather than discovered on a customer's database.
 */
final class MariaDbGrammarTest extends TestCase
{
    private MariaDbGrammar $mariadb;
    private MysqlGrammar $mysql;

    protected function setUp(): void
    {
        $this->mariadb = new MariaDbGrammar();
        $this->mysql = new MysqlGrammar();
    }

    /**
     * Blueprints covering every branch the grammars can take: each type, both
     * nullabilities, every default shape, keys, indexes and table options.
     *
     * @return array<string, Blueprint>
     */
    private function corpus(): array
    {
        $tables = [];

        $tables['every type'] = new Blueprint('ezdoc_kitchen_sink', function (Blueprint $t): void {
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

        $tables['defaults and nullability'] = new Blueprint('t_defaults', function (Blueprint $t): void {
            $t->string('a')->nullable();
            $t->string('b')->default('draft');
            $t->integer('c')->default(0);
            $t->float('d')->default(1.5);
            $t->boolean('e')->default(true);
            $t->boolean('f')->default(false);
            $t->datetime('g')->defaultRaw('CURRENT_TIMESTAMP');
            $t->string('h')->nullable()->default(null);
            $t->string('i')->comment("catatan 'penting'");
        });

        $tables['keys and indexes'] = new Blueprint('ezdoc_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('status', 20)->index();
            $t->string('title', 120);
            $t->foreignId('template_id')->references('id')->on('ezdoc_templates')->cascadeOnDelete();
            $t->index(['status', 'title'], 'idx_status_title');
            $t->unique(['template_id', 'title'], 'uniq_template_title');
            $t->timestamps();
        });

        $tables['composite primary key'] = new Blueprint('ezdoc_document_tags', function (Blueprint $t): void {
            $t->bigint('document_id');
            $t->string('tag', 40);
            $t->primary(['document_id', 'tag']);
        });

        $withOptions = new Blueprint('t_options', function (Blueprint $t): void {
            $t->id();
        });
        $withOptions->engine('MyISAM')->charset('latin1')->collation('latin1_swedish_ci')
            ->comment('tabel arsip')->ifNotExists()->temporary();
        $tables['table options'] = $withOptions;

        $tables['awkward identifiers'] = new Blueprint('ta`bel', function (Blueprint $t): void {
            $t->string('ko`lom', 20);
            $t->string("kutip'satu", 20)->default("nilai'default");
        });

        return $tables;
    }

    // ─── The one intended difference ───

    public function testOnlyTheNameDiffers(): void
    {
        self::assertSame('mariadb', $this->mariadb->name());
        self::assertSame('mysql', $this->mysql->name());
    }

    public function testItIsAMysqlGrammar(): void
    {
        self::assertInstanceOf(
            MysqlGrammar::class,
            $this->mariadb,
            'the dialects overlap enough that MariaDB inherits rather than reimplements'
        );
    }

    /**
     * Guards the premise of every other test here. If MariaDbGrammar starts
     * overriding emission methods, "identical output" stops being something
     * inheritance guarantees, and the assertions below become the only thing
     * holding it — which is the point at which the override needs discussing.
     */
    public function testNoEmissionMethodIsOverridden(): void
    {
        $declared = [];
        foreach ((new ReflectionClass(MariaDbGrammar::class))->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() === MariaDbGrammar::class) {
                $declared[] = $m->getName();
            }
        }
        sort($declared);

        self::assertSame(
            ['name'],
            $declared,
            'MariaDbGrammar overrode ' . implode(', ', $declared)
            . ' — intentional divergence belongs in a test of its own'
        );
    }

    // ─── Everything else must match ───

    public function testDdlIsIdenticalToMysqlAcrossEveryBlueprintShape(): void
    {
        foreach ($this->corpus() as $label => $bp) {
            self::assertSame(
                $this->mysql->compileCreateTable($bp),
                $this->mariadb->compileCreateTable($bp),
                "DDL diverged from MySQL for: {$label}"
            );
        }
    }

    public function testIdentifierQuotingIsIdenticalToMysql(): void
    {
        foreach (['documents', 'ezdoc.documents', 'ta`bel', 'createdAt', 'order'] as $name) {
            self::assertSame(
                $this->mysql->wrapIdentifier($name),
                $this->mariadb->wrapIdentifier($name),
                "quoting diverged for: {$name}"
            );
        }
    }

    /**
     * MariaDB inherits MySQL's backslash escaping, and it has to: both treat
     * `\` as an escape character inside literals unless NO_BACKSLASH_ESCAPES
     * is set.
     */
    public function testStringQuotingIsIdenticalToMysql(): void
    {
        foreach (['draft', "it's", 'C:\\dokumen', "'; DROP TABLE t; --", "baris\nbaru"] as $value) {
            self::assertSame(
                $this->mysql->quoteString($value),
                $this->mariadb->quoteString($value),
                'quoting diverged for a literal'
            );
        }
    }

    public function testPagingIsIdenticalToMysql(): void
    {
        foreach ([[null, 0], [25, 0], [25, 50], [null, 100], [0, 0]] as [$limit, $offset]) {
            self::assertSame(
                $this->mysql->compileLimit($limit, $offset),
                $this->mariadb->compileLimit($limit, $offset),
                "paging diverged for limit={$limit} offset={$offset}"
            );
        }
    }

    public function testDropTableIsIdenticalToMysql(): void
    {
        self::assertSame(
            $this->mysql->compileDropTable('ezdoc_documents'),
            $this->mariadb->compileDropTable('ezdoc_documents')
        );
        self::assertSame(
            $this->mysql->compileDropTable('ezdoc_documents', false),
            $this->mariadb->compileDropTable('ezdoc_documents', false)
        );
    }

    public function testIndexAndForeignKeyCompilationIsIdenticalToMysql(): void
    {
        $index = (new IndexDef(['status', 'title'], IndexDef::KIND_INDEX, 'idx_status_title'));
        self::assertSame(
            $this->mysql->compileCreateIndex('ezdoc_documents', $index),
            $this->mariadb->compileCreateIndex('ezdoc_documents', $index)
        );

        $fk = (new ForeignKeyDef(['template_id'], 'ezdoc_templates', ['id']))
            ->name('fk_dokumen_template')
            ->cascadeOnDelete();
        self::assertSame(
            $this->mysql->compileForeignKey($fk),
            $this->mariadb->compileForeignKey($fk)
        );
    }

    public function testFeatureFlagsAreIdenticalToMysql(): void
    {
        self::assertSame($this->mysql->supportsNativeJson(), $this->mariadb->supportsNativeJson());
        self::assertSame($this->mysql->supportsNativeUuid(), $this->mariadb->supportsNativeUuid());
        self::assertSame($this->mysql->supportsNativeEnum(), $this->mariadb->supportsNativeEnum());
        self::assertSame($this->mysql->supportsSavepoints(), $this->mariadb->supportsSavepoints());
    }

    // ─── Documented divergences that are not yet acted on ───

    /**
     * MariaDB's JSON is an alias for LONGTEXT with a JSON_VALID check, not the
     * binary type MySQL 5.7+ stores. The keyword is accepted by both, so the
     * emitted DDL is portable; only the storage differs.
     *
     * That is why `supportsNativeJson()` still answers true here — the caller's
     * question is whether it can write `JSON`, and it can.
     */
    public function testJsonIsStillEmittedAsTheJsonKeyword(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->json('metadata');
        });

        self::assertStringContainsString('`metadata` JSON', $this->mariadb->compileCreateTable($bp)[0]);
        self::assertTrue($this->mariadb->supportsNativeJson());
    }

    /**
     * MariaDB 10.7 added a native UUID type, but 10.3–10.6 remain the common
     * production versions and do not have it. CHAR(36) works on all of them.
     */
    public function testUuidStaysCharThirtySixForOlderMariaDbSupport(): void
    {
        $bp = new Blueprint('t', function (Blueprint $t): void {
            $t->uuid('public_id');
        });

        self::assertStringContainsString('`public_id` CHAR(36)', $this->mariadb->compileCreateTable($bp)[0]);
        self::assertFalse($this->mariadb->supportsNativeUuid());
    }
}
