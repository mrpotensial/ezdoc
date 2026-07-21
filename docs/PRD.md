# ezdoc — Product Requirements Document

> **Status**: Draft v1 · **Owner**: `mrpotensial` · **Last updated**: 2026-07-06
> **Target audience**: developer yang extend, deploy, atau consume library ini.

---

## 1. Vision

`ezdoc` adalah **framework-agnostic PHP library** untuk sistem generate dokumen berbasis template dengan:

- Template versioning (family + child versions, immutable snapshot)
- Document lifecycle (draft → published → locked → archived)
- RBAC per-template (create/edit/lock/delete) + per-TTD (siapa boleh sign)
- Public QR verification (HMAC signature + content hash, tamper detection)
- Audit trail persistent (compliance-ready)
- Framework portability: plain PHP (monolith), Laravel, Symfony, WordPress, atau consumer app apapun
- **Domain-agnostic** — bukan hospital-only. Sama kode bisa handle rekam medis, kontrak legal, invoice keuangan, SK karyawan, sertifikat pelatihan, dll.
- **International-first** — no locale/lang lock-in, no Indonesian-specific field name di core schema. Timestamps UTC, currency ISO 4217, language BCP 47.

Library ini standalone project — tidak vendor-locked ke industry manapun. Dogfood pertama di sebuah hospital app (koneksi.php-based monolith) sebagai use case validation, tapi core sengaja domain-agnostic sejak hari pertama.

**Target use cases** (sample domain — semua pakai schema yang sama):

| Domain | Contoh dokumen | Subject | Signature level yang cocok |
|--------|----------------|---------|-----------------------------|
| **Hospital** | Rekam medis, surat rujukan, resep obat, informed consent | Patient (norm/RM) | L2 (internal cert) + A1 (audit publik) untuk compliance |
| **Legal / Contract** | Perjanjian sewa, MoU, NDA, kontrak kerja | Party (client_id) | L3 (Peruri/Privy) — legally binding |
| **HR** | SK karyawan, surat peringatan, sertifikat pelatihan | Employee (NIP/emp_id) | L2 (internal cert) atau L3 kalau formal |
| **Finance / Invoice** | Invoice, kwitansi, purchase order | Invoice / customer | L1 + A1 (blockchain anchor untuk audit trail) |
| **Education** | Ijazah, sertifikat kelulusan, transkrip nilai | Student (NIM/student_id) | L3 (BSrE) — legally binding untuk ijazah |
| **Government** | Surat keterangan, izin usaha, dokumen kependudukan | Warga / entity | L3 (BSrE) — mandatory untuk dokumen pemerintah |
| **Insurance** | Polis, klaim, sertifikat | Policyholder | L3 + A1 untuk high-value |
| **Real estate** | Akta jual beli, sertifikat, surat pemilikan | Property (SHM number) | L3 + notaris workflow |
| **Warehouse / Logistics** | Surat jalan, bukti terima barang, packing list | Shipment (BL/AWB) | L1 (internal) atau L2 |

## 2. Success Criteria

### 2.1 v1.0 — PHP native release
| Criteria | Target |
|----------|--------|
| PHP compatibility | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 (dengan polyfill layer) |
| Test coverage | ≥ 70% unit + integration |
| Composer install | `composer require mrpotensial/ezdoc` works standalone (tanpa external app deps) |
| Framework adapters | Laravel service provider + WordPress plugin bootstrap sample |
| Docs | README + PRD + API reference + migration guide dari legacy |
| Security | Hardcoded creds = 0, HMAC secret = env-based, audit RBAC-checked |
| Signature level | L1 (HMAC), L2 (LocalPKI), L3 (Peruri) semua production-ready + PAdES envelope |
| Blockchain anchoring | OpenTimestamps + Polygon production-ready (opsional install) |
| UI packaging | `mrpotensial/ezdoc-ui-blade` publishable via `vendor:publish`, view resolver + slots |
| **Domain-agnostic** | Core schema tidak punya `norm`/`nopen`/hospital-specific column. Polymorphic subject + `field_values JSON`. Sample profiles: hospital, contract, HR, invoice |
| **International-ready** | UTF-8 NFC everywhere, ISO 4217 currency, ISO 3166 country, BCP 47 locale, E.164 phone. Label multi-locale JSON. `utf8mb4_0900_ai_ci` collation |
| **Extensibility** | Consumer bisa register custom profile (mis. `hospital-id`) untuk convenience API tanpa fork core |
| **Full-featured views** | `views/document/{designer,generate,list}.php` ported dari dogfood consumer app — WYSIWYG designer (TinyMCE), document generator (form + TTD signature + materai + PDF), list dengan RBAC filter. Publish + customize pattern (Filament-style). **Consumer install → langsung punya working editor + generator tanpa build sendiri** |
| **Alpine.js interactivity** | All modals + dropdowns + collapse pakai gold-standard Alpine pattern (backdrop no explicit z-index, content wins via DOM order). 22 slot injection points untuk consumer extension |
| **1-line mount pattern** | `Ezdoc\App::run($config)` works out-of-box — front controller + internal router (Filament / Livewire / Nova pattern). Zero manual wiring boilerplate (koneksi + bootstrap + URL config + wrapper page). Plus `Ezdoc\App::demo()` zero-config SQLite mode untuk instant install verification |
| Performance | Migration idempotent, self-heal orphan registry, < 100ms untuk hot paths |

### 2.2 v2.0 — Cross-language ecosystem
| Criteria | Target |
|----------|--------|
| Language ports | PHP (canonical), Go (`mrpotensial/ezdoc-go`), TypeScript (`@mrpotensial/ezdoc-ts`) |
| Shared spec | `ezdoc-spec` repo public dengan JSON Schema + OpenAPI 3.1 + conformance test vectors |
| PSrE integration | Multi-CA support (Peruri + Privy + VIDA, MultiProvider fallback) |
| Signature level | L1-L3 semua production-ready across all languages, bit-exact identical envelope |
| Blockchain anchoring | OpenTimestamps + Polygon + BatchAnchor (Merkle tree) di semua language port |
| PDF envelope | PAdES-B-LT signed PDF (Adobe Reader validation pass) |
| Timestamping | RFC 3161 TSA support (BSrE atau external) |
| Cross-language interop | Sign di PHP → verify di Go/TS = same result (contract-tested via conformance) |
| UI ecosystem | Blade (official) + React (community, shadcn-style) + Vue/Livewire (community) |

## 3. Current State (v0.1-unreleased snapshot)

### 3.1 Foundation Done

| Layer | File | Status |
|-------|------|--------|
| Autoload | `autoload.php` | PSR-4 fallback + polyfill wiring |
| Composer | `composer.json` | php: `>=7.4`, suggest `symfony/polyfill-php80` |
| DI Container | `src/Context.php` | Immutable, `withDb()`/`withRoleProvider()`, `fromGlobals()` |
| UUID | `src/UUID.php` | v7 (RFC 9562), v4, extractTimestampMs, isValid |
| Auth | `src/Auth/{RoleProvider,HasRoleProvider,CallableRoleProvider}.php` | Interface + 2 impls |
| Audit | `src/Audit/Logger.php` | 19-field silent-fail writer |
| Migration | `src/Migrations/Runner.php` | Self-heal orphan registry, batch-continue |
| Polyfill | `lib/polyfill.php` | str_starts_with, str_ends_with, str_contains, get_debug_type, array_is_list, mb_str_pad |
| Bootstrap | `bootstrap.php` | Sanity check tables, EZDOC_STRICT_SETUP flag, friendly error page |
| CLI | `cli/migrate.php` | `status | migrate | reset | fresh` |
| Schema | `migrations/2026_01_01_*` | 5 canonical migrations (templates, documents, default_vars, audit_log, legacy migrator) |

### 3.2 Endpoint / HTTP layer Partial

| Path | Files | Status |
|------|-------|--------|
| `actions/_dispatcher.php` | 1 | Routes 5 verbs (GET generate_qr, POST _doc_action=*, _ajax=1, ajax=1 action=save/toggle_lock/copy_template, action=delete) |
| `actions/document/` | 8 files | save, delete_slot, delete_version, generate_qr, list_versions, new_version, restore_slot, toggle_lock |
| `actions/template/` | 10 files | 4 done (save, copy, delete, toggle_lock) + 6 empty (analyze_query, cleanup_orphans, field_usage, field_usage_all, list_categories, rename_field) |
| `actions/default_vars/` | 3 files | 2 done (add_var, delete_var) + 1 empty (list_vars) |

### 3.3 Domain layer Not yet

24 file `src/` masih **empty scaffolding**:

| Namespace | Empty files | Current fallback |
|-----------|-------------|------------------|
| `Ezdoc\Access\*` | AccessConfig, AccessControl, AccessDecision, PermissionRule | Function-based di `lib/authorization.php` |
| `Ezdoc\Document\*` | Document, DocumentRepository, DocumentService, SaveDocumentRequest, SaveDocumentResult | Procedural code di `actions/document/save_document.php` |
| `Ezdoc\Exceptions\*` | EzdocException, AccessDeniedException, NotFoundException, ValidationException | `\RuntimeException` |
| `Ezdoc\Template\*` | Template, ParsedTemplate, TemplateParser, TemplateRepository | Procedural di `actions/template/*.php` + inline handlers di `page/form_pembuat_surat_v3.php` |

### 3.4 Migration path Clean (v0.9.12)

- **14 legacy migrations DELETED** (v0.9.12 cleanup): `20260701_create_surat_*` (7),
 `20260706_create_ezdoc_*` (3 duplicates), `20260706_migrate_data_surat_to_ezdoc_*` (3),
 `2026_01_01_000099_migrate_legacy_surat_data.php` (1)
- **6 canonical migrations retained**: `2026_01_01_000001..5_create_ezdoc_*` + `2026_07_16_000001_alter_ezdoc_templates_add_floating_elements`
- Registry cleanup: orphan entries (`20260701*`, `20260706*` records tanpa file) can be pruned
 via admin UI ("Prune Orphan Registry Entries" button di `?ezdoc_page=admin_migrate`).
 Idempotent, safe repeated
- Library now **fully ezdoc_*-tables-only** — no consumer-app-specific tables in library scope

### 3.5 Tests Minimal

- Unit: `tests/ContextTest.php` (Context DI, CallableRoleProvider normalization, immutability)
- Belum: Migration runner tests, Audit logger tests, UUID validation tests, integration tests

### 3.6 Signature layer L1 only (target: L1 + L2 + L3 + A1)

Sekarang cuma **Level 1 (HMAC signature)** — cukup untuk internal tamper-detection tapi **tidak legally binding** menurut UU ITE Indonesia. Roadmap: tambah L2 (Local PKI), L3 (PSrE Indonesia), dan A1 (blockchain anchor, orthogonal).

| Level | Mechanism | Legal validity (Indonesia) | Status |
|-------|-----------|---------------------------|--------|
| **L1** | HMAC-SHA256 dengan shared secret + content hash | Not legally binding (tapi tamper-detectable) | Implemented |
| **L2** | Local PKI — self-signed / internal CA cert (X.509) | Non-repudiation within organization | Planned v0.6 |
| **L3** | PSrE Indonesia (Peruri, Privy, VIDA, Digisign, BSrE) | Legally binding per UU ITE 2016 Pasal 11 | Planned v0.7+ |
| **L3+** | L3 + TSA timestamp (RFC 3161) → PAdES-B-LT | Legally binding + verifiable long-term | Planned v0.8 |
| **A1** | Blockchain anchor (hash publik di chain) — orthogonal, boleh combine dengan L1-L3 | Bukti keberadaan pada waktu X (proof-of-existence). Bukan sertifikasi identitas. Combined dengan L3 → strongest guarantee. | Planned v0.9.5 |

> **Note pola pikir**: Level 1-3 = "**siapa** yang tanda tangan + integritas isi". Anchoring (A1) = "**bukti publik** bahwa dokumen ini exist pada waktu X yang tidak bisa diubah setelah ter-anchor". Anchoring bisa combined dengan any level — L3 + A1 = signature legally binding + audit trail publik immutable.

### 3.7 UI Layer Framework done, full views deferred to v0.9.7

**UI framework (v0.6.6) DONE**:
- `Ezdoc\UI\{ViewResolver, Config, Theme, Slot, SlotRegistry, PublishCommand}` — publish-based extension pattern
- Minimal starter templates: `views/{layout,document/list,document/form}.php` (~300 LOC combined)
- Tailwind CSS + Alpine.js CDN loaded via `views/layout.php`
- CSS variable bridge for brand theming

**Full-featured views MASIH di consumer repo** (belum di library):

| File | Line count | Fungsi | Status |
|------|------------|--------|--------|
| `page/form_pembuat_surat_v3.php` | 4465 (post-Tailwind) | Template designer — WYSIWYG TinyMCE, field editor, signature slot builder, RBAC config | Planned v0.9.7-a |
| `page/form_pembuat_surat_cetak_v3.php` | 4040 (post-Tailwind) | Document generator — fill fields, sign TTD, materai, generate PDF, print, verify QR | Planned v0.9.7-b |
| `page/form_pembuat_surat_list_v3.php` | ~600 | List documents + recent widget + category filter + RBAC per-template | Planned v0.9.7-c |

**Sudah selesai (partial UI progress)**:
- v0.2: Extract 7 inline handlers ke `actions/template/*.php` + `actions/default_vars/*.php`
- v0.6.5: Extract render-path helpers ke `lib/{doc_meta,doc_template,list}_helpers.php`
- v0.6.5: Tailwind conversion + Alpine.js modals + backdrop z-index fix (gold standard pattern)
- v0.6.6: UI framework (ViewResolver + Slot + Config + Theme + PublishCommand)
- v0.7.1: `db_helpers.php` (`ezdoc_query_prepared()`) untuk portable DB access

**Gap identified**: Consumer library user butuh **working editor + generator out-of-box**. Library tanpa full views tidak akan di-adopt — majority consumer tidak akan build 9000 LOC WYSIWYG editor sendiri. Framework-only library cocok untuk consumer minority yang mau bangun sendiri.

**Solution**: **v0.9.7 milestone** — port full designer + generator + list dari dogfood consumer app (post-Tailwind versions) ke `ezdoc/views/document/*.php`. Abstract consumer-app-specific globals (`query()`, `hasRole()`, `$author_id`) ke `Context` + `RoleProvider`. Add 22 slot injection points untuk consumer extension. Publish + customize pattern (industri standard: Filament, shadcn/ui).

**Follow-up (v0.9.8)**: Views yang di-port di v0.9.7 masih butuh manual wiring — consumer harus setup koneksi.php + bootstrap.php + URL config + wrapper page untuk dispatch. **v0.9.8** adds `Ezdoc\App` orchestrator (front controller + internal router) supaya consumer cukup 1-line `Ezdoc\App::run($config)` untuk mount semua (list, designer, generator, action, asset routes). Deprecate manual boilerplate. Adopt industri standard mount pattern (Filament, Livewire, Nova).

**Reference implementation**: dogfood consumer app pages sudah dogfooded end-to-end di production — proven featureset yang consumer library user akan expect.

### 3.8 Cryptography Minimal

Sekarang: hash `sha256`, HMAC via `hash_hmac()`, UUID v7 via `random_bytes`. Belum ada:
- Asymmetric crypto (RSA / ECDSA)
- X.509 certificate handling (`openssl_x509_*`)
- PKCS#7 / CMS signing envelope
- PAdES signed PDF
- Timestamp Authority (RFC 3161)
- Key management (env var → file → HSM path)
- Certificate revocation check (CRL / OCSP)

## 4. Gap Analysis

### 4.1 What's blocking library extraction?

