# ezdoc

Framework-agnostic PHP library untuk **document generation** dgn WYSIWYG template
designer, **versioning**, **digital signature** (L1 HMAC / L2 LocalPKI / L3 PSrE
+ PAdES), **RBAC**, **audit log**, **QR verify** publik, dan **spec-first
cross-language port readiness**.

Universal schema (no domain-specific columns di core) — cocok untuk **hospital**
(rekam medis), **legal** (kontrak), **HR** (SK karyawan), **finance** (invoice),
**education** (ijazah), **government** (surat resmi), insurance, real estate,
warehouse, dsb.

**Status**: v0.9.9-dev (foundation matang, PHP release siap v1.0). Blueprint DSL
+ 5-database grammar + PDO adapter + QueryBuilder + spec-dump CLI live-validated.

---

## Features

### Core
- **WYSIWYG template designer** — TinyMCE-based, field markers (`{{name}}`),
  TTD placeholders, materai, QR, logo, conditional sections, tabel dinamis
- **Document lifecycle** — draft / published / locked / archived + soft-delete
  dgn audit trail
- **Template versioning** — UUID family + version chain (immutable per version)
- **RBAC per-template** (create/edit/lock/delete) + per-TTD (siapa boleh sign)
- **Audit log** — persistent append-only event trail, distributed-tracing ready
  (session_id, request_id, trace_id)

### Signature layer
- **L1 HMAC** (default, fastest, no cert) — envelope shared-secret hash
- **L2 LocalPKI** — X.509 self-signed / private CA, PKCS#7 envelope
- **L3 PSrE** (Peruri, Privy stub, VIDA stub) — commercial PSrE integration
- **PAdES** (ETSI EN 319 142) — PDF Advanced Electronic Signatures via
  JSignPdf, OpenSSL, atau external signer closure
- **RFC 3161 timestamp** (TSA) — long-term validity (B-T level)

### Persistence — v0.9.9 (**new**)
- **Zero external DB library** — in-house `Ezdoc\Db\*` abstraction
- **5 databases supported**: MySQL, MariaDB, SQLite, PostgreSQL, SQL Server
- **Blueprint DSL** — Laravel-familiar schema declaration, framework-neutral
- **Grammar per platform** — dialect-specific SQL emission (JSON native, ENUM,
  UUID, TIMESTAMP precision, LIMIT/OFFSET vs OFFSET…FETCH NEXT)
- **QueryBuilder** — chainable fluent (SELECT/INSERT/UPDATE/DELETE, WHERE
  AND/OR, JOIN, ORDER, LIMIT, batch INSERT)
- **Schema Comparator** — diff old vs new Blueprint → ALTER plan (foundation)
- **Transaction sugar** dgn nested savepoint support

### Cross-language spec — v0.9.9 (**new**)
- **`ezdoc-spec/`** artifacts (generated via `php cli/spec-dump.php`):
  - `schema/tables.{json,yaml}` — DB schema descriptor
  - `ddl/{mysql,mariadb,sqlite,postgres,sqlserver}.sql` — DDL per platform
  - `meta/{version.json,checksum.txt}` — CI gate metadata
- **CI-verifiable** — `php cli/spec-dump.php --check` fail kalau spec drift
- **Cross-lang consumption** — Go/Rust/TS ports future tinggal baca YAML/JSON

### App orchestrator — v0.9.8
- **1-line mount**: `Ezdoc\App::run($config)` — front controller + internal
  router + auto-detect routes (list/designer/generate/action/asset)
- **Zero-config demo**: `Ezdoc\App::demo()` — SQLite in-memory, no DB config
- **Fragment vs full-page** rendering (opt-in prefix)

### UI extension framework — v0.6.6+
- **Slot API** — 22+ injection points untuk consumer extension (branding, extra
  buttons, custom fields tanpa fork)
- **Publish + customize** pattern (Filament/shadcn-ui style)

---

## Installation

### Via Composer (recommended untuk Laravel/Symfony/Slim/CI4)

