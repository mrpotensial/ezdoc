<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\ColumnDef — value object untuk satu column definition.
 *
 * Return dari `Blueprint::string()`, `Blueprint::integer()`, `Blueprint::json()`, dst.
 * Immutable-by-convention (fluent methods return `$this` untuk chaining tapi
 * mutate internal state — pola familiar Laravel Blueprint).
 *
 * ## Kenapa mutable, bukan immutable
 *
 * Blueprint DSL user-facing:
 *   $t->string('name', 100)->nullable()->default('anon')->comment('...');
 *
 * Kalau immutable, tiap modifier bikin clone → allocation berat. Laravel/Doctrine
 * juga mutable. Untuk DSL builder, mutable OK — object lifetime cuma seumur
 * closure Blueprint construction.
 *
 * PHP 7.4+ compatible.
 */
final class ColumnDef
{
    // ---- required fields ---------------------------------------------------
    /** @var string */
    private $name;

    /** @var string Canonical type name (dari TypeRegistry). */
    private $type;

    // ---- length / precision ------------------------------------------------
    /** @var int|null */
    private $length;

    /** @var int|null */
    private $precision;

    /** @var int|null */
    private $scale;

    // ---- modifiers ---------------------------------------------------------
    /** @var bool */
    private $nullable = false;

    /** @var bool */
    private $autoIncrement = false;

    /** @var bool */
    private $unsigned = false;

    /** @var bool */
    private $primary = false;

    /** @var bool */
    private $unique = false;

    /** @var bool */
    private $index = false;

    // ---- default value -----------------------------------------------------
    /**
     * @var mixed|null Materialized default value (untuk literal INSERT default).
     *   null berarti "no default clause" — beda dgn defaultRaw yang untuk raw SQL.
     */
    private $default = null;

    /** @var bool True kalau `default(null)` di-call explicit — untuk membedakan null. */
    private $hasDefault = false;

    /** @var string|null Raw SQL fragment untuk DEFAULT clause (mis. 'CURRENT_TIMESTAMP'). */
    private $defaultRaw;

    // ---- metadata ----------------------------------------------------------
    /** @var string|null */
    private $comment;

    // ---- enum-specific -----------------------------------------------------
    /** @var list<string>|null Enum allowed values (untuk type = 'enum'). */
    private $enumValues;

    // ---- FK inline shorthand (dari Blueprint::foreignId()) -----------------
    /** @var array{table:string, column:string, on_delete?:string, on_update?:string}|null */
    private $references;

    // ---- collation/charset (MySQL/MariaDB per-column) ----------------------
    /** @var string|null */
    private $collation;

    /** @var string|null */
    private $charset;

    /** @var bool True kalau `->change()` di-call — untuk migration ALTER (v0.9.10). */
    private $change = false;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    // ========================================================================
    // Getters — dipakai Grammar untuk emit SQL
    // ========================================================================

    public function getName(): string          { return $this->name; }
    public function getType(): string          { return $this->type; }
    public function getLength(): ?int          { return $this->length; }
    public function getPrecision(): ?int       { return $this->precision; }
    public function getScale(): ?int           { return $this->scale; }
    public function isNullable(): bool         { return $this->nullable; }
    public function isAutoIncrement(): bool    { return $this->autoIncrement; }
    public function isUnsigned(): bool         { return $this->unsigned; }
    public function isPrimary(): bool          { return $this->primary; }
    public function isUnique(): bool           { return $this->unique; }
    public function isIndexed(): bool          { return $this->index; }
    public function hasDefault(): bool         { return $this->hasDefault; }
    /** @return mixed */
    public function getDefault()               { return $this->default; }
    public function getDefaultRaw(): ?string   { return $this->defaultRaw; }
    public function getComment(): ?string      { return $this->comment; }
    /** @return list<string>|null */
    public function getEnumValues(): ?array    { return $this->enumValues; }
    /** @return array{table:string, column:string, on_delete?:string, on_update?:string}|null */
    public function getReferences(): ?array    { return $this->references; }
    public function getCollation(): ?string    { return $this->collation; }
    public function getCharset(): ?string      { return $this->charset; }
    public function isChange(): bool           { return $this->change; }

    // ========================================================================
    // Fluent modifiers — DSL surface
    // ========================================================================

    /** Length untuk VARCHAR/CHAR. */
    public function length(int $length): self
    {
        $this->length = $length;
        return $this;
    }