| Blocker | Impact | Effort |
|---------|--------|--------|
| **Inline handlers di `page/form_pembuat_surat_v{2,3}.php`** — 9 action masih inline, tidak testable | High: business logic terperangkap di UI file | Medium (extract + write tests) |
| **No Domain classes** — Document/Template/Access hanya procedural | High: consumer library harus paham SQL internal | High (rewrite as OOP) |
| **No Exception hierarchy** — semua pakai `\RuntimeException` | Medium: consumer tidak bisa catch spesifik (mis. hanya AccessDenied) | Low (define classes, wire throwing sites) |
| **Migration legacy masih 13 files** — noise buat consumer baru | Low: functional tapi confusing | Low (delete after production migrated) |
| **No framework adapter** — Laravel/Symfony consumer harus wire manual | Medium: friction untuk adoption | Medium (bootstrap sample per framework) |
| **HMAC secret hardcoded** (belum diverifikasi) | High (security) | Low (env var + `getenv()`) |
| **Tests coverage < 20%** | Medium: risk regressions on refactor | Ongoing |

### 4.2 What's missing untuk 1.0?

Prioritas:

1. **P0** — Extract 9 inline handlers ke `actions/*.php` files
2. **P0** — Wire proper `Exception` classes + refactor throwing sites
3. **P0** — `Ezdoc\App` orchestrator (front controller + internal router) — 1-line mount, deprecate ~200 LOC manual wiring boilerplate per consumer install (v0.9.8)
4. **P1** — Implement `Ezdoc\Document\Document` + Repository + Service (OOP layer)
5. **P1** — Implement `Ezdoc\Template\Template` + Parser + Repository
6. **P1** — Implement `Ezdoc\Access\*` untuk RBAC yang testable & swappable
7. **P2** — Framework adapters (Laravel service provider, WordPress plugin sample)
8. **P2** — Comprehensive test suite (≥ 70% coverage)
9. **P3** — API reference docs (auto-generated dari docblocks)
10. **P3** — Legacy migration cleanup (setelah semua env migrated)

## 5. Architecture

### 5.1 Layered view

```
┌─────────────────────────────────────────────────────────────┐
│ Consumer app (native install, tidak ada bridge/RPC) │
│ ─ PHP: composer require mrpotensial/ezdoc │
│ ─ Go: go get github.com/mrpotensial/ezdoc-go │
│ ─ TS: npm install @mrpotensial/ezdoc │
│ Semua bahasa punya library sendiri yang setara — tidak │
│ ada backend PHP yang di-remote-call oleh Go/TS. │
└────────────────────────┬────────────────────────────────────┘
 │
 HTTP request
 │
┌────────────────────────▼────────────────────────────────────┐
│ HTTP Layer (actions/_dispatcher.php + actions/*.php) │
│ - Whitelist routing (no arbitrary include) │
│ - JSON responses via ezdoc_respond_* │
│ - RBAC guard via ezdoc_require_* │
└────────────────────────┬────────────────────────────────────┘
 │
┌────────────────────────▼────────────────────────────────────┐
│ Domain Layer (src/{Document,Template,Access}/*) ← TODO │
│ - Repository (persistence) │
│ - Service (business logic + orchestration) │
│ - DTO (Request/Result objects) │
│ - Value objects (Template, ParsedTemplate) │
└────────────────────────┬────────────────────────────────────┘
 │
┌────────────────────────▼────────────────────────────────────┐
│ Signature & Crypto Layer (src/Signature/*) ← v0.6+ │
│ - SignatureProvider interface (adapter pattern) │
│ - Impls: HmacProvider (L1), PkiProvider (L2), PSrE (L3) │
│ - Envelope: raw hash | X.509 CMS | PAdES for PDF │
│ - Timestamping: RFC 3161 TSA client │
│ - Key manager: env → file → HSM (PKCS#11) │
└────────────────────────┬────────────────────────────────────┘
 │
┌────────────────────────▼────────────────────────────────────┐
│ Infrastructure (src/{Audit,Migrations,Auth}/*) ← DONE │
│ - Context DI container │
│ - Audit Logger │
│ - Migration Runner │
│ - RoleProvider abstraction │
└────────────────────────┬────────────────────────────────────┘
 │
 mysqli
 │
┌────────────────────────▼────────────────────────────────────┐
│ Storage (MySQL 8+) │
│ - ezdoc_templates (versioning: uuid+is_current+parent) │
│ - ezdoc_documents (JSON fields, HMAC signature) │
│ - ezdoc_signatures (X.509 cert, envelope, TSA response) │
│ - ezdoc_default_vars │
│ - ezdoc_audit_log (event_uuid, actor, target) │
│ - ezdoc_migrations (registry, self-heal) │
└─────────────────────────────────────────────────────────────┘
```

### 5.2 Domain model (planned)

```
Template ────has-many───▶ TemplateVersion (via is_current flag + parent_version_id chain)
 │
 │ owned-by (polymorphic — bisa User, Team, Org)
 ▼
Owner (owner_type + owner_id — bukan hardcoded ke Pegawai)

Template ────generates──▶ Document ────has-many──▶ Signature
 │ │ │
 ▼ ▼ ▼
signature_config JSON Subject (polymorphic) provider-specific
field_schema JSON │ envelope + cert
 │
 subject_type + subject_id
 (Patient / Contract / Employee / Invoice / …)
```

### 5.2b Universal, dynamic, international schema (revised design)

**Problem yang mau dipecahkan**: schema awal punya kolom `norm` (nomor rekam medis) + `nopen` (nomor pendaftaran) — Indonesian hospital specific. Kalau library dipakai untuk kontrak legal, HR letter, atau invoice → 2 kolom itu meaningless.

**Solusi**: 3 lapisan design pattern

#### 1. Polymorphic subject (universal identifier)

Hilangkan `norm` / `nopen` dari core. Ganti dengan `subject_type` + `subject_id` — pattern polymorphic association (Rails / Doctrine / Django convention).

```sql
-- ezdoc_documents (revised core)
CREATE TABLE ezdoc_documents (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 uuid CHAR(36) UNIQUE NOT NULL,
 template_id INT NOT NULL,
 template_uuid CHAR(36) NOT NULL,
 template_version INT NOT NULL,

 -- Universal identity fields (semua domain punya)
 title VARCHAR(255) NULL, -- "Surat Rujukan Poli - 92164" (bebas format)
 reference_number VARCHAR(64) NULL, -- external ref (was 'norm'), any format

 -- Polymorphic subject (nullable — tidak wajib)
 subject_type VARCHAR(64) NULL, -- 'patient' | 'contract' | 'employee' | 'invoice' | …
 subject_id VARCHAR(128) NULL, -- ID di system consumer (bebas format)

 -- Dynamic content
 field_values JSON NOT NULL, -- filled template values (norm, nopen masuk sini)
 metadata JSON NULL, -- consumer-specific extra data

 -- ... rest: status, content_hash, signature stuff, timestamps
);

-- Index untuk lookup polymorphic
CREATE INDEX idx_subject ON ezdoc_documents (subject_type, subject_id);
```

**Migration path untuk existing hospital consumer**:
- `norm` dari kolom → `field_values.norm` + `subject_id = norm` + `subject_type = 'patient'`
- `nopen` dari kolom → `field_values.nopen`
- `reference_number` = `norm` (untuk index cepat)

**Contoh multi-domain di same table**:

| id | title | subject_type | subject_id | reference_number | field_values (JSON) |
|----|-------|--------------|------------|-------------------|---------------------|
| 1 | Surat Rujukan | `patient` | `92164` | `92164` | `{"norm":"92164","nopen":"P-2026-001","diagnosis":"..."}` |
| 2 | Perjanjian Sewa | `contract` | `CTR-2026-01` | `CTR-2026-01` | `{"pihak_1":"...","masa_sewa":"12 bulan"}` |
| 3 | SK Karyawan | `employee` | `EMP-042` | `SK-042-2026` | `{"nama":"Budi","jabatan":"Manager","gaji":8000000}` |
| 4 | Invoice | `invoice` | `INV-8912` | `INV-8912` | `{"pelanggan":"...","total":15000000,"currency":"IDR"}` |

#### 2. Dynamic field_schema (schema on template, bukan on DB)

Template define fields sendiri. Zero hardcoded columns per domain.

```sql
-- ezdoc_templates.field_schema JSON
[
 {
 "name": "patient_name",
 "type": "string",
 "required": true,
 "label": {"en": "Patient Name", "id": "Nama Pasien"},
 "validate": {"min_length": 2, "max_length": 100}
 },
 {
 "name": "diagnosis",
 "type": "textarea",
 "required": true,
 "label": {"en": "Diagnosis", "id": "Diagnosa"}
 },
 {
 "name": "birth_date",
 "type": "date",
 "required": false,
 "label": {"en": "Date of Birth", "id": "Tanggal Lahir"}
 },
 {
 "name": "billed_amount",
 "type": "money",
 "required": true,
 "label": {"en": "Amount"},
 "currency": "IDR",
 "validate": {"min": 0}
 }
]
```

Supported field types (universal):
- **Primitive**: `string`, `textarea`, `integer`, `decimal`, `boolean`
- **Temporal**: `date`, `time`, `datetime`, `duration` (ISO 8601)
- **Formatted**: `email`, `url`, `phone` (E.164), `uuid`
- **Universal ID**: `subject_ref` (link ke subject_type/subject_id)
- **Money**: `money` (dengan currency code ISO 4217)
- **Enum**: `enum` (dengan `options: [{value, label: {...}}]`)
- **Composite**: `array`, `object` (nested schema)
- **File**: `file`, `image` (dengan `mime`, `max_size`)
- **Signature slot**: `signature` (link ke ezdoc_signatures)

#### 3. International-first conventions

Zero locale/language lock-in di core schema:

| Concern | Convention (universal) | Contoh |
|---------|------------------------|--------|
| **Timestamp** | UTC storage, ISO 8601 dengan ms | `2026-08-15T10:30:00.123Z` |
| **Currency** | ISO 4217 code + amount as integer minor units | `IDR`, `USD`; amount=1500000 (Rp 15,000) |
| **Language / Locale** | BCP 47 tag | `id-ID`, `en-US`, `ar-SA` |
| **Country** | ISO 3166-1 alpha-2 | `ID`, `US`, `SG` |
| **Phone** | E.164 (`+62 812...`) | `+6281234567890` |
| **Person name** | UTF-8 NFC, `given_name` + `family_name` (bukan `nama_lengkap`) | Support Chinese, Arabic, Indonesian names |
| **Label / message** | Multi-locale object `{lang: text}` | `{"en": "Amount", "id": "Jumlah", "ar": "المبلغ"}` |
| **Number format** | Store as number, format via consumer's locale | Store `1500000`, render `Rp 1.500.000` (id) or `Rp 1,500,000` (en) |
| **Sort collation** | `utf8mb4_0900_ai_ci` (unicode-aware, accent-insensitive) | Support alfabetis bahasa apapun |

**Charset & collation** (MySQL default per DDL):
```sql
CREATE DATABASE ezdoc CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

`utf8mb4` support 4-byte characters — emoji, langka CJK, Arabic + Hebrew. `_0900_ai_ci` = Unicode 9.0 aware, accent-insensitive, case-insensitive.

**Label / message pattern**:
```json
{
 "en": "Patient Name",
 "id": "Nama Pasien",
 "ms": "Nama Pesakit",
 "ar": "اسم المريض"
}
```
Fallback: kalau requested locale tidak ada, fall back ke `en`, lalu key pertama.

#### 4. Extensible via profile (optional)

Untuk consumer yang mau custom aggressive tanpa fork:

```php
// consumer's app
Ezdoc\Schema\ProfileRegistry::register('hospital-id', [
 'subject_types' => ['patient'],
 'convenience_columns' => ['norm', 'nopen', 'poli'],
 'label_translations' => require 'hospital-id-labels.php',
 'validators' => [
 'norm' => fn($v) => preg_match('/^\d{6,10}$/', $v),
 ],
]);

Ezdoc\Context::default()->useProfile('hospital-id');
```

Profile jadi 1 layer di atas core — tetap pakai `field_values JSON` under the hood, tapi expose `->norm` getter untuk ergonomi.

### 5.3 Access model (planned)

```
AccessConfig (JSON di ezdoc_templates.access_config):
 {
 "create": ["role:admin", "role:manager"],
 "edit": ["role:admin", "user:42"],
 "lock": ["role:admin"],
 "delete": ["role:admin"]
 }

AccessDecision:
 - allow: bool
 - reason: string (untuk audit log denied event)
 - matched_rule: string

AccessControl:
 - can(User, Action, Template): AccessDecision
 - assertCan(User, Action, Template): void throws AccessDeniedException
```

### 5.4 Signature adapter pattern (planned v0.6+)

Interface tunggal, banyak backend. Sama pattern seperti Laravel's Filesystem drivers.

```
Ezdoc\Signature\SignatureProvider (interface)
 sign(SignRequest): SignResult // hasil = bytes envelope + metadata
 verify(bytes, VerifyContext): Verdict // valid | tampered | expired | untrusted
 capabilities(): ProviderCapabilities // level, supports timestamping, dll

Impls (Signature — L1 s/d L3):
 - HmacProvider — L1 (existing behavior, tetap default)
 - LocalPkiProvider — L2, self-signed atau internal CA cert
 - PeruriProvider — L3, Peruri Digital Sign API
 - PrivyProvider — L3, Privy REST API
 - VidaProvider — L3, VIDA e-Sign
 - BsreProvider — L3, BSrE (government) — biasanya via cert issuance saja
 - MultiProvider — chain of providers (fallback / redundancy)
```

**Anchoring adapter (orthogonal, boleh combine dengan SignatureProvider level manapun):**

```
Ezdoc\Anchor\AnchorProvider (interface)
 anchor(bytes: hash): AnchorReceipt // publish hash ke chain, return tx receipt
 verify(receipt): AnchorVerdict // baca chain, konfirmasi hash exist di waktu X
 status(receipt): AnchorStatus // pending | confirmed | orphaned
 capabilities(): AnchorCapabilities // chain name, avg cost, avg confirmation time

Impls:
 - EthereumMainnetAnchor — mainnet, expensive tapi paling immutable (~$5-50/anchor)
 - PolygonAnchor — L2 EVM, murah (~$0.01/anchor), 2-second block time
 - ArbitrumAnchor — L2 EVM, tengah-tengah
 - OpenTimestampsAnchor — Bitcoin OP_RETURN via OpenTimestamps.org (free, batched)
 - HyperledgerFabricAnchor — permissioned, untuk consortium (mis. RS-RS Indonesia)
 - LocalChainAnchor — Ganache/Anvil untuk dev/test
 - BatchAnchor — decorator: aggregate N docs → 1 merkle root → 1 anchor
```

**Composition pattern** (blockchain adalah decorator, bukan replacement):

```php
// L3 sign + anchor ke Polygon
$signer = new PeruriProvider($peruriConfig);
$anchor = new PolygonAnchor($polygonConfig);

$result = $signer->sign($request); // → signature envelope + cert
$receipt = $anchor->anchor($result->contentHash); // → tx hash di Polygon