```bash
composer require mrpotensial/ezdoc
```

### Standalone (untuk monolith non-Composer, WordPress/CI3 dsb)

```bash
# Drop ezdoc/ ke project, no composer required
git clone https://github.com/mrpotensial/ezdoc.git /path/to/vendor/ezdoc
```

```php
require_once __DIR__ . '/vendor/ezdoc/autoload.php';
```

---

## Quick Start

### Option 1: Zero-config demo (test drive)

```php
require_once 'vendor/ezdoc/autoload.php';

Ezdoc\App::demo();  // spins up SQLite in-memory + seeds → try designer/gen live
```

Visit `http://localhost:8000/?ezdoc_page=list` → click "New Template" → design →
save → generate document → sign → verify QR. Zero config.

### Option 2: Production wiring (Laravel example)

```php
Ezdoc\App::run([
    'db' => [
        'driver' => 'pdo',
        'dsn'    => env('DB_DSN', 'mysql:host=127.0.0.1;dbname=myapp'),
        'user'   => env('DB_USER'),
        'pass'   => env('DB_PASS'),
    ],
    'auth' => [
        'role_provider' => new Ezdoc\Auth\CallableRoleProvider(
            hasRole:          fn($roles) => auth()->user()?->hasRole($roles) ?? false,
            currentUserId:    fn() => auth()->id() ?? 0,
            currentUserRoles: fn() => auth()->user()?->getRoleNames()->all() ?? [],
        ),
    ],
    'brand' => [
        'app_name'    => 'My Company',
        'primary'     => '#2563eb',
        'logo_url'    => '/logo.svg',
    ],
]);
```

### Option 3: Legacy consumer (mysqli global + backward compat)

```php
require_once __DIR__ . '/ezdoc/bootstrap.php';

// Auto-picks up $conn mysqli global from consumer's own bootstrap file
// (whatever the consumer app calls it — db.php, config.php, koneksi.php, etc.).
// Global helpers ready to use
ezdoc_audit_log('doc.created', ['doc_id' => 42]);

// Repository interfaces backward-compat — accept mysqli OR Ezdoc\Db\Connection
$docRepo = new Ezdoc\Document\DocumentRepository($conn);  // auto-wrap
$doc = $docRepo->findById(1);
```

---

## Architecture

Hexagonal-ish layering. Application code depends on interfaces (`Connection`,
`RoleProvider`), infrastructure implementations plugged in via adapter.

