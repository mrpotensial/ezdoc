# DB Abstraction Layer (v0.9.9)

Ezdoc ships dgn **in-house database abstraction** — zero external dependency,
5 database dialect support (MySQL, MariaDB, SQLite, PostgreSQL, SQL Server),
Blueprint DSL schema declaration, chainable QueryBuilder, dan transaction sugar.

**Design principle**: consumer aplikasi tidak harus install `doctrine/dbal` atau
library eksternal apapun. Ezdoc pakai PHP ext-mysqli + ext-pdo_* (built-in).

---

## Table of Contents

- [Quick Start](#quick-start)
- [Connection interface](#connection-interface)
- [Adapters](#adapters)
- [Blueprint DSL](#blueprint-dsl)
- [Grammar per platform](#grammar-per-platform)
- [Types system](#types-system)
- [QueryBuilder](#querybuilder)
- [Repository usage pattern](#repository-usage-pattern)
- [Transactions](#transactions)
- [Migration & backward compat](#migration--backward-compat)
- [Extending](#extending)

---

## Quick Start

### With existing mysqli global (koneksi.php pattern)

```php
use Ezdoc\Db\Mysqli\MysqliConnection;

$db = new MysqliConnection($conn);   // wrap existing $conn
$rows = $db->fetchAll('SELECT id, name FROM ezdoc_templates WHERE is_active = ?', [1]);
```

### With PDO (any driver)

```php
use Ezdoc\Db\Pdo\PdoConnection;

// One-line factory dari DSN
$db = PdoConnection::fromDsn('sqlite:/path/to/app.db');
$db = PdoConnection::fromDsn('mysql:host=127.0.0.1;dbname=myapp', 'user', 'pass');
$db = PdoConnection::fromDsn('pgsql:host=localhost;dbname=myapp', 'user', 'pass');
$db = PdoConnection::fromDsn('sqlsrv:Server=localhost;Database=myapp', 'user', 'pass');

// Atau wrap existing PDO
$pdo = new PDO('mysql:...', 'user', 'pass');
$db = new PdoConnection($pdo);
```

### Repository (preferred)

```php
use Ezdoc\Document\DocumentRepository;

$repo = new DocumentRepository($db);   // accept Connection OR raw mysqli
$doc = $repo->findById(42);
```

---

## Connection interface

`Ezdoc\Db\Connection` adalah kontrak driver-agnostic. Semua Repository dan
QueryBuilder talk lewat interface ini — bukan langsung ke mysqli/PDO.

```php
interface Connection
{
    public function grammar(): Grammar;                    // SQL dialect helper
    public function schemaManager(): SchemaManager;        // DDL execution
    public function query(): QueryBuilder;                 // fluent SQL builder

    public function prepare(string $sql): Statement;
    public function execute(string $sql, array $params = []): int;   // affected rows
    public function fetchOne(string $sql, array $params = []): ?array;
    public function fetchAll(string $sql, array $params = []): array;
    public function fetchScalar(string $sql, array $params = []);    // first col, first row

    public function lastInsertId();

    public function transaction(callable $callback);
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;

    public function raw();   // escape hatch: return underlying mysqli/PDO
}
```

**Positional params only** (`?`) — universal across driver. Named params
(`:foo`) tidak native di mysqli.

---

## Adapters

### `Ezdoc\Db\Mysqli\MysqliConnection` (default, zero-dep)

- Wrap raw `mysqli` instance
- Auto-detect grammar via `server_info` (MySQL vs MariaDB)
- Support nested transaction via `SAVEPOINT`
- Backward-compat: consumer `koneksi.php` pattern intact

### `Ezdoc\Db\Pdo\PdoConnection` (universal)

- Wrap `PDO` instance (mysql/sqlite/pgsql/sqlsrv)
- Auto-detect grammar via `PDO::ATTR_DRIVER_NAME`
- SQLite: auto-enable `PRAGMA foreign_keys = ON`
- Factory `PdoConnection::fromDsn($dsn, $user, $pass, $options)`

**Kapan pakai mana**:
- `MysqliConnection` — kalau consumer sudah punya `mysqli` global (dgn charset/timezone/etc sudah set)
- `PdoConnection` — untuk multi-driver support atau `App::demo()` mode (SQLite in-memory)

---

## Blueprint DSL

`Ezdoc\Db\Schema\Blueprint` adalah source-of-truth schema declaration.
Framework-neutral, Laravel-familiar naming.

### Example

```php
use Ezdoc\Db\Schema\Blueprint;

$blueprint = new Blueprint('ezdoc_documents', function (Blueprint $t) {
    $t->id();                                          // BIGINT UNSIGNED AUTO_INCREMENT PK
    $t->uuid('uuid')->unique();                        // CHAR(36) UNIQUE
    $t->foreignId('template_id')
        ->references('id')->on('ezdoc_templates')
        ->cascadeOnDelete();                           // FK dgn CASCADE
    $t->string('title', 255)->nullable();
    $t->json('field_values')->defaultRaw("'{}'");
    $t->enum('status', ['draft','published','locked','archived'])->default('draft');
    $t->boolean('is_locked')->default(false);
    $t->integer('version')->unsigned()->default(1);
    $t->timestamps();                                  // created_at + updated_at
    $t->softDeletes();                                 // deleted_at nullable

    // Indexes
    $t->index('template_id');
    $t->index(['status', 'is_locked'], 'idx_status_lock');
    $t->unique('uuid', 'uk_uuid');

    // Table options
    $t->engine('InnoDB');
    $t->charset('utf8mb4');
});
```

### Column type methods

| Method | SQL type (MySQL) |
|---|---|
| `id($name='id')` | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `bigint($name)` | `BIGINT` |
| `integer($name)` | `INT` |
| `string($name, $len=255)` | `VARCHAR($len)` |
| `text($name)` | `TEXT` |
| `longText($name)` | `LONGTEXT` |
| `boolean($name)` | `TINYINT(1)` |
| `json($name)` | `JSON` |
| `uuid($name)` | `CHAR(36)` |
| `enum($name, $values)` | `ENUM(...)` |
| `datetime($name)` | `DATETIME` |
| `date($name)`, `time($name)` | `DATE`, `TIME` |
| `decimal($name, $p, $s)` | `DECIMAL($p, $s)` |
| `float($name)` | `DOUBLE` |
| `binary($name)` | `LONGBLOB` |
| `foreignId($name)` | `BIGINT UNSIGNED` + FK helper |
| `timestamps()` | adds `created_at` + `updated_at` |
| `softDeletes()` | adds `deleted_at` nullable |

### Column modifiers

Chainable after column method — return ColumnDef:

```php
$t->string('email')
    ->nullable()
    ->default('anon@example.com')
    ->unique()
    ->comment('User email');

$t->integer('count')
    ->unsigned()
    ->default(0);

$t->datetime('created_at')
    ->defaultRaw('CURRENT_TIMESTAMP');
```

Available modifiers: `nullable()`, `default($v)`, `defaultRaw($sql)`, `comment($s)`,
`unique()`, `index()`, `primary()`, `unsigned()`, `autoIncrement()`,
`length($n)`, `decimal($p, $s)`, `enumValues([...])`, `references($col)->on($tbl)`,
`cascadeOnDelete()`, `nullOnDelete()`, `restrictOnDelete()`, `charset($s)`,
`collation($s)`, `change()` (v0.9.10+ ALTER support).

### Index methods

```php
$t->primary('id');                              // simple PK
$t->primary(['tenant_id', 'user_id']);          // composite PK

$t->unique('email');                            // simple UNIQUE
$t->unique(['tenant_id', 'slug'], 'uk_slug');   // named composite UNIQUE

$t->index('created_at');                        // simple INDEX
$t->index(['status', 'created_at'], 'idx_status_time');

$t->foreign('user_id', 'users', ['id'])
    ->name('fk_orders_user')
    ->cascadeOnDelete();
```

---

## Grammar per platform

Setiap DB target punya Grammar concrete. Auto-detected dari Connection —
consumer normalnya tidak call langsung.

| Grammar | Ident quote | Autoinc | Bool | JSON | UUID | Limit |
|---|---|---|---|---|---|---|
| `MysqlGrammar` | `` `col` `` | `AUTO_INCREMENT` | `TINYINT(1)` | `JSON` native | `CHAR(36)` | `LIMIT n OFFSET m` |
| `MariaDbGrammar` | (extends MySQL) | same | same | JSON (LONGTEXT pre-10.2) | same | same |
| `SqliteGrammar` | `"col"` | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INTEGER` | `TEXT` + `json_valid()` CHECK | `TEXT` | `LIMIT n OFFSET m` |
| `PostgresGrammar` | `"col"` | `GENERATED ALWAYS AS IDENTITY` | `BOOLEAN` | `JSONB` native | `UUID` native | `LIMIT n OFFSET m` |
| `SqlServerGrammar` | `[col]` | `IDENTITY(1,1)` | `BIT` | `NVARCHAR(MAX)` + `ISJSON()` CHECK | `UNIQUEIDENTIFIER` | `OFFSET m ROWS FETCH NEXT n ROWS ONLY` |

### Feature flags

Grammar expose `supportsNativeJson()`, `supportsNativeUuid()`,
`supportsNativeEnum()`, `supportsSavepoints()` — untuk consumer branch
kalau butuh grammar-specific optimization.

### Manually emit DDL

```php
use Ezdoc\Db\Grammar\PostgresGrammar;

$grammar = new PostgresGrammar();
$sqls = $grammar->compileCreateTable($blueprint);
foreach ($sqls as $sql) echo $sql . ";\n";
```

---

## Types system

`Ezdoc\Db\Types\*` handle PHP ↔ DB value conversion (encode/decode). Grammar
handle SQL type declaration.

Built-in types:

| Type class | Canonical name | PHP ↔ DB |
|---|---|---|
| `StringType` | `string` | identity |
| `IntegerType` | `integer` | `(int)` cast |
| `BigIntType` | `bigint` | **preserved as string** (avoid 32-bit overflow) |
| `BooleanType` | `boolean` | `bool` ↔ `0/1` or `'t'/'f'` |
| `JsonType` | `json` | `array` ↔ `json_encode` |
| `UuidType` | `uuid` | identity + lowercase normalize |
| `DateTimeType` | `datetime` | `\DateTimeImmutable` ↔ `'Y-m-d H:i:s'` |
| `TextType` | `text` | identity |
| `EnumType` | `enum` | validate value ∈ allowed set |

### Custom type

```php
use Ezdoc\Db\Types\Type;
use Ezdoc\Db\Types\TypeRegistry;

class MoneyType implements Type
{
    public function name(): string { return 'money'; }
    public function toPhp($v) { return $v === null ? null : new Money($v); }
    public function toDb($v)  { return $v instanceof Money ? $v->getAmount() : $v; }
}

$registry = new TypeRegistry();
$registry->register(new MoneyType());
```

---

## QueryBuilder

Chainable fluent API — Grammar-driven SQL compile.

### SELECT

```php
$rows = $db->query()
    ->select('id', 'name', 'status')
    ->from('ezdoc_templates')
    ->where('is_active = ?', 1)
    ->andWhere('deleted_at IS NULL')
    ->orderBy('id', 'DESC')
    ->limit(20)
    ->offset(40)
    ->fetchAll();

$one = $db->query()
    ->from('ezdoc_documents')
    ->where('uuid = ?', $uuid)
    ->fetchOne();

$count = $db->query()
    ->select('COUNT(*)')
    ->from('ezdoc_documents')
    ->where('template_id = ?', $id)
    ->fetchScalar();
```

### JOIN

```php
$rows = $db->query()
    ->select('d.id', 'd.title', 't.name AS tpl')
    ->from('ezdoc_documents', 'd')
    ->leftJoin('ezdoc_templates', 't.id = d.template_id', 't')
    ->where('d.status = ?', 'published')
    ->fetchAll();
```

### INSERT (single + batch)

```php
$db->query()
    ->insertInto('ezdoc_documents')
    ->values([
        'uuid'        => $uuid,
        'template_id' => 1,
        'title'       => 'Hello',
    ])
    ->execute();

$id = $db->lastInsertId();

// Batch
$q = $db->query()->insertInto('logs');
foreach ($rows as $row) $q->values($row);
$q->execute();
```

### UPDATE

```php
$db->query()
    ->update('ezdoc_documents')
    ->set('title', 'New Title')
    ->set('updated_at', gmdate('Y-m-d H:i:s'))
    ->where('id = ?', $id)
    ->execute();

// Raw expression (mis. increment counter)
$db->query()
    ->update('ezdoc_documents')
    ->setRaw('scan_count', 'scan_count + ?', [1])
    ->where('id = ?', $id)
    ->execute();
```

### DELETE

```php
$db->query()
    ->deleteFrom('ezdoc_documents')
    ->where('deleted_at IS NOT NULL AND deleted_at < ?', $cutoff)
    ->execute();
```

---

## Repository usage pattern

Repository = domain-specific gateway. Injects `Connection` (bukan raw mysqli).

```php
final class DocumentRepository
{
    /** @var Connection */
    private $db;

    /** @param Connection|mysqli $db */
    public function __construct($db)
    {
        if ($db instanceof Connection)     $this->db = $db;
        elseif ($db instanceof mysqli)     $this->db = new MysqliConnection($db);
        else throw new \InvalidArgumentException('...');
    }

    public function findById(int $id): ?Document
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLS . ' FROM t WHERE id = ?', [$id]);
        return $row ? Document::fromRow($row) : null;
    }
}
```

Ezdoc ships 5 Repository:

- `Ezdoc\Document\DocumentRepository` — CRUD documents dgn optimistic locking
- `Ezdoc\Template\TemplateRepository` — CRUD + versioning (createNewVersion)
- `Ezdoc\Signature\SignatureRepository` — envelope CRUD + verify status
- `Ezdoc\Audit\AuditRepository` — read-side gateway (write via Logger)
- `Ezdoc\DefaultVars\DefaultVarsRepository` — whitelist CRUD

Semua accept `Connection` or `mysqli` (backward-compat).

---

## Transactions

### Sugar (recommended)

`Connection::transaction(callable)` — auto begin/commit/rollback dgn
exception propagation:

```php
$db->transaction(function ($conn) {
    $conn->execute('INSERT INTO t (a) VALUES (?)', [1]);
    $conn->execute('INSERT INTO t (a) VALUES (?)', [2]);
    // implicit commit on return
});

// Rollback via exception
try {
    $db->transaction(function () use ($db) {
        $db->execute('INSERT INTO t (a) VALUES (?)', [1]);
        throw new \RuntimeException('rollback');
    });
} catch (\RuntimeException $e) {
    // rolled back; e re-thrown
}
```

### Nested (savepoint)

Automatic — Connection detects depth counter:

```php
$db->transaction(function () use ($db) {
    $db->execute('INSERT INTO t (a) VALUES (?)', [1]);
    try {
        $db->transaction(function () use ($db) {
            $db->execute('INSERT INTO t (a) VALUES (?)', [2]);
            throw new \RuntimeException('inner rollback via savepoint');
        });
    } catch (\RuntimeException $e) {
        // Inner rolled back to savepoint; outer continues
    }
    // Outer commit — hanya row (1) persisted
});
```

### Manual

```php
$db->beginTransaction();
try {
    $db->execute(...);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

---

## Migration & backward compat

### Existing consumer (koneksi.php pattern)

**Zero code change** — Repository constructors accept raw mysqli:

```php
require_once __DIR__ . '/ezdoc/bootstrap.php';   // sets up $conn global

// Existing code masih works as-is
$repo = new Ezdoc\Document\DocumentRepository($conn);   // auto-wrap ke MysqliConnection
$doc = $repo->findById(42);
```

### Opt-in to PDO

```php
Ezdoc\App::run([
    'db' => [
        'driver' => 'pdo',
        'dsn'    => 'pgsql:host=localhost;dbname=myapp',
        'user'   => 'app',
        'pass'   => 'secret',
    ],
]);
```

Atau construct sendiri di consumer:

```php
$db = Ezdoc\Db\Pdo\PdoConnection::fromDsn('sqlite::memory:');
$docRepo = new Ezdoc\Document\DocumentRepository($db);
```

### Migrations (Runner)

Existing imperative migrations di `migrations/2026_01_01_*.php` tetap works —
Runner unchanged (v0.9.10 planned: switch pakai Blueprint langsung).

Untuk cross-language spec, edit `migrations/blueprints/*.php` + run
`php cli/spec-dump.php` — regenerate `ezdoc-spec/` artifacts.

---

## Extending

### Add new Grammar (custom DB)

```php
namespace App\Db;

use Ezdoc\Db\Grammar\Grammar;

class OracleGrammar implements Grammar
{
    public function name(): string { return 'oracle'; }
    public function wrapIdentifier(string $id): string { return '"' . $id . '"'; }
    // ... implement all methods (see Grammar interface)
}

// Inject di consumer bootstrap
$grammar = new OracleGrammar();
$db = new PdoConnection($pdo, $grammar);   // override auto-detect
```

### Add SchemaManager (v0.9.9 stub → v0.9.10 full)

Untuk v0.9.9, `SchemaManager` throw "not yet implemented". Migration runner
sementara pakai raw SQL. v0.9.10 planned: full SchemaManager impl untuk
introspect + diff + apply ALTER.

Workaround sekarang: use `Connection::execute()` untuk manual DDL.

### Custom exception handling

Semua DB errors di-wrap ke `Ezdoc\Db\Exception\DbException` hierarchy:
- `ConnectionException` — koneksi gagal
- `QueryException` — SQL execute gagal (dgn SQLSTATE code)
- `TransactionException` — transaction state error
- `SchemaException` — schema/Blueprint/Grammar error

```php
try {
    $db->execute('...');
} catch (\Ezdoc\Db\Exception\QueryException $e) {
    if ($e->getSqlState() === '23000') {
        // Duplicate key / integrity violation
    }
    throw $e;
}
```

---

## See also

- [CROSS-LANGUAGE.md](CROSS-LANGUAGE.md) — spec-first ecosystem strategy
- [PRD.md](PRD.md) — full roadmap + design rationale
- [../migrations/blueprints/](../migrations/blueprints/) — Blueprint source examples
- [../ezdoc-spec/](../ezdoc-spec/) — generated cross-lang artifacts
