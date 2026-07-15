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
 * SQLite 3.9+ SQL grammar.
 *
 * Concrete Grammar impl untuk SQLite. Unlock `App::demo()` zero-config mode
 * (SQLite = single-file DB, no daemon).
 *
 * ## SQLite quirks yang harus di-handle
 *
 * - **INTEGER PRIMARY KEY AUTOINCREMENT** wajib inline, tidak boleh terpisah
 *   `PRIMARY KEY(col)` clause. Kita special-case autoInc+primary.
 * - **UNIQUE inline** OK. Regular index HARUS via separate `CREATE INDEX`.
 * - **JSON** — pakai `TEXT` (SQLite 3.9+ punya JSON1 extension bundled),
 *   optional CHECK (json_valid(col)) untuk validation.
 * - **UUID** — pakai `TEXT` (36-char canonical form).
 * - **BOOLEAN** — pakai `INTEGER` (0/1 convention).
 * - **ENUM** — pakai `TEXT` + `CHECK (col IN ('a','b','c'))`.
 * - **FOREIGN KEY** — DDL support, tapi enforcement butuh `PRAGMA foreign_keys=ON`
 *   di connection level (bukan urusan Grammar).
 * - **DECIMAL** — pakai `NUMERIC` affinity (SQLite type system loose).
 * - **AUTO_INCREMENT** SQLite hanya works di column INTEGER PRIMARY KEY.
 *   Untuk BIGINT AUTO_INCREMENT dari Blueprint::id(), kita degrade ke INTEGER
 *   (SQLite INTEGER always 64-bit anyway, alias untuk rowid).
 *
 * Double-quote untuk identifier (SQL standard), single-quote untuk string.
 *
 * @implementation-notes SQL formulas studied dari Doctrine DBAL SqlitePlatform
 *   (MIT). Reimplemented from spec.
 */
class SqliteGrammar implements Grammar
{
    public function name(): string { return 'sqlite'; }

    public function wrapIdentifier(string $identifier): string
    {
        if (strpos($identifier, '.') !== false) {
            $parts = explode('.', $identifier);
            return implode('.', array_map([$this, 'wrapSegment'], $parts));
        }
        return $this->wrapSegment($identifier);
    }

    private function wrapSegment(string $seg): string
    {
        // Escape double-quote via doubling (SQL standard)
        return '"' . str_replace('"', '""', $seg) . '"';
    }

    public function quoteString(string $value): string
    {
        // SQL standard: escape single-quote via doubling
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function mapType(Type $type, ColumnDef $col): string
    {
        // Special-case: autoInc primary → INTEGER (bukan BIGINT); SQLite
        // requires exact "INTEGER PRIMARY KEY [AUTOINCREMENT]" untuk rowid alias
        if ($col->isAutoIncrement() && $col->isPrimary()) {
            return 'INTEGER';
        }

        switch ($type->name()) {
            case 'string':
                $len = $col->getLength() ?? 255;
                return "VARCHAR($len)";

            case 'text':
                return 'TEXT';

            case 'integer':
                return 'INTEGER';

            case 'bigint':
                return 'BIGINT'; // SQLite treats as INTEGER affinity anyway

            case 'boolean':
                return 'INTEGER';

            case 'json':
                return 'TEXT';

            case 'uuid':
                return 'TEXT';

            case 'datetime':
                return 'DATETIME'; // stored as TEXT via SQLite date functions

            case 'date':
                return 'DATE';

            case 'time':
                return 'TIME';

            case 'decimal':
                $p = $col->getPrecision() ?? 10;
                $s = $col->getScale() ?? 0;
                return "NUMERIC($p, $s)";

            case 'float':
                return 'REAL';

            case 'blob':
                return 'BLOB';

            case 'enum':
                // SQLite enum → TEXT + CHECK constraint (di compileColumn)
                return 'TEXT';

            default:
                throw new SchemaException(
                    "SqliteGrammar: unknown type '{$type->name()}' untuk column '{$col->getName()}'"
                );
        }
    }

    public function compileCreateTable(Blueprint $blueprint): array
    {
        $sqls = [];
        $parts = [];

        // Columns
        foreach ($blueprint->getColumns() as $col) {
            $parts[] = '  ' . $this->compileColumn($col);
        }

        // Composite/deferred PRIMARY KEY only if bukan sudah handled via INTEGER PRIMARY KEY inline.
        $primaryCols = $this->collectPrimaryColumns($blueprint);
        $inlinePkExists = false;
        foreach ($blueprint->getColumns() as $col) {
            if ($col->isAutoIncrement() && $col->isPrimary()) {
                $inlinePkExists = true;
                break;
            }
        }
        if (!$inlinePkExists && $primaryCols !== []) {
            $parts[] = '  PRIMARY KEY (' . implode(', ', array_map(
                [$this, 'wrapIdentifier'],
                $primaryCols
            )) . ')';
        }

        // Unique inline (SQLite support UNIQUE(col) di CREATE TABLE)
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->isPrimary()) continue;
            if ($idx->isUnique()) {
                $cols = implode(', ', array_map([$this, 'wrapIdentifier'], $idx->getColumns()));
                // Name di UNIQUE constraint via CONSTRAINT clause
                $name = $idx->getName() ?? $this->autoIndexName($blueprint->getName(), $idx);
                $parts[] = '  CONSTRAINT ' . $this->wrapIdentifier($name) . " UNIQUE ($cols)";
            }
        }

