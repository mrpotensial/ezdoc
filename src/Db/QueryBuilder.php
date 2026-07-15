<?php

declare(strict_types=1);

namespace Ezdoc\Db;

use Ezdoc\Db\Exception\SchemaException;

/**
 * Ezdoc\Db\QueryBuilder — chainable fluent SQL builder, Grammar-driven compile.
 *
 * Repository normal case: 90% queries bisa di-express via QueryBuilder tanpa
 * raw SQL. Untuk edge case yang tidak ter-cover (window functions, CTEs, PG
 * arrays, dsb), Repository tetap bisa pakai `Connection::execute()` dgn raw
 * SQL — QueryBuilder tidak wajib.
 *
 * ## Coverage
 *
 * v0.9.9 W2 MVP:
 *   - SELECT with distinct/columns/from/where(and/or)/join(inner/left/right)/
 *     orderBy/limit/offset
 *   - INSERT single + batch
 *   - UPDATE with set(col, val) chain + where
 *   - DELETE with where
 *
 * Deferred (v0.9.10+):
 *   - GROUP BY / HAVING
 *   - UNION / UNION ALL
 *   - UPSERT (per-grammar complexity)
 *   - Subquery composition
 *   - Window functions
 *
 * ## Usage
 *
 * ```php
 * $rows = $db->query()
 *     ->select('id', 'title', 'status')
 *     ->from('ezdoc_documents')
 *     ->where('template_id = ?', $templateId)
 *     ->andWhere('deleted_at IS NULL')
 *     ->orderBy('id', 'DESC')
 *     ->limit(20)
 *     ->fetchAll();
 *
 * $id = $db->query()
 *     ->insertInto('ezdoc_documents')
 *     ->values(['uuid' => $uuid, 'template_id' => 1, 'title' => 'foo'])
 *     ->execute();
 *
 * $db->query()
 *     ->update('ezdoc_documents')
 *     ->set('title', 'new')
 *     ->set('updated_at', gmdate('Y-m-d H:i:s'))
 *     ->where('id = ?', $id)
 *     ->execute();
 *
 * $db->query()
 *     ->deleteFrom('ezdoc_documents')
 *     ->where('id = ?', $id)
 *     ->execute();
 * ```
 *
 * @implementation-notes Chainable pattern inspired by Doctrine DBAL QueryBuilder
 *   + Laravel Query Builder. Reimplemented from spec, no vendored code.
 *
 * PHP 7.4+ compatible — no first-class callables, no readonly props.
 */
final class QueryBuilder
{
    public const TYPE_SELECT = 'select';
    public const TYPE_INSERT = 'insert';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DELETE = 'delete';

    /** @var Connection */
    private $conn;

    /** @var string One of TYPE_* */
    private $type = self::TYPE_SELECT;

    /** @var bool */
    private $distinct = false;

    /** @var list<string> Column expressions untuk SELECT. */
    private $columns = ['*'];

    /** @var string|null Table name (from / update / delete / insert into). */
    private $table;

    /** @var string|null Alias for table. */
    private $tableAlias;

    /** @var list<array{type:string, table:string, alias:?string, on:string}> */
    private $joins = [];

    /** @var list<string> WHERE fragments (di-join dgn AND/OR sesuai boolean). */
    private $wheres = [];

    /** @var list<string> Params untuk WHERE clause. */
    private $whereParams = [];

    /** @var list<array{col:string, dir:string}> */
    private $orders = [];

    /** @var int|null */
    private $limit;

    /** @var int */
    private $offset = 0;

    // INSERT state
    /** @var list<string> Column names for INSERT. */
    private $insertCols = [];

    /** @var list<list<mixed>> Rows of values (batch support). */
    private $insertRows = [];

    // UPDATE state
    /** @var list<array{col:string, expr:string, params:list<mixed>}> */
    private $updates = [];

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    // ========================================================================
    // SELECT
    // ========================================================================

    /**
     * @param string ...$columns
     */
    public function select(string ...$columns): self
    {
        $this->type = self::TYPE_SELECT;
        $this->columns = $columns !== [] ? array_values($columns) : ['*'];
        return $this;
    }

    public function distinct(bool $flag = true): self
    {
        $this->distinct = $flag;
        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = $table;
        $this->tableAlias = $alias;
        return $this;
    }

    // ========================================================================
    // JOINS
    // ========================================================================

    public function join(string $table, string $on, ?string $alias = null): self
    {
        return $this->addJoin('INNER JOIN', $table, $on, $alias);
    }

    public function leftJoin(string $table, string $on, ?string $alias = null): self
    {
        return $this->addJoin('LEFT JOIN', $table, $on, $alias);
    }

