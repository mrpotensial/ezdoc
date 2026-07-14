<?php

declare(strict_types=1);

namespace Ezdoc\Db\Schema;

/**
 * Ezdoc\Db\Schema\Blueprint — DSL untuk describe table schema secara portable.
 *
 * Single source of truth untuk struktur table di ezdoc. Grammar concrete
 * (MysqlGrammar, SqliteGrammar, dll) consume Blueprint untuk emit CREATE TABLE
 * per platform. `cli/spec-dump.php` consume Blueprint untuk emit YAML/JSON
 * cross-language descriptor.
 *
 * ## Usage
 *
 * ```php
 * $blueprint = new Blueprint('ezdoc_documents', function (Blueprint $t) {
 *     $t->id();
 *     $t->uuid('uuid')->unique();
 *     $t->foreignId('template_id')->references('id')->on('ezdoc_templates')
 *                                 ->cascadeOnDelete();
 *     $t->string('title', 255)->nullable();
 *     $t->json('field_values')->defaultRaw("'{}'");
 *     $t->enum('status', ['draft','active','archived'])->default('draft');
 *     $t->boolean('is_locked')->default(false);
 *     $t->timestamps();
 *     $t->softDeletes();
 *     $t->index(['template_id']);
 *     $t->index(['status', 'is_locked'], 'idx_status_lock');
 * });
 * ```
 *
 * ## Design notes
 *
 * - **Naming familiar Laravel** — user PHP awam yg pernah pakai Laravel bisa
 *   langsung produktif. Semantic tetap framework-neutral (Blueprint bukan
 *   Laravel-exclusive term).
 * - **Fluent method returns ColumnDef** — supaya bisa chain modifier langsung.
 * - **Static factory `create()`** — Migration files instantiate Blueprint via
 *   `Blueprint::create('name', function ($t) { ... })` return `self`.
 *
 * @implementation-notes DSL surface (method names, chaining pattern) mirroring
 *   Laravel Illuminate\Database\Schema\Blueprint. Framework-neutral karena istilah
 *   "Blueprint" juga dipakai Cycle ORM + Doctrine DBAL Schema. Reimplemented from spec.
 *
 * PHP 7.4+ compatible.
 */
final class Blueprint
{
    /** @var string */
    private $name;

    /** @var list<ColumnDef> */
    private $columns = [];

    /** @var list<IndexDef> */
    private $indexes = [];

    /** @var list<ForeignKeyDef> */
    private $foreignKeys = [];

    /** @var string|null MySQL/MariaDB engine (mis. InnoDB). */
    private $engine;

    /** @var string|null MySQL/MariaDB/SQLServer charset. */
    private $charset;

    /** @var string|null Collation. */
    private $collation;

    /** @var string|null Table comment. */
    private $comment;

    /** @var bool True kalau `->temporary()` — CREATE TEMPORARY TABLE. */
    private $temporary = false;

    /** @var bool True kalau IF NOT EXISTS clause di CREATE TABLE. */
    private $ifNotExists = false;

    /**
     * @param string                 $name
     * @param callable(Blueprint):void|null $builder Optional callback untuk
     *   populate langsung. Idiom Laravel-style.
     */
    public function __construct(string $name, ?callable $builder = null)
    {
        $this->name = $name;
        if ($builder !== null) {
            $builder($this);
        }
    }

    /**
     * Static factory — familiar API untuk migration files.
     *
     * @param callable(Blueprint):void $builder
     */
    public static function create(string $name, callable $builder): self
    {
        return new self($name, $builder);
    }

    // ========================================================================
    // State accessors — dipakai Grammar
    // ========================================================================

    public function getName(): string { return $this->name; }

    /** @return list<ColumnDef> */
    public function getColumns(): array { return $this->columns; }

