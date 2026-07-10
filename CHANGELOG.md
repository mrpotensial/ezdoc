# Changelog

All notable changes to `ezdoc` will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.5] - 2026-07-10 — "Render-path helper extraction (partial UI split)"

### Scope — realistic assessment vs PRD DoD
Original PRD DoD ("v3 files < 100 LOC") was **aspirational** — bulk of the ~9500 LOC across 3 page files is HTML/CSS/JS UI code, not extractable handlers. That true UI split (partials + asset separation) belongs di **v0.6.6 UI packaging**. What v0.6.5 *actually* delivers:

### Added — 3 new helper libraries (~291 lines total, dari cetak + list pages)
- `ezdoc/lib/doc_meta_helpers.php` (62 lines):
  - `ezdoc_fetch_creator_name($conn, $id)` — SELECT nama_pegawai untuk display
  - `ezdoc_load_whitelisted_vars($conn)` — SELECT var_name dari default vars whitelist
- `ezdoc/lib/doc_template_helpers.php` (162 lines):
  - `resolveDefault()` — resolve `{{@varname}}` placeholder ke default value
  - `evalCondExprPHP()` — evaluate conditional expression di template content
  - `evalSingleCondPHP()` — evaluate single condition
  - `processConditionalSections()` — apply `{{#if}}...{{/if}}` conditionals
- `ezdoc/lib/list_helpers.php` (67 lines):
  - `h_list()` — HTML escape wrapper (function_exists-guarded)
  - `ezdoc_relative_time($datetimeStr)` — "5d lalu"/"3m lalu"/"2j lalu"/"4h lalu"/tanggal
  - `ezdoc_doc_link_params($row)` — build cetak URL query params

### Changed — page file slim-down
| File | Before | After | Removed |
|------|--------|-------|---------|
| `page/form_pembuat_surat_v3.php` (designer) | 4478 | 4478 | 0 (all handlers already extracted di v0.2) |
| `page/form_pembuat_surat_cetak_v3.php` (generate) | 4136 | 4032 | **-104** (helper extraction) |
| `page/form_pembuat_surat_list_v3.php` (list) | 608 | 593 | **-15** (helper extraction) |

Total page-file reduction: **-119 lines**, dengan +291 lines masuk ke shared lib files.

### Dispatcher unchanged
Docblock updated dengan "Render-path helpers (v0.6.5)" section — helpers required inline (bukan routed). Whitelist tetap **21 routes** dari v0.2 (12 template + 3 default_vars + 6 doc_action).

### Skipped intentionally — deferred to v0.6.6
- **UI partials extraction** — HTML render blocks (list mode branch ~150 lines, editor mode ~445 lines, modals ~165 lines) belum di-split ke `views/*.php` files
- **JS asset extraction** — main JS block dari v3.php (~3525 lines dari line 951-4476) belum di-move ke `assets/editor.js` — butuh PHP-in-JS injection bridge (5 injection points identified: config JSON di line 992-1036, wrapSaveForLockProtection IIFE, TinyMCE init gate, user-context injection)
- **CSS extraction** — inline `<style>` blocks belum di-split
- **Legacy inline save handler** di `form_pembuat_surat_cetak_v3.php:577-653` — state-coupled dengan `$saveMessage`/`$dbFields`/`$dbTtd`/`$isEditMode`, complex refactor. Modern save flow sudah via `_ajax=1` → `save_document.php` action, tapi legacy branch belum di-remove untuk backward compat
- **Document lookup helper** di `form_pembuat_surat_cetak_v3.php:222-302` — 5 SELECT variants dengan 8+ downstream var assignments. MEDIUM risk — defer sampai domain layer full-refactored (v0.4.1)

### Multi-agent workflow
Milestone di-eksekusi via workflow: 3 research + 3 extract + 1 verify agents. **6/7 agents completed** — list extract agent failed karena JS TDZ error (`${listReport}` typo referencing not-yet-declared var). List extraction dikerjakan manual sequential setelah workflow.
- **7 agents planned, 6 completed, ~343K tokens, ~1h 12min wall-clock**
- Verify agent report: syntax PASS all files, dispatcher audit shows 21 routes correctly whitelisted, zero stray inline `$_POST['action']` handlers di v3 pages

