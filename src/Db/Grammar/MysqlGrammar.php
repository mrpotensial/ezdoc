<?php

declare(strict_types=1);

namespace Ezdoc\Db\Grammar;

use Ezdoc\Db\Exception\SchemaException;
use Ezdoc\Db\Schema\Blueprint;
use Ezdoc\Db\Schema\ColumnDef;
use Ezdoc\Db\Schema\ForeignKeyDef;
use Ezdoc\Db\Schema\IndexDef;
use Ezdoc\Db\Types\Type;

/**
 * MySQL 5.7+ / 8.0 SQL grammar.
 *
 * Concrete Grammar impl untuk MySQL. Support:
 *   - Native JSON type (5.7.8+)
 *   - Native ENUM
 *   - Native UUID via CHAR(36) — 8.0 punya UUID_TO_BIN() tapi belum native type
 *   - CHAR/VARCHAR utf8mb4
 *   - InnoDB default (transactional + FK)
 *
 * Backtick untuk identifier, single-quote untuk string literal.
 *
 * @implementation-notes SQL formulas studied dari Doctrine DBAL MySQL80Platform
 *   (MIT) + Laravel Illuminate\Database\Schema\Grammars\MySqlGrammar (MIT).
 *   Reimplemented from spec, no vendored code. Behavior validated via
 *   tests/Db/Grammar/MysqlGrammarTest.php (docker-compose spins up mysql:8).
 */
class MysqlGrammar implements Grammar
{
    public function name(): string { return 'mysql'; }

    // ========================================================================
    // Identifier + literal quoting
    // ========================================================================

    public function wrapIdentifier(string $identifier): string
    {
        // Handle dot-notation `schema.table` atau `table.column`
        if (strpos($identifier, '.') !== false) {
            $parts = explode('.', $identifier);
            return implode('.', array_map([$this, 'wrapSegment'], $parts));
        }
        return $this->wrapSegment($identifier);
    }

    private function wrapSegment(string $seg): string
    {
        // Escape backtick di dalam nama (jarang, tapi safety)
        return '`' . str_replace('`', '``', $seg) . '`';
    }

    public function quoteString(string $value): string
    {
        // MySQL single-quote string dgn backslash escape.
        // NOTE: kalau ANSI_QUOTES sql_mode aktif, quoting berubah — but ezdoc
        // assume default sql_mode. Consumer yang enable ANSI_QUOTES bertanggung
        // jawab set sesuai.
        return "'" . addcslashes($value, "\\'\0") . "'";
    }

    // ========================================================================
    // Type mapping — Type + ColumnDef → SQL column type declaration
    // ========================================================================

    public function mapType(Type $type, ColumnDef $col): string
    {
        switch ($type->name()) {
            case 'string':
                $len = $col->getLength() ?? 255;
                return "VARCHAR($len)";

            case 'text':
                $len = $col->getLength();
                if ($len !== null) {
                    if ($len > 16777215) return 'LONGTEXT';
                    if ($len > 65535)    return 'MEDIUMTEXT';
                    if ($len > 255)      return 'TEXT';
                    return "VARCHAR($len)";
                }
                return 'TEXT';

            case 'integer':
                return $col->isUnsigned() ? 'INT UNSIGNED' : 'INT';

            case 'bigint':
                return $col->isUnsigned() ? 'BIGINT UNSIGNED' : 'BIGINT';

            case 'boolean':
                // TINYINT(1) — MySQL convention for booleans (per DBAL + Laravel)
                return 'TINYINT(1)';

            case 'json':
                return 'JSON';

            case 'uuid':
                return 'CHAR(36)';

            case 'datetime':
                return 'DATETIME';

            case 'date':
                return 'DATE';

            case 'time':
                return 'TIME';

            case 'decimal':
                $p = $col->getPrecision() ?? 10;
                $s = $col->getScale() ?? 0;
                return "DECIMAL($p, $s)";

            case 'float':
                return 'DOUBLE';

            case 'blob':
                return 'LONGBLOB';

            case 'enum':
                $values = $col->getEnumValues();
                if ($values === null || $values === []) {
                    throw new SchemaException(
                        "MySQL enum column '{$col->getName()}' butuh enumValues"
                    );
                }
                $quoted = array_map(function ($v) { return $this->quoteString((string) $v); }, $values);
                return 'ENUM(' . implode(', ', $quoted) . ')';

            default:
                throw new SchemaException(
                    "MysqlGrammar: unknown type '{$type->name()}' untuk column '{$col->getName()}'"
                );
        }
    }

