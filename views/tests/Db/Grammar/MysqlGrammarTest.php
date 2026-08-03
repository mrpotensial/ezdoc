<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Db\Grammar;

use Ezdoc\Db\Grammar\MysqlGrammar;
use Ezdoc\Db\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * MysqlGrammar smoke tests — validate Blueprint → SQL emission.
 *
 * Focus di correctness DDL output (string matching). Full integration test
 * (execute vs real mysql:8 container) di tests/integration/ nanti (v0.9.9 W2).
 *
 * PHP 7.4+ compatible — data provider array untuk cover edge cases.
 */
final class MysqlGrammarTest extends TestCase
{
    /** @var MysqlGrammar */
    private $grammar;

    protected function setUp(): void
    {
        $this->grammar = new MysqlGrammar();
    }

    // ------------------------------------------------------------------------
    // Identifier + literal quoting
    // ------------------------------------------------------------------------

    public function testWrapIdentifierBacktick(): void
    {
        self::assertSame('`foo`', $this->grammar->wrapIdentifier('foo'));
    }

    public function testWrapIdentifierDotNotation(): void
    {
        self::assertSame('`ezdoc`.`documents`', $this->grammar->wrapIdentifier('ezdoc.documents'));
    }

    public function testWrapIdentifierEscapeBacktick(): void
    {
        self::assertSame('`foo``bar`', $this->grammar->wrapIdentifier('foo`bar'));
    }

    public function testQuoteStringBasic(): void
    {
        self::assertSame("'hello'", $this->grammar->quoteString('hello'));
    }

    public function testQuoteStringEscapeSingleQuote(): void
    {
        self::assertSame("'it\\'s'", $this->grammar->quoteString("it's"));
    }

    public function testQuoteStringEscapeBackslash(): void
    {
        self::assertSame("'a\\\\b'", $this->grammar->quoteString('a\\b'));
    }

    // ------------------------------------------------------------------------
    // Type mapping via full Blueprint compile
    // ------------------------------------------------------------------------

    public function testCreateSimpleTable(): void
    {
        $bp = new Blueprint('users', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('email')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $sqls = $this->grammar->compileCreateTable($bp);
        self::assertCount(1, $sqls);
        $sql = $sqls[0];

        // Core column types
        self::assertStringContainsString('`id` BIGINT UNSIGNED', $sql);
        self::assertStringContainsString('AUTO_INCREMENT', $sql);
        self::assertStringContainsString('`name` VARCHAR(100) NOT NULL', $sql);
        self::assertStringContainsString('`email` VARCHAR(255) NOT NULL', $sql);
        self::assertStringContainsString('`active` TINYINT(1) NOT NULL DEFAULT 1', $sql);

        // Timestamps
        self::assertStringContainsString('`created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP', $sql);
        self::assertStringContainsString('`updated_at` DATETIME NULL', $sql);

        // Primary key composite output
        self::assertStringContainsString('PRIMARY KEY (`id`)', $sql);

        // Unique key (from ->unique() shorthand on email)
        self::assertMatchesRegularExpression('/UNIQUE KEY `[^`]+` \(`email`\)/', $sql);

        // Engine + charset defaults
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
    }

    public function testJsonAndUuidAndEnum(): void
    {
        $bp = new Blueprint('templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->json('metadata')->nullable();
            $t->enum('status', ['draft', 'active', 'archived'])->default('draft');
        });

        $sql = $this->grammar->compileCreateTable($bp)[0];
        self::assertStringContainsString('`uuid` CHAR(36) NOT NULL', $sql);
        self::assertStringContainsString('`metadata` JSON NULL', $sql);
        self::assertStringContainsString("`status` ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft'", $sql);
    }

    public function testForeignKeyCascade(): void
    {
        $bp = new Blueprint('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')
              ->references('id')->on('templates')
              ->cascadeOnDelete();
        });

        $sql = $this->grammar->compileCreateTable($bp)[0];
        self::assertStringContainsString('`template_id` BIGINT UNSIGNED', $sql);
        self::assertStringContainsString(
            'FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE',
            $sql
        );
    }

    public function testCompositeIndex(): void
    {
        $bp = new Blueprint('audits', function (Blueprint $t) {
            $t->id();
            $t->string('action');
            $t->integer('user_id');
            $t->index(['user_id', 'action'], 'idx_user_action');
        });

        $sql = $this->grammar->compileCreateTable($bp)[0];
        self::assertStringContainsString(
            'KEY `idx_user_action` (`user_id`, `action`)',
            $sql
        );
    }

    public function testDropTable(): void
    {
        self::assertSame(
            'DROP TABLE IF EXISTS `users`',
            $this->grammar->compileDropTable('users')
        );
        self::assertSame(
            'DROP TABLE `users`',
            $this->grammar->compileDropTable('users', false)
        );
    }

    // ------------------------------------------------------------------------
    // Feature flags
    // ------------------------------------------------------------------------

    public function testFeatureFlags(): void
    {
        self::assertTrue($this->grammar->supportsNativeJson());
        self::assertFalse($this->grammar->supportsNativeUuid());
        self::assertTrue($this->grammar->supportsNativeEnum());
        self::assertTrue($this->grammar->supportsSavepoints());
    }
}