    public function rightJoin(string $table, string $on, ?string $alias = null): self
    {
        return $this->addJoin('RIGHT JOIN', $table, $on, $alias);
    }

    private function addJoin(string $type, string $table, string $on, ?string $alias): self
    {
        $this->joins[] = ['type' => $type, 'table' => $table, 'alias' => $alias, 'on' => $on];
        return $this;
    }

    // ========================================================================
    // WHERE
    // ========================================================================

    /**
     * @param string       $expr   SQL fragment dgn positional `?`
     * @param mixed|list<mixed> $params Scalar atau list untuk bindings
     */
    public function where(string $expr, $params = []): self
    {
        $this->wheres = [];
        $this->whereParams = [];
        return $this->andWhere($expr, $params);
    }

    /**
     * @param string       $expr
     * @param mixed|list<mixed> $params
     */
    public function andWhere(string $expr, $params = []): self
    {
        $this->wheres[] = ($this->wheres !== [] ? 'AND ' : '') . '(' . $expr . ')';
        $this->appendParams($params);
        return $this;
    }

    /**
     * @param string       $expr
     * @param mixed|list<mixed> $params
     */
    public function orWhere(string $expr, $params = []): self
    {
        if ($this->wheres === []) {
            return $this->andWhere($expr, $params);
        }
        $this->wheres[] = 'OR (' . $expr . ')';
        $this->appendParams($params);
        return $this;
    }

    /**
     * @param mixed|list<mixed> $params
     */
    private function appendParams($params): void
    {
        if ($params === [] || $params === null) return;
        if (!is_array($params)) $params = [$params];
        foreach ($params as $p) $this->whereParams[] = $p;
    }

    // ========================================================================
    // ORDER BY / LIMIT / OFFSET
    // ========================================================================