### Design highlights
- **Helper-in-lib pattern** (bukan action-in-actions): 5 SAFE_EXTRACTIONS dari cetak agent semua pure render-path helpers, bukan AJAX endpoints. Dispatch route TIDAK sesuai — moved ke `ezdoc/lib/*.php` sebagai reusable helpers included inline
- **function_exists-guard everywhere**: kalau ada file lain yang define same helper (mis. `h_list()` di v2.php), no redeclaration error
- **Backward compat 100%**: page-file behavior tidak berubah — helpers cuma extracted, tidak refactored logic

### Known concerns
- Legacy save handler di cetak_v3.php:577-653 duplicates modern `save_document.php` flow. Defer removal sampai integration tests exist
- View partials + asset extraction (bulk of file size) masih di v0.6.6
- Real "line count < 200" DoD achievable only after v0.6.6 view resolver + Blade partials

## [0.6.0] - 2026-07-10 — "Signature adapter + LocalPKI + KeyStore"

### Added
- **Signature core** (7 files, ~29 KB): `Ezdoc\Signature\*`
  - `SignatureProvider` — interface (`sign()`, `verify()`, `capabilities()`)
  - `SignRequest` — DTO, auto-computes SHA-256 hex from `contentBytes` if `contentHash` omitted, validates envelope format whitelist
  - `SignResult` — DTO with `envelope` (binary), `envelopeFormat`, `certificatePem`, `providerName`, `level (1-3)`, `signedAt`. `toArray()` base64-encodes envelope untuk binary-safe JSON, plus `toJson()` helper
  - `Verdict` — immutable value object dengan `STATUS_*` constants (VALID/TAMPERED/EXPIRED/REVOKED/UNTRUSTED/ERROR/PENDING). Static factories: `valid()`, `tampered()`, `untrusted()`, `error()`. `isDenied()` excludes `ERROR`
  - `VerifyContext` — DTO carrying `contentBytes`, `expectedSignerId`, `providerHint`, `metadata`
  - `ProviderCapabilities` — DTO with `providerName`, `level`, `supportedFormats`, `supportsTimestamping`, `maxContentBytes`, `notes`, plus `supportsFormat()` helper

- **Providers** (2 files, ~16 KB): `Ezdoc\Signature\Providers\*`
  - `HmacProvider` — L1 baseline. Constructor validates secret ≥ 32 chars (throws `ValidationException`) + algo via `hash_hmac_algos()`. `sign()` HMACs `contentHash` (mirrors legacy `doc_verify_sign_slug` domain-separation pattern). Full-length hex envelope (caller truncate at higher layer if needed). `fromEnv()` reads `EZDOC_HMAC_SECRET` env var or throws `EzdocException`
  - `LocalPkiProvider` — L2. Constructor takes `KeyStore` + alias + algo. `sign()` uses `openssl_sign()` producing binary envelope + cert PEM in result. `verify()` uses `openssl_verify()` with strict `=== 1/0/-1` mapping ke Verdict states. Drains OpenSSL error queue before every call. Prefers cert from `VerifyContext::metadata['certificate_pem']` (persist-then-verify) with KeyStore fallback

- **KeyStore layer** (5 files, ~24 KB): `Ezdoc\Signature\KeyStore\*`
  - `KeyStore` — interface (`loadPrivateKey()`, `loadCertificate()`, `loadChain()`, `hasKey()`)
  - `PrivateKey` — wrapper for cross-PHP-version safety. Internal `mixed` property holds resource (PHP 7.x) atau `OpenSSLAsymmetricKey` (8+). `__destruct` guards `PHP_VERSION_ID < 80000 && is_resource(...)` supaya PHP 8.4 (yang remove `openssl_pkey_free`) tetap aman. Factories: `fromPem(pem, passphrase)`, `fromFile(path, passphrase)`
  - `X509Certificate` — wrapper dengan `getSubjectCN()`, `getIssuerCN()`, `getSerialNumber()`, `getNotBefore/After()`, `isExpired()`, `isValidAt(ts)`. Cross-version safe
  - `EnvKeyStore` — reads env vars `EZDOC_KEY_{ALIAS}_PRIVATE` (base64 PEM), `_CERT`, `_CHAIN`, `_PASSPHRASE`. Alias sanitized (dash→underscore, uppercase)
  - `FileKeyStore` — reads `{rootDir}/{alias}.key/.crt/.chain` files. Alias sanitized `[A-Za-z0-9_-]+` + `realpath()` for path traversal prevention