    /** Precision + scale untuk DECIMAL/NUMERIC. */
    public function decimal(int $precision, int $scale = 0): self
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    public function autoIncrement(bool $auto = true): self
    {
        $this->autoIncrement = $auto;
        return $this;
    }

    public function unsigned(bool $unsigned = true): self
    {
        $this->unsigned = $unsigned;
        return $this;
    }

    /** Shorthand — pkey inline (Blueprint::primary() untuk composite). */
    public function primary(bool $primary = true): self
    {
        $this->primary = $primary;
        return $this;
    }

    /** Shorthand — add unique index on this column. */
    public function unique(bool $unique = true): self
    {
        $this->unique = $unique;
        return $this;
    }

    /** Shorthand — add regular index on this column. */
    public function index(bool $index = true): self
    {
        $this->index = $index;
        return $this;
    }

    /**
     * Literal default value — di-encode via Grammar (proper quoting per type).
     * Panggil `default(null)` explicit untuk DEFAULT NULL clause.
     *
     * @param mixed $value
     */
    public function default($value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    /**
     * Raw SQL default — mis. `defaultRaw('CURRENT_TIMESTAMP')`,
     * `defaultRaw('gen_random_uuid()')`.
     *
     * Grammar emit sebagai-adanya. Bertanggung jawab untuk portability.
     */
    public function defaultRaw(string $sqlFragment): self
    {
        $this->defaultRaw = $sqlFragment;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * @param list<string> $values
     */
    public function enumValues(array $values): self
    {
        $this->enumValues = array_values($values);
        return $this;
    }

    /**
     * FK inline shorthand — dari `Blueprint::foreignId('template_id')->references('id')->on('templates')`.
     *
     * Set target column dulu, `on()` set target table (Laravel pattern).
     */
    public function references(string $foreignColumn): self
    {
        if ($this->references === null) {
            $this->references = ['table' => '', 'column' => $foreignColumn];
        } else {
            $this->references['column'] = $foreignColumn;
        }
        return $this;
    }

    public function on(string $foreignTable): self
    {
        if ($this->references === null) {
            $this->references = ['table' => $foreignTable, 'column' => 'id'];
        } else {
            $this->references['table'] = $foreignTable;
        }
        return $this;
    }

    public function onDelete(string $action): self
    {
        if ($this->references === null) $this->references = ['table' => '', 'column' => 'id'];
        $this->references['on_delete'] = $action;
        return $this;
    }

    public function onUpdate(string $action): self
    {
        if ($this->references === null) $this->references = ['table' => '', 'column' => 'id'];
        $this->references['on_update'] = $action;
        return $this;
    }

    /** Cascade shorthand — `->cascadeOnDelete()` = `->onDelete('cascade')`. */
    public function cascadeOnDelete(): self { return $this->onDelete('cascade'); }
    public function cascadeOnUpdate(): self { return $this->onUpdate('cascade'); }
    public function nullOnDelete(): self    { return $this->onDelete('set null'); }
    public function restrictOnDelete(): self { return $this->onDelete('restrict'); }

    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Mark column sebagai "change" (untuk future ALTER emission). MVP v0.9.9
     * hanya create, jadi flag ini di-preserve untuk v0.9.10 ALTER support.
     */
    public function change(): self
    {
        $this->change = true;
        return $this;
    }

    /**
     * Serialize to array — untuk YAML/JSON emit di spec-dump CLI.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'name' => $this->name,
            'type' => $this->type,
        ];
        if ($this->length !== null)      $arr['length'] = $this->length;
        if ($this->precision !== null)   $arr['precision'] = $this->precision;
        if ($this->scale !== null)       $arr['scale'] = $this->scale;
        if ($this->nullable)             $arr['nullable'] = true;
        if ($this->autoIncrement)        $arr['auto_increment'] = true;
        if ($this->unsigned)             $arr['unsigned'] = true;
        if ($this->primary)              $arr['primary'] = true;
        if ($this->unique)               $arr['unique'] = true;
        if ($this->index)                $arr['index'] = true;
        if ($this->hasDefault)           $arr['default'] = $this->default;
        if ($this->defaultRaw !== null)  $arr['default_raw'] = $this->defaultRaw;
        if ($this->comment !== null)     $arr['comment'] = $this->comment;
        if ($this->enumValues !== null)  $arr['enum_values'] = $this->enumValues;
        if ($this->references !== null)  $arr['references'] = $this->references;
        if ($this->collation !== null)   $arr['collation'] = $this->collation;
        if ($this->charset !== null)     $arr['charset'] = $this->charset;
        return $arr;
    }
}
