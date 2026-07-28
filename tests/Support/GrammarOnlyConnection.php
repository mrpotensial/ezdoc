<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Support;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Grammar\Grammar;
use Ezdoc\Db\QueryBuilder;
use Ezdoc\Db\Schema\SchemaManager;
use Ezdoc\Db\Statement;
use LogicException;

/**
 * A Connection that knows its dialect and nothing else.
 *
 * QueryBuilder takes a Connection because it can also run what it builds, but
 * compiling to SQL needs only `grammar()`. This double supplies that and makes
 * every other method throw, so a test that accidentally executes a query fails
 * loudly instead of reaching for a database that is not there.
 *
 * Standing in for a live connection is what lets one query be compiled against
 * all five dialects in a single test — the check that matters for portability,
 * and one no single real database could perform.
 */
final class GrammarOnlyConnection implements Connection
{
    private Grammar $grammar;

    public function __construct(Grammar $grammar)
    {
        $this->grammar = $grammar;
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /** @return never */
    private function notConnected(string $method)
    {
        throw new LogicException(
            "GrammarOnlyConnection::{$method}() was called — this double compiles SQL, it does not run it."
        );
    }

    public function schemaManager(): SchemaManager { $this->notConnected(__FUNCTION__); }
    public function prepare(string $sql): Statement { $this->notConnected(__FUNCTION__); }
    public function execute(string $sql, array $params = []): int { $this->notConnected(__FUNCTION__); }
    public function fetchOne(string $sql, array $params = []): ?array { $this->notConnected(__FUNCTION__); }
    public function fetchAll(string $sql, array $params = []): array { $this->notConnected(__FUNCTION__); }
    public function fetchScalar(string $sql, array $params = []) { $this->notConnected(__FUNCTION__); }
    public function lastInsertId() { $this->notConnected(__FUNCTION__); }
    public function transaction(callable $callback) { $this->notConnected(__FUNCTION__); }
    public function beginTransaction(): void { $this->notConnected(__FUNCTION__); }
    public function commit(): void { $this->notConnected(__FUNCTION__); }
    public function rollback(): void { $this->notConnected(__FUNCTION__); }
    public function inTransaction(): bool { $this->notConnected(__FUNCTION__); }
    public function raw() { $this->notConnected(__FUNCTION__); }
}