- **Migration**: `migrations/2026_01_01_000005_create_ezdoc_signatures.php` — creates `ezdoc_signatures` table dengan:
  - Core: uuid, document_id (FK → ezdoc_documents), signature_id_within_doc, signer_id, signer_role, signer_user_id
  - Provider: provider, level (1-3), envelope_format, envelope BLOB
  - Content: content_hash (SHA-256), content_hash_algo
  - Cert (L2/L3): certificate_pem, certificate_serial, certificate_subject, certificate_issuer
  - Timestamp: tsa_response BLOB (untuk v0.8 RFC 3161), signed_at DATETIME(3), verified_at DATETIME(3)
  - Status: verify_status ENUM(valid/tampered/expired/revoked/untrusted/error/pending), verify_reason
  - Metadata JSON + audit columns (created_at, updated_at, deleted_at)
  - Indexes: UNIQUE(uuid), idx_document, idx_signer, idx_provider_level, idx_signed_at, idx_verify_status, idx_cert_serial
  - FK: `document_id` REFERENCES ezdoc_documents(id) ON DELETE RESTRICT ON UPDATE CASCADE

- **Bootstrap**: `bootstrap.php` sanity check now includes `ezdoc_signatures` di `$__ezdocTables`

- **Docs**: `docs/SIGNATURE.md` — 10 KB guide covering:
  - Levels overview (L1 HMAC / L2 LocalPKI / L3 PSrE)
  - Provider decision matrix
  - Quick start L1 (env var + code sample)
  - Quick start L2 (OpenSSL genpkey + req commands + FileKeyStore layout + code sample)
  - Storage layer (ezdoc_signatures columns + query samples)
  - 6-step verification chain
  - Upgrade path L2 → L3 (PSrE)
  - Security considerations (file perms, env-vs-file, rotation, immutability)

### Design highlights
- **Adapter pattern**: single `SignatureProvider` interface, swappable impls. v0.7 (Peruri/Privy/VIDA) tinggal implement interface — consumer code tidak berubah
- **PHP 7.4/8 dual runtime**: OpenSSL handles wrapped di `PrivateKey`/`X509Certificate` classes dengan `mixed` internal + `class_exists('OpenSSL...', false)` runtime check. Zero type-hint di signature (would break 7.4)
- **HMAC domain-separation**: `HmacProvider` signs `contentHash` (bukan raw bytes) mirroring legacy `doc_verify_sign_slug($slug)` pattern
- **Constant-time verify**: HMAC verify pakai `hash_equals()`, OpenSSL verify strict `=== 1` compare
- **OpenSSL error hygiene**: drains error queue before every call supaya diagnostics tidak polluted by stale errors
- **Path traversal defense**: KeyStore aliases sanitized `[A-Za-z0-9_-]+` + `realpath()` di FileKeyStore

### Concerns for future milestones
- `LocalPkiProvider::capabilities()` declares `pkcs7` format tapi cuma `raw` implemented — full PKCS#7/CMS envelope di v0.7 saat handle PSrE integration
- `Verdict::expired` factory missing (v0.6 pakai direct `new Verdict()`). Add factory di v0.7
- `openssl_verify()` PHP 8.4+ can throw untuk malformed input di edge case — pertimbangkan try/catch wrapper di v0.7
- No test files (out of scope; unit tests untuk semua adapter classes di v0.6.1 atau bundle dengan v0.7)
- Existing `lib/doc_verify_helpers.php` procedural HMAC flow TIDAK di-refactor — tetap jalan sebagai backward-compat layer. Consumer library dapat pakai `HmacProvider::fromEnv()` sebagai OOP alternative

