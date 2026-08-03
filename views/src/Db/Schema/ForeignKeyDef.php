<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\ForeignKeyDef — value object untuk satu FK constraint.
 *
 * Created oleh:
 *   - `Blueprint::foreign('template_id')->references('id')->on('templates')` — explicit
 *   - `Blueprint::foreignId('template_id')->constrained('templates')` — shorthand
 *   - Post-hoc dari `ColumnDef::references()` yang di-collect Blueprint
 *
 * ## ON DELETE / ON UPDATE actions
 *
 * Standard SQL:
 *   - `cascade` — delete/update child rows
 *   - `set null` — set FK col to NULL (col harus nullable)
 *   - `set default` — set to DEFAULT clause value
 *   - `restrict` — reject delete/update kalau ada child (immediate check)
 *   - `no action` — restrict (deferred check di Postgres via DEFERRABLE)
 *
 * Grammar akan validate + emit sesuai platform capability. SQLite `ON UPDATE`
 * kurang consistent — Grammar handle.
 *
 * PHP 7.4+ compatible.
 */
final class ForeignKeyDef
{
    /** @var list<string> */
    private $columns;

    /** @var string */
    private $foreignTable;

    /** @var list<string> */
    private $foreignColumns;

    /** @var string|null 'cascade' | 'set null' | 'restrict' | 'no action' | 'set default' */
    private $onDelete;

    /** @var string|null Same values sebagai onDelete. */
    private $onUpdate;

    /** @var string|null Deterministic name, auto-gen kalau null. */
    private $name;

    /**
     * @param list<string> $columns
     * @param string       $foreignTable
     * @param list<string> $foreignColumns
     */
    public function __construct(array $columns, string $foreignTable, array $foreignColumns = ['id'])
    {
        $this->columns = array_values($columns);
        $this->foreignTable = $foreignTable;
        $this->foreignColumns = array_values($foreignColumns);
    }

    /** @return list<string> */
    public function getColumns(): array { return $this->columns; }
    public function getForeignTable(): string { return $this->foreignTable; }
    /** @return list<string> */
    public function getForeignColumns(): array { return $this->foreignColumns; }
    public function getOnDelete(): ?string { return $this->onDelete; }
    public function getOnUpdate(): ?string { return $this->onUpdate; }
    public function getName(): ?string { return $this->name; }

    public function onDelete(string $action): self
    {
        $this->onDelete = $this->normalizeAction($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = $this->normalizeAction($action);
        return $this;
    }

    public function cascadeOnDelete(): self  { return $this->onDelete('cascade'); }
    public function cascadeOnUpdate(): self  { return $this->onUpdate('cascade'); }
    public function nullOnDelete(): self     { return $this->onDelete('set null'); }
    public function restrictOnDelete(): self { return $this->onDelete('restrict'); }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** Normalize casing/whitespace supaya Grammar bisa switch/case reliable. */
    private function normalizeAction(string $action): string
    {
        return strtolower(trim($action));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'columns' => $this->columns,
            'foreign_table' => $this->foreignTable,
            'foreign_columns' => $this->foreignColumns,
        ];
        if ($this->onDelete !== null) $arr['on_delete'] = $this->onDelete;
        if ($this->onUpdate !== null) $arr['on_update'] = $this->onUpdate;
        if ($this->name !== null)     $arr['name'] = $this->name;
        return $arr;
    }
}
