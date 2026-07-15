<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\SchemaDiff — result dari Comparator, list perubahan antara
 * dua schema.
 *
 * Value object plain. Consumer (Migration generator di v0.9.10+) walk diff ini
 * untuk emit ALTER statements via Grammar.
 *
 * PHP 7.4+ compatible.
 */
final class SchemaDiff
{
    /** @var list<Blueprint> Table yang ada di new tapi tidak ada di old (CREATE) */
    public $addedTables = [];

    /** @var list<string> Table names yang ada di old tapi tidak ada di new (DROP) */
    public $droppedTables = [];

    /** @var array<string,TableDiff> Per-table changes untuk table yang exist di both */
    public $changedTables = [];

    /** @return bool True kalau tidak ada perubahan sama sekali. */
    public function isEmpty(): bool
    {
        return $this->addedTables === []
            && $this->droppedTables === []
            && $this->changedTables === [];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $changed = [];
        foreach ($this->changedTables as $name => $td) {
            $changed[$name] = $td->toArray();
        }
        return [
            'added_tables'   => array_map(function (Blueprint $b) { return $b->getName(); }, $this->addedTables),
            'dropped_tables' => $this->droppedTables,
            'changed_tables' => $changed,
        ];
    }
}
