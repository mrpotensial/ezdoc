<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\Comparator — diff engine untuk 2 schema snapshots.
 *
 * Input: list dari old Blueprint + list dari new Blueprint (per table).
 * Output: `SchemaDiff` yang list added/dropped/changed tables + per-table
 * added/dropped/changed columns/indexes/FKs.
 *
 * ## MVP scope v0.9.9
 *
 * - Detect added/dropped tables
 * - Per table: detect added/dropped/changed columns (by name comparison)
 * - Column "changed" adalah shallow — pakai `ColumnDef::toArray()` deep equal.
 *   Kalau ada difference apapun → flag changed. Fine-grained field-level diff
 *   deferred v0.9.10.
 * - Detect added/dropped indexes by name (auto-name deterministic per Grammar)
 * - Detect added/dropped foreign keys by name
 *
 * ## Deferred v0.9.10+
 *
 * - Renamed detection (currently: rename detect as drop+add — user harus manual
 *   craft RENAME statement)
 * - Fine-grained column change detection (type change vs nullable change vs
 *   default change) untuk ALTER emission optimization
 * - Cross-Grammar ALTER SQL emission via new Grammar::compileAlter methods
 *
 * ## Usage
 *
 * ```php
 * $comparator = new Comparator();
 * $diff = $comparator->compare($oldBlueprints, $newBlueprints);
 * if (!$diff->isEmpty()) {
 *     echo json_encode($diff->toArray(), JSON_PRETTY_PRINT);
 * }
 * ```
 *
 * @implementation-notes Algorithm mirroring Doctrine DBAL Comparator (MIT).
 *   Reimplemented from spec, MVP-level detail.
 *
 * PHP 7.4+ compatible.
 */
final class Comparator
{
    /**
     * @param list<Blueprint> $oldSchema
     * @param list<Blueprint> $newSchema
     */
    public function compare(array $oldSchema, array $newSchema): SchemaDiff
    {
        $diff = new SchemaDiff();

        // Index by name
        $oldByName = [];
        foreach ($oldSchema as $bp) $oldByName[$bp->getName()] = $bp;
        $newByName = [];
        foreach ($newSchema as $bp) $newByName[$bp->getName()] = $bp;

        // Added tables
        foreach ($newByName as $name => $bp) {
            if (!isset($oldByName[$name])) {
                $diff->addedTables[] = $bp;
            }
        }

        // Dropped tables
        foreach ($oldByName as $name => $_) {
            if (!isset($newByName[$name])) {
                $diff->droppedTables[] = $name;
            }
        }

        // Changed tables (exist di both)
        foreach ($newByName as $name => $newBp) {
            if (!isset($oldByName[$name])) continue;
            $td = $this->compareTable($oldByName[$name], $newBp);
            if (!$td->isEmpty()) {
                $diff->changedTables[$name] = $td;
            }
        }

        return $diff;
    }

    public function compareTable(Blueprint $old, Blueprint $new): TableDiff
    {
        $td = new TableDiff($new->getName());

        // Columns
        $oldCols = [];
        foreach ($old->getColumns() as $c) $oldCols[$c->getName()] = $c;
        $newCols = [];
        foreach ($new->getColumns() as $c) $newCols[$c->getName()] = $c;

        foreach ($newCols as $name => $col) {
            if (!isset($oldCols[$name])) {
                $td->addedColumns[] = $col;
            } elseif ($oldCols[$name]->toArray() !== $col->toArray()) {
                $td->changedColumns[$name] = ['old' => $oldCols[$name], 'new' => $col];
            }
        }
        foreach ($oldCols as $name => $_) {
            if (!isset($newCols[$name])) {
                $td->droppedColumns[] = $name;
            }
        }

        // Indexes — by explicit name kalau ada, else stable auto-name
        $oldIdx = $this->indexesByName($old);
        $newIdx = $this->indexesByName($new);
        foreach ($newIdx as $key => $idx) {
            if (!isset($oldIdx[$key])) {
                $td->addedIndexes[] = $idx;
            }
        }
        foreach ($oldIdx as $key => $_) {
            if (!isset($newIdx[$key])) {
                $td->droppedIndexes[] = $key;
            }
        }

        // Foreign keys — by explicit name kalau ada, else auto-key dari kolom+target
        $oldFks = $this->foreignKeysByKey($old);
        $newFks = $this->foreignKeysByKey($new);
        foreach ($newFks as $key => $fk) {
            if (!isset($oldFks[$key])) {
                $td->addedForeignKeys[] = $fk;
            }
        }
        foreach ($oldFks as $key => $_) {
            if (!isset($newFks[$key])) {
                $td->droppedForeignKeys[] = $key;
            }
        }

        return $td;
    }

    /**
     * @return array<string,IndexDef>
     */
    private function indexesByName(Blueprint $bp): array
    {
        $out = [];
        foreach ($bp->getIndexes() as $idx) {
            $key = $idx->getName() ?? ($idx->getKind() . ':' . implode(',', $idx->getColumns()));
            $out[$key] = $idx;
        }
        return $out;
    }

    /**
     * @return array<string,ForeignKeyDef>
     */
    private function foreignKeysByKey(Blueprint $bp): array
    {
        $out = [];
        foreach ($bp->getForeignKeys() as $fk) {
            $key = $fk->getName() ?? (implode(',', $fk->getColumns())
                . '->' . $fk->getForeignTable() . '(' . implode(',', $fk->getForeignColumns()) . ')');
            $out[$key] = $fk;
        }
        return $out;
    }
}
