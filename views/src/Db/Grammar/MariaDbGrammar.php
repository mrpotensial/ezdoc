<?php

declare(strict_types=1);

namespace Ezdoc\Db\Grammar;

use Ezdoc\Db\Schema\ColumnDef;
use Ezdoc\Db\Types\Type;

/**
 * MariaDB 10.3+ SQL grammar.
 *
 * Extends MysqlGrammar — dialect overlap ~95%. Differences:
 *
 * - **JSON type** — MariaDB 10.2+ JSON keyword adalah alias untuk LONGTEXT
 *   + JSON_VALID CHECK constraint. Semantic == MySQL kalau pakai `JSON`, tapi
 *   underlying storage berbeda (LONGTEXT vs native binary).
 * - **JSON functions** — mostly compatible (JSON_EXTRACT, JSON_SET, etc)
 *   dgn beberapa naming difference yg jarang di-use di Repository ezdoc.
 * - **UUID type native** (MariaDB 10.7+) — untuk sekarang kita tetap CHAR(36)
 *   supaya compat dgn 10.3-10.6 (majoritas prod install).
 * - **CHECK constraint** — MariaDB 10.2+ enforces properly (MySQL 5.7 tidak
 *   enforce, hanya syntax-accept).
 * - **SEQUENCE object** (MariaDB 10.3+) — Postgres-style native. Kita tidak
 *   pakai (AUTO_INCREMENT cukup untuk ezdoc use case).
 *
 * Untuk v0.9.9 W2, difference dgn MysqlGrammar hanya:
 *   - `name()` return 'mariadb'
 *   - Comment header
 *
 * SQL emission identical. Kalau consumer butuh MariaDB-specific overrides
 * di masa depan, override method spesifik.
 *
 * @implementation-notes Extends MysqlGrammar. MariaDB fork story: identifier
 *   quoting + AUTO_INCREMENT + InnoDB defaults all consistent. Divergence
 *   points terisolir (JSON storage, UUID native 10.7+) — tidak affect DDL
 *   emit di baseline v0.9.9.
 */
class MariaDbGrammar extends MysqlGrammar
{
    public function name(): string { return 'mariadb'; }

    // Override placeholder — kalau v0.9.10+ butuh JSON-specific tweak,
    // uncomment + custom emit. Untuk sekarang inherit MysqlGrammar mapType.
    //
    // public function mapType(Type $type, ColumnDef $col): string
    // {
    //     if ($type->name() === 'json') {
    //         return 'LONGTEXT';  // MariaDB pre-10.2 style
    //     }
    //     return parent::mapType($type, $col);
    // }
}
