<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\TableDiff — per-table diff antara old dan new state.
 *
 * PHP 7.4+ compatible.
 */
final class TableDiff
{
    /** @var string Table name */
    public $name;

    /** @var list<ColumnDef> New columns (ADD COLUMN) */
    public $addedColumns = [];

    /** @var list<string> Column names to DROP */
    public $droppedColumns = [];

    /**
     * Changed columns: same name, different attribute (type/length/nullable/etc).
     * Detail-level detection basic MVP — hanya flag "changed", tidak specify apa
     * yang change. v0.9.10 expand ini ke fine-grained change list.
     *
     * @var array<string,array{old:ColumnDef, new:ColumnDef}>
     */
    public $changedColumns = [];

    /** @var list<IndexDef> */
    public $addedIndexes = [];

    /** @var list<string> Index names to DROP */
    public $droppedIndexes = [];

    /** @var list<ForeignKeyDef> */
    public $addedForeignKeys = [];

    /** @var list<string> FK names to DROP */
    public $droppedForeignKeys = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function isEmpty(): bool
    {
        return $this->addedColumns === []
            && $this->droppedColumns === []
            && $this->changedColumns === []
            && $this->addedIndexes === []
            && $this->droppedIndexes === []
            && $this->addedForeignKeys === []
            && $this->droppedForeignKeys === [];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $changed = [];
        foreach ($this->changedColumns as $n => $pair) {
            $changed[$n] = ['old' => $pair['old']->toArray(), 'new' => $pair['new']->toArray()];
        }
        return [
            'name' => $this->name,
            'added_columns'    => array_map(function (ColumnDef $c) { return $c->toArray(); }, $this->addedColumns),
            'dropped_columns'  => $this->droppedColumns,
            'changed_columns'  => $changed,
            'added_indexes'    => array_map(function (IndexDef $i) { return $i->toArray(); }, $this->addedIndexes),
            'dropped_indexes'  => $this->droppedIndexes,
            'added_foreign_keys'   => array_map(function (ForeignKeyDef $fk) { return $fk->toArray(); }, $this->addedForeignKeys),
            'dropped_foreign_keys' => $this->droppedForeignKeys,
        ];
    }
}