    /** @return list<IndexDef> */
    public function getIndexes(): array
    {
        // Sync column-level shortcuts (unique/index/primary) ke IndexDef list
        // — deferred sampai getIndexes() dipanggil supaya order deterministic.
        $synced = $this->indexes;
        foreach ($this->columns as $col) {
            if ($col->isPrimary() && $col->getName() !== 'id') {
                // id() auto-set primary — jangan double.
                // Non-id primary column shorthand: bikin IndexDef.
                $synced[] = new IndexDef([$col->getName()], IndexDef::KIND_PRIMARY);
            }
            if ($col->isUnique()) {
                $synced[] = new IndexDef([$col->getName()], IndexDef::KIND_UNIQUE);
            }
            if ($col->isIndexed()) {
                $synced[] = new IndexDef([$col->getName()], IndexDef::KIND_INDEX);
            }
        }
        return $synced;
    }

    /** @return list<ForeignKeyDef> */
    public function getForeignKeys(): array
    {
        // Sync column-level `references()`/`on()` shortcuts ke ForeignKeyDef list.
        $synced = $this->foreignKeys;
        foreach ($this->columns as $col) {
            $ref = $col->getReferences();
            if ($ref === null || $ref['table'] === '') continue;
            $fk = new ForeignKeyDef([$col->getName()], $ref['table'], [$ref['column']]);
            if (isset($ref['on_delete'])) $fk->onDelete($ref['on_delete']);
            if (isset($ref['on_update'])) $fk->onUpdate($ref['on_update']);
            $synced[] = $fk;
        }
        return $synced;
    }

    public function getEngine(): ?string    { return $this->engine; }
    public function getCharset(): ?string   { return $this->charset; }
    public function getCollation(): ?string { return $this->collation; }
    public function getComment(): ?string   { return $this->comment; }
    public function isTemporary(): bool     { return $this->temporary; }
    public function isIfNotExists(): bool   { return $this->ifNotExists; }

    // ========================================================================
    // Column type methods — return ColumnDef untuk fluent chaining
    // ========================================================================

    /** Big integer autoincrement primary key. */
    public function id(string $name = 'id'): ColumnDef
    {
        $col = (new ColumnDef($name, 'bigint'))
            ->autoIncrement()
            ->unsigned()
            ->primary();
        $this->columns[] = $col;
        return $col;
    }

    /** Alias to id() — Laravel-familiar. */
    public function bigIncrements(string $name = 'id'): ColumnDef
    {
        return $this->id($name);
    }

    public function increments(string $name = 'id'): ColumnDef
    {
        $col = (new ColumnDef($name, 'integer'))
            ->autoIncrement()
            ->unsigned()
            ->primary();
        $this->columns[] = $col;
        return $col;
    }

    public function string(string $name, int $length = 255): ColumnDef
    {
        $col = (new ColumnDef($name, 'string'))->length($length);
        $this->columns[] = $col;
        return $col;
    }