        // Foreign keys inline
        foreach ($blueprint->getForeignKeys() as $fk) {
            $parts[] = '  ' . $this->compileForeignKey($fk);
        }

        $ifNotExists = $blueprint->isIfNotExists() ? 'IF NOT EXISTS ' : '';
        $temporary = $blueprint->isTemporary() ? 'TEMPORARY ' : '';
        $table = $this->wrapIdentifier($blueprint->getName());

        $sqls[] = "CREATE {$temporary}TABLE {$ifNotExists}$table (\n"
            . implode(",\n", $parts)
            . "\n)";

        // Regular (non-unique) indexes via separate CREATE INDEX
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->isPrimary() || $idx->isUnique()) continue;
            $sqls[] = $this->compileCreateIndex($blueprint->getName(), $idx);
        }

        return $sqls;
    }

    private function compileColumn(ColumnDef $col): string
    {
        $type = $this->mapTypeInline($col);
        $out = $this->wrapIdentifier($col->getName()) . ' ' . $type;

        // INTEGER PRIMARY KEY AUTOINCREMENT special path
        if ($col->isAutoIncrement() && $col->isPrimary()) {
            $out .= ' PRIMARY KEY AUTOINCREMENT';
            // SQLite auto-pk selalu NOT NULL implicit
            return $out;
        }

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

        // Enum: TEXT + CHECK (col IN ('a','b','c'))
        if ($col->getType() === 'enum') {
            $vals = $col->getEnumValues();
            if ($vals !== null && $vals !== []) {
                $quoted = array_map(function ($v) { return $this->quoteString((string) $v); }, $vals);
                $colRef = $this->wrapIdentifier($col->getName());
                $out .= ' CHECK (' . $colRef . ' IN (' . implode(', ', $quoted) . '))';
            }
        }

        // JSON: optional validation via json_valid (SQLite 3.9+ JSON1 ext)
        if ($col->getType() === 'json') {
            $colRef = $this->wrapIdentifier($col->getName());
            $nullClause = $col->isNullable() ? "$colRef IS NULL OR " : '';
            $out .= " CHECK ($nullClause" . "json_valid($colRef))";
        }

        return $out;
    }

    /** Wrap ColumnDef->type into Type stub untuk mapType() dispatch. */
    private function mapTypeInline(ColumnDef $col): string
    {
        $type = new class ($col->getType()) implements Type {
            private $n;
            public function __construct(string $n) { $this->n = $n; }
            public function name(): string { return $this->n; }
            public function toPhp($v) { return $v; }
            public function toDb($v)  { return $v; }
        };
        return $this->mapType($type, $col);
    }

    private function collectPrimaryColumns(Blueprint $blueprint): array
    {
        $cols = [];
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->isPrimary()) {
                foreach ($idx->getColumns() as $c) $cols[] = $c;
            }
        }
        foreach ($blueprint->getColumns() as $col) {
            if ($col->isPrimary() && !in_array($col->getName(), $cols, true)) {
                $cols[] = $col->getName();
            }
        }
        return array_values(array_unique($cols));
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

    public function compileLimit(?int $limit, int $offset = 0): string
    {
        if ($limit === null && $offset === 0) return '';
        if ($limit === null) return "LIMIT -1 OFFSET $offset"; // -1 = no limit di SQLite
        $sql = "LIMIT $limit";
        if ($offset > 0) $sql .= " OFFSET $offset";
        return $sql;
    }

    public function supportsNativeJson(): bool  { return false; }
    public function supportsNativeUuid(): bool  { return false; }
    public function supportsNativeEnum(): bool  { return false; }
    public function supportsSavepoints(): bool  { return true; }
}