```
┌─────────────────────────────────────────────────────────────────┐
│                     Consumer Application                         │
│   (Laravel/Symfony/Slim/CI/monolith/WordPress plugin/...)        │
└────────────────────┬────────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Ezdoc\App                                    │
│   Orchestrator: front controller + router + Context DI           │
│   Entry:  App::run(config)  |  App::demo()  |  legacy include    │
└────────────────────┬────────────────────────────────────────────┘
                     │
     ┌───────────────┼──────────────────────┬───────────────┐
     ▼               ▼                      ▼               ▼
┌─────────┐   ┌─────────────┐      ┌─────────────┐   ┌──────────────┐
│  Views  │   │  Actions    │      │  Signature  │   │   Audit      │
│         │   │  (endpoints)│      │  adapters   │   │   Logger     │
│designer │   │ save_doc    │      │             │   │              │
│generate │   │ save_tpl    │      │ HMAC        │   │ append-only  │
│list     │   │ generate_qr │      │ LocalPKI    │   │ event trail  │
│         │   │ toggle_lock │      │ Peruri      │   │              │
│Slot API │   │ ... (21)    │      │ Privy stub  │   │              │
└─────────┘   └──────┬──────┘      │ VIDA stub   │   └──────────────┘
                     │             │ +PAdES+TSA  │
                     ▼             └─────────────┘
              ┌─────────────────────────────────────┐
              │       Ezdoc\{Document,Template,     │
              │       Signature,Audit,DefaultVars}  │
              │              Repositories            │
              │      (fetch/save via Connection)     │
              └─────────────────┬───────────────────┘
                                │
                                ▼
              ┌─────────────────────────────────────┐
              │       Ezdoc\Db\Connection            │  ◄─── contract
              │       interface (driver-agnostic)   │
              └───┬────────────────┬────────────┬───┘
                  │                │            │
                  ▼                ▼            ▼
           ┌──────────────┐  ┌──────────┐  ┌──────────┐
           │MysqliConnect │  │PdoConnect│  │ (future) │
           │(zero-dep,    │  │(mysql/   │  │ DBAL/    │
           │ backward     │  │ sqlite/  │  │ custom   │
           │ compat)      │  │ pgsql/   │  │ adapter) │
           │              │  │ sqlsrv)  │  │          │
           └──────┬───────┘  └────┬─────┘  └──────────┘
                  │               │
                  ▼               ▼
              ┌───────────────────────────────┐
              │  Ezdoc\Db\Grammar\Grammar     │  ◄─── SQL dialect
              │  Mysql|MariaDb|Sqlite|        │
              │  Postgres|SqlServer            │
              └───────────────────────────────┘
                          ▲
                          │
              ┌───────────────────────────────┐
              │  Ezdoc\Db\Schema\Blueprint     │  ◄─── source of truth
              │  (Laravel-familiar DSL)        │
              │  migrations/blueprints/*.php   │
              └───────────────────────────────┘
                          │
                          ▼   (via cli/spec-dump.php)
              ┌───────────────────────────────┐
              │        ezdoc-spec/            │  ◄─── cross-lang artifacts
              │  schema/*.{json,yaml}          │
              │  ddl/{mysql,...}.sql           │
              │  meta/{version,checksum}       │
              │  ─ consumed by Go/Rust/TS ─    │
              └───────────────────────────────┘
```

---

## Directory Structure

