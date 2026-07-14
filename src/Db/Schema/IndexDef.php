<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\IndexDef — value object untuk satu index definition.
 *
 * Created oleh:
 *   - `Blueprint::index(['col1', 'col2'])` — regular index
 *   - `Blueprint::unique(['col1'])` — unique index
 *   - `Blueprint::primary(['id'])` — primary key (special-case; grammar handle)
 *   - `ColumnDef::index()` / `::unique()` — column-level shorthand (Blueprint
 *     collect ke IndexDef list post-facto)
 *
 * ## Kind semantic
 *
 * - `index` — regular btree (default di semua T2 target)
 * - `unique` — uniqueness constraint + implicit index
 * - `primary` — PRIMARY KEY (usually single-column autoincrement id)
 *
 * Extended kinds (`fulltext`, `spatial`, `gin`, `gist`, `hash`) belum di scope
 * v0.9.9 — v0.9.10 nanti kalau ada use case.
 *
 * ## Auto-generated name
 *
 * Kalau name null, Grammar generate `idx_<table>_<col1>_<col2>` (regular),
 * `uniq_<table>_<col>` (unique), `pk_<table>` (primary). Consumer bisa kasih
 * name explicit untuk deterministic naming.
 *
 * PHP 7.4+ compatible.
 */
final class IndexDef
{
    public const KIND_INDEX   = 'index';
    public const KIND_UNIQUE  = 'unique';
    public const KIND_PRIMARY = 'primary';

    /** @var list<string> */
    private $columns;

    /** @var string One of KIND_* constants. */
    private $kind;

    /** @var string|null Deterministic name, atau null untuk auto-gen di Grammar. */
    private $name;

    /** @var string|null Comment (MySQL/Postgres support). */
    private $comment;

    /**
     * @param list<string> $columns
     * @param string       $kind    KIND_* constant
     * @param string|null  $name    Optional deterministic name
     */
    public function __construct(array $columns, string $kind = self::KIND_INDEX, ?string $name = null)
    {
        $this->columns = array_values($columns);
        $this->kind = $kind;
        $this->name = $name;
    }

    /** @return list<string> */
    public function getColumns(): array { return $this->columns; }
    public function getKind(): string   { return $this->kind; }
    public function getName(): ?string  { return $this->name; }
    public function getComment(): ?string { return $this->comment; }

    public function isUnique(): bool  { return $this->kind === self::KIND_UNIQUE; }
    public function isPrimary(): bool { return $this->kind === self::KIND_PRIMARY; }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $arr = ['columns' => $this->columns, 'kind' => $this->kind];
        if ($this->name !== null)    $arr['name'] = $this->name;
        if ($this->comment !== null) $arr['comment'] = $this->comment;
        return $arr;
    }
}
