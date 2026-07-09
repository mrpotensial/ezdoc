# Changelog

All notable changes to `ezdoc` will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-07-06 — "Exception & Access classes"

### Added
- `Ezdoc\Exceptions\EzdocException` — base exception (extends `\RuntimeException` untuk backward compat)
- `Ezdoc\Exceptions\AccessDeniedException` — HTTP 403, factory `forAction()` + `missingRole()`
- `Ezdoc\Exceptions\NotFoundException` — HTTP 404, factory `forResource()`
- `Ezdoc\Exceptions\ValidationException` — HTTP 400, factory `forField()` + `forFields()`, error map
- `Ezdoc\Access\PermissionRule` — value object untuk 1 rule (`role:X`, `user:N`, `*`)
- `Ezdoc\Access\AccessConfig` — wrapper untuk template's `access_config JSON` (support canonical + legacy format)
- `Ezdoc\Access\AccessDecision` — result object (allow/deny + reason + matched rule)
- `Ezdoc\Access\AccessControl` — RBAC service, `can()` + `assertCan()` + `hasAnyRole()`
- `ezdoc_access_control()` — global adapter untuk get default AccessControl instance
- `ezdoc_check_access(json, action)` — global adapter untuk check dengan AccessConfig JSON

### Changed
- `Context::fromGlobals()` — throw `EzdocException` instead of raw `\RuntimeException` (backward compat: EzdocException extends \RuntimeException)

## [0.2.0] - 2026-07-06 — "Extract & Harden"

### Added
- Extract 7 inline handlers dari `form_pembuat_surat_v3.php` ke `ezdoc/actions/`:
  - `actions/template/analyze_query.php`
  - `actions/template/list_categories.php`
  - `actions/template/field_usage.php`
  - `actions/template/field_usage_all.php`
  - `actions/template/rename_field.php` (dengan audit log + sanitize)
  - `actions/template/cleanup_orphans.php` (dengan audit log)
  - `actions/default_vars/list_vars.php`
- `_dispatcher.php` whitelist expanded: 12 template actions + 3 default_vars actions (was 3 + 0)
- HMAC secret hardening di `lib/doc_verify_helpers.php`: env var override via `EZDOC_HMAC_SECRET` (precedence: env → file → auto-generate)

### Changed
- `form_pembuat_surat_v3.php`: removed ~230 lines inline handler (routed via dispatcher)
- `list_vars.php`: hilangkan dead-code `CREATE TABLE surat_default_vars` (legacy)
- `rename_field.php`: tambah sanitize new name, tracking skipped conflicts, audit log
- `cleanup_orphans.php`: tambah audit log dengan removedKeys list

## [Unreleased-pre-0.2]

### Added
- PSR-4 namespace `Ezdoc\*` — library-ready untuk Composer install
- `Ezdoc\UUID` class — v7 (time-ordered) + v4 (random) + timestamp extraction
- `Ezdoc\Context` — DI container untuk framework-agnostic usage
- `Ezdoc\Auth\RoleProvider` interface + `HasRoleProvider` (koneksi.php) + `CallableRoleProvider` (closure-based)
- `Ezdoc\Audit\Logger` — namespaced audit trail writer
- `Ezdoc\Migrations\Runner` — namespaced migration runner
- `composer.json` — Composer package definition
- `autoload.php` — PSR-4 fallback autoloader (works without Composer)
- `phpunit.xml` — testing infrastructure
- Basic UUID + Context tests
- `README.md`, `LICENSE` (MIT), `CHANGELOG.md`

### Changed
- **BREAKING (schema)**: Consolidated 13 legacy migrations → 5 canonical migrations
- **BREAKING (schema)**: Tables renamed `surat_*_v2` → `ezdoc_*`:
  - `surat_template_v2` → `ezdoc_templates`
  - `surat_dokumen_v2` → `ezdoc_documents`
  - `surat_default_vars` → `ezdoc_default_vars`
- **BREAKING (columns)**: Semantic column names:
  - `nama_template` → `name`
  - `doc_scope` → `scope`
  - `template_html` → `content`
  - `config_ttd` → `signature_config`
  - `config_header` → `layout_config`
  - `data_fields` → `field_values`
  - `data_ttd` → `signature_values`
  - `data_hash*` → `content_hash*`
- UUID default upgraded v4 → v7 (time-ordered, RFC 9562)
- Global function `ezdoc_uuid_v7()` (was `ezdoc_uuid_v4()`)
- Global function `ezdoc_audit_log()` now routes via `Ezdoc\Audit\Logger`
- Global function `ezdoc_migrate()` now routes via `Ezdoc\Migrations\Runner`
- Global function `ezdoc_has_role()` now routes via `Ezdoc\Auth\RoleProvider`

### Added — Schema improvements
- `uuid` CHAR(36) di semua tables (was numeric ID only)
- `metadata` JSON column — extensibility tanpa migration
- `revision` INT UNSIGNED — optimistic locking counter
- `content_hash` CHAR(64) di ezdoc_templates — integrity check
- `owner_id`, `created_by`, `updated_by` — actor tracking
- Template versioning: `is_current` flag + `parent_version_id` chain
- Document lifecycle: `status` ENUM(draft/published/locked/archived)
- `expires_at` DATETIME di ezdoc_documents — auto-expire support
- `deleted_reason` TEXT — audit trail lengkap
- `event_uuid`, `request_id`, `session_id`, `trace_id` di audit_log — distributed tracing
- `previous_value`, `new_value` JSON — field-level change tracking
- `warning` ENUM di audit result
- `api` ENUM di actor_type
- `DATETIME(3)` millisecond precision di audit_log
- FULLTEXT index di ezdoc_templates(name, category)
- FK constraints ke ezdoc_templates (ezdoc_documents.template_id → RESTRICT delete)
- BIGINT ids (was INT) — future-proof large scale

### Backward Compat
- v2 files (form_pembuat_surat_v2.php, form_pembuat_surat_cetak_v2.php, form_pembuat_surat_list.php) tetap works dengan `surat_*_v2` tables
- Data migration file otomatis copy legacy → new tables saat pertama kali migration jalan
- Global functions (`ezdoc_*`) tetap ada sebagai thin wrappers ke namespaced classes

## [0.1.0] - 2026-06-27

Initial release:
- QR verify with HMAC signature
- Data hash Level 3
- Audit log (v1 schema)
- RBAC per-template + per-TTD
- Template & document actions extracted ke ezdoc/actions/