```
ezdoc/
├── composer.json                 Package manifest (PHP 7.4+, ext-mysqli/json/mbstring)
├── autoload.php                  PSR-4 autoloader (Composer fallback)
├── bootstrap.php                 Legacy entry (auto-migrate + global helpers)
├── config.php                    Feature flags + path constants
│
├── src/                          Namespaced PHP (Ezdoc\*)
│   ├── App.php                   Orchestrator (App::run, App::demo)
│   ├── Context.php               DI container
│   ├── UUID.php                  v7 (time-ordered, RFC 9562) + v4 generators
│   │
│   ├── Db/                       DB abstraction layer (v0.9.9)
│   │   ├── Connection.php        interface
│   │   ├── Statement.php         interface
│   │   ├── QueryBuilder.php      chainable fluent SQL builder
│   │   ├── Mysqli/               zero-dep backward-compat adapter
│   │   ├── Pdo/                  universal PDO adapter (4 drivers)
│   │   ├── Grammar/              MySQL/MariaDB/SQLite/Postgres/SQLServer
│   │   ├── Schema/               Blueprint, ColumnDef, IndexDef, ForeignKeyDef, Comparator
│   │   ├── Types/                String, Integer, BigInt, Boolean, Json, Uuid, DateTime, ...
│   │   └── Exception/            typed error hierarchy
│   │
│   ├── Document/                 domain layer
│   │   ├── Document.php          value object
│   │   ├── DocumentRepository.php
│   │   ├── DocumentService.php
│   │   ├── SaveDocumentRequest.php
│   │   └── SaveDocumentResult.php
│   ├── Template/                 domain layer
│   │   ├── Template.php
│   │   ├── ParsedTemplate.php
│   │   ├── TemplateParser.php
│   │   └── TemplateRepository.php
│   ├── Signature/                envelope adapters + PAdES + TSA
│   │   ├── SignatureRepository.php
│   │   ├── Envelope/             PKCS#7, PAdES envelope
│   │   ├── Pdf/                  PdfSigner (OpenSSL, JSignPdf, External)
│   │   ├── Timestamp/            RFC 3161 (OpenSSL, HTTP)
│   │   └── Remote/               HttpClient untuk PSrE
│   ├── Audit/
│   │   ├── Logger.php            write side (append-only)
│   │   └── AuditRepository.php   read side
│   ├── DefaultVars/
│   │   └── DefaultVarsRepository.php
│   ├── Auth/
│   │   ├── RoleProvider.php      interface
│   │   ├── HasRoleProvider.php   consumer hasRole() global default (backward-compat shim)
│   │   └── CallableRoleProvider.php  closure-based (Laravel/Symfony friendly)
│   ├── Access/                   RBAC per-template + per-TTD
│   ├── Exceptions/               typed exception hierarchy
│   ├── Http/
│   │   └── Router.php            internal router untuk App::run()
│   ├── Migrations/
│   │   └── Runner.php            schema migration runner
│   └── UI/
│       ├── Theme.php             branding tokens
│       ├── Slot.php              extension injection point
│       ├── ViewResolver.php      customizable view path resolution
│       └── Translator.php        i18n (v0.9.9)
│
├── views/                        User-facing HTML+PHP
│   ├── layout.php                shell (Tailwind + Alpine + Bootstrap Icons)
│   └── document/
│       ├── designer.php          WYSIWYG template designer
│       ├── generate.php          document generator + fill fields + sign
│       └── list.php              document list + recent widget
│
├── actions/                      HTTP endpoint handlers
│   ├── _dispatcher.php           routes ?action=xxx to file
│   ├── document/                 save/delete/lock/version/QR/restore
│   └── template/                 save/copy/delete/lock/analyze/rename
│
├── migrations/
│   ├── 2026_01_01_*.php          Legacy imperative CREATE TABLE
│   └── blueprints/               v0.9.9 Blueprint DSL source of truth
│       ├── ezdoc_templates.php
│       ├── ezdoc_documents.php
│       ├── ezdoc_default_vars.php
│       ├── ezdoc_audit_log.php
│       └── ezdoc_signatures.php
│
├── cli/
│   └── spec-dump.php             Regenerate ezdoc-spec/ (+ --check CI mode)
│
├── ezdoc-spec/                   Generated cross-language artifacts (v0.9.9)
│   ├── schema/                   tables.{json,yaml} — cross-lang descriptor
│   ├── ddl/                      {mysql,mariadb,sqlite,postgres,sqlserver}.sql
│   ├── meta/                     version.json + checksum.txt
│   └── README.md
│
├── lang/                         i18n message bundles (v0.9.9)
│   └── id/                       Bahasa Indonesia
│
├── lib/                          Global function wrappers (backward compat)
├── docs/                         PRD, PAdES/TSA, PSrE integration, UI customization
├── tests/                        PHPUnit
└── public/                       demo entry (Ezdoc\App::run mount)
```

---

## Database Schema

Blueprint source of truth: [`migrations/blueprints/`](migrations/blueprints/).
Generated cross-platform DDL: [`ezdoc-spec/ddl/`](ezdoc-spec/ddl/).

| Table | Purpose |
|-------|---------|
| `ezdoc_templates` | Template design + versioning (uuid family + version chain) |
| `ezdoc_documents` | Document instances, link ke specific template version |
| `ezdoc_default_vars` | Whitelist default variables untuk template placeholder |
| `ezdoc_audit_log` | Persistent event trail (append-only, compliance) |
| `ezdoc_signatures` | Signature envelopes per document (L1/L2/L3 assurance) |
| `ezdoc_migrations` | Track applied migrations |

Key columns across tables:
- **`uuid`** — UUID v7 (36-char, time-ordered, RFC 9562)
- **`metadata`** JSON — extensibility tanpa migration
- **`revision`** INT — optimistic locking counter
- **`content_hash`** SHA-256 — data integrity check
- **`deleted_at`** — soft-delete with audit trail (deleted_by, deleted_reason)

---

## Multi-Language Ecosystem (Strategy)