$doc->signature_envelope = $result->envelope;
$doc->anchor_receipt = $receipt; // stored terpisah, opsional
```

**Config per template**: `signature_config JSON` di `ezdoc_templates`:
```json
{
 "provider": "peruri",
 "level": 3,
 "require_timestamp": true,
 "signers": [
 { "role": "dokter_penanggung_jawab", "min_count": 1 },
 { "role": "kepala_bagian", "min_count": 1 }
 ],
 "envelope": "pades-b-lt"
}
```

**Storage**: kolom baru `ezdoc_signatures` (v0.6):
```
id UUID PRIMARY KEY
document_id INT NOT NULL
signer_id INT NOT NULL
provider VARCHAR(32) -- 'hmac' | 'local_pki' | 'peruri' | ...
level TINYINT -- 1 | 2 | 3
envelope MEDIUMBLOB -- raw CMS / PAdES bytes
certificate_pem TEXT -- signer's cert (untuk L2/L3)
tsa_response BLOB -- RFC 3161 timestamp (kalau ada)
signed_at DATETIME(3)
verified_at DATETIME(3) -- last verify
verify_status ENUM('valid', 'tampered', 'expired', 'revoked', 'untrusted')
```

**Storage**: kolom baru `ezdoc_anchors` (v0.9.5, untuk blockchain anchoring):
```
id UUID PRIMARY KEY
document_id INT NOT NULL
signature_id UUID NULL -- optional link ke signature (kalau anchor'ed post-signing)
anchor_provider VARCHAR(32) -- 'ethereum' | 'polygon' | 'opentimestamps' | ...
chain_id BIGINT NULL -- 1 (Ethereum), 137 (Polygon), NULL for non-EVM
tx_hash VARCHAR(128) -- transaction hash on chain
block_number BIGINT NULL -- untuk verifiable ordering
block_timestamp DATETIME(3) -- proof-of-existence timestamp
merkle_root VARCHAR(128) NULL -- kalau pakai BatchAnchor (multi-doc → 1 anchor)
merkle_proof JSON NULL -- inclusion proof kalau batched
content_hash CHAR(64) -- SHA-256 hash yang di-anchor
anchor_status ENUM('pending', 'confirmed', 'orphaned', 'failed')
gas_fee_wei DECIMAL(30,0) NULL -- untuk cost accounting
anchored_at DATETIME(3)
confirmed_at DATETIME(3) NULL -- diisi setelah N confirmations
```

### 5.5 Cryptography layer (planned v0.6+)

**Key management hierarchy** (paling secure duluan):
```
Priority:
 1. HSM via PKCS#11 (production untuk L3)
 2. Env var (secret key untuk L1/HMAC)
 3. File path (PEM cert untuk L2, dengan permissions 0600)
 4. Config file (fallback dev, tidak untuk prod)

Interface: KeyStore
 loadPrivateKey(alias: string): PrivateKey
 loadCertificate(alias: string): X509Certificate
 loadChain(alias: string): array<X509Certificate>
```

**Signature envelopes** yang disupport:
| Envelope | Use case | Standard |
|----------|----------|----------|
| Raw HMAC | Internal tamper-detect | (custom) |
| PKCS#7 / CMS | Signed data blob | RFC 5652 |
| PAdES-B-B | PDF signed (basic) | ETSI EN 319 142 |
| PAdES-B-LT | PDF signed + long-term validation | ETSI EN 319 142 |
| XAdES | Signed XML (untuk BSSN interop) | ETSI EN 319 132 |

**Timestamping** (RFC 3161):
- Client: `TimestampClient` — POST hash ke TSA, parse response
- Providers: BSrE TSA, external TSA (freetsa.org untuk dev)
- Stored di `ezdoc_signatures.tsa_response`

**Verification chain**:
1. Content hash re-computed match `content_hash`
2. Signature envelope valid (crypto check)
3. Certificate valid + not expired
4. Certificate chain to trusted root (CA bundle)
5. Certificate not revoked (CRL / OCSP — optional)
6. Timestamp valid + within cert validity window (L3+)
7. Anchor receipt (kalau ada) — hash on-chain match, block confirmed, tx not orphaned (A1)

### 5.6 Blockchain anchoring architecture (planned v0.9.5+)

**Model**: anchoring = publikasikan `SHA-256(content)` ke blockchain publik yang immutable + append-only. Bukti dokumen sudah exist pada waktu block X, tidak bisa diubah/di-backdated setelahnya.

**Kenapa orthogonal dari L1-L3 signature**:
- L1-L3 menjawab: **siapa yang tanda tangan?** (identity + integrity)
- Anchoring menjawab: **kapan dokumen ini exist?** (proof-of-existence, tamper-evidence publik)
- Combine: L3 (Peruri) + Polygon anchor → signature legally binding + audit trail publik immutable. Regulator / auditor bisa verify sendiri tanpa akses ke consumer's DB.

**Chain choice matrix**:

| Chain | Cost per anchor (2026 est.) | Confirmation | Immutability | Use case |
|-------|------------------------------|--------------|--------------|----------|
| **Ethereum mainnet** | $2-20 (gas) | 12-15 sec/block × N conf | Ultimate | High-value docs, insurance, contracts >Rp 100jt |
| **Polygon PoS** | $0.001-0.01 | 2 sec/block | Sangat kuat | Default recommendation — cheap + fast + EVM compat |
| **Arbitrum One** | $0.05-0.5 | ~250ms | (rooted di Ethereum) | Middle ground, EVM compat |
| **OpenTimestamps (Bitcoin)** | Free (batched) | ~1 jam | Ultimate | Free, no wallet, batched per hour. Verify tanpa kita running node. |
| **Hyperledger Fabric** | Zero (self-hosted) | <1 sec | (permissioned) | Konsorsium RS-RS Indonesia dengan governance shared |
| **BSV** | ~$0.001 | 10 min | | Alternatif Bitcoin, dukung OP_RETURN besar |

**Rekomendasi default**: **OpenTimestamps** (free, mature, tidak butuh wallet management) + optional **PolygonAnchor** untuk yang butuh EVM/smart contract capabilities.

**Anchor flow**:

```
1. Consumer trigger anchor (via config auto-anchor atau explicit call):
 $anchor = new PolygonAnchor($config);
 $receipt = $anchor->anchor($doc->content_hash);

2. Adapter internal:
 a. Sign tx dengan operator private key (env var, tidak per-signer)
 b. Submit tx ke chain: contract.storeHash(hash, docId)
 c. Return AnchorReceipt { tx_hash, submitted_at, status='pending' }

3. Background worker (cron / queue) update status:
 a. Poll receipt status: tx confirmed di block N?
 b. Update ezdoc_anchors.status = 'confirmed', confirmed_at = ...

4. Verify (later, by regulator/auditor):
 $verdict = $anchor->verify($receipt);
 → verdict.exists = true
 → verdict.block_number = 12345678
 → verdict.block_timestamp = "2026-08-15T10:30:00Z"
 → verdict.content_hash_on_chain = 'abc...' → must match doc's current hash
```

**Cost mitigation — BatchAnchor decorator**:

Untuk high-volume (mis. semua rekam medis harian), jangan anchor per-doc — build Merkle tree, anchor cuma root:

```
Daily job (jam 00:00):
 hashes = SELECT content_hash FROM ezdoc_documents WHERE anchored=0 AND date=today
 merkle = buildMerkleTree(hashes)
 receipt = anchor.anchor(merkle.root)
 for each doc:
 doc.anchor_receipt_id = receipt.id
 doc.merkle_proof = merkle.getProof(doc.hash)
 doc.anchored = 1
```

Hasil: 10,000 dokumen/hari cuma perlu **1 tx** — cost dari $50 turun jadi $0.001. Individual verify tetap possible via merkle proof.

**Smart contract minimal (Solidity, untuk EVM chains)**:

```solidity
// EzdocAnchor.sol — deploy sekali per chain
contract EzdocAnchor {
 event DocumentAnchored(bytes32 indexed contentHash, string docId, uint256 timestamp);
 mapping(bytes32 => uint256) public anchoredAt; // hash → block.timestamp

 function anchor(bytes32 contentHash, string calldata docId) external {
 require(anchoredAt[contentHash] == 0, "Already anchored");
 anchoredAt[contentHash] = block.timestamp;
 emit DocumentAnchored(contentHash, docId, block.timestamp);
 }

 function verify(bytes32 contentHash) external view returns (uint256) {
 return anchoredAt[contentHash]; // 0 = not anchored, else timestamp
 }
}
```

**Verifiability (publik, tanpa akses ke library kita)**:

Consumer bisa provide "verify link" ke third-party:
```
https://polygonscan.com/tx/0xabc... ← siapa saja bisa lihat
 ← hash: 0xdef... exists di block 12345 tanggal 2026-08-15
```
Anyone can independently verify by:
1. Compute SHA-256 dari PDF mereka
2. Compare dengan hash di polygonscan
3. Kalau match → doc genuinely existed pada waktu block itu

Zero trust ke library maintainer / consumer — proof publik.

**Privacy considerations**:
- ONLY hash yang di-publish ke chain (never content plaintext)
- Hash tidak reveal content (SHA-256 preimage resistance)
- Doc ID juga hashed atau opsional (untuk avoid enumeration attack)
- Compliance: HIPAA / GDPR / UU PDP OK karena hash bukan PII

**W3C Verifiable Credentials interop (stretch, v2.x+)**:
- Alternatif untuk hospital consortium: DID (Decentralized Identifier) + VC
- Signer punya DID (`did:web:yourorg.com/user/handle`) + private key
- Signature = Ed25519 signature sesuai spec W3C
- Verifiable di manapun tanpa CA vendor (peer-to-peer trust via DID)
- Trade-off: butuh signer punya wallet + manage DID (UX friction)

### 5.7 Cross-language native ports (planned v1.5+)

**Model: arsitektur yang di-reuse, implementation di-rewrite native.**

Yang **di-reuse** (bukan di-port, ini SAMA persis di semua bahasa):
- DB schema (`ezdoc_*` tables + kolom + index)
- Domain model (Template versioning, Document lifecycle, RBAC config JSON)
- Signature envelope binary format (PKCS#7 / PAdES bytes)
- Content hash algorithm (JSON canonicalization → SHA-256)
- QR payload format (versioned struct)
- Verify protocol (step-by-step chain check)
- Audit event schema
- HTTP API contract (kalau consumer expose endpoint)

Yang **di-rewrite native** per bahasa:
- Class hierarchy → idiomatic per bahasa (Go struct+interface, TS class, PHP class)
- Autoloader / module system
- Error handling style (Go error, TS/PHP Exception)
- Concurrency model (Go goroutines, TS Promise, PHP sync)
- Test framework (PHPUnit, Go testing, Vitest)
- Framework adapter (Laravel provider, Nest module, Gin middleware)

Setiap bahasa punya **library standalone** yang consumer install & pakai langsung di runtime mereka. Tidak ada backend PHP yang jadi central; tidak ada REST/gRPC bridge. Container Go pakai `ezdoc-go` binary murni; container Node pakai `@mrpotensial/ezdoc` npm murni. Semua konek ke same DB, produce same signature bytes, verify same envelope — because they follow the same architecture spec.

Alasan pilih native ports (bukan bridge):

| Aspect | Native port | Bridge (PHP backend + client) |
|--------|-------------|-------------------------------|
| Deployment | Single container per app | Butuh PHP-fpm + reverse proxy jaringan |
| Latency | In-process function call (~μs) | Network round-trip (~ms) |
| Failure mode | Fail with app | New failure surface (network partition, PHP down) |
| Ops complexity | Zero (built-in library) | Full stack: PHP runtime, DB pool, load balancer |
| Language DX | Idiomatic per bahasa | Awkward serialize/deserialize on every call |
| Offline / edge | Cloudflare Workers, mobile, air-gapped | Butuh network ke PHP host |

**Spec-first coordination** — supaya port beda-beda tetap kompatibel:

```
ezdoc-spec/ # separate repo — single source of truth
├── schemas/ # JSON Schema (draft 2020-12) + YAML mirror
│ ├── tables.yaml # DB schema descriptor (seeded v0.9.9)
│ ├── enums.yaml # enum values (status, signature_level, etc)
│ ├── template.json # Template domain model
│ ├── document.json # Document domain model
│ ├── signature.json # SignRequest / SignResult / Verdict
│ └── audit-event.json # AuditLog record
├── protocol/ # binary & wire formats
│ ├── signature-envelope.md # PKCS#7 / PAdES bytes format
│ ├── content-hash.md # canonical JSON → SHA-256 algorithm
│ ├── verify-protocol.md # verify chain step-by-step
│ ├── qr-payload.md # QR versioned payload format
│ ├── hash-algos.json # SHA-256/SHA-512 constants (seeded v0.9.9)
│ ├── signature-levels.json # L1/L2/L3/A1 (seeded v0.9.9)
│ └── envelope-types.json # PAdES/CAdES/XAdES (seeded v0.9.9)
├── ddl/ # generated SQL DDL per platform (seeded v0.9.9)
│ ├── mysql.sql
│ ├── mariadb.sql
│ ├── sqlite.sql
│ ├── postgres.sql
│ └── sqlserver.sql
└── conformance/ # test fixtures untuk cross-lang interop (v1.1)
 ├── test-vectors.json # input+expected output pairs
 └── signatures/*.pem # sample cert + signature bytes
```

**Native implementations** — masing-masing repo sendiri, tim/kontributor bisa beda:

| Language | Package | Runtime | Container use case |
|----------|---------|---------|--------------------|
| PHP | `mrpotensial/ezdoc` (Packagist) | 7.4+ | Monolith (koneksi.php-based, WordPress, Laravel) |
| Go | `github.com/mrpotensial/ezdoc-go` | 1.21+ | Standalone verify service, CLI tool, Kubernetes microservice |
| TypeScript | `@mrpotensial/ezdoc` (npm) | Node 20+ / Bun / Deno | Next.js server actions, Nest API, browser verify UI |
| Rust *(stretch)* | `mrpotensial-ezdoc` (crates.io) | stable | Cloudflare Workers, embedded, extreme perf |

Each port:
- Reads same DB tables (`ezdoc_*`)
- Produces same audit log format
- Sign/verify menghasilkan bytes yang bit-exact identical
- Punya own idiomatic API (Go pakai error return, TS pakai Promise, PHP pakai Exception)

**Conformance test contract**:
- Setiap port punya CI job yang import `ezdoc-spec/conformance/`
- Test: "given this template JSON + this key, produce this signature envelope byte-for-byte"
- Test: "given this signed document, verify returns Verdict.valid"
- Kalau fail → PR blocked. Non-negotiable.

**Deterministic serialization** (kunci interop):
- Content hash: canonicalized JSON per **RFC 8785 (JCS)** → SHA-256
- JSON keys sorted lexicographically
- Numbers pakai IEEE 754 double representation
- Strings NFC-normalized Unicode
- Timestamps ISO 8601 UTC dengan millisecond precision (`2026-07-06T14:32:15.123Z`)
- UUIDs v7 lowercase hyphenated
- Binary → base64url (RFC 4648 §5, no padding)

**Non-portable parts** (per-language freedom):
- Autoloader / module system
- Framework adapter (Laravel provider vs Nest module vs Gin middleware)
- Test framework (PHPUnit vs Go testing vs Vitest)
- Idiom (naming convention, error handling style)

### 5.8 UI Layer — headless core + reference UI (planned v0.6.5+ / v0.9.7)

**Problem**: 3 halaman v3 (designer, generate/cetak, list) = 9500 baris HTML/CSS/JS + inline PHP handler. Ini fitur inti library. Consumer WAJIB bisa pakai out-of-box, TAPI juga WAJIB bisa custom (branding, layout, extra field), AND WAJIB portable ke framework lain (Laravel, Next.js, Rust) untuk cross-language ecosystem.

**Cross-framework portability design principle** (mandatory untuk v0.9.7+):

Views di library WAJIB dumb — cuma HTML + fetch calls ke endpoints. Semua business logic di Service classes yang expose via HTTP contract. Consumer boleh:
- Pakai library's PHP views out-of-box (TinyMCE + Alpine + Tailwind)
- Publish + edit (Filament-style)
- Ignore library views total, build sendiri di React/Vue/Rust — YANG PENTING mereka honor endpoint contract + template content spec

Contract sources of truth (framework-agnostic):
- `ezdoc-spec/schemas/*.{json,yaml}` (seeded v0.9.9, enriched v1.1) — data models (Document, Template, Signature) + DB schema descriptors
- `ezdoc-spec/ddl/*.sql` (seeded v0.9.9) — generated DDL per platform (mysql/mariadb/sqlite/postgres/sqlserver)
- `ezdoc-spec/protocol/*.{md,json}` (seeded v0.9.9, enriched v1.1) — template content format, field markers, signature envelope, hash-algo constants, signature levels
- `ezdoc-spec/openapi.yaml` (v1.1 milestone) — REST API contract
- `ezdoc-spec/conformance/test-vectors.json` (v1.1 milestone) — cross-language interop tests

Framework adapters (per language):
- **PHP** (library default v0.9.7): `ezdoc/views/document/*.php` — plain PHP + TinyMCE + Alpine
- **Laravel Blade** (v0.5 or v0.9.7 companion): `packages/ezdoc-ui-blade/` — Blade wrapper
- **React/Next.js** (v2.0): `@mrpotensial/ezdoc-ui-react` — TipTap + shadcn
- **Vue** (community stretch): `@mrpotensial/ezdoc-ui-vue` — Quill + Headless UI
- **Livewire** (community stretch): `mrpotensial/ezdoc-ui-livewire`
- **HTMX** (community stretch): plain HTML + HTMX + Alpine — universal
- **Rust** (community stretch): Yew/Leptos WASM

Semua framework adapters implement SAME endpoint contract + SAME content spec → user data portable across frameworks. Consumer boleh migrate dari PHP monolith ke Next.js SPA tanpa lose data.



**Pattern industri proven** untuk shipping customizable UI dari library:

| Pattern | Contoh library terkenal | Trade-off |
|---------|-------------------------|-----------|
| **A. Publish/eject views** | Laravel Filament, Backpack, Nova; Django templates | Consumer dapat file, edit langsung. Simple, tapi update library break custom-nya kalau ada schema change. |
| **B. Headless + reference UI** | shadcn/ui, Radix UI, TanStack Table, Headless UI | Core library zero-UI. UI package terpisah. Consumer install atau copy-paste. Most flexible. |
| **C. Slot/render-prop** | React components (Ariakit, MUI), Vue slots | Component API accept `renderX` props. Bagus untuk small components, susah untuk full page. |
| **D. Theme override + hook** | WordPress, Shopify, CKEditor 5 | Theme file di path tertentu override default. Butuh view resolver + convention. |

**Recommendation**: **B + A hybrid** — mainstream industri (shadcn model dengan Laravel publish semantic).

```
Library terdiri dari 3 layer:

┌─────────────────────────────────────────────────────────────┐
│ Core (headless) ← "mrpotensial/ezdoc" │
│ - Domain logic, RBAC, migration, audit, signature │
│ - Zero HTML, zero CSS, zero JS │
│ - Just PHP classes + REST/action endpoints │
│ - Consumer bisa build UI apapun di atas ini │
└─────────────────────────────────────────────────────────────┘
 │
 ┌────────────────┴──────────────┐
 │ │
┌────────────▼────────────┐ ┌──────────────▼──────────────┐
│ Reference UI (default) │ │ Consumer's own UI (custom) │
│ "mrpotensial/ezdoc-ui-blade" │ │ React/Vue/whatever │
│ - Blade views │ │ - Panggil action endpoints │
│ - Vanilla JS + Bootstrap│ │ - Consumer own layout │
│ - Publishable │ │ │
│ - Copy → own it │ │ │
└─────────────────────────┘ └─────────────────────────────┘
```

**Layer 1: Core (headless)** — `mrpotensial/ezdoc`
- Zero UI. Cuma domain + endpoint.
- Consumer bisa 100% skip reference UI, bangun UI sendiri di atas action endpoints.

**Layer 2: Reference UI** — `mrpotensial/ezdoc-ui-blade` (paket terpisah, opsional)
- Ship 3 view: `designer.blade.php`, `document-form.blade.php`, `document-list.blade.php`
- Ship CSS + JS assets (Bootstrap-based, vanilla JS, no build step required)
- Ship publish command: `php artisan vendor:publish --tag=ezdoc-views`
- Ship view resolver: resolve `ezdoc::designer` → cek consumer's `resources/views/vendor/ezdoc/designer.blade.php` dulu, fallback ke library default

**Layer 3: Consumer customization** — 4 tingkat, dari paling ringan ke paling berat:

| Tingkat | Cara | Effort | Use case |
|---------|------|--------|----------|
| **1. Config only** | Setting nama, logo, warna via `ezdoc.config.php` | 5 menit | Rebrand ringan |
| **2. CSS override** | Load CSS tambahan setelah library CSS | 30 menit | Custom color scheme, spacing |
| **3. View override (publish)** | `php artisan vendor:publish --tag=ezdoc-views` → edit file | 1-2 jam | Rearrange layout, add fields |
| **4. Full replacement** | Build UI sendiri, panggil action endpoints saja | 1-2 minggu | Convert ke React/Vue SPA, integrate ke dashboard existing |

**View resolver contract** (industri standar, dari Laravel):

```php
// bootstrap.php
Ezdoc\UI\ViewResolver::register([
 'designer' => 'ezdoc-ui-blade::designer',
 'document-form' => 'ezdoc-ui-blade::document-form',
 'document-list' => 'ezdoc-ui-blade::document-list',
]);

// resolver logic:
// 1. Consumer's resources/views/vendor/ezdoc/designer.blade.php ← if exists, use this
// 2. Consumer's resources/views/ezdoc/designer.blade.php ← fallback
// 3. Library's views/blade/designer.blade.php ← default (shipped)
```

**Publish command** (Laravel-style, port equivalent per bahasa):

```bash
# PHP (Laravel)
php artisan vendor:publish --provider="Ezdoc\Providers\EzdocServiceProvider" --tag=views
php artisan vendor:publish --tag=ezdoc-assets # CSS + JS
php artisan vendor:publish --tag=ezdoc-config # config file
php artisan vendor:publish --tag=ezdoc-migrations # DB migrations (kalau bukan pakai runner internal)

# PHP (non-Laravel)
php vendor/mrpotensial/ezdoc/cli/publish.php views # copy views/ ke consumer's chosen path
php vendor/mrpotensial/ezdoc/cli/publish.php assets # copy assets

# TypeScript (shadcn-style)
npx @mrpotensial/ezdoc-ui add designer # copy DesignerPage.tsx ke consumer's project
npx @mrpotensial/ezdoc-ui add document-form
npx @mrpotensial/ezdoc-ui add document-list

# Go
go run github.com/mrpotensial/ezdoc-go/cmd/ezdoc publish views ./resources/views
```

**Slot / extension points** (untuk consumer yang mau add tanpa fork):

Di dalam default view, sediakan named slots pakai convention:

```blade
{{-- ezdoc-ui-blade/designer.blade.php --}}
@yield('ezdoc:designer:before-canvas') {{-- consumer bisa tambah instruksi di atas canvas --}}

<div class="ezdoc-canvas">
 ... default designer UI ...
</div>

@yield('ezdoc:designer:after-canvas')
@yield('ezdoc:designer:sidebar-extra') {{-- consumer bisa tambah panel di sidebar --}}
```

Consumer app pakai `@section('ezdoc:designer:after-canvas')` untuk inject tanpa fork.

**Framework adapter (Layer 2 per framework)**:

| Framework | UI adapter package | Notes |
|-----------|-------------------|-------|
| Laravel Blade | `mrpotensial/ezdoc-ui-blade` | Blade views + publish command |
| Plain PHP | `mrpotensial/ezdoc-ui-php` | Plain PHP includes (untuk legacy monolith app tanpa template engine) |
| Next.js / React | `@mrpotensial/ezdoc-ui-react` | shadcn-style copy, pakai action endpoints |
| Vue 3 | `@mrpotensial/ezdoc-ui-vue` | (community, stretch) |
| Livewire | `mrpotensial/ezdoc-ui-livewire` | (community, stretch) |

**Contoh library dunia nyata yang pakai pattern ini**:

- **shadcn/ui** (React) — "not a component library, but a collection". Copy-paste, own it. Publish via `npx shadcn add button`
- **Laravel Filament v3** — publish views + slots + config. Core admin panel logic terpisah dari Blade views
- **Radix UI** — headless primitives, style + layout consumer's own
- **CKEditor 5** — headless editor engine + swappable UI themes (Classic, Balloon, Inline, Decoupled)
- **TanStack Table** — headless table logic, bring your own `<td>`
- **TinyMCE** — core headless + separate skin packages
- **Filament v3 forms** — form logic + shippable views + slot API

## 6. Roadmap

**Total timeline (single dev, focused)**: ~36-37 weeks (~8-9 months) untuk v1.0 (termasuk v0.9.7 full views + v0.9.8 App orchestrator + v0.9.9 DB abstraction + v0.9.10 standalone hardening + v0.9.11 view separation), tambah ~16 weeks (~4 bulan) untuk v2.0 = ~52-53 weeks (**~12-13 bulan**) ke ecosystem cross-language.

**Note on v0.9.7 (added post-review)**: Milestone ini insertion baru based on user feedback "library tanpa WYSIWYG editor tidak akan di-adopt". Port full designer + generator dari dogfood consumer app ke library views = **~3-4 weeks extra** — critical blocker untuk v1.0 realistic adoption.

**Note on v0.9.8 (added post-review)**: Milestone ini insertion baru based on user feedback "manual wiring bikin path errors dan boilerplate ~200 LOC per consumer install". Industri standar (Filament, Livewire, Nova) ship 1-line `App::run()` mount pattern. `Ezdoc\App` front controller + internal router + zero-config demo = **~2-3 weeks extra** — critical for adoption ergonomics.

**Note on v0.9.9 (added post-review)**: Milestone ini insertion baru based on user feedback "target market RS/pemerintah/awam yg install-and-forget → zero external DB dep; sekaligus bekali cross-language port". Custom in-house DB abstraction (dari knowledge borrow ke Doctrine DBAL, no vendored code) + Blueprint DSL + T2 Grammar (MySQL/MariaDB/SQLite/Postgres/SQLServer) + spec-first bootstrap = **~3.5-4 weeks extra** — critical untuk PHP-upgrade freedom + cross-lang port ergonomics + zero-dep philosophy.

**Fase besar**:

| Fase | Versi | Cumulative | Fokus |
|------|-------|------------|-------|
| **Consolidation** | v0.2 - v0.5 | ~5 weeks | Extract, hardening, exception hierarchy, domain classes, framework sample |
| **Signing core** | v0.6 - v0.6.6 | ~12 weeks | Signature adapter + Local PKI + UI extraction/packaging |
| **PSrE + PDF** | v0.7 - v0.9 | ~19 weeks | Peruri, PAdES+TSA, more PSrE (Privy/VIDA) |
| **Anchoring** | v0.9.5 | ~23 weeks | Blockchain anchor (OpenTimestamps + Polygon) |
| **Full views** | v0.9.7 | ~27 weeks | Migrate designer + generator + list dari consumer app ke library (WYSIWYG editor + PDF gen) |
| **App orchestrator** | v0.9.8 | ~30 weeks | `Ezdoc\App::run()` 1-line mount + internal router + zero-config demo |
| **DB abstraction** | v0.9.9 | ~34 weeks | Zero-dep DB layer + Blueprint DSL + 5 Grammars (T2) + spec-first artifacts |
| **Standalone hardening** | v0.9.10 | ~35 weeks | Consumer-app dep extraction: PdfRenderer, DateFormatter, Db/Auth call sites |
| **View separation** | v0.9.11 | ~36 weeks | Split designer + generate ke per-action files (MVC convention), page break preview di generate |
| **Floating sidecar** | v0.9.12 | ~38 weeks | Floating elements ke JSON metadata sidecar (Google Docs/Word pattern), eliminate in-DOM markers |
| **Extraction** | v1.0 | ~39 weeks | Standalone PHP library di Packagist |
| **Spec** | v1.1 | ~41 weeks | ezdoc-spec repo publik (dari v0.9.9 seed) |
| **Go port** | v1.5 | ~47 weeks | Native Go implementation |
| **TS port** | v2.0 | ~55 weeks | Native TypeScript + Next.js sample |

**Catatan estimation**:
- Timeline asumsi **1 dev fokus purnawaktu**. Parallelization (mis. UI dev sambil PSrE integration) bisa potong 30-40%.
- Milestone v0.7 (PSrE first CA) beban paling variabel — tergantung vendor cooperation & sandbox availability.
- Blockchain milestone (v0.9.5) bisa di-defer atau di-parallel karena tidak block v1.0 shipping.

### 6.1 Milestone v0.2 — "Extract & harden" ~1 week

**Goal**: pindahkan business logic dari inline handler UI ke file terpisah yang testable.

- [ ] Extract 6 template action files (analyze_query, cleanup_orphans, field_usage, field_usage_all, list_categories, rename_field) dari `form_pembuat_surat_v3.php`
- [ ] Extract 1 default_vars file (list_vars) — sudah ada 2 lain (add_var, delete_var)
- [ ] Update `_dispatcher.php` whitelist untuk route ke file-file baru
- [ ] Hardening: HMAC secret pindah ke env var (`EZDOC_HMAC_SECRET`)
- [ ] Delete legacy migrations after verifying production sudah migrated
- [ ] Bump versi → `0.2.0`

**Definition of Done**:
- `form_pembuat_surat_v3.php` tidak punya `if ($postAction === '...')` handler lagi (semua routed via dispatcher)
- `php -l` semua file pass di PHP 7.4 + 8.3
- Manual smoke test: designer page + document form + verify page semua jalan

### 6.2 Milestone v0.3 — "Exception & Access classes" ~1 week

**Goal**: proper Exception hierarchy + Access classes untuk RBAC yang testable.

- [ ] Implement `Ezdoc\Exceptions\EzdocException` (base) + `AccessDenied`, `NotFound`, `Validation`
- [ ] Refactor `\RuntimeException` throws → subclass yang tepat
- [ ] Implement `Ezdoc\Access\{AccessConfig, AccessControl, AccessDecision, PermissionRule}`
- [ ] Wire `AccessControl` di `lib/authorization.php` sebagai adapter (backward compat)
- [ ] Tests: unit test untuk Access classes (target 80% coverage untuk `src/Access/*`)
- [ ] Bump versi → `0.3.0`

**Definition of Done**:
- Consumer bisa `catch (AccessDeniedException $e)` untuk handle RBAC error spesifik
- `AccessControl::can($user, 'edit', $template)` return `AccessDecision` yang punya `reason`
- Unit tests pass

### 6.3 Milestone v0.4 — "Document/Template domain" ~2 weeks

**Goal**: OOP Document + Template layer supaya consumer library tidak perlu tau SQL.

- [ ] Implement `Ezdoc\Document\{Document, DocumentRepository, DocumentService, SaveDocumentRequest, SaveDocumentResult}`
- [ ] Implement `Ezdoc\Template\{Template, ParsedTemplate, TemplateParser, TemplateRepository}`
- [ ] Refactor `actions/document/save_document.php` → panggil `DocumentService->save(SaveDocumentRequest)`
- [ ] Refactor template actions → panggil `TemplateRepository` + `TemplateParser`
- [ ] Tests: unit + integration untuk domain classes
- [ ] Bump versi → `0.4.0`

**Definition of Done**:
- Consumer bisa:
 ```php
 $svc = new Ezdoc\Document\DocumentService($ctx);
 $result = $svc->save(new SaveDocumentRequest([...]));
 ```
- Zero direct `mysqli_query()` di `actions/*.php` (semua via Repository)

### 6.4 Milestone v0.5 — "Framework adapters" ~1 week

**Goal**: sample bootstrap untuk Laravel + WordPress supaya consumer tinggal copy-paste.

- [ ] Buat folder `examples/` dengan:
 - `examples/laravel/EzdocServiceProvider.php`
 - `examples/wordpress/ezdoc-plugin.php`
 - `examples/plain-php/index.php`
- [ ] Docs: quickstart guide per framework
- [ ] Bump versi → `0.5.0-rc1`

### 6.5 Milestone v0.6 — "Signature adapter + Local PKI" ~2 weeks

**Goal**: pluggable signature backend + L2 (self-signed / internal CA) support.

- [ ] Implement `Ezdoc\Signature\{SignatureProvider, SignRequest, SignResult, Verdict}`
- [ ] Implement `HmacProvider` (existing behavior, jadi default L1)
- [ ] Implement `LocalPkiProvider` — pakai `openssl_sign()` + X.509 cert
- [ ] Implement `KeyStore` — env → file → HSM interface (HSM impl stub dulu)
- [ ] New migration: `ezdoc_signatures` table
- [ ] Refactor existing sign/verify code untuk pakai adapter
- [ ] Tests: unit test HMAC provider + LocalPKI provider
- [ ] Docs: cara generate self-signed cert untuk L2
- [ ] Bump versi → `0.6.0`

### 6.6 Milestone v0.6.5 — "UI extraction & headless core" ~3 weeks

**Goal**: pisahkan business logic dari UI di 3 halaman v3 → core headless, UI jadi reference impl publishable.

- [ ] Extract inline PHP handler dari `form_pembuat_surat_v3.php` (~4700 LOC) → action endpoints di `actions/` (~15 endpoints tambahan)
- [ ] Extract inline handler dari `form_pembuat_surat_cetak_v3.php` (~4100 LOC) → action endpoints
- [ ] Extract inline handler dari `form_pembuat_surat_list_v3.php` (~600 LOC) → action endpoints
- [ ] Refactor HTML/CSS/JS jadi Blade partials + component structure
- [ ] Tests: unit test action endpoints + smoke test view rendering
- [ ] Dogfood consumer switch: `form_pembuat_surat_v3.php` sekarang cuma `require ezdoc/views/designer.php`
- [ ] Bump versi → `0.6.5`

**Definition of Done**:
- Setiap v3 file jadi < 100 LOC (cuma wrapper)
- Zero SQL query di UI file
- All actions callable via HTTP endpoints (via dispatcher)
- Manual smoke test: designer + generate + list semua jalan seperti dulu

### 6.7 Milestone v0.6.6 — "UI packaging & view resolver" ~2 weeks

**Goal**: bikin UI jadi paket terpisah `mrpotensial/ezdoc-ui-blade` yang publishable per pattern Laravel Filament.

- [ ] Buat repo/folder baru `packages/ezdoc-ui-blade/` (monorepo sub-package)
- [ ] Implement `Ezdoc\UI\ViewResolver` — fallback chain (vendor/consumer → package default)
- [ ] Move Blade views ke `packages/ezdoc-ui-blade/views/`
- [ ] Package assets: CSS, JS, images
- [ ] Implement publish command:
 - `php artisan vendor:publish --tag=ezdoc-views`
 - Plain PHP: `php vendor/mrpotensial/ezdoc-ui-blade/cli/publish.php views ./resources/views/vendor/ezdoc`
- [ ] Implement slot system: `@yield('ezdoc:designer:sidebar-extra')` × 10-15 named slots
- [ ] Docs: "How to customize" guide (4 tingkat: config → CSS → view publish → full replacement)
- [ ] Bump versi → `0.6.6`

**Definition of Done**:
- Consumer bisa install `mrpotensial/ezdoc` saja (core, tanpa UI)
- Consumer bisa install `mrpotensial/ezdoc + mrpotensial/ezdoc-ui-blade` untuk default UI
- Publish command works: file ke-copy ke consumer's app, edit di sana take precedence
- Sample: consumer config.php override warna & logo (config-only), pass Adobe Reader test

### 6.8 Milestone v0.7 — "PSrE integration (first CA)" ~3 weeks

**Goal**: L3 signing via 1 CA Indonesia — Peruri Digital Sign paling dulu (paling banyak dipakai instansi kesehatan).

- [ ] Study Peruri Digital Sign API (register akun sandbox, dapat sample cert)
- [ ] Implement `Ezdoc\Signature\Providers\PeruriProvider`
- [ ] Implement `Ezdoc\Signature\Envelope\PkcsSevenEnvelope` (RFC 5652)
- [ ] Support OTP flow (email/SMS untuk approve signing)
- [ ] Verification: fetch signer cert from PSrE, validate chain to root
- [ ] Sandbox integration test (real Peruri sandbox call)
- [ ] Bump versi → `0.7.0`

### 6.9 Milestone v0.8 — "PAdES + Timestamp" ~2 weeks

**Goal**: signed PDF yang bisa di-verify Adobe Reader + long-term validation.

- [ ] Implement `Ezdoc\Signature\Envelope\PadesEnvelope` (ETSI EN 319 142)
- [ ] Implement `Ezdoc\Signature\Timestamp\TimestampClient` (RFC 3161)
- [ ] Support BSrE TSA + external TSA (freetsa.org untuk dev)
- [ ] Verify PDF di Adobe Reader (green checkmark, no warning)
- [ ] Docs: PDF signing flow end-to-end
- [ ] Bump versi → `0.8.0`

### 6.10 Milestone v0.9 — "More PSrE providers" ~2 weeks

**Goal**: multi-CA choice untuk consumer.

- [ ] Implement `PrivyProvider` (Privy REST API)
- [ ] Implement `VidaProvider` (VIDA e-Sign)
- [ ] Implement `MultiProvider` (chain fallback / redundancy)
- [ ] Docs: comparison matrix (biaya, coverage, use case)
- [ ] Bump versi → `0.9.0`

### 6.11 Milestone v0.9.5 — "Blockchain anchoring" ~3-4 weeks

**Goal**: proof-of-existence layer via blockchain anchor — orthogonal add-on ke existing L1-L3 signature.

- [ ] Implement `Ezdoc\Anchor\{AnchorProvider, AnchorReceipt, AnchorVerdict, AnchorStatus}`
- [ ] Implement `OpenTimestampsAnchor` (default recommend — free, no wallet, batched)
- [ ] Implement `PolygonAnchor` (untuk yang butuh smart contract capabilities)
- [ ] Implement `BatchAnchor` decorator (Merkle tree untuk high-volume cost reduction)
- [ ] Deploy `EzdocAnchor.sol` ke Polygon mainnet + testnet (Amoy testnet)
- [ ] New migration: `ezdoc_anchors` table
- [ ] Background worker: `cli/anchor-worker.php` (poll pending anchors, update status)
- [ ] Verify service: fetch on-chain, cross-check hash, return `AnchorVerdict`
- [ ] Cost accounting: `ezdoc_anchor_costs` view (per-provider, per-month rollup)
- [ ] Public verify page: `/anchor-verify?receipt_id=xxx` — no login, show chain explorer link
- [ ] Docs: chain choice guide, cost estimator, when-to-use table
- [ ] Bump versi → `0.9.5`

**Definition of Done**:
- User bisa `AnchorProvider->anchor($hash)` dari code, dapat `tx_hash`
- Verify page tampilkan bukti: hash on-chain matches, block timestamp, link ke explorer
- BatchAnchor: 1000 dokumen di-anchor jadi 1 tx, individual proof tetap works
- Test: L3 (Peruri) + A1 (Polygon) combined flow end-to-end pass

### 6.12 Milestone v0.9.7 — "Full-featured library views (designer + generator)" ~3-4 weeks

**Goal**: Port full-featured designer + document generator UI dari dogfood consumer app (reference: `page/form_pembuat_surat_*_v3.php`) ke `ezdoc/views/document/` sebagai starter templates library. Consumer library user dapat WYSIWYG designer + generator out-of-box tanpa build sendiri.

**Rationale**: Library tanpa full-featured UI (designer WYSIWYG + document generator) tidak akan di-adopt. Consumer akan pilih library lain yang sudah "batteries-included". Framework-only library (v0.6.6) cocok untuk consumer yang mau bangun sendiri, tapi majority butuh working starter.

**Industri standar reference pattern**: **publish + customize** (Laravel Filament v3, shadcn/ui, Radix UI). Library ships **full-featured views AS-IS**, consumer runs `php cli/publish.php views` untuk copy ke app, edit copies at will. ViewResolver picks consumer's copy first, falls back ke library default.

**Scope migration**:

Files yang di-migrate dari `page/*.php` → `ezdoc/views/document/*.php`:

| Source (dogfood reference app) | Target (ezdoc library) | LOC (post-Tailwind conversion) |
|----------------|------------------------|--------------------------------|
| `page/form_pembuat_surat_v3.php` | `views/document/designer.php` | 4465 |
| `page/form_pembuat_surat_cetak_v3.php` | `views/document/generate.php` | 4040 |
| `page/form_pembuat_surat_list_v3.php` | Enhance existing `views/document/list.php` | 608 |

**Total ~9100 LOC** migration dengan preservation semua interactivity + fitur.

**consumer-app-specific dependencies yang HARUS di-abstract**:

- [ ] `global $conn` → `$ctx->db` (Context DI)
- [ ] `query($sql)` → `Ezdoc\Document\DocumentRepository::listByX()` OR `ezdoc_query_prepared()` (v0.7.1)
- [ ] `esc($val)` → `mysqli_prepare + bind_param` prepared statements
- [ ] `hasRole($role)` → `$ctx->roleProvider->hasRole($role)`
- [ ] `$author_id` → `$ctx->roleProvider->currentUserId()`
- [ ] `$author_role_array` → `$ctx->roleProvider->currentUserRoles()`
- [ ] Hardcoded `?page=form_pembuat_surat_*` URLs → `$config->get('urls.designer/generate/list', ...)` patterns dengan `{uuid}` placeholder
- [ ] Hardcoded AJAX action URLs → route via `Ezdoc\UI\Config::get('urls.actions_base', 'actions/')` + Dispatcher

**Fitur yang HARUS preserved (non-negotiable)**:

Designer (`views/document/designer.php`):
- [ ] TinyMCE 6 WYSIWYG init + custom toolbar
- [ ] Field marker `{{field_name}}` placeholders (parseTemplate)
- [ ] TTD placeholder drag-drop positioning (floating + inline)
- [ ] Materai placeholder insert
- [ ] QR field placeholder
- [ ] Logo insert dengan sizing
- [ ] Sidebar panels (Field/TTD/Materai lists dengan filter search)
- [ ] Variable Manager modal
- [ ] Field Inspector modal (usage count + rename + cleanup orphans)
- [ ] Query DB modal (dynamic table binding)
- [ ] URL Parameters modal
- [ ] Verify Preview modal
- [ ] Save template dengan versioning + access_config RBAC
- [ ] Copy template
- [ ] Toggle lock
- [ ] Delete template (superadmin only)
- [ ] Alpine.js state untuk semua modals (gold-standard pattern)

Generator (`views/document/generate.php`):
- [ ] Template picker fallback screen (kalau no template_id)
- [ ] Form auto-generate dari template `field_values` + `signature_config`
- [ ] TTD signature canvas (mouse + touch drawing)
- [ ] Materai upload (file → base64 data URL, 30-char serial + upload timestamp)
- [ ] QR code generation via `?action=generate_qr` endpoint
- [ ] Verify QR mode toggle
- [ ] Save document via `_ajax=1` action → `DocumentService::save()`
- [ ] New version create
- [ ] Version selector dropdown
- [ ] Document info popup
- [ ] Keyboard shortcuts (Ctrl+S, Ctrl+/)
- [ ] Delete slot / restore slot (superadmin)
- [ ] **PDF render via dompdf** — preserve `renderContentForPdf()` + inline PDF `<style>` block verbatim
- [ ] Print mode dengan `@page` PHP-interpolated paper size

**Slot injection points** (consumer boleh inject custom UI tanpa fork):

Designer slots (12 slots):
- [ ] `designer:toolbar-extra` — tambah button di top-bar
- [ ] `designer:sidebar-extra` — tambah panel di sidebar
- [ ] `designer:modals-extra` — tambah modal
- [ ] `designer:field-context-menu` — right-click menu per field
- [ ] `designer:ttd-context-menu` — right-click menu per TTD
- [ ] `designer:save-hook` — pre-save validation callback

Generator slots (10 slots):
- [ ] `generate:before-form` — heading, breadcrumbs, custom nav
- [ ] `generate:after-form` — footer notes
- [ ] `generate:signature-extra` — custom sign panel
- [ ] `generate:preview-header` — PDF preview toolbar
- [ ] `generate:field-picker` — custom autocomplete for specific fields
- [ ] `generate:save-hook` — pre-save validation

**Migration approach — 3 sub-milestones**:

- [ ] **v0.9.7-a** (~1 week): Migrate `designer.php`. Workflow multi-agent dengan careful research phase. Backup `.bak` before touch.
- [ ] **v0.9.7-b** (~1 week): Migrate `generate.php`. Same pattern. PDF preservation adalah highest risk.
- [ ] **v0.9.7-c** (~1 week): Migrate `list.php` enhancement (recent docs widget, category filter pills, RBAC per-template badge). Update demo showcase.
- [ ] Docs update: `docs/UI-CUSTOMIZATION.md` tambah "Full-featured views" section dengan publish + customize workflow

**Definition of Done**:

- `ezdoc/views/document/designer.php` fully functional standalone, tanpa consumer-app-specific globals
- `ezdoc/views/document/generate.php` fully functional, dompdf PDF gen works
- `ezdoc/views/document/list.php` enhanced dengan recent docs + category filter
- Consumer fresh install: `composer require mrpotensial/ezdoc && php cli/publish.php views` → langsung dapat working designer + generator
- Demo dogfood consumer app switch dari `page/form_pembuat_surat_*_v3.php` inline require → publish views to `page/vendor/ezdoc/document/*.php` OR `require ezdoc/views/document/*.php` directly
- Backward compat: legacy consumer pages tetap functional selama migration period (paralel deployment)
- Slot injection tested: 22 slots (12 designer + 10 generator) demoed di `page/ezdoc_ui_demo.php`
- Verified end-to-end: save template → generate document → sign TTD → PDF preview → verify QR → all works via library views only

**Cross-framework portability constraints (mandatory)**:

Designer + generator views di v0.9.7 WAJIB di-arsitektur supaya native ports (Laravel v0.5, Next.js/React v2.0, Go v1.5, Rust) bisa remake dengan safety. Consumer boleh implement UI di framework apapun selama mereka honor spec + endpoint contract.

**7 principles**:

1. **Views are dumb** — Zero business logic di `views/*.php`. Cuma HTML + JS + fetch calls ke endpoints. Semua save/load/sign flow via `actions/*.php` REST endpoints. Business logic ada di `Ezdoc\Document\DocumentService` + `Ezdoc\Template\TemplateService` (v0.4).
2. **HTTP endpoints as contract** — Semua interactivity via documented REST-like endpoints. Contract = ezdoc-spec (v1.1 milestone). Any language yang implement same endpoint contract bisa host UI.
3. **Data format = portable JSON** — Zero PHP-specific serialization (no `serialize()`, no `var_export`). Semua requests/responses = standard JSON. Field values, signature config, verify config = JSON di database (already schema).
4. **Template content format = spec** — Field markers `{{name}}`, TTD placeholders (`<div class="ttd-placeholder" data-ttd data-nama-field data-allowed-roles>`), materai placeholders documented di `ezdoc-spec/protocol/`. Any WYSIWYG editor yang produce spec-compliant content = valid.
5. **Editor is swappable** — TinyMCE 6 = reference impl untuk PHP library. Consumer boleh swap ke framework-native editor: Laravel Filament Rich Editor, Next.js TipTap/BlockNote, Vue Quill. Content format tetap sama (spec-compliant HTML).
6. **Signature envelope = ISO standards** — PKCS#7 (RFC 5652), PAdES (ETSI EN 319 142), RFC 3161 TSA. Bit-exact identical across languages (per v0.7 + v0.8 spec). Signed dokumen dari PHP dapat di-verify oleh Go / TS client.
7. **Client state = DOM + fetch API** — Views pakai vanilla JS + Alpine.js (v0.6.5 conversion). Zero framework-specific state (Redux, MobX, Vuex). Portable ke framework apapun yang punya DOM + fetch (semua modern JS/TS).

**Anti-patterns (banned untuk v0.9.7)**:

- PHP-serialized data di response (`serialize()`, `var_export`, `json_encode` with PHP object metadata)
- Session-based state passing (`$_SESSION['designer_state']`)
- Direct DB access dari view file (`mysqli_query` di HTML)
- PHP-specific templating primitives yang tight-coupled (Blade `@if`, Twig `{% %}`) — plain PHP `<?= ?>` OK karena tidak bikin dependency
- TinyMCE-specific data yang tidak documented di spec (mis. proprietary data attrs)
- jQuery-specific selectors, Angular directives, Vue templates — vanilla JS only
- Custom URL routing (mis. hash-based routing) — semua URLs via Config pattern

**Portability test criteria untuk v0.9.7 DoD**:

- [ ] Designer views di-render tanpa modification kalau consumer swap `koneksi.php` → PDO/Doctrine/Eloquent (data access via Repository, not direct SQL di view)
- [ ] Semua AJAX call punya JSON request + JSON response, tidak ada form-encoded state di URL beyond query params `?uuid=X&action=Y`
- [ ] Template content JSON export dari one consumer bisa di-import ke Laravel Filament project (bit-identical `field_values`, `signature_config`)
- [ ] Signed document dari PHP library bisa di-verify oleh Go client (v1.5) end-to-end (conformance test vector di ezdoc-spec)
- [ ] `actions/*.php` endpoints documented di `ezdoc-spec/openapi.yaml` sebagai reference — endpoints yang consumer boleh implement in Next.js/Rust/Go/CI4
- [ ] `views/document/designer.php` bisa di-copy ke fresh Laravel project + require dari Blade layout, no fatal error (asalkan consumer wire `Context::default()` dengan mysqli/PDO adapter)
- [ ] Zero references ke consumer-app-specific vendor libraries di views (no `koneksi.php`, no `SImpel/*` classes, no `RSIA_*` constants)

**Concerns / risks**:

- **dompdf PDF style block** MUST NOT be Tailwind — dompdf tidak parse Tailwind CDN. Keep inline `<style>` block verbatim. **Cross-framework note**: dompdf PHP-only. Native ports (Node/Go/Rust) pakai equivalent (Puppeteer, wkhtmltopdf, printpdf). PDF spec tetap sama (PAdES envelope).
- **TinyMCE iframe content styles** — Tailwind CDN loaded di outer document tidak apply ke iframe. Preserve inline styles OR configure TinyMCE to load ezdoc.css. **Cross-framework note**: kalau consumer swap TinyMCE → TipTap/BlockNote, inline styles jadi non-issue (native React/Vue components).
- **Print @page rules** dengan PHP interpolation (`<?= $paperDim ?>`) — cannot be Tailwind. **Cross-framework note**: paper size = template metadata, portable across languages.
- **Alpine state coupling** — designer + generator sudah pakai Alpine post-Tailwind conversion (v0.6.5 workflow). Preserve state names. **Cross-framework note**: Alpine = DOM-based, works di React/Vue via web components jika consumer mau. Alternative native: React `useState`, Vue `ref`.
- **Repository refactor complexity** — 4114 `query()` calls across pengeluaran/ folder, tapi cuma yang di 2 files ini yang perlu di-refactor untuk migration.

### 6.13 Milestone v0.9.8 — "App orchestrator + Zero-config install" ~2-3 weeks

**Goal**: 1-line `Ezdoc\App::run()` untuk consumer mount → deprecate manual wiring boilerplate. Zero-config demo mode dengan SQLite in-memory untuk instant install verification. Adopt industry-standard "front controller + mount pattern" (Filament, Livewire, Nova).

**Problem context**:
- Sekarang (v0.9.7): consumer harus wire manual — koneksi.php require + bootstrap.php require + Config::fromArray dengan ~30 keys + Slot::register untuk custom + URL config untuk 15 action endpoints + wrapper page untuk dispatch. Total ~200 LOC boilerplate + sering path errors.
- Industri: 1-line install. Filament: `->plugin(\Filament\Admin::make())`. Livewire: `\Livewire\Livewire::route()`. Nova: `Route::any('nova/{any}', ...)`.

**Deliverables**:
- [ ] `Ezdoc\App` class — front controller + internal router (spec: ezdoc-spec/api/app.md)
 - Static `App::run(array $config)` — main entry point
 - Static `App::demo(array $overrides = [])` — zero-config demo mode dengan SQLite
- [ ] `Ezdoc\Http\Router` — resolve internal URLs (?ezdoc_page=list|designer|generate|action|asset)
- [ ] `Ezdoc\Http\RequestContext` + `ResponseWriter` — typed request/response abstraction (framework-agnostic)
- [ ] Asset serving route: `?ezdoc_asset=css/ezdoc.css` — App streams file dengan proper MIME (fixes relative path breakage)
- [ ] Action dispatch internal — deprecate manual `ezdoc_action.php` wrapper (soft deprecate: still works)
- [ ] SQLite migrations variant (parallel dengan MySQL) — di `ezdoc/migrations/sqlite/`
- [ ] CLI `php ezdoc/cli/serve.php` — start PHP built-in server + auto-migrate SQLite + seed 3 sample templates + open browser
- [ ] Docs `docs/QUICKSTART.md` — 5-line consumer setup guide
- [ ] Docs `docs/APP-API.md` — App::run() config schema reference
- [ ] Deprecation notice untuk manual boilerplate (soft, keep working untuk 1 minor version)
- [ ] Update `ezdoc_ui_demo.php` → refactor to use App::run() (dogfood + regression test)

**Cross-framework portability constraints** (mandatory):
- App class MUST NOT assume specific framework
- All request/response via RequestContext (no global $_GET/$_POST reads in library code)
- Support BOTH mysqli AND PDO out of the box (auto-detect)
- Session/auth via RoleProvider abstraction (already exists)
- Asset URLs via App-generated (never hardcoded)
- Should work in: plain PHP, Laravel route, Slim route, CI4 controller, Symfony controller

**Definition of Done**:
- Fresh consumer: `composer require` → 1 PHP file dengan `Ezdoc\App::demo()` → full working UI (list + designer + generator + PDF render) tanpa DB config
- Consumer app: `Ezdoc\App::run(['db' => $conn])` → semua URL bekerja, semua asset load
- Zero path errors: browser accesses any URL under App base_path → correct route (list, designer, generate, action, asset)
- Existing monolith consumer (page/ezdoc_ui_demo.php via manual wiring) TETAP works — no breaking during migration period
- Round-trip test: install → open designer → create template → save → open generator → pick template → render PDF → all works with zero manual URL wiring

**Anti-patterns to avoid**:
- Forcing consumer to route ALL app URLs through Ezdoc\App (should be opt-in prefix)
- Hardcoded framework detection (Laravel-specific code paths, etc.)
- Requiring composer autoload (should work with plain include/require too)
- Owning the entire response cycle (should return output, not exit)
- Silent path resolution "magic" — path decisions must be traceable

### 6.14 Milestone v0.9.9 — "DB abstraction + Repository completion + Spec-first bootstrap" ~3.5-4 weeks **SHIPPED 2026-07-15**

**Goal**: Zero raw SQL di `actions/` + `views/`. Semua persistence via Repository. DB driver swap = 1 config change (mysqli / PDO-mysql / PDO-sqlite / PDO-pgsql / PDO-sqlsrv). Schema-first cross-platform DDL emit. Spec artifacts (YAML/JSON) extracted paralel supaya v1.1 tinggal repo split, bukan design ulang.

**Design principle — zero-dep philosophy dgn strategic knowledge borrow**:
- Target market ezdoc: RS/pemerintah/PHP-awam yg install-and-forget. Composer deps = friction. **Zero external DB library**.
- Bukan reinvent wheel: study Doctrine DBAL source (MIT-licensed) sebagai reference SQL-dialect knowledge; **reimplement** dalam gaya ezdoc, credit di file header, no vendored code.
- Own 100% code path → PHP version upgrade freedom + cross-language port ergonomics.

**Scope**:
- **DB abstraction** (`src/Db/`):
 - `Connection.php` interface — `prepare`, `execute`, `fetchOne`, `fetchAll`, `transaction`, `schemaManager()`
 - `Mysqli/MysqliConnection.php` — default zero-dep adapter (backward-compat dengan existing consumer koneksi.php)
 - `Pdo/PdoConnection.php` — untuk sqlite/pgsql/sqlsrv (PDO extension built-in di PHP)
- **Schema DSL Blueprint** (`src/Db/Schema/`) — Laravel-familiar naming, framework-neutral semantics:
 - `Blueprint.php` — DSL: `id()`, `uuid()`, `string()`, `integer()`, `bigint()`, `json()`, `boolean()`, `enum()`, `text()`, `timestamps()`, `softDeletes()`, `foreignId()->references()`, `index()`, `unique()`
 - `TableDef.php`, `ColumnDef.php`, `IndexDef.php`, `ForeignKeyDef.php`
 - `Comparator.php` — diff old vs new Blueprint → generate ALTER statements
- **Grammar per platform** (`src/Db/Grammar/`) — **T2 target** (5 databases):
 - `Grammar.php` interface — `compileCreateTable`, `compileAlter`, `compileSelect`, `compileInsert`, `compileUpdate`, `compileDelete`, `wrapIdentifier`, `mapType`
 - `MysqlGrammar.php` — MySQL 5.7+/8.0 dialect (backtick idents, native JSON, AUTO_INCREMENT)
 - `MariaDbGrammar.php` — MariaDB 10.3+ (JSON via LONGTEXT + CHECK constraint on 10.2-)
 - `SqliteGrammar.php` — SQLite 3.35+ (TEXT + json_valid CHECK, INTEGER PRIMARY KEY AUTOINCREMENT)
 - `PostgresGrammar.php` — PostgreSQL 12+ (double-quote idents, native JSONB, GENERATED AS IDENTITY)
 - `SqlServerGrammar.php` — SQL Server 2019+ (bracket idents, NVARCHAR(MAX) for JSON, IDENTITY(1,1), OFFSET…FETCH NEXT)
- **QueryBuilder** (`src/Db/QueryBuilder.php`) — chainable fluent API: `select/from/where/andWhere/orWhere/join/leftJoin/groupBy/having/orderBy/limit/offset/union/insert/update/delete/upsert`. Grammar-driven SQL compilation.
- **Types system** (`src/Db/Types/`) — data-driven mapping tables borrowed dari DBAL Types (JsonType, UuidType, EnumType, DateTimeType, BooleanType, TextType); cross-platform normalization.
- **Repository completion** (~800 LOC refactor):
 - Existing: `DocumentRepository`, `TemplateRepository` → refactor pakai `Ezdoc\Db\Connection` (bukan mysqli langsung)
 - New: `SignatureRepository` (TTD values, currently inline in save_document.php)
 - New: `AuditRepository` (audit_log queries)
 - New: `DefaultVarsRepository`
 - Sweep: 21 files di `actions/` → thin controllers → Service → Repository
 - Grep goal: `mysqli_query|->query\(` di actions/ + views/ → **zero hits**
- **Spec-first artifacts** (`ezdoc-spec/`, staging subfolder di ezdoc repo dulu; extract terpisah di v1.1):
 ```
 ezdoc-spec/
 schema/
 tables.yaml ← generated from Blueprint DSL via cli/spec-dump.php
 enums.yaml
 ddl/
 mysql.sql ← Blueprint → MysqlGrammar → DDL
 mariadb.sql
 sqlite.sql
 postgres.sql
 sqlserver.sql
 protocol/
 hash-algos.json ← SHA-256, SHA-512 constants
 signature-levels.json ← L1/L2/L3/A1
 envelope-types.json ← PAdES, CAdES, XAdES
 meta/
 version.json
 checksum.txt ← CI enforce: spec-dump.php --check == this
 README.md ← "PHP is source of truth; how to consume in Go/Rust/TS"
 ```
- **CLI**: `cli/spec-dump.php` — regenerate all spec files from Blueprint source
- **CI enforcement**: `spec-dump.php --check` → CI runs → `git diff --exit-code ezdoc-spec/` → fail if code changed but spec not regenerated

**Cross-language port design**:
- Schema descriptor YAML dirancang first-class — bukan afterthought
- Go: `yq eval` → codegen struct dari tables.yaml
- Rust: `serde_yaml` → struct + Diesel/SeaORM binding
- TypeScript: `js-yaml` → interface + Prisma/Drizzle schema
- Semua **tidak perlu tahu apapun soal DBAL/PHP-specific** — hanya baca YAML declarative

**PHP version upgrade risk**:
- Zero external DB lib = zero external upgrade force
- PHP 7.4 → 8.x transition pakai `symfony/polyfill-*` (sudah ada di composer.json)
- Type system data-driven (bukan class-per-type inheritance) → tidak kena PHP major class-syntax changes

**Effort breakdown**:
- Week 1: Connection interface + Mysqli adapter + PDO adapter (4 drivers) + Blueprint DSL foundation
- Week 2: 5 Grammar implementations (reference DBAL Platforms — port SQL formulas, not files) + Types system
- Week 3: QueryBuilder + Comparator/diff engine + spec-dump CLI + first codegen run
- Week 4: Repository sweep (21 actions files → thin controllers) + test matrix (docker-compose per Grammar) + docs

**Migration & backcompat**:
- `Ezdoc\App::run()` auto-detect existing `$conn` mysqli global → wrap `MysqliConnection` — **zero config change** untuk existing consumers
- Opt-in PDO: `$config['db']['driver'] = 'pdo', 'dsn' => 'sqlite:...'`
- `App::demo()` (dari v0.9.8) sekarang bisa run tanpa mysql daemon: default ke PDO-SQLite

**Definition of Done**:
- [x] `Ezdoc\Db\Connection` interface + Mysqli + PDO adapters
- [ ] 5 Grammar implementations lulus test matrix (docker-compose spins up each DB in CI) — **deferred v0.9.10** (all 5 grammars ada + smoke test dgn live MySQL passed; docker matrix belum setup)
- [x] Blueprint DSL: existing 5 migrations (`ezdoc_templates`, `ezdoc_documents`, `ezdoc_default_vars`, `ezdoc_audit_log`, `ezdoc_signatures`) rewritten via Blueprint (di `migrations/blueprints/*.php` sebagai spec-first source)
- [ ] Migration runner emits per-Grammar DDL (test: same Blueprint → correct DDL untuk 5 platforms) — **deferred v0.9.10** (spec-dump CLI works, tapi Migration Runner sendiri masih pakai legacy imperative migrations)
- [x] All Repositories accept `Ezdoc\Db\Connection` (bukan mysqli); grep `mysqli_query|->query\(` di `actions/` + `views/` → 0 hits (21/21 actions files refactored)
- [x] New: `SignatureRepository`, `AuditRepository`, `DefaultVarsRepository`
- [x] `cli/spec-dump.php` generates `ezdoc-spec/` completely; CI check-diff passes (`--check` mode implemented)
- [ ] `App::demo()` runs on SQLite (no mysql daemon required) — **deferred v0.9.10** (PdoConnection ready; App::demo() wiring belum di-switch ke SQLite default)
- [ ] Same test suite passes against mysqli, PDO-mysql, PDO-sqlite, PDO-pgsql, PDO-sqlsrv — **deferred v0.9.10** (needs formal PHPUnit + docker-compose CI setup)
- [x] `docs/DB-ABSTRACTION.md` written — how consumer picks driver, how contributor adds new Grammar
- [x] `docs/CROSS-LANGUAGE.md` written — spec-first ecosystem strategy untuk port implementers
- [ ] `docs/CROSS-LANGUAGE.md` written — how a Go/Rust/TS port reads spec

**Anti-patterns to avoid**:
- Vendoring DBAL files verbatim (license entanglement + fork burden) — reimplement from knowledge
- Leaking `Ezdoc\Db\*` implementation types (mysqli/PDO/DBAL) ke Repository — Repository hanya lihat `Connection` interface
- Composer require `doctrine/dbal` — kontradiksi zero-dep philosophy
- Hand-maintain SQL DDL files terpisah per DB (drift risk) — spec files harus generated
- ORM feature creep (relations, lazy loading, entity manager) — Repository + QueryBuilder cukup untuk YAGNI

### 6.15 Milestone v0.9.10 — "Standalone library hardening" ~1-2 weeks

**Goal**: audit + eliminate semua consumer-app runtime dependencies dari library. Ezdoc runs standalone tanpa consumer's `koneksi.php` / `page/*.php` / project-specific helpers. Prereq mandatory sebelum v1.0 Packagist extraction.

**Scope boundary**: milestone ini fokus ke **consumer-app dependency extraction** (PdfRenderer, DateFormatter, Db call sites, Auth call sites). Deferred DoD items dari v0.9.9 (Grammar test matrix CI, docker-compose, `App::demo()` SQLite mode, integration test suite) referenced dengan tag `deferred v0.9.10` di section 6.14 → di-roll ke **v0.9.10-track-B** (separate mini-milestone) atau langsung ke v1.0 prep, TIDAK di-scope di sini untuk hindari overload. Sub-track split by domain: **A** = consumer dep removal (this section), **B** = DB abstraction CI completion (deferred v0.9.9).

**Motivation**: dogfood consumer (`SIMpel`) exposes ezdoc ke consumer-specific globals (`generatePDF()`, `ubahTanggalKeIndonesia()`, `query()`, `hasRole()`). Sebelum extraction ke Packagist, semua ini harus punya library-native replacement + backward-compat fallback.

**Design principle**: setiap consumer dependency di-replace dgn (1) contract interface, (2) library-native default implementation, (3) optional backward-compat shim yg detect existing consumer function dan pakai kalau available. Industry-standard pattern: Symfony transports, Laravel drivers, Filament contracts.

**Contracts extracted** (each = interface + default impl + Context wiring):

| Consumer function | Contract | Default impl | Industry precedent |
|---|---|---|---|
| `generatePDF()` | `Ezdoc\Rendering\PdfRenderer` | `DompdfRenderer` | Symfony Mailer `TransportInterface`, Barryvdh/laravel-dompdf `stream()` |
| `ubahTanggalKeIndonesia()` | `Ezdoc\Format\DateFormatter` (static) | Built-in `en`, `id` locale tables | Carbon `translatedFormat()`, Symfony Intl |
| `query()` global | `Ezdoc\Db\Connection` | `MysqliConnection`, `PdoConnection` | Doctrine DBAL, Laravel `Illuminate\Database` |
| `hasRole()` global | `Ezdoc\Auth\RoleProvider` (already exists v0.6+) | `HasRoleProvider`, `CallableRoleProvider` | Symfony Security voters |

- [x] `Ezdoc\Rendering\PdfRenderer` interface + `DompdfRenderer` default impl
- [x] `Ezdoc\Format\DateFormatter` static utility (`localize()`, `registerLocale()`)
- [x] Context extended with `$pdf` property + `withPdf()` immutable wither
- [x] `generate.php` uses library-native renderer (removed `function_exists('generatePDF')` fallback)
- [x] `resolveDefault()` uses `DateFormatter::localize()` (with `ubahTanggalKeIndonesia()` backward-compat shim)
- [ ] `Ezdoc\Db\Connection` abstraction usage sweep — replace `query()` global calls di actions/, views/
- [ ] `Ezdoc\Auth\RoleProvider` usage sweep — replace `hasRole()` global calls
- [ ] Audit consumer-specific constants (`RSIA_*`, `SIMPEL_*`) — remove or route via config
- [ ] Update `docs/QUICKSTART.md`, `docs/UI-CUSTOMIZATION.md`, `README.md` — remove `koneksi.php` references (say "consumer bootstrap" generically)
- [ ] Add `docs/PDF-RENDERING.md` — PdfRenderer contract + DompdfRenderer + custom backend guide
- [ ] Add `docs/LOCALIZATION.md` — DateFormatter API + locale registration
- [ ] Add integration test: run designer + generate + PDF export dengan pure ezdoc bootstrap (no `koneksi.php` required)

**Definition of Done**:
- `grep -r "koneksi.php\|generatePDF\|ubahTanggalKeIndonesia\|hasRole\|\$conn" ezdoc/src ezdoc/lib ezdoc/actions ezdoc/views` → zero runtime call sites (only comments/docs referencing consumer pattern as example)
- Fresh consumer install: composer require + `Context::default()->withPdf(new DompdfRenderer())` + all features work
- Backward-compat shims retained where they don't add runtime cost (function_exists checks)
- All contracts documented dgn precedent cited (Symfony/Laravel/Carbon/Doctrine equivalent)

**Non-goals**:
- Removing CLI dependency on koneksi.php (CLI is opt-in for consumer, kept for backward-compat via `require_once` in `cli/migrate.php` header docs)
- Introducing new abstractions beyond parity with removed consumer functions

### 6.16 Milestone v0.9.11 — "View separation + generate UX polish" ~1-2 weeks

**Goal**: pisah views yg overloaded jadi single-file-per-action structure (MVC convention) + tambah page break preview di generate view untuk match designer UX.

**Motivation**: `designer.php` (5534 lines) dan `generate.php` (4666 lines) currently mix multiple views (list + edit + create + action) di satu file. Susah maintain, susah customize per-consumer, susah publish overrides. Industry-standard MVC one-view-per-action pattern (Laravel, Filament, Symfony, Rails) makes code navigable + consumer publish override targeted.

**Precedent (industry-standard MVC view convention)**:
- **Laravel**: `resources/views/documents/{index,create,edit,show}.blade.php` — one Blade per action, follows REST verb convention
- **Filament**: `ListResource`, `CreateResource`, `EditResource`, `ViewResource` — separate classes per action, each with own view file
- **Symfony**: `templates/{controller}/{action}.html.twig` — action-per-file convention
- **Rails**: `views/{controller}/{index,new,edit,show,create,update}.html.erb` — resourceful routing → per-action view file
- **Django**: `templates/{app}/{model}_{action}.html` — same pattern

**Deliverables**:

- **Template list separation** shipped:
 - [x] Extract template LIST section dari `designer.php` → new file `views/document/template_list.php` (214 lines, includes 37 lines docblock + 177 lines content)
 - [x] designer.php reduced 5534 → 5362 lines (−172, list conditional replaced dgn `require`)
 - [x] Uses `require` include pattern (backward-compat, no routing change needed)
 - [x] Slots retained (`designer:list-header-extra`, `designer:list-row-actions-extra`) — rename optional followup
 - Router refactor + slot rename di-defer ke v1.0 prep (breaking change scope)

- **Generate view separation** shipped:
 - [x] Audited `generate.php` — picker section (template selection) di line 174-255
 - [x] Extract picker section → new file `views/document/generate_list.php` (99 lines)
 - [x] generate.php reduced 4666 → 4639 lines (−27, picker HTML replaced dgn `require`)
 - [x] Uses `require` include pattern, backward-compat

- **Page break preview di generate view** shipped:
 - [x] Applied dashed page break line CSS di generate `.page` (dual-layer bg masking, same technique as designer)
 - [x] Values hardcoded via PHP interpolation dari `$paperDim['height']` (no CSS var indirection needed)
 - [x] Visible di edit-on state; hidden di edit-off (`background-image: none`) + `@media print` reset
 - [x] Precedent: Google Docs page break markers, Word Web, Notion — all edit-mode indicator convention

**Design principles**:
- **Backward-compat via slot forwarding**: old slot names still work, forwarded to new naming with deprecation notice
- **Router smart-defaults**: consumer routing tetap works kalau tidak explicit override (main router detects sub-view via query param)
- **Publish override friendly**: `php cli/publish.php views` copies BOTH old dan new file structure — consumer can pick

**Definition of Done**:
- [x] `template_list.php` + `generate_list.php` new files exist with extracted sections
- [x] `designer.php` list section extracted (5534 → 5362 lines, −172)
- [x] `generate.php` picker section extracted (4666 → 4639 lines, −27)
- [x] Page break dashed line visible di generate edit-on view
- [x] Include pattern preserves backward-compat (no routing change needed)
- [ ] Full ≤2500 designer + ≤3000 generate line targets — deferred to v1.0 prep (needs bigger refactor of shared JS blocks; scope too big for v0.9.11 without breaking dispatch)
- [ ] Router direct routing ke sub-view identifiers — deferred to v1.0 (breaking change)
- [ ] Slot rename (`designer:list-*` → `template_list:*`) with backward-compat forwarding — deferred to v1.0 (breaking for existing consumer slot registrations)
- [ ] `docs/VIEWS.md` — deferred (existing docs sufficient for current include pattern)

**Non-goals**:
- Rewriting to Blade template syntax (still plain PHP for library-standalone)
- Introducing new component framework (staying compatible with v0.9.9 slot system)
- Router refactor to direct sub-view routing (breaking change — deferred to v1.0 prep)

### 6.17 Milestone v0.9.12 — "Sidecar metadata for floating elements" ~1-2 weeks

**Goal**: refactor floating element storage dari in-DOM markers → sidecar metadata pattern (industry-standard). Floating elements (logo/TTD/QR/materai floating variants) dikeluarkan dari HTML content editor, disimpan sebagai JSON metadata terpisah, auto-merged saat render, auto-extracted saat edit.

**Motivation**: Current in-DOM approach (span/div dgn position:absolute inside editor content) menyebabkan:
- Empty line bugs (wrapper `<p>` visible even dgn `.floating-only` classification)
- Accidental deletion saat user delete empty line (floating element inside p ikut ke-hapus)
- Editor style interference (padding, margin, line-height wrapper conflicts dgn content flow)
- Complex classification logic that's error-prone

Sidecar pattern eliminates all these issues by decoupling floating elements dari text flow entirely.

**Precedent (industry-standard proven)**:
- **Google Docs** — Text + Drawings di XML nodes terpisah (`<a:drawing>` XML for shapes, `<w:t>` for text runs)
- **MS Word** — Floating shapes/images di `<w:drawing>` element, terpisah dari text runs
- **Figma / Sketch / Adobe InDesign** — Layer model, setiap object standalone
- **CKEditor 5 Widgets** — atomic non-editable widget units dgn `data-*` position attrs
- **Slate.js `void` nodes** — non-editable JSON structures separate from text
- **Prosemirror** — nodeviews for atomic embedded content

**Schema change** (backward-compat migration):

Add `floating_elements JSON NULL` column to `ezdoc_templates`:
```json
{
 "html": "<p>Text content...</p>",
 "floating_elements": [
 {
 "id": "logo_hospital",
 "type": "logo",
 "position_x": 400,
 "position_y": 100,
 "z_index": "front",
 "data": { "width": "80px" }
 },
 {
 "id": "ttd_dokter",
 "type": "ttd",
 "position_x": 500,
 "position_y": 800,
 "z_index": "front",
 "data": { "label": "Doctor", "nama_field": "nama_dokter" }
 }
 ]
}
```

**Deliverables**:

- **Schema migration**:
 - [ ] Add `floating_elements JSON NULL` column ke `ezdoc_templates` + `ezdoc_documents` tables
 - [ ] Migration untuk existing templates: extract floating markers dari HTML → serialize to JSON → strip from HTML
 - [ ] Rollback strategy documented

- **Designer refactor**:
 - [ ] Extract floating elements from HTML on template load → populate JS state
 - [ ] Remove floating markers dari TinyMCE editor content (only inline elements stay in editor)
 - [ ] Show floating elements in dedicated "Floating Elements" sidebar panel dgn drag-to-reposition
 - [ ] Overlay layer di atas editor iframe untuk visual position editing (transparent layer, click-through to editor for text edit)
 - [ ] Serialize floating state on save → JSON metadata

- **Generate refactor**:
 - [ ] Load HTML + floating_elements JSON
 - [ ] Render floating elements as absolute-positioned elements OUTSIDE `.content` wrapper
 - [ ] Position preserved: (position_x, position_y) mm from `.page` origin
 - [ ] Inline elements (non-floating) tetap di HTML content (mereka semantically fit text flow)

- **Backward-compat**:
 - [ ] Detect old-format templates (floating markers still in HTML)
 - [ ] Auto-migrate on first load
 - [ ] Preserve position data selama migration

**Definition of Done**:
- Editor content only contains inline elements + text (no floating markers)
- Floating elements auto-included on save via JSON serialization
- Floating elements auto-extracted on edit
- Zero empty line bugs from floating inserts
- Zero accidental deletion when clearing empty lines
- All existing templates work (backward-compat migration successful)
- Generate output visually identical to before refactor
- Designer UX improved dgn dedicated floating panel

**Non-goals**:
- Migrating INLINE elements (logo/TTD/QR inline variants) — mereka semantically fit text flow, stay in editor
- Changing floating element PDF export (still same rendered output)

### 6.18 Milestone v0.9.13 — "Screen pagination + layout modes + Paged.js removal" ~1-2 weeks

**Goal**: visual multi-paper cards di generate view (screen) matching Google
Docs / Word Online UX. Editing preserved via CSS mask + JS spacer approach
(single DOM container, no restructure). Adopted patterns dari Paged.js
chunker tapi native implementation tanpa external CDN dependency. Layout
mode toggle (paged/continuous) untuk different rendering needs.

**Motivation**: Sebelumnya generate view render content di single tall
`.page` container. Content overflowed paperH tanpa visual page break —
text bleed continuously dari "paper 1" area ke "paper 2" area tanpa gap.
User request: match Google Docs / Word Online visual (paper cards with
gap between) tapi tanpa breaking editing UX (contenteditable `.f` fields
+ TTD signing modal + floating positioning).

**Precedent (industry-standard proven)**:
- **CKEditor 5 Pagination Premium** — commercial plugin ($1500/year) dgn
  same approach: single container + CSS visual paper cards + JS spacer.
  No DOM restructure to preserve editing UX
- **Google Docs** — visual multi-paper pattern (JS-heavy impl too complex
  to port; adopted visual approach only)
- **Word Online** — similar single-container-visual-paginated pattern
- **Paged.js chunker** — inspiration untuk overflow detection algorithm
  (traverse block children, measure position, decide break point). Study
  only, no vendored code

**Deliverables**:

- **`Ezdoc\UI\ScreenPagination`** helper class (new):
  - `renderCss(paperW, paperH, padT, padR, padB, padL, gap, mode)` — CSS
    dgn mask-image cutout + drop-shadow filter (paged mode) atau minimal
    reset (continuous mode)
  - `renderJs(paperH, padT, padB, gap, mode)` — JS IIFE dgn iterative main
    loop (max 300 iters) + case matrix (A push-whole, B split OL/UL, B
    row-spacer TABLE, C accept overflow, D nested drill-down)
  - Cleanup phase idempotent (`mergeSplitContinuations`) untuk re-runs
  - MutationObserver debounced 500ms + `_isRunning` guard + `pushedOnce`
    WeakSet untuk stability

- **`configHeader.layoutMode`** config:
  - Values: `'paged'` (default) atau `'continuous'`
  - Designer UI: "Layout Mode" dropdown di Page settings panel
  - Persisted di `ezdoc_templates.layout_config` JSON
  - Continuous mode: single flow container, no mask, no dashed line preview

- **Print CSS simplification (Paged.js removal)**:
  - Native `window.print()` (no CDN dependency)
  - `@page margin: padT padR padB padL` per-physical-page (CSS Paged Media
    Level 3 spec)
  - `.page` shrunk ke printable area
  - Floating elements `transform: translate(-padL, -padT)` compensation
  - Screen pagination artifacts turned off untuk print (mask, filter,
    spacers)

- **Row-spacer approach untuk tables** (superseded splitTableAt):
  - Insert `<tr class="ezdoc-page-spacer">` with `<td colspan="N">`
    sebelum crossing row → row pushed to next paper padT
  - Preserves single `<table>` structure (no thead cloning chain issue)
  - Zero ID duplication risk

**Definition of Done**:
- Generate view shows content sebagai visual paper cards (paged mode)
  atau single flow (continuous mode)
- `.f` fields tetap editable (contenteditable preserved)
- Table splits properly di row boundary tanpa chain of 20+ pieces
- OL/UL splits preserve numbering (start attribute)
- Print output consistent dgn dompdf PDF Raw (same @page margin approach)
- Zero external CDN dependency
- MutationObserver stable (no infinite loops via re-entry guard)

**Non-goals**:
- Line-level split dalam paragraphs (accepted as trade-off)
- Descend into `<td>` content untuk split paragraphs inside (deferred)
- Word-online-style live pagination di designer editor (out of scope,
  editor pakai TinyMCE which paginated di iframe body sizing pattern)

**Known limitations (accepted)**:
- Paragraphs terlalu panjang untuk fit paper bleed ke gap area (rare untuk
  form-filled templates)
- Single-row tables dgn row taller than paper capacity accept overflow
  (mask hides parts in gap area)
- Firefox older versions mungkin ignore `@page margin` — user harus set
  "Default margins" di print dialog

### 6.19 Milestone v1.0 — "PHP library extraction (Packagist)" ~1 week

**Goal**: pisahkan `ezdoc/` jadi standalone repo, publish ke Packagist. **Depends on v0.9.7 (full views) + v0.9.8 (App orchestrator) + v0.9.9 (DB abstraction) + v0.9.10 (standalone hardening — no consumer-app runtime deps) + v0.9.11 (view separation + generate polish) + v0.9.12 (sidecar floating elements) + v0.9.13 (screen pagination + layout modes)** completed.

- [ ] Move `ezdoc/` folder ke repo baru `mrpotensial/ezdoc`
- [ ] Setup GitHub Actions CI (phpunit + phpstan level 6 + PHP matrix 7.4-8.3)
- [ ] Tag `v1.0.0`, publish ke Packagist
- [ ] Dogfood consumer switch dari path-based include → `composer require mrpotensial/ezdoc`
- [ ] Deprecation notice untuk backward-compat globals

**Definition of Done**:
- `composer require mrpotensial/ezdoc` works di fresh Laravel project
- Dogfood consumer production pakai versi Packagist (bukan lokal path)
- L1 (HMAC), L2 (LocalPKI), L3 (Peruri) semua production-ready
- **Full-featured designer + generator views included** (from v0.9.7) — consumer bisa langsung pakai
- **`Ezdoc\App::run()` 1-line mount + `Ezdoc\App::demo()` zero-config SQLite mode** (from v0.9.8) — consumer install verification tanpa DB config
- Fresh consumer test: install → `Ezdoc\App::demo()` → save template → generate doc → sign → verify (semua works out-of-box, tanpa manual wiring)

### 6.20 Milestone v1.1 — "Spec extraction (repo split + conformance vectors)" ~1-2 weeks

**Goal**: split `ezdoc-spec/` subfolder (seeded di v0.9.9) → standalone repo publik `mrpotensial/ezdoc-spec`; enrich dengan conformance test vectors untuk native ports.

**Prereq**: v0.9.9 completed — `ezdoc-spec/` sudah exist as subfolder di ezdoc repo dgn full schema/ddl/protocol artifacts.

- [ ] `git subtree split ezdoc-spec/` → new repo `mrpotensial/ezdoc-spec`
- [ ] Add conformance test vectors: `conformance/test-vectors.json` (canonical inputs → expected outputs untuk hash, signature verify, template render, QR generation)
- [ ] Enrich protocol docs (envelope format details, hash algo alignment, verify chain state machine) — beyond v0.9.9 seed
- [ ] PHP impl jadi first "reference implementation" yang lulus conformance test suite di CI
- [ ] Cross-lang portage guide: `docs/CROSS-LANGUAGE.md` (dari v0.9.9) → move ke ezdoc-spec/README.md dgn examples Go/Rust/TS
- [ ] Bump versi PHP → `1.1.0`, tag `ezdoc-spec` → `v1.0.0`
- [ ] Ezdoc PHP repo tetap contain `ezdoc-spec/` sebagai git submodule (atau composer-suggests) supaya spec-dump CLI tetap round-trip check

**Definition of Done**:
- `ezdoc-spec` repo public, MIT license, tag v1.0.0
- PHP CI job runs conformance suite → pass
- Repo has: schemas/, ddl/, protocol/, conformance/, docs/
- Docs: "How to write a new port" guide dgn Go + Rust + TS starter examples

### 6.21 Milestone v1.5 — "Go port" ~4-6 weeks

**Goal**: `ezdoc-go` — native Go implementation, container-friendly.

- [ ] Setup repo `mrpotensial/ezdoc-go` dengan Go modules (1.21+)
- [ ] Implement domain models sesuai spec
- [ ] Implement `SignatureProvider` interface + HmacProvider + LocalPkiProvider
- [ ] Implement conformance test suite (import `ezdoc-spec/conformance/`)
- [ ] Implement CLI: `ezdoc verify --file doc.pdf` untuk verifikasi standalone
- [ ] Docker image untuk verify-service microservice
- [ ] Docs: quickstart Go + Kubernetes deployment sample
- [ ] Tag `v0.1.0` Go module

**Definition of Done**:
- `go get github.com/mrpotensial/ezdoc-go` works
- Conformance test pass (signature dari PHP di-verify oleh Go = same result)
- Docker image jalan di Kubernetes cluster

### 6.22 Milestone v2.0 — "TypeScript port + full ecosystem" ~6-8 weeks

**Goal**: `@mrpotensial/ezdoc` — TypeScript native untuk Next.js / Node / browser.

- [ ] Setup repo `mrpotensial/ezdoc-ts` dengan tsup untuk dual ESM+CJS build
- [ ] Implement domain models (Zod schema-parity dengan JSON Schema)
- [ ] Implement `SignatureProvider` interface + HMAC + WebCrypto backend
- [ ] Browser-safe subset (verify-only, tidak sign — private key jangan ke browser)
- [ ] Next.js Server Actions sample project
- [ ] Docs: quickstart Next.js + edge runtime (Cloudflare Workers)
- [ ] Tag `v1.0.0` npm

**Definition of Done**:
- `npm i @mrpotensial/ezdoc` works
- Conformance test pass (PHP ↔ Go ↔ TS interop verified)
- Next.js example app deployed di Vercel
- Browser subset pass di 3 browser latest (Chrome, Firefox, Safari)

## 7. Non-Goals

Explicit apa yang **TIDAK** akan dilakukan library ini:

- **PDF rendering from scratch** — tetap pakai library external (dompdf, mpdf, TCPDF di sisi consumer). Library ini cuma **sign** PDF (PAdES envelope), bukan generate.
- **Domain-specific column di core schema** — TIDAK ADA `norm`, `nopen`, `poli`, `nik`, `nip`, `invoice_no` sebagai kolom hardcoded. Semua domain-specific data masuk `field_values JSON` + `subject_type`/`subject_id`. Consumer boleh bikin profile dengan convenience getter, tapi core schema tetap universal.
- **Indonesia-specific default** di code path — no default lang=id / country=ID / currency=IDR di core. Semua explicit config atau UTC/en-US default.
- **~~Form UI builder~~** — REVISED: reference UI (designer + generate + list) SEKARANG IN-SCOPE, tapi dikemas sebagai paket terpisah (`mrpotensial/ezdoc-ui-blade`) yang optional install. Core `mrpotensial/ezdoc` tetap headless. Consumer bebas skip reference UI dan bangun sendiri.
- **Non-Blade UI packages first-class** — Blade version (`ezdoc-ui-blade`) di-maintain resmi. React/Vue/Livewire adapter = community-driven / stretch goal.
- **Multi-tenant native** — kalau butuh, consumer wrap di layer atas (via Context injection)
- **Real-time collaboration** — out of scope; snapshot + versioning saja
- **Laravel package first-class** — plain PHP dulu, framework adapter menyusul
- **Backward compat dengan `surat_*_v2` tables** — legacy migration provided sekali, setelah itu deprecated
- **PHP < 7.4** — EOL, tidak worth effort untuk support
- **Own CA / issue certificates** — library HANYA konsumsi cert (self-signed atau dari PSrE Indonesia). Tidak jadi Certificate Authority sendiri.
- **Hardware Security Module driver** — support HSM via PKCS#11 interface saja (via `ext-openssl` atau external library seperti `pkcs11-tool`). Tidak nulis native HSM driver.
- **Bridge / RPC between language ports** — Go port TIDAK panggil PHP backend. Setiap port standalone. Interoperability via shared spec + DB, bukan network protocol.
- **~~Blockchain / distributed ledger anchoring~~** — REVISED: **IN-SCOPE** sebagai add-on orthogonal (v0.9.5). Anchor bukan pengganti signature — combine bebas dengan L1-L3. Default backends: OpenTimestamps (free) + Polygon (murah). Consumer bisa custom `AnchorProvider` untuk chain lain.
- **Native crypto wallet management** — library tidak simpan private key user's wallet. Untuk EVM anchoring, operator key (env var, satu untuk seluruh org) yang submit tx — bukan per-signer wallet. Full W3C VC / per-user DID flow = stretch v2.x+.
- **Cryptocurrency payments / DeFi** — anchoring pakai chain HANYA untuk hash storage, bukan token transfer. Tidak jadi wallet/pembayaran feature.
- **AI / OCR / document classification** — out of scope
- **Non-Indonesia PSrE support first-class** — DocuSign / Adobe Sign / bisa saja di-integrasi kalau ada demand, tapi bukan roadmap utama. Fokus PSrE Indonesia (Peruri, Privy, VIDA, BSrE).

## 8. Compatibility & Distribution

### 8.1 PHP versions

- **Minimum**: 7.4 (via polyfill layer)
- **Recommended**: 8.2+
- **Tested via CI**: 7.4, 8.0, 8.1, 8.2, 8.3 (via `matrix` di GitHub Actions)

### 8.2 Composer package

- Package name: `mrpotensial/ezdoc`
- Namespace: `Ezdoc\*`
- License: MIT
- Repository: TBD (kandidat: `github.com/mrpotensial/ezdoc`)

### 8.3 Semantic versioning

- `0.x` — pre-release, breaking changes allowed
- `1.x` — stable, breaking changes require major bump

## 9. Security Considerations

- **HMAC secret**: WAJIB via env var (`EZDOC_HMAC_SECRET`), NEVER hardcoded
- **SQL injection**: semua query via `mysqli_prepare()` + `bind_param()` (no `mysqli_query()` dengan concat)
- **XSS**: consumer's UI responsibility, tapi library provide `ezdoc_e()` helper untuk output escape
- **RBAC**: default deny, whitelist role/user IDs
- **Audit trail**: silent-fail write (business logic never blocked kalau audit table down), tapi log error via `error_log`
- **Session hijack**: library tidak handle session — delegated ke consumer's auth system

## 10. Open Questions

Berikut point yang perlu decision dari stakeholder:

### 10.1 Baseline (v0.2-v0.5)
1. **Legacy migrations** — kapan aman di-delete dari folder `migrations/`? Perlu confirm semua production env sudah migrated ke `ezdoc_*` tables.
2. **HMAC secret rotation** — apakah perlu support key rotation (multiple valid keys)?
3. **Doc versioning** — apakah document juga versioned seperti template? (skarang document immutable setelah locked)
4. **Multi-language i18n** — apakah error message perlu i18n dari awal?
5. **Framework priorities** — Laravel dulu atau WordPress dulu untuk sample?

### 10.2 Signature & PSrE (v0.6-v0.9)
6. **PSrE prioritas** — Peruri dulu (kandidat teratas karena banyak dipakai instansi kesehatan) atau Privy (pricing lebih ramah startup)? Konfirmasi budget & existing contract.
7. **Certificate storage** — cert-per-user disimpan di `ezdoc_user_certificates` (baru), atau reference-only ke PSrE user account (fetch on-demand)?
8. **OTP flow untuk L3** — email, SMS, atau in-app push? Vendor biasanya kasih pilihan multi-channel.
9. **TSA source** — pakai TSA nya PSrE (bundled), BSrE (government), atau external (freetsa.org)?
10. **Sign-later flow** — kalau signer offline, apakah document boleh disimpan sebagai draft dengan placeholder signature, atau tolak upfront?

### 10.3 Blockchain anchoring (v0.9.5)
11a. **Chain default** — OpenTimestamps (free, Bitcoin-rooted, 1h delay) atau Polygon (paid ~$0.01, 2s delay, smart contract)? Kandidat: kombinasi — Polygon untuk real-time, OpenTimestamps untuk backup.
11b. **Operator wallet management** — private key untuk submit tx disimpan di env var, HSM, atau outsourced (mis. Fireblocks / OpenZeppelin Defender)?
11c. **Auto-anchor vs manual** — semua doc auto-anchor, atau opt-in per template (via `anchor_config` di `ezdoc_templates`)?
11d. **BatchAnchor cadence** — daily rollup, hourly, atau on-demand ketika mencapai N pending?
11e. **Chain di-support kedua** — setelah default, chain apa lagi (Ethereum mainnet untuk gravitas, Hyperledger untuk konsorsium hospital, Solana untuk cost)?
11f. **Publik-verifiable link** — apakah kita expose halaman `/verify/{receipt_id}` sebagai public URL untuk audit third-party?

### 10.4 Cross-language ports (v1.1+)
11. **Spec ownership** — `mrpotensial` maintain spec sendiri, atau donate ke lembaga (mis. Kominfo working group / hospital consortium / open governance) supaya jadi standar publik?
12. **Go port timing** — begitu PHP v1.0 rilis, langsung mulai? Atau tunggu 6 bulan buat validasi stabilitas API?
13. **TS port scope** — full parity dengan PHP (server + sign) atau verify-only untuk browser?
14. **Rust port** — apakah masuk roadmap resmi atau community-driven saja?
15. **Certification interop** — apakah perlu certification dari BSSN / Kominfo untuk klaim "compliant"?

### 10.5 Business & governance
16. **License** — MIT (permissive, adoption max) atau Apache 2.0 (patent grant, lebih formal)?
17. **Commercial support** — `mrpotensial` jual support commercial (hosted PSrE, custom adapter), atau full free? Kalau commercial, apakah bundle dengan cloud offering?
18. **Governance model** — maintainer-only, atau open contributors dengan RFC process? Konsorsium (mis. hospital / legal / edu network) bisa co-govern?

## 11. Reference

### 11.1 Foundation
- **UUID v7**: [RFC 9562](https://datatracker.ietf.org/doc/rfc9562/) (May 2024)
- **Semantic Versioning**: https://semver.org/spec/v2.0.0.html
- **Keep a Changelog**: https://keepachangelog.com/en/1.1.0/
- **PSR-4 Autoloading**: https://www.php-fig.org/psr/psr-4/
- **Symfony Polyfill**: https://github.com/symfony/polyfill

### 11.2 Cryptography & Signature
- **PKCS#7 / CMS**: [RFC 5652](https://datatracker.ietf.org/doc/rfc5652/) — Cryptographic Message Syntax
- **PAdES**: [ETSI EN 319 142](https://www.etsi.org/deliver/etsi_en/319100_319199/31914201/) — PDF Advanced Electronic Signatures
- **XAdES**: [ETSI EN 319 132](https://www.etsi.org/deliver/etsi_en/319100_319199/31913201/) — XML Advanced Electronic Signatures
- **RFC 3161**: Time-Stamp Protocol (TSP)
- **RFC 8785 (JCS)**: JSON Canonicalization Scheme — untuk deterministic hash
- **PKCS#11**: HSM interface — https://www.oasis-open.org/committees/tc_home.php?wg_abbrev=pkcs11
- **X.509**: [RFC 5280](https://datatracker.ietf.org/doc/rfc5280/) — Certificate & CRL Profile

### 11.3 Indonesian PSrE
- **Peruri Digital Sign**: https://peruri.co.id/produk-layanan/digital-sign
- **Privy**: https://privy.id/for-developer
- **VIDA**: https://vida.id/produk/e-sign
- **Digisign**: https://digisign.id
- **BSrE (Balai Sertifikasi Elektronik)**: https://bsre.bssn.go.id
- **UU ITE 2016** Pasal 11 — Tanda tangan elektronik sertifikasi (bersertifikat = kekuatan hukum penuh)
- **PP 71/2019** — Penyelenggaraan Sistem dan Transaksi Elektronik

### 11.4 Blockchain & anchoring
- **OpenTimestamps**: https://opentimestamps.org — free Bitcoin anchoring, batched
- **Chainpoint**: https://chainpoint.org — anchoring standard (v4)
- **Ethereum Improvement Proposals (EIPs)**: https://eips.ethereum.org/
- **EIP-712** — typed structured data signing: https://eips.ethereum.org/EIPS/eip-712
- **EIP-1559** — gas fee mechanism (baseline biaya anchor): https://eips.ethereum.org/EIPS/eip-1559
- **Polygon docs**: https://docs.polygon.technology
- **Hyperledger Fabric**: https://hyperledger-fabric.readthedocs.io
- **W3C Verifiable Credentials Data Model 2.0**: https://www.w3.org/TR/vc-data-model-2.0/
- **W3C Decentralized Identifiers (DIDs)**: https://www.w3.org/TR/did-core/
- **Solidity docs**: https://docs.soliditylang.org
- **web3.php**: https://github.com/web3p/web3.php — Ethereum interaction from PHP
- **go-ethereum**: https://geth.ethereum.org/docs/developers/dapp-developer/native — Go client
- **ethers.js**: https://docs.ethers.org — TypeScript client

### 11.5 Cross-language / native ports
- **Go `crypto/x509`**: https://pkg.go.dev/crypto/x509
- **Node.js `crypto` (WebCrypto)**: https://nodejs.org/api/webcrypto.html
- **Rust `openssl` crate**: https://docs.rs/openssl/
- **JSON Schema draft 2020-12**: https://json-schema.org/draft/2020-12/schema
- **OpenAPI 3.1**: https://spec.openapis.org/oas/v3.1.0