### Multi-agent workflow
Milestone di-eksekusi via workflow: 2 research + 3 writer + 1 docs + 1 verify agents. 7 agents, ~309K tokens, ~2h 34min wall-clock. All 14 PHP files pass syntax + zero PHP 8+ leaks. Verify agent double-checked `OpenSSLAsymmetricKey`/`OpenSSLCertificate` only appear in docblocks + runtime checks (never in signatures).

## [0.4.0] - 2026-07-06 — "Document + Template domain classes"

### Added
- **Document domain layer** (5 classes, ~40 KB):
  - `Ezdoc\Document\Document` — immutable value object, `fromRow()` factory back-fills legacy schema columns (`norm`/`nopen`/`label`) into `field_values` array for domain-agnostic API
  - `Ezdoc\Document\SaveDocumentRequest` — DTO for save operations (INSERT/UPDATE) with `expectedRevision` for optimistic locking
  - `Ezdoc\Document\SaveDocumentResult` — DTO returning `documentId`, `uuid`, `revision`, `isNew`, `contentHash`
  - `Ezdoc\Document\DocumentRepository` — CRUD + `findById/findByUuid/findByPublicSlug/listByTemplate/listByStatus`; INSERT auto-generates UUID v7 via `Ezdoc\UUID::v7()`; UPDATE checks `WHERE revision=$currentRevision` for concurrent-write detection; `save()` computes `content_hash` = SHA-256 of canonicalized `field_values` JSON
  - `Ezdoc\Document\DocumentService` — orchestrator: loads template for `access_config`, RBAC check via `AccessControl`, delegates to Repository, emits `document.created` / `document.updated` audit events (or `authz.denied` on RBAC fail via `Logger::denied()`)
- **Template domain layer** (4 classes, ~40 KB):
  - `Ezdoc\Template\Template` — immutable VO; JSON columns decoded in constructor; `getAccessConfigObject()` returns `AccessConfig` instance
  - `Ezdoc\Template\ParsedTemplate` — immutable holder for `{fields, params, signatureSlots}` from parsed template HTML; provides `hasField()`, `getFieldNames()`
  - `Ezdoc\Template\TemplateParser` — instantiable, single `parse()` entry. Regex adapted to actual codebase markers:
    * Params: `/\{\{([^}]+)\}\}/` (double-curly)
    * TTD slots: `ttd-placeholder` div with `data-ttd`, `data-label`, `data-nama-field`, `data-allowed-roles`
    * Fields: union of params + `data-qr` + `materai-placeholder` + TTD `data-nama-field`, deduped by first-appearance order
  - `Ezdoc\Template\TemplateRepository` — mysqli with prepared statements; `findById`, `findByUuid` (latest version), `findCurrentByUuid` (is_current=1), `findByIdOrFail`, `listCurrent/listByOwner/listByCategory`; `save()` INSERT bumps nothing, UPDATE does `revision = revision + 1`; `createNewVersion()` wraps INSERT-new + old-row `is_current=0` in atomic transaction; `softDelete()` sets `deleted_at/by/reason` + `is_current=0`

### Notes
- Domain classes are AVAILABLE for consumer usage, tapi existing procedural `actions/document/*.php` dan `actions/template/*.php` TIDAK di-refactor di release ini (backward compat 100%). Refactor deferred ke milestone berikutnya (v0.4.1 atau v0.6.5 UI extraction).
- **Legacy column back-fill** di `Document::fromRow()` adalah tech-debt untuk v0.6.5 — akan dihapus setelah schema drops `norm`/`nopen`/`label` kolom hardcoded.
- **Optimistic locking**: `Repository::save()` UPDATE detects concurrent writes via `WHERE revision = ?` guard; caller boleh set `SaveDocumentRequest::expectedRevision` untuk explicit CAS check.
- **Slug UNIQUE constraint** di `ezdoc_templates.slug` belum ada di schema (cuma index) — tiny collision window; fix di v0.6.5 migration.

### Multi-agent workflow
This milestone di-eksekusi via workflow orchestration (2 research + 2 writer + 1 verify agents in parallel phases). 5 agents, ~240K tokens, ~34 minutes wall-clock (vs estimated ~1 week solo). All 9 files pass PHP 7.4 syntax + zero PHP 8+ leaks.

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
