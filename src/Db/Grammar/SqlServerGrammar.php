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
 * SQL Server 2019+ SQL grammar.
 *
 * Concrete Grammar impl untuk Microsoft SQL Server / Azure SQL. Focus pada
 * enterprise consumer (beberapa RS pemerintah Indonesia pakai SQL Server via
 * Windows Server stack).
 *
 * ## SQL Server quirks
 *
 * - **Identifier bracket** `[col]` — atau double-quote kalau QUOTED_IDENTIFIER=ON
 *   (bracket lebih safe untuk universal support)
 * - **Autoincrement** — `IDENTITY(1,1)` clause (bukan AUTO_INCREMENT)
 * - **Boolean** — `BIT` (0/1)
 * - **JSON** — NVARCHAR(MAX) + optional CHECK (ISJSON(col) = 1) constraint.
 *   Native JSON type PLANNED (SQL Server 2025 preview) tapi belum GA.
 * - **UUID** — `UNIQUEIDENTIFIER` native (16-byte, case-insensitive compare)
 * - **ENUM** — CHECK constraint (no native)
 * - **DATETIME2** — preferred over DATETIME (better precision + range)
 * - **LIMIT/OFFSET** — pakai `OFFSET N ROWS FETCH NEXT M ROWS ONLY`
 *   (SQL:2008 standard, SQL Server 2012+)
 * - **UPSERT** — MERGE statement (complex, tidak di scope v0.9.9)
 * - **CASCADE** — supported, tapi hanya 1 cycle (multi-cascade error)
 *
 * @implementation-notes SQL formulas studied dari Doctrine DBAL SQLServerPlatform
 *   (MIT). Reimplemented from spec.
 */
class SqlServerGrammar implements Grammar
{
    public function name(): string { return 'sqlserver'; }

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
        // Escape closing bracket via doubling
        return '[' . str_replace(']', ']]', $seg) . ']';
    }

    public function quoteString(string $value): string
    {
        // T-SQL single-quote escape via doubling
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function mapType(Type $type, ColumnDef $col): string
    {
        switch ($type->name()) {
            case 'string':
                $len = $col->getLength() ?? 255;
                return "NVARCHAR($len)";

            case 'text':
                return 'NVARCHAR(MAX)';

            case 'integer':
                return 'INT';

            case 'bigint':
                return 'BIGINT';

            case 'boolean':
                return 'BIT';

            case 'json':
                return 'NVARCHAR(MAX)';

            case 'uuid':
                return 'UNIQUEIDENTIFIER';

            case 'datetime':
                return 'DATETIME2';

            case 'date':
                return 'DATE';

            case 'time':
                return 'TIME';

            case 'decimal':
                $p = $col->getPrecision() ?? 10;
                $s = $col->getScale() ?? 0;
                return "DECIMAL($p, $s)";

            case 'float':
                return 'FLOAT';

            case 'blob':
                return 'VARBINARY(MAX)';

            case 'enum':
                // NVARCHAR + CHECK constraint (no native enum)
                return 'NVARCHAR(255)';

            default:
                throw new SchemaException(
                    "SqlServerGrammar: unknown type '{$type->name()}' untuk column '{$col->getName()}'"
                );
        }
    }

    public function compileCreateTable(Blueprint $blueprint): array
    {
        $sqls = [];
        $parts = [];

        foreach ($blueprint->getColumns() as $col) {
            $parts[] = '  ' . $this->compileColumn($col);
        }

        $primaryCols = $this->collectPrimaryColumns($blueprint);
        if ($primaryCols !== []) {
            $pkName = 'pk_' . $blueprint->getName();
            $parts[] = '  CONSTRAINT ' . $this->wrapIdentifier($pkName)
                . ' PRIMARY KEY (' . implode(', ', array_map(
                    [$this, 'wrapIdentifier'],
                    $primaryCols
                )) . ')';
        }

        // Unique inline (dgn CONSTRAINT name)
        foreach ($blueprint->getIndexes() as $idx) {
            if ($idx->isPrimary()) continue;
            if ($idx->isUnique()) {
                $cols = implode(', ', array_map([$this, 'wrapIdentifier'], $idx->getColumns()));
                $name = $idx->getName() ?? $this->autoIndexName($blueprint->getName(), $idx);
                $parts[] = '  CONSTRAINT ' . $this->wrapIdentifier($name) . " UNIQUE ($cols)";
            }
        }

        // FK inline
        foreach ($blueprint->getForeignKeys() as $fk) {
            $parts[] = '  ' . $this->compileForeignKey($fk);
        }

        $table = $this->wrapIdentifier($blueprint->getName());
        // T-SQL: no IF NOT EXISTS clause di CREATE TABLE; consumer harus
        // wrap dgn IF NOT EXISTS query di SchemaManager. Kita ignore
        // ifNotExists flag untuk Grammar level.
        $sqls[] = "CREATE TABLE $table (\n" . implode(",\n", $parts) . "\n)";

        // Regular indexes via separate CREATE INDEX
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

        // Nullability
        $out .= $col->isNullable() ? ' NULL' : ' NOT NULL';

        // IDENTITY (autoincrement)
        if ($col->isAutoIncrement()) {
            $out .= ' IDENTITY(1,1)';
        }

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

        // Enum via CHECK constraint
        if ($col->getType() === 'enum') {
            $vals = $col->getEnumValues();
            if ($vals !== null && $vals !== []) {
                $quoted = array_map(function ($v) { return $this->quoteString((string) $v); }, $vals);
                $colRef = $this->wrapIdentifier($col->getName());
                $out .= ' CHECK (' . $colRef . ' IN (' . implode(', ', $quoted) . '))';
            }
        }

        // JSON via ISJSON CHECK
        if ($col->getType() === 'json') {
            $colRef = $this->wrapIdentifier($col->getName());
            $nullClause = $col->isNullable() ? "$colRef IS NULL OR " : '';
            $out .= " CHECK ($nullClause" . "ISJSON($colRef) = 1)";
        }

        return $out;
    }

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
        if ($ifExists) {
            // T-SQL 2016+ syntax
            return 'DROP TABLE IF EXISTS ' . $this->wrapIdentifier($tableName);
        }
        return 'DROP TABLE ' . $this->wrapIdentifier($tableName);
    }

    public function compileLimit(?int $limit, int $offset = 0): string
    {
        // T-SQL 2012+ syntax. Butuh ORDER BY sebelum OFFSET — caller QueryBuilder
        // wajib emit ORDER BY (kalau tidak ada, add ORDER BY 1 sebagai fallback
        // — but that's caller's job, we emit tail only).
        if ($limit === null && $offset === 0) return '';
        if ($limit === null) return "OFFSET $offset ROWS";
        return "OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";
    }

    public function supportsNativeJson(): bool  { return false; } // NVARCHAR + ISJSON hint
    public function supportsNativeUuid(): bool  { return true; }
    public function supportsNativeEnum(): bool  { return false; }
    public function supportsSavepoints(): bool  { return true; } // SAVE TRANSACTION
}
