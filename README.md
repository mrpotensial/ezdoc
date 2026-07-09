# ezdoc

Framework-agnostic PHP library untuk document generation, template versioning,
RBAC, audit log, dan QR verify.

Framework-agnostic, domain-agnostic, international-first. Cocok untuk berbagai domain: hospital (rekam medis), legal (kontrak), HR (SK karyawan), finance (invoice), education (ijazah), government (surat resmi), insurance, real estate, warehouse, dll.

Universal schema (no `norm`/`nopen` di core — polymorphic subject + dynamic field_schema JSON).

## Features

- **Template Management** dengan versioning (family + versions)
- **Document Lifecycle** — draft / published / locked / archived
- **RBAC per-template** (create/edit/lock/delete) + per-TTD (siapa boleh sign)
- **Public Verification** — QR + HMAC signature + data hash (Level 1-3)
- **Audit Log** — persistent event trail untuk compliance
- **Migration System** — versioned schema changes, idempotent
- **UUID v7** — time-ordered, DB-friendly (RFC 9562)

## Installation

### Via Composer (recommended)

```bash
composer require mrpotensial/ezdoc
```

```php
require_once 'vendor/autoload.php';

// Setup Context (DI container)
$ctx = new Ezdoc\Context(
    db: $mysqli,
    roleProvider: new Ezdoc\Auth\HasRoleProvider(), // or custom
);

// Optionally set as default (for global helpers backward compat)
Ezdoc\Context::setDefault($ctx);
```

### Standalone (untuk monolith non-Composer)

```php
require_once __DIR__ . '/ezdoc/bootstrap.php';

// Global helpers ready to use (backward compat)
ezdoc_audit_log('doc.created', ['doc_id' => 42]);
```

## Configuration

Constants — define BEFORE bootstrap:

```php
define('EZDOC_AUTO_MIGRATE', true);           // Auto-run migrations saat load
define('EZDOC_ENFORCE_RBAC', true);           // Strict mode (false = permissive)
define('EZDOC_TEMPLATE_MANAGER_ROLES', ['superadmin']); // Global template mgmt
define('DOC_VERIFY_BASE_URL', 'https://verify.example.com'); // QR verify URL base
```

## Custom Role Provider (Laravel example)

```php
use Ezdoc\Auth\CallableRoleProvider;

$roleProvider = new CallableRoleProvider(
    hasRole: fn($roles) => auth()->user()?->hasRole($roles) ?? false,
    currentUserId: fn() => auth()->id() ?? 0,
    currentUserRoles: fn() => auth()->user()?->getRoleNames()->all() ?? [],
);

$ctx = new Ezdoc\Context(db: $mysqli, roleProvider: $roleProvider);
```

## Architecture

```
ezdoc/
├── composer.json          Package definition
├── autoload.php           PSR-4 autoloader (Composer fallback)
├── bootstrap.php          Entry point (load libs + auto-migrate)
├── config.php             Feature flags, path constants
│
├── src/                   Namespaced PHP classes (Ezdoc\*)
│   ├── UUID.php           v7 (time-ordered) + v4 generators
│   ├── Context.php        DI container
│   ├── Auth/
│   │   ├── RoleProvider.php          interface
│   │   ├── HasRoleProvider.php       koneksi.php default
│   │   └── CallableRoleProvider.php  closure-based
│   ├── Audit/
│   │   └── Logger.php                event trail writer
│   └── Migrations/
│       └── Runner.php                schema migration runner
│
├── lib/                   Global function wrappers (backward compat)
│   ├── uuid.php
│   ├── responses.php
│   ├── role_provider.php
│   ├── authorization.php
│   ├── audit.php
│   ├── schema.php
│   └── migrations.php
│
├── migrations/            Schema versioning (idempotent .php files)
│   ├── 2026_01_01_000001_create_ezdoc_templates.php
│   ├── 2026_01_01_000002_create_ezdoc_documents.php
│   ├── 2026_01_01_000003_create_ezdoc_default_vars.php
│   ├── 2026_01_01_000004_create_ezdoc_audit_log.php
│   └── 2026_01_01_000099_migrate_legacy_surat_data.php
│
├── actions/               Endpoint handlers (dispatcher-based)
│   ├── _dispatcher.php
│   ├── document/
│   └── template/
│
└── tests/                 PHPUnit tests
    └── UUIDTest.php
```

## Database Schema

### Tables

| Table | Purpose |
|-------|---------|
| `ezdoc_templates` | Template design dengan versioning (uuid family + version chain) |
| `ezdoc_documents` | Document instances, link ke specific template version |
| `ezdoc_default_vars` | Whitelist default variables untuk template placeholder |
| `ezdoc_audit_log` | Persistent event trail (append-only) |
| `ezdoc_migrations` | Track applied migrations |

### Key columns

- **`uuid`** — UUID v7 (36-char, time-ordered)
- **`metadata`** JSON — extensibility tanpa migration
- **`revision`** INT — optimistic locking counter
- **`content_hash`** SHA-256 — data integrity check

## Testing

```bash
composer test
```

## Development

```bash
composer stan  # PHPStan level 6
```

## License

MIT — see LICENSE file.

## Roadmap

- [ ] PDF hash storage (Level 3 verify extend)
- [ ] TTE integration (BSrE, Privy, DocuSign providers)
- [ ] Notification service (email/WA saat sign event)
- [ ] Bulk operations (mass generate docs)
- [ ] Retention policy audit log
- [ ] Multi-tenant support