    public function orderBy(string $col, string $dir = 'ASC'): self
    {
        $dir = strtoupper(trim($dir));
        if ($dir !== 'ASC' && $dir !== 'DESC') $dir = 'ASC';
        $this->orders[] = ['col' => $col, 'dir' => $dir];
        return $this;
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    // ========================================================================
    // INSERT
    // ========================================================================

    public function insertInto(string $table): self
    {
        $this->type = self::TYPE_INSERT;
        $this->table = $table;
        return $this;
    }

    /**
     * @param array<string,mixed> $data Column => value. Column order dari
     *   keys pertama call jadi otoritas — subsequent batches wajib match.
     */
    public function values(array $data): self
    {
        if ($this->insertCols === []) {
            $this->insertCols = array_keys($data);
        }
        // Validate column consistency untuk batch mode
        if (array_keys($data) !== $this->insertCols) {
            // Reorder ke match insertCols
            $ordered = [];
            foreach ($this->insertCols as $col) {
                if (!array_key_exists($col, $data)) {
                    throw new SchemaException("QueryBuilder::values missing column '$col' untuk batch insert");
                }
                $ordered[] = $data[$col];
            }
            $this->insertRows[] = $ordered;
        } else {
            $this->insertRows[] = array_values($data);
        }
        return $this;
    }

    // ========================================================================
    // UPDATE
    // ========================================================================

    public function update(string $table): self
    {
        $this->type = self::TYPE_UPDATE;
        $this->table = $table;
        return $this;
    }

    /**
     * Set column ke value literal.
     *
     * @param string $col
     * @param mixed  $value
     */
    public function set(string $col, $value): self
    {
        $this->updates[] = ['col' => $col, 'expr' => '?', 'params' => [$value]];
        return $this;
    }

    /**
     * Set column ke raw SQL expression (mis. `col = col + 1`).
     *
     * @param string     $col
     * @param string     $expr Raw SQL yg reference to `?` placeholders.
     * @param list<mixed> $params
     */
    public function setRaw(string $col, string $expr, array $params = []): self
    {
        $this->updates[] = ['col' => $col, 'expr' => $expr, 'params' => $params];
        return $this;
    }

    // ========================================================================
    // DELETE
    // ========================================================================

    public function deleteFrom(string $table): self
    {
        $this->type = self::TYPE_DELETE;
        $this->table = $table;
        return $this;
    }

    // ========================================================================
    // Compile → SQL + params
    // ========================================================================

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    public function toSql(): array
    {
        switch ($this->type) {
            case self::TYPE_SELECT: return $this->compileSelect();
            case self::TYPE_INSERT: return $this->compileInsert();
            case self::TYPE_UPDATE: return $this->compileUpdate();
            case self::TYPE_DELETE: return $this->compileDelete();
        }
        throw new SchemaException("QueryBuilder: unknown query type '{$this->type}'");
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function compileSelect(): array
    {
        if ($this->table === null) {
            throw new SchemaException('QueryBuilder SELECT butuh from()');
        }
        $g = $this->conn->grammar();
        $cols = implode(', ', array_map(function ($c) use ($g) {
            // '*' atau ekspresi complex → apa-adanya. Simple identifier → wrap.
            if ($c === '*' || strpbrk($c, ' ,()*.') !== false) return $c;
            return $g->wrapIdentifier($c);
        }, $this->columns));

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $cols;
        $sql .= ' FROM ' . $g->wrapIdentifier($this->table);
        if ($this->tableAlias !== null) $sql .= ' ' . $g->wrapIdentifier($this->tableAlias);

        foreach ($this->joins as $j) {
            $sql .= ' ' . $j['type'] . ' ' . $g->wrapIdentifier($j['table']);
            if ($j['alias'] !== null) $sql .= ' ' . $g->wrapIdentifier($j['alias']);
            $sql .= ' ON ' . $j['on'];
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' ', $this->wheres);
        }

        if ($this->orders !== []) {
            $parts = [];
            foreach ($this->orders as $o) {
                $col = strpbrk($o['col'], ' ,()*.') !== false
                    ? $o['col']
                    : $g->wrapIdentifier($o['col']);
                $parts[] = $col . ' ' . $o['dir'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        $limitSql = $g->compileLimit($this->limit, $this->offset);
        if ($limitSql !== '') $sql .= ' ' . $limitSql;

        return [$sql, $this->whereParams];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function compileInsert(): array
    {
        if ($this->table === null) {
            throw new SchemaException('QueryBuilder INSERT butuh insertInto()');
        }
        if ($this->insertRows === []) {
            throw new SchemaException('QueryBuilder INSERT butuh values()');
        }
        $g = $this->conn->grammar();
        $table = $g->wrapIdentifier($this->table);
        $cols = implode(', ', array_map([$g, 'wrapIdentifier'], $this->insertCols));

        $rowsSql = [];
        $params = [];
        $ph = '(' . implode(', ', array_fill(0, count($this->insertCols), '?')) . ')';
        foreach ($this->insertRows as $row) {
            $rowsSql[] = $ph;
            foreach ($row as $v) $params[] = $v;
        }

        $sql = "INSERT INTO $table ($cols) VALUES " . implode(', ', $rowsSql);
        return [$sql, $params];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function compileUpdate(): array
    {
        if ($this->table === null) {
            throw new SchemaException('QueryBuilder UPDATE butuh update()');
        }
        if ($this->updates === []) {
            throw new SchemaException('QueryBuilder UPDATE butuh set()');
        }
        $g = $this->conn->grammar();

        $setParts = [];
        $params = [];
        foreach ($this->updates as $u) {
            $setParts[] = $g->wrapIdentifier($u['col']) . ' = ' . $u['expr'];
            foreach ($u['params'] as $p) $params[] = $p;
        }

        $sql = 'UPDATE ' . $g->wrapIdentifier($this->table)
            . ' SET ' . implode(', ', $setParts);

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' ', $this->wheres);
            foreach ($this->whereParams as $p) $params[] = $p;
        }

        return [$sql, $params];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function compileDelete(): array
    {
        if ($this->table === null) {
            throw new SchemaException('QueryBuilder DELETE butuh deleteFrom()');
        }
        $g = $this->conn->grammar();
        $sql = 'DELETE FROM ' . $g->wrapIdentifier($this->table);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' ', $this->wheres);
        }
        return [$sql, $this->whereParams];
    }

    // ========================================================================
    // Execute — delegate to Connection
    // ========================================================================

    /**
     * Execute INSERT/UPDATE/DELETE — return affected rows (atau lastInsertId
     * kalau INSERT + wanted).
     *
     * @return int Affected rows
     */
    public function execute(): int
    {
        [$sql, $params] = $this->toSql();
        return $this->conn->execute($sql, $params);
    }

    /**
     * Execute SELECT, return first row atau null.
     *
     * @return array<string,mixed>|null
     */
    public function fetchOne(): ?array
    {
        [$sql, $params] = $this->toSql();
        return $this->conn->fetchOne($sql, $params);
    }

    /**
     * Execute SELECT, return semua rows.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchAll(): array
    {
        [$sql, $params] = $this->toSql();
        return $this->conn->fetchAll($sql, $params);
    }

    /**
     * Execute SELECT, return first column of first row.
     *
     * @return mixed
     */
    public function fetchScalar()
    {
        [$sql, $params] = $this->toSql();
        return $this->conn->fetchScalar($sql, $params);
    }
}