    public function text(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'text');
        $this->columns[] = $col;
        return $col;
    }

    /** LONGTEXT hint (grammar map: MySQL LONGTEXT, others tetap TEXT). */
    public function longText(string $name): ColumnDef
    {
        $col = (new ColumnDef($name, 'text'))->length(4294967295); // 4GB hint
        $this->columns[] = $col;
        return $col;
    }

    public function integer(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'integer');
        $this->columns[] = $col;
        return $col;
    }

    public function bigint(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'bigint');
        $this->columns[] = $col;
        return $col;
    }

    public function boolean(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'boolean');
        $this->columns[] = $col;
        return $col;
    }

    public function json(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'json');
        $this->columns[] = $col;
        return $col;
    }

    public function uuid(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'uuid');
        $this->columns[] = $col;
        return $col;
    }

    /**
     * @param list<string> $values
     */
    public function enum(string $name, array $values): ColumnDef
    {
        $col = (new ColumnDef($name, 'enum'))->enumValues($values);
        $this->columns[] = $col;
        return $col;
    }

    public function datetime(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'datetime');
        $this->columns[] = $col;
        return $col;
    }

    public function date(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'date');
        $this->columns[] = $col;
        return $col;
    }

    public function time(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'time');
        $this->columns[] = $col;
        return $col;
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDef
    {
        $col = (new ColumnDef($name, 'decimal'))->decimal($precision, $scale);
        $this->columns[] = $col;
        return $col;
    }

    public function float(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'float');
        $this->columns[] = $col;
        return $col;
    }

    public function binary(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'blob');
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Foreign key ergonomic helper: bigint unsigned column siap dijadikan FK.
     *
     * ```php
     * $t->foreignId('template_id')->references('id')->on('ezdoc_templates');
     * ```
     */
    public function foreignId(string $name): ColumnDef
    {
        $col = (new ColumnDef($name, 'bigint'))->unsigned();
        $this->columns[] = $col;
        return $col;
    }

    // ========================================================================
    // Composite column methods — add multiple columns sekaligus
    // ========================================================================

    /** Add `created_at` + `updated_at` nullable timestamps. */
    public function timestamps(): void
    {
        $this->datetime('created_at')->nullable()->defaultRaw('CURRENT_TIMESTAMP');
        $this->datetime('updated_at')->nullable();
    }

    /** Add `deleted_at` nullable timestamp untuk soft delete. */
    public function softDeletes(string $name = 'deleted_at'): ColumnDef
    {
        return $this->datetime($name)->nullable();
    }

    // ========================================================================
    // Index / constraint methods — return IndexDef / ForeignKeyDef untuk chaining
    // ========================================================================

    /**
     * @param list<string>|string $columns
     */
    public function primary($columns): IndexDef
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $idx = new IndexDef($cols, IndexDef::KIND_PRIMARY);
        $this->indexes[] = $idx;
        return $idx;
    }

    /**
     * @param list<string>|string $columns
     */
    public function unique($columns, ?string $name = null): IndexDef
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $idx = new IndexDef($cols, IndexDef::KIND_UNIQUE, $name);
        $this->indexes[] = $idx;
        return $idx;
    }

    /**
     * @param list<string>|string $columns
     */
    public function index($columns, ?string $name = null): IndexDef
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $idx = new IndexDef($cols, IndexDef::KIND_INDEX, $name);
        $this->indexes[] = $idx;
        return $idx;
    }

    /**
     * Add FK constraint. Return ForeignKeyDef untuk `->references()->on()->cascadeOnDelete()`.
     *
     * @param list<string>|string $columns
     */
    public function foreign($columns, string $foreignTable = '', array $foreignColumns = ['id']): ForeignKeyDef
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $fk = new ForeignKeyDef($cols, $foreignTable, $foreignColumns);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    // ========================================================================
    // Table-level options
    // ========================================================================

    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function temporary(bool $temporary = true): self
    {
        $this->temporary = $temporary;
        return $this;
    }

    public function ifNotExists(bool $flag = true): self
    {
        $this->ifNotExists = $flag;
        return $this;
    }

    // ========================================================================
    // Serialization — untuk YAML/JSON emit di spec-dump CLI
    // ========================================================================

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $columns = [];
        foreach ($this->columns as $c) $columns[$c->getName()] = $c->toArray();

        $indexes = [];
        foreach ($this->getIndexes() as $i) $indexes[] = $i->toArray();

        $fks = [];
        foreach ($this->getForeignKeys() as $f) $fks[] = $f->toArray();

        $arr = [
            'name' => $this->name,
            'columns' => $columns,
        ];
        if ($indexes !== []) $arr['indexes'] = $indexes;
        if ($fks !== [])     $arr['foreign_keys'] = $fks;
        if ($this->engine !== null)    $arr['engine'] = $this->engine;
        if ($this->charset !== null)   $arr['charset'] = $this->charset;
        if ($this->collation !== null) $arr['collation'] = $this->collation;
        if ($this->comment !== null)   $arr['comment'] = $this->comment;
        if ($this->temporary)          $arr['temporary'] = true;
        if ($this->ifNotExists)        $arr['if_not_exists'] = true;
        return $arr;
    }
}
