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
 * PostgreSQL 12+ SQL grammar.
 *
 * Concrete Grammar impl untuk Postgres. Native support paling banyak:
 *   - JSONB (indexable, deduplicated, superior ke JSON)
 *   - UUID (16-byte storage, native indexing)
 *   - BOOLEAN (native, TRUE/FALSE keywords)
 *   - GENERATED AS IDENTITY (SQL standard successor of SERIAL)
 *   - Rich array types (belum di-scope v0.9.9)
 *
 * ## Postgres quirks
 *
 * - **Identifier double-quote** `"col"` (SQL standard, beda dari MySQL backtick)
 * - **BIGINT + IDENTITY** — `BIGINT GENERATED ALWAYS AS IDENTITY` (PG 10+)
 *   preferable over legacy `BIGSERIAL` sequence pattern
 * - **JSONB > JSON** — always prefer JSONB kecuali user butuh preserve order
 * - **ENUM** — CREATE TYPE ... AS ENUM (...) — first-class user type. Untuk
 *   simplicity di v0.9.9 kita pakai TEXT + CHECK (postponing schema mgmt
 *   complexity untuk enum type lifecycle).
 * - **No UNSIGNED** — Postgres tidak punya unsigned integer, warning ke user
 *   yang define `->unsigned()` (silent ignore, comment-notes).
 * - **UPSERT** — ON CONFLICT (target) DO UPDATE SET ... (PG 9.5+)
 * - **INDEX types** — btree default, gin untuk JSONB, gist untuk geometry.
 *   V0.9.9 hanya btree.
 * - **CASCADE actions same as SQL standard** — sama seperti MySQL.
 *
 * @implementation-notes SQL formulas studied dari Doctrine DBAL PostgreSQLPlatform
 *   (MIT). Reimplemented from spec.
 */
class PostgresGrammar implements Grammar
{
    public function name(): string { return 'postgres'; }

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
        return '"' . str_replace('"', '""', $seg) . '"';
    }

    public function quoteString(string $value): string
    {
        // SQL standard: escape single-quote via doubling
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function mapType(Type $type, ColumnDef $col): string
    {
        switch ($type->name()) {
            case 'string':
                $len = $col->getLength() ?? 255;
                return "VARCHAR($len)";

            case 'text':
                return 'TEXT'; // PG TEXT unlimited, no length hint needed

            case 'integer':
                // Postgres tidak punya UNSIGNED — ignore unsigned flag dgn silent
                return 'INTEGER';

            case 'bigint':
                return 'BIGINT';

            case 'boolean':
                return 'BOOLEAN';

            case 'json':
                return 'JSONB'; // prefer JSONB atas JSON

            case 'uuid':
                return 'UUID';

            case 'datetime':
                return 'TIMESTAMP(6) WITHOUT TIME ZONE';

            case 'date':
                return 'DATE';

            case 'time':
                return 'TIME(6) WITHOUT TIME ZONE';

            case 'decimal':
                $p = $col->getPrecision() ?? 10;
                $s = $col->getScale() ?? 0;
                return "NUMERIC($p, $s)";

            case 'float':
                return 'DOUBLE PRECISION';

            case 'blob':
                return 'BYTEA';

            case 'enum':
                // v0.9.9: pakai TEXT + CHECK (postpone native CREATE TYPE lifecycle)
                return 'TEXT';

            default:
                throw new SchemaException(
                    "PostgresGrammar: unknown type '{$type->name()}' untuk column '{$col->getName()}'"
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
            $parts[] = '  PRIMARY KEY (' . implode(', ', array_map(
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

        $ifNotExists = $blueprint->isIfNotExists() ? 'IF NOT EXISTS ' : '';
        $temporary = $blueprint->isTemporary() ? 'TEMPORARY ' : '';
        $table = $this->wrapIdentifier($blueprint->getName());

        $sqls[] = "CREATE {$temporary}TABLE {$ifNotExists}$table (\n"
            . implode(",\n", $parts)
            . "\n)";

        // Table COMMENT — Postgres pattern: separate COMMENT ON TABLE statement
        if (($cmt = $blueprint->getComment()) !== null) {
            $sqls[] = 'COMMENT ON TABLE ' . $table . ' IS ' . $this->quoteString($cmt);
        }

        // Column COMMENT — separate COMMENT ON COLUMN statements
        foreach ($blueprint->getColumns() as $col) {
            if (($cmt = $col->getComment()) !== null) {
                $sqls[] = 'COMMENT ON COLUMN ' . $table . '.' . $this->wrapIdentifier($col->getName())
                    . ' IS ' . $this->quoteString($cmt);
            }
        }

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

        // Autoincrement → GENERATED ALWAYS AS IDENTITY (PG 10+)
        if ($col->isAutoIncrement()) {
            $out .= ' GENERATED ALWAYS AS IDENTITY';
        }

        // Nullability
        $out .= $col->isNullable() ? '' : ' NOT NULL';

        // Default
        if ($col->getDefaultRaw() !== null) {
            $out .= ' DEFAULT ' . $col->getDefaultRaw();
        } elseif ($col->hasDefault()) {
            $default = $col->getDefault();
            if ($default === null) {
                $out .= ' DEFAULT NULL';
            } elseif (is_bool($default)) {
                $out .= ' DEFAULT ' . ($default ? 'TRUE' : 'FALSE');
            } elseif (is_int($default) || is_float($default)) {
                $out .= ' DEFAULT ' . $default;
            } else {
                $out .= ' DEFAULT ' . $this->quoteString((string) $default);
            }
        }

        // Enum via CHECK constraint (TEXT + CHECK)
        if ($col->getType() === 'enum') {
            $vals = $col->getEnumValues();
            if ($vals !== null && $vals !== []) {
                $quoted = array_map(function ($v) { return $this->quoteString((string) $v); }, $vals);
                $colRef = $this->wrapIdentifier($col->getName());
                $out .= ' CHECK (' . $colRef . ' IN (' . implode(', ', $quoted) . '))';
            }
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
        $ifClause = $ifExists ? 'IF EXISTS ' : '';
        return "DROP TABLE {$ifClause}" . $this->wrapIdentifier($tableName);
    }

    public function compileLimit(?int $limit, int $offset = 0): string
    {
        if ($limit === null && $offset === 0) return '';
        if ($limit === null) return "OFFSET $offset";
        $sql = "LIMIT $limit";
        if ($offset > 0) $sql .= " OFFSET $offset";
        return $sql;
    }

    public function supportsNativeJson(): bool  { return true; }
    public function supportsNativeUuid(): bool  { return true; }
    public function supportsNativeEnum(): bool  { return true; } // via CREATE TYPE, tapi impl v0.9.9 pakai CHECK
    public function supportsSavepoints(): bool  { return true; }
}