    // ========================================================================
    // DDL compilation — Blueprint → CREATE TABLE SQL
    // ========================================================================

    public function compileCreateTable(Blueprint $blueprint): array
    {
        $parts = [];

        // Columns
        foreach ($blueprint->getColumns() as $col) {
            $parts[] = '  ' . $this->compileColumn($col);
        }

        // Primary key (composite atau shorthand-collected)
        $primaryColumns = $this->collectPrimaryColumns($blueprint);
        if ($primaryColumns !== []) {
            $parts[] = '  PRIMARY KEY (' . implode(', ', array_map(
                [$this, 'wrapIdentifier'],
                $primaryColumns
            )) . ')';
        }

        // Unique + regular indexes (inline)
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->isPrimary()) continue; // already handled above
            $parts[] = '  ' . $this->compileIndexInline($blueprint->getName(), $idx);
        }

        // Foreign keys
        foreach ($blueprint->getForeignKeys() as $fk) {
            $parts[] = '  ' . $this->compileForeignKey($fk);
        }

        $ifNotExists = $blueprint->isIfNotExists() ? 'IF NOT EXISTS ' : '';
        $temporary = $blueprint->isTemporary() ? 'TEMPORARY ' : '';
        $table = $this->wrapIdentifier($blueprint->getName());

        $sql = "CREATE {$temporary}TABLE {$ifNotExists}$table (\n"
            . implode(",\n", $parts)
            . "\n)";

        // Table options
        $opts = [];
        $engine = $blueprint->getEngine() ?? 'InnoDB';
        $opts[] = "ENGINE=$engine";
        $charset = $blueprint->getCharset() ?? 'utf8mb4';
        $opts[] = "DEFAULT CHARSET=$charset";
        if (($coll = $blueprint->getCollation()) !== null) {
            $opts[] = "COLLATE=$coll";
        }
        if (($cmt = $blueprint->getComment()) !== null) {
            $opts[] = "COMMENT=" . $this->quoteString($cmt);
        }
        if ($opts !== []) $sql .= ' ' . implode(' ', $opts);

        return [$sql];
    }

    /**
     * Compile satu column ke fragment: `` `col` TYPE [UNSIGNED] NULL|NOT NULL DEFAULT ... AUTO_INCREMENT COMMENT '...' ``
     */
    private function compileColumn(ColumnDef $col): string
    {
        $type = $this->mapTypeByName($col);
        $out = $this->wrapIdentifier($col->getName()) . ' ' . $type;

        // Nullability
        $out .= $col->isNullable() ? ' NULL' : ' NOT NULL';

        // Default
        if ($col->getDefaultRaw() !== null) {
            $out .= ' DEFAULT ' . $col->getDefaultRaw();
        } elseif ($col->hasDefault()) {
            $default = $col->getDefault();
            if ($default === null) {
                $out .= ' DEFAULT NULL';
            } elseif (is_bool($default)) {
                $out .= ' DEFAULT ' . ($default ? '1' : '0');
            } elseif (is_int($default) || is_float($default)) {
                $out .= ' DEFAULT ' . $default;
            } else {
                $out .= ' DEFAULT ' . $this->quoteString((string) $default);
            }
        }

        // Autoincrement
        if ($col->isAutoIncrement()) {
            $out .= ' AUTO_INCREMENT';
        }

        // Column collation/charset (per-column override)
        if (($cs = $col->getCharset()) !== null)   $out .= " CHARACTER SET $cs";
        if (($col_c = $col->getCollation()) !== null) $out .= " COLLATE $col_c";

        // Comment
        if (($cmt = $col->getComment()) !== null) {
            $out .= ' COMMENT ' . $this->quoteString($cmt);
        }

        return $out;
    }

    /** Helper — get Type from TypeRegistry not needed here; use ColumnDef->type directly. */
    private function mapTypeByName(ColumnDef $col): string
    {
        // Build minimal Type stub with name() untuk pass ke mapType.
        // Alternatively bisa inject TypeRegistry — tapi Grammar tidak butuh
        // full Type impl untuk mapping (hanya butuh canonical name).
        $type = new class ($col->getType()) implements Type {
            private $n;
            public function __construct(string $n) { $this->n = $n; }
            public function name(): string { return $this->n; }
            public function toPhp($v) { return $v; }
            public function toDb($v)  { return $v; }
        };
        return $this->mapType($type, $col);
    }

    /**
     * Collect primary-key columns dari:
     *   1. Explicit `primary(['a','b'])` di Blueprint
     *   2. Column shorthand `->primary()` di ColumnDef
     */
    private function collectPrimaryColumns(Blueprint $blueprint): array
    {
        $cols = [];
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->isPrimary()) {
                foreach ($idx->getColumns() as $c) $cols[] = $c;
            }
        }
        // Fallback: also scan columns for isPrimary() shorthand (id() sets primary=true)
        foreach ($blueprint->getColumns() as $col) {
            if ($col->isPrimary() && !in_array($col->getName(), $cols, true)) {
                $cols[] = $col->getName();
            }
        }
        return array_values(array_unique($cols));
    }

    private function compileIndexInline(string $tableName, IndexDef $idx): string
    {
        $cols = implode(', ', array_map([$this, 'wrapIdentifier'], $idx->getColumns()));
        $name = $idx->getName() ?? $this->autoIndexName($tableName, $idx);

        if ($idx->isUnique()) {
            return 'UNIQUE KEY ' . $this->wrapIdentifier($name) . " ($cols)";
        }
        return 'KEY ' . $this->wrapIdentifier($name) . " ($cols)";
    }

    private function autoIndexName(string $tableName, IndexDef $idx): string
    {
        $prefix = $idx->isUnique() ? 'uniq' : 'idx';
        return $prefix . '_' . $tableName . '_' . implode('_', $idx->getColumns());
    }

    public function compileForeignKey(ForeignKeyDef $fk): string
    {
        $cols = implode(', ', array_map([$this, 'wrapIdentifier'], $fk->getColumns()));
        $foreignCols = implode(', ', array_map([$this, 'wrapIdentifier'], $fk->getForeignColumns()));
        $foreignTable = $this->wrapIdentifier($fk->getForeignTable());

        $sql = "FOREIGN KEY ($cols) REFERENCES $foreignTable ($foreignCols)";
        if (($od = $fk->getOnDelete()) !== null) $sql .= ' ON DELETE ' . strtoupper($od);
        if (($ou = $fk->getOnUpdate()) !== null) $sql .= ' ON UPDATE ' . strtoupper($ou);

        // Kalau ada explicit name, prepend CONSTRAINT
        if (($name = $fk->getName()) !== null) {
            $sql = 'CONSTRAINT ' . $this->wrapIdentifier($name) . ' ' . $sql;
        }
        return $sql;
    }

    public function compileCreateIndex(string $tableName, IndexDef $index): string
    {
        $cols = implode(', ', array_map([$this, 'wrapIdentifier'], $index->getColumns()));
        $name = $index->getName() ?? $this->autoIndexName($tableName, $index);
        $unique = $index->isUnique() ? 'UNIQUE ' : '';
        return "CREATE {$unique}INDEX " . $this->wrapIdentifier($name)
            . ' ON ' . $this->wrapIdentifier($tableName)
            . " ($cols)";
    }

    public function compileDropTable(string $tableName, bool $ifExists = true): string
    {
        $ifClause = $ifExists ? 'IF EXISTS ' : '';
        return "DROP TABLE {$ifClause}" . $this->wrapIdentifier($tableName);
    }

    // ========================================================================
    // Feature flags
    // ========================================================================

    public function supportsNativeJson(): bool  { return true; }
    public function supportsNativeUuid(): bool  { return false; }
    public function supportsNativeEnum(): bool  { return true; }
    public function supportsSavepoints(): bool  { return true; }
}