ezdoc is designed as **spec-first ecosystem** — beberapa **native packages
terpisah** per bahasa, semua honor kontrak yang sama supaya interop:

```
                    ezdoc-spec/          ◄─── contract (source of truth)
                       │
       ┌───────────────┼───────────────┬────────────────┐
       ▼               ▼               ▼                ▼
  ezdoc (PHP)       ezdoc-go       ezdoc-ts        ezdoc-rs
  ─ Packagist       ─ Go modules   ─ npm           ─ crates.io
  ─ v1.0 target     ─ v1.5 planned ─ v2.0 planned  ─ stretch
  (current)         (roadmap)      (roadmap)       (roadmap)
```

### Untuk consumer aplikasi

Pakai package bahasa native — **jangan generate struct sendiri dari spec**.

| Bahasa consumer | Package | Cara install |
|---|---|---|
| PHP | `mrpotensial/ezdoc` | `composer require mrpotensial/ezdoc` |
| Go *(planned v1.5)* | `github.com/mrpotensial/ezdoc-go` | `go get github.com/mrpotensial/ezdoc-go` |
| TypeScript *(planned v2.0)* | `@mrpotensial/ezdoc` | `npm i @mrpotensial/ezdoc` |
| Rust *(stretch)* | `mrpotensial-ezdoc` | `cargo add mrpotensial-ezdoc` |

**Saat ini hanya PHP** yang tersedia. Native ports lain masih di roadmap
(lihat [docs/PRD.md](docs/PRD.md) section 6). Kalau butuh Go/TS/Rust hari ini,
pilihan: pakai PHP ezdoc sebagai HTTP service + call dari bahasa lain, atau
sponsor/bantu native port.

### Untuk port implementer (kontributor)

Kalau kau mau bantu bikin `ezdoc-go` / `ezdoc-rs` / `ezdoc-ts`, `ezdoc-spec/`
adalah **kontrak** yang wajib di-honor supaya interop:

- `schema/tables.{json,yaml}` — DB schema descriptor (single source of truth)
- `ddl/{mysql,mariadb,sqlite,postgres,sqlserver}.sql` — reference DDL
- `meta/{version.json,checksum.txt}` — versioning + CI gate
- (v1.1 planned) `conformance/test-vectors.json` — cross-lang interop tests
- (v1.1 planned) `protocol/*.md` — signature envelope format, hash algo, verify chain

Port harus lulus conformance suite → hasilnya bit-exact identical output antar
port (signature bytes, content hash, canonical JSON). PHP impl jadi first
reference implementation.

### Regenerate spec (development)

```bash
php cli/spec-dump.php           # regenerate ezdoc-spec/
php cli/spec-dump.php --check   # CI gate — fail kalau spec drift dari source
```

`ezdoc-spec/` di-generate dari `migrations/blueprints/*.php` (single source of
truth di PHP layer). Setiap schema change → regen + commit both.

---

## Configuration

```php
Ezdoc\App::run([
    'app' => [
        'base_path'    => '/ezdoc',        // URL prefix untuk App routes
        'query_key'    => 'ezdoc_page',    // ?ezdoc_page=... default
        'default_page' => 'list',
    ],
    'db' => [
        'driver' => 'mysqli' | 'pdo',
        // untuk 'pdo':
        'dsn'  => 'mysql:host=...' | 'sqlite:/path/file.db' | 'pgsql:...' | 'sqlsrv:...',
        'user' => '...',
        'pass' => '...',
    ],
    'auth' => [
        'role_provider' => Ezdoc\Auth\RoleProvider,
    ],
    'brand' => [
        'app_name'      => 'MyApp',
        'primary_color' => '#2563eb',
        'logo_url'      => '/logo.svg',
    ],
    'signature' => [
        'default_level' => 1 | 2 | 3,
        'providers'     => [ /* HMAC, LocalPKI, Peruri, Privy, VIDA configs */ ],
    ],
    'layout' => [
        'nav' => [ 'enabled' => true, 'items' => [ /* custom nav */ ] ],
    ],
]);
```

Or via constants (legacy pattern, BEFORE bootstrap):

```php
define('EZDOC_AUTO_MIGRATE', true);
define('EZDOC_ENFORCE_RBAC', true);
define('DOC_VERIFY_BASE_URL', 'https://verify.example.com');
```

---

## Testing

```bash
composer test                        # PHPUnit full suite
composer stan                        # PHPStan level 6
composer check-compat                # PHP 7.4 compat check
php cli/spec-dump.php --check        # CI gate — spec matches source
```

---

## Compatibility

- **PHP**: 7.4+ (tested 7.4, 8.0, 8.1, 8.2, 8.3)
- **Databases**: MySQL 5.7+/8.0, MariaDB 10.3+, SQLite 3.9+, PostgreSQL 12+,
  SQL Server 2019+
- **Extensions**: ext-mysqli, ext-json, ext-mbstring (required); ext-pdo_* untuk
  non-mysql drivers
- **Optional deps**: `symfony/polyfill-php80` (recommended untuk PHP 7.4 runtime)

---

## Documentation

| Topic | File |
|---|---|
| Product requirements + roadmap | [docs/PRD.md](docs/PRD.md) |
| Quickstart tutorial | [docs/QUICKSTART.md](docs/QUICKSTART.md) |
| App orchestrator API | [docs/APP-API.md](docs/APP-API.md) |
| Signature adapters (L1/L2/L3) | [docs/SIGNATURE.md](docs/SIGNATURE.md) |
| PAdES + RFC 3161 TSA | [docs/PADES-TSA.md](docs/PADES-TSA.md) |
| PSrE integration (Peruri/Privy/VIDA) | [docs/PSRE-INTEGRATION.md](docs/PSRE-INTEGRATION.md) |
| UI customization + Slot API | [docs/UI-CUSTOMIZATION.md](docs/UI-CUSTOMIZATION.md) |
| i18n (translations) | [docs/I18N.md](docs/I18N.md) |
| DB abstraction (v0.9.9) | *coming in W4.2* |
| Cross-language portage | *coming in W4.2* |

---

## License

MIT — see [LICENSE](LICENSE) file.

---

## Roadmap

Ordered by planned release:

- [x] **v0.6** — Signature adapter framework + LocalPKI
- [x] **v0.6.5-0.6.6** — UI extraction + Slot API + ViewResolver
- [x] **v0.7-0.9** — PSrE providers (Peruri) + PAdES + RFC 3161 TSA
- [x] **v0.9.7** — Full designer + generator + list views (WYSIWYG)
- [x] **v0.9.8** — `App::run()` 1-line mount + `App::demo()` zero-config
- [ ] **v0.9.9** — DB abstraction (5 grammars) + Blueprint DSL + spec-first (**in progress**)
- [ ] **v1.0** — PHP release via Packagist
- [ ] **v1.1** — `ezdoc-spec` separate repo publik + conformance test vectors
- [ ] **v1.5** — Go port (`ezdoc-go`)
- [ ] **v2.0** — TypeScript port + Next.js sample + ecosystem
- [ ] Blockchain anchor (planned v0.9.5+) — OpenTimestamps + Polygon

Full roadmap detail: [docs/PRD.md](docs/PRD.md).

---

## Contributing

Sebelum PR:
1. Follow existing code style (PSR-12-ish, 4-space indent)
2. Run `composer test && composer stan` — must pass
3. Untuk schema change: edit `migrations/blueprints/*.php` + run
   `php cli/spec-dump.php` + commit both source + generated
4. Untuk library-neutral naming: hindari "SIMRS"/"SIMpel" spesifik di docblock
   library code — pakai "consumer app" / "host app"
5. Untuk fix industry-standard: cite precedent (Filament/Laravel/Doctrine/
   Symfony/Prisma pattern) di PR description

Issues + PRs welcome at https://github.com/mrpotensial/ezdoc.
