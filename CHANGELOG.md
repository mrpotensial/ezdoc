# Changelog

All notable changes to `ezdoc` will be documented in this file.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) + [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — i18n scaffold (`Ezdoc\UI\Translator`)
Externalized hardcoded Bahasa Indonesia UI strings in `views/document/designer.php`
and `views/document/generate.php` into per-locale PHP array catalogs — see
[docs/I18N.md](docs/I18N.md).

- **`Ezdoc\UI\Translator`** (`src/UI/Translator.php`) — composes `Ezdoc\UI\Config`
  internally (same pattern as `Theme`). `t(key, params, default)` never throws;
  falls back to `default`/key on a missing or malformed catalog. `{param}`
  interpolation via `strtr()` (deliberately not `{{param}}`, which collides with
  ezdoc's own document-template mustache syntax).
- **`lang/id/{common,designer,generate}.php`** — Indonesian string catalogs.
  Only `id` populated for now; structure supports adding `lang/en/*` later
  without view-code changes.
- Both view files wire `$translator` through their existing bootstrap-fallback
  block (mirrors the `$ctx`/`$config` dual-path resolution already there), with
  explicit `$GLOBALS['translator']` promotion — required because
  `Router::renderView()` includes views from method scope (same scope-isolation
  class of bug fixed for `$dbFields`/`$dbTtd` previously). A local
  `function_exists`-guarded `t()` helper sits next to each view's existing `h()`.
  JS side gets a matching `EZDOC_I18N` dictionary + `t()` walker, injected via
  each file's existing URL-bag mechanism (`data-ezdoc-urls` attribute for
  designer.php, inline `window.EZDOC_URLS` const for generate.php).
- Out of scope for this pass (see docs/I18N.md): AJAX action-endpoint response
  strings, per-document/template author-entered content (`data-label`,
  `data-options`, category/template names), and the pre-existing
  `Config`-driven copy overrides (`designer.page_title`, `generate.picker_*`).
- **`lang/en/{common,designer,generate}.php`** — English catalog, generated
  directly from the `$default` argument already present at every `t()` call
  site (not hand-translated) — that argument doubles as the English source
  string per this system's convention. Verified via `php -l` plus a
  standalone runtime smoke test (key resolution, `{param}` interpolation,
  per-locale isolation, missing-key/bad-locale fallback) for both `id` and
  `en`.

## [0.8.0] - 2026-07-10 — "PAdES envelope + RFC 3161 timestamp + PDF sign/verify wrapper"

### Added — Timestamp layer (5 files, ~1240 LOC, `src/Signature/Timestamp/`)
RFC 3161 Timestamp Authority (TSA) integration untuk PAdES-B-T long-term validity.

- **TimestampClient interface** — `requestTimestamp(hash, algo)`, `verifyTimestamp(token, hash)`
- **TimestampToken** — DTO dengan auto-parse via minimal DER walker (best-effort, tidak throw on malformed). Getters: `getGenTime()`, `getSerialNumber()`, `getPolicyOid()`, `getTsaCertPem()`. `toBase64()` / `fromBase64()` untuk transport.
- **TimestampVerdict** — DTO dengan status factories: `valid(genTime)`, `invalid(reason)`, `untrusted(reason)` (structurally OK tapi CA missing)
- **OpensslTimestampClient** — shell-out ke `openssl ts` CLI:
  - Request: `openssl ts -query -digest HEX -sha256 -cert` + curl POST ke TSA URL (Content-Type: application/timestamp-query)
  - Verify: `openssl ts -verify -in TOKEN -digest HEX -CAfile BUNDLE`
  - Nonce included by default (anti-replay per RFC 3161)
  - Accepts `$dataHash` as hex-string OR raw binary (auto-detect via `ctype_xdigit` + even length)
  - Config: `openssl_bin` (Windows path override), `auth_header` (Basic Auth for BSrE), `ca_bundle_path`, `timeout`
  - Uses `proc_open()` + `escapeshellarg()` — no unsafe shell interpolation
- **HttpTimestampClient** — pure PHP fallback (no CLI dependency):
  - Manually emits DER-encoded TimeStampReq via minimal ASN.1 builder
  - Static `$HASH_OID_BYTES` lookup untuk SHA-256/384/512
  - POST via `Ezdoc\Signature\Remote\HttpClient`
  - Verify returns `TimestampVerdict::untrusted("HttpTimestampClient cannot verify — use OpensslTimestampClient")`

### Added — PAdES + PDF layer (6 files, ~1605 LOC, `src/Signature/{Envelope,Pdf}/`)
PDF Advanced Electronic Signatures (ETSI EN 319 142) — PAdES-B-B baseline dengan extensibility ke B-T / B-LT.

- **PadesEnvelope implements Envelope** — wraps PdfSigner interface:
  - `pack(signature, content, cert, options)` — delegates to PdfSigner impl untuk embed
  - `unpack(bytes)` — extract signature bytes + /ByteRange + signer cert
  - `verify(bytes, originalContent, options)` — delegates ke `PdfSigner::verifyPdf()`
  - `canDetached(): false` — PDF signatures are always attached
  - Static `isPadesSignedPdf(bytes): bool` — sniff `/Sig` / `/Type/Sig` in PDF trailer
- **PdfSigner interface** — kontrak PDF signer:
  - `embedSignature(pdfBytes, pkcs7Bytes, cert, options): string` — returns signed PDF
  - `extractSignature(pdfBytes): array` — returns signature_bytes + byte_range + cert_pem + sig_info
  - `verifyPdf(pdfBytes, options): array` — returns valid + reason + checks + signer_cert_pem + signed_at
- **OpensslPdfSigner** — FULL extract/verify, embedSignature STUB:
  - `extractSignature()` — FULL: manual `/ByteRange` + `/Contents` parse via regex, `openssl_pkcs7_read` untuk cert extraction, `/Sig` dict fields (M, Name, Reason, Location)
  - `verifyPdf()` — FULL: `pdfsig` (poppler-utils) primary + `openssl cms -verify` fallback + ByteRange full-coverage check
  - `embedSignature()` — **STUB** dengan 4 TODO markers. Throws `EzdocException` dengan actionable guidance untuk pakai JSignPdfSigner / SetasignPdfSigner / ExternalPdfSigner. Chose fail-loudly over silent no-op untuk prevent downstream assumptions.
- **JSignPdfSigner** — FULL production-ready via shell-out ke `jsignpdf.jar`:
  - Requires Java Runtime + jsignpdf.jar (free, https://jsignpdf.sourceforge.net/)
  - Constructor: `jsignpdf_path`, `java_path`, `temp_dir`
  - `embedSignature()` — full args: `-ksf` (keystore file), `-ksp` (passphrase), `-ka` (alias), `-r` (reason), `-l` (location), `-cn` (name), `-ts` (TSA URL), `-V` (visible sig), `-pg` + `-llx` (position)
  - Supports PAdES-B-T via `-ts` flag; PAdES-B-LT via `-tsp` + OCSP options
  - CLI flag baseline for JSignPdf 2.2.x (TODO: re-check untuk installed version)
- **ExternalPdfSigner** — closure-delegation untuk cloud signing services:
  - Constructor: `embedFn`, `verifyFn`, `extractFn` callables
  - Consumer wraps vendor REST client (Peruri PAdES endpoint, Privy PDF sign, VIDA)
- **PdfBytesRange** — utility for `/ByteRange` parsing/manipulation:
  - `fromPdf(bytes): self` — parse first `/ByteRange` in PDF
  - `findAll(bytes): array<self>` — multi-signature support
  - `getSignatureOffset()`, `getSignatureLength()`
  - `computeHashedContent(bytes)` — extract bytes covered by ByteRange
  - Static `computeHash(pdfBytes, byteRange, algo)` — SHA-256 default
  - `isFullCoverage()` — reject partial-coverage attacks
  - `isPlaceholder()` — detect pre-sign placeholder ByteRange

### Added — Documentation
`docs/PADES-TSA.md` (~380 lines, 16.8 KB):
- Overview: PAdES profiles (B-B/B-T/B-LT/B-LTA), UU ITE + PP 71/2019 legal framing, RFC 3161 role
- Architecture: SignatureProvider ↔ PdfSigner ↔ PadesEnvelope ↔ TimestampClient separation, ASCII flow diagram
- **PdfSigner comparison table** (Setasign commercial / JSignPdf free full-featured / Openssl verify-only / External cloud)
- **TimestampClient comparison table** (Openssl full / Http sign-only)
- **Common TSA endpoints table** (BSrE / FreeTSA / DigiCert / Sectigo) + 3 config code samples
- Quick start B-B: full 4-step sample (load PDF + hash ByteRange + sign hash + embed)
- Quick start B-T: TSA integration via `options['timestamp_token']` + B-LT note
- Quick start Verify: extract via `PadesEnvelope::unpack()` + PKCS#7 verify + TST verify + combine Verdict
- Adobe Reader validation: green/yellow/red status meanings, AATL trust list, PSrE root import
- Known limitations di v0.8, L2 → L3 migration side-by-side code, testing without live TSA
- References: ETSI EN 319 142, RFC 3161, RFC 5652, BSrE portal, JSignPdf, setasign FPDI, AATL

### Design decisions

**Reference impl via shell-out** — PHP tidak punya native PAdES support. Realistic strategy:
- Timestamp: `openssl ts` CLI portable + battle-tested
- PDF signing: JSignPdf (Java + jsignpdf.jar) untuk production, ExternalPdfSigner untuk cloud services
- Consumer choose based on constraints (Java available? commercial license? cloud service?)

**OpensslPdfSigner embedSignature intentionally throws** — silent no-op would leave downstream code assuming signed PDF. Exception routes consumer ke JSignPdf/External/Setasign. `embedSignature()` requires PDF incremental xref update + `/ByteRange` patch which is complex ASN.1 + PDF cross-ref work; in-tree implementation would risk PDFs that "look valid" tapi fail PSrE conformance suites.

**Shell-out safety** — verify agent confirmed:
- Unsafe patterns (`shell_exec`, `exec(`, `passthru`): **0 matches** ✓
- `proc_open` (safe pipe-based): **12 matches** across 4 files ✓
- `escapeshellarg` (arg escape): **29 matches** across 3 files ✓
- All CLI args go through escapeshellarg — no user string interpolated raw

**Nonce included by default** — anti-replay per RFC 3161. Spec text mentions `-no_nonce` tapi RFC 3161 §2.4 strongly recommends nonce.

**BSrE integration ready** — `OpensslTimestampClient` config supports Basic Auth header untuk BSrE production endpoint (`https://tsa.bssn.go.id/signing/timestamp`) + `ca_bundle_path` untuk trust chain.

### Recommended consumer setup

| Use case | Recommended stack | Notes |
|----------|-------------------|-------|
| **Dev / testing** | `ExternalPdfSigner` dengan mock closures | No dependencies |
| **Internal docs, PAdES-B-B only** | TCPDF `setSignature()` at document level + `OpensslPdfSigner` untuk extract/verify | PHP-only |
| **Enterprise PAdES-B-T/LT** | `JSignPdfSigner` + JSignPdf.jar + JRE + TSA URL | Free, full-featured |
| **PSrE compliance (Peruri/Privy/BSrE)** | `ExternalPdfSigner` wrapping vendor REST client | Vendor returns signed PDF |
| **Verify pipeline** | `OpensslPdfSigner` + `pdfsig` (poppler-utils) | Richest verdict |

### Known limitations di v0.8

- **OpensslPdfSigner::embedSignature** NOT implemented (STUB) — PDF byte-range manipulation complex; consumer routes ke JSignPdf / SetasignPdfSigner / ExternalPdfSigner
- **JSignPdf CLI flag drift** across major versions — pin jar version in production deployment
- **Windows `pdfsig`** rarely available — verify falls back ke `openssl cms -verify` (chain only, no TSA/LTV verification)
- **Reserved `/Contents` size** default 32KB; deep chain (Peruri intermediate + root) + LT (OCSP/CRL) mungkin butuh 48-64KB — configurable via `reserved_signature_size`
- **Adobe Trust Store** — valid PAdES-B-LT signature tetap yellow triangle kalau PSrE root tidak di AATL/user trust list. This is UX, bukan signer bug — documented untuk end users
- **PAdES-B-LTA** (Archive Timestamp) — planned v0.8.1

### Multi-agent workflow
6 agents parallel (2 research + 3 write + 1 verify). **~275K tokens, ~20 minutes wall-clock**. All 11 PHP files pass syntax + zero PHP 8+ leaks. Verify agent confirmed:
- Integration: all PdfSigner impls + TimestampClient impls + PadesEnvelope resolve correctly
- Shell-out safety: 0 unsafe patterns, all args escaped
- 4 TODO/stub markers in OpensslPdfSigner detected (as expected)

## [0.7.1] - 2026-07-10 — "Hotfix: signatures migration + Tailwind UI + query() portability"

### 🚨 Fixed — critical migration bug (ezdoc_signatures FK type mismatch)

`ezdoc_signatures` CREATE TABLE gagal silently sejak v0.6 karena FK constraint type mismatch. Bootstrap sanity check show "Setup Database Diperlukan" page di semua halaman consumer.

**Root cause**:
- `ezdoc_documents.id` = `BIGINT` (SIGNED)
- `ezdoc_signatures.document_id` = `BIGINT UNSIGNED` ❌
- MySQL FK requires exact type match (sign/unsigned modifier included)
- `$conn->query()` return `false` (bukan throw) → Runner `\Throwable` catch tidak trigger → migration recorded as applied padahal table tidak dibuat

**Fixes applied**:
1. **Migration file** (`migrations/2026_01_01_000005_create_ezdoc_signatures.php`):
   - `id BIGINT UNSIGNED` → `BIGINT`
   - `document_id BIGINT UNSIGNED` → `BIGINT`
   - `signer_user_id BIGINT UNSIGNED` → `BIGINT`
   - Added explicit `if ($ok === false) throw new RuntimeException(...)` with `$conn->error` context
2. **Runner self-heal** (`src/Migrations/Runner.php`):
   - Default `coreTables` sekarang include `ezdoc_signatures` — auto-detect orphan + clear registry
3. **Runner fail-loudly mode**:
   - Enable `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` selama migration execute
   - Legacy `$conn->query()` sekarang throw `mysqli_sql_exception` on SQL error
   - `try/catch/finally` restore previous report mode setelah selesai
   - **Prevents future silent-failure bugs** untuk migration lain

Consumer action: **reload halaman** — self-heal akan trigger + re-run migrations dengan fixed types.

### 🚨 Fixed — form_pembuat_surat_list_v3.php fatal `query()` undefined

File di-akses langsung (bypass `index.php?page=...` router yang biasanya load `koneksi.php`) → `query()` undefined → fatal error di line 65.

**Fix**: added defensive `require_once __DIR__ . '/../koneksi.php';` guarded dengan `function_exists('query')` check + `require_once __DIR__ . '/../ezdoc/bootstrap.php';` at top of file. Idempotent (function_exists guard + require_once).

### Added — Portable DB helper (`ezdoc/lib/db_helpers.php`)

Industry-standard prepared-statement wrapper untuk consumer library user yang tidak punya legacy monolith `query()` global function.

**Preference order (best → worst)**:
1. Repository classes (`Ezdoc\Document\DocumentRepository`, dll) — v0.4+ pattern
2. `ezdoc_query_prepared($sql, $params, $types)` — prepared statement wrapper dengan auto-type detection
3. `ezdoc_query($sql)` — legacy `query()` fallback, backward compat only (marked deprecated)

Functions provided:
- `ezdoc_query_prepared($sql, array $params = [], string $types = ''): array`
  - Empty `$params` → raw query fallback
  - Auto-detect types kalau tidak disediakan (string, int, float, blob)
  - Validate types length matches params count
  - Returns array of assoc rows atau empty on error (silent + error_log)
- `ezdoc_query($sql)` — backward-compat wrapper:
  - Priority 1: pakai legacy `query()` kalau available (koneksi.php)
  - Priority 2: fallback native mysqli via `ezdoc_get_db_connection()`
- `ezdoc_get_db_connection(): ?\mysqli`:
  - Priority 1: `\Ezdoc\Context::default()->db` (v0.3+ library-ready)
  - Priority 2: `$GLOBALS['conn']` (legacy koneksi.php)
- `ezdoc_escape_identifier(string): string` — whitelist `[a-zA-Z0-9_]`, backtick-wrap, throw ValidationException on invalid

### Changed — UI starter templates: Bootstrap → Tailwind CSS

Industry-standard shift dari Bootstrap ke Tailwind CSS (utility-first, dominant di React/Vue/Next.js/Laravel ecosystem 2024+).

**Files converted**:
- `views/layout.php` — Tailwind Play CDN + `@tailwindcss/forms` + `@tailwindcss/typography` plugins, CSS variable bridge yang inject `--ezdoc-*` dari Config
- `views/document/list.php` — utility classes (grid, table, badges), status badges dengan Tailwind ring + color palette, empty state dengan SVG icon
- `views/document/form.php` — form fields dengan Tailwind Forms plugin styling, fieldset borders, monospace code hints

**Design highlights**:
- **CSS variable bridge**: layout `<head>` inline `<style>` inject `--ezdoc-primary` dari `$theme` → Level-1 Config override propagates ke Level-2 CSS otomatis
- **Zero build step** starter — Play CDN load dari `<script>`, tidak perlu npm/webpack
- **Production path documented** — consumer bisa swap ke compiled Tailwind untuk 15-25 KB bundle (vs ~350 KB CDN)
- **Fallback graceful** — `ezdoc.css` defines `.ezdoc-*` component classes untuk consumer TANPA Tailwind

### Changed — `assets/css/ezdoc.css` refactored

- Removed Bootstrap-specific overrides
- Added Tailwind-compatible token palette (matches Tailwind hue conventions: gray-500 base, blue-600 issued, emerald-600 signed, red-600 void)
- Component fallback classes (`.ezdoc-btn`, `.ezdoc-card`, `.ezdoc-table`, `.ezdoc-badge`, `.ezdoc-input`) untuk consumer tanpa Tailwind
- Dark mode support via `<html class="dark">` toggle
- Extended variables: `--ezdoc-shadow-sm/md/lg`, `--ezdoc-status-draft/issued/signed/void`

### Changed — `docs/UI-CUSTOMIZATION.md` updated

- Added "Why Tailwind?" preface with industry-standard justification
- Updated Level 2 dengan 3 patterns: CSS variables (recommended) / Tailwind utility overrides / component-level style overrides
- Added "Dark mode" subsection dengan JS toggle example
- New comprehensive "Tailwind production build" section (~80 lines):
  - npm install + tailwind.config.js dengan library views scan
  - CSS entrypoint pattern
  - Compile commands (dev watch + production minify)
  - Swap CDN with compiled bundle (Level 3 view publish)
  - Sample bundle sizes (350 KB CDN → 15-25 KB compiled)
  - Standalone Tailwind binary alternative (no Node.js)
  - Skip Tailwind entirely pattern (use ezdoc.css fallback only)

### Notes on `query()` migration scope

Grep menunjukkan **4,114 `query()` calls** di seluruh pengeluaran/ folder (bukan cuma ezdoc). Wholesale migration ke prepared statements = weeks of refactor + high regression risk. Library scope untuk ezdoc:
- ✅ `ezdoc/lib/db_helpers.php` provides portable alternatives
- ✅ Repository classes (v0.4+) sudah pakai prepared statements internally
- ❌ Existing monolith consumer pages tetap pakai `query()` — that's consumer legacy code, not library concern
- ✅ Consumer library user (framework or fresh install) dapat pakai `ezdoc_query_prepared()` atau Repository directly

## [0.7.0] - 2026-07-10 — "PSrE integration foundation (Envelope + HttpClient + Peruri/Privy stubs)"

### Added — Envelope layer (5 files, ~31 KB)
`src/Signature/Envelope/*` — signature envelope format abstraction
- `Envelope` — interface dengan `getFormat()`, `pack()`, `unpack()`, `verify()`, `canDetached()`
- `RawEnvelope` — passthrough (no wrapping) untuk L1/L2
- `HmacEnvelope` — `hmac:<hex>` prefix wrapper (tolerant of already-prefixed input)
- `PkcsSevenEnvelope` — **full RFC 5652 CMS/PKCS#7 implementation** via `openssl_pkcs7_sign|verify|read`:
  - Uses `tempnam()` + `chmod 0600` + `try/finally` untuk temp file safety (openssl_pkcs7_* functions require file paths, no string buffer support)
  - `PKCS7_BINARY | PKCS7_DETACHED` flags default (avoid CRLF canonicalization)
  - Static `isPkcs7(bytes): bool` — sniffs PEM armor `-----BEGIN PKCS7-----` atau DER signedData OID
  - Drains OpenSSL error queue before + after operations
- `EnvelopeRegistry` — format string → Envelope map dengan `defaultRegistry()` yang pre-register raw + hmac + pkcs7

### Added — Remote/HTTP layer (6 files, ~50 KB)
`src/Signature/Remote/*` — HTTP client abstraction + async signing infrastructure
- `HttpClient` — interface (`request(method, url, options): HttpResponse`)
- `HttpResponse` — immutable DTO dengan case-insensitive `getHeader()`, `isSuccess()` (2xx), `getJsonBody()` (throws ValidationException on invalid JSON)
- `CurlHttpClient` — final class implementing `HttpClient` via curl:
  - Constructor validates `extension_loaded('curl')` — throws ValidationException if missing
  - Auto-encodes JSON body + Content-Type, form-urlencodes array body
  - Parses response headers robustly (handles redirect chains)
  - Throws `EzdocException` on curl transport error dengan errno/errstr context
  - Supports mTLS + CA bundle + proxy via default curl opts
- `SignSession` — DTO for multi-step signing state:
  - Constants: STATUS_PENDING / OTP_REQUIRED / PROCESSING / COMPLETED / EXPIRED / FAILED
  - Methods: `isCompleted()`, `isExpired()`, `needsOtp()`, `toArray()`
- `OtpChallenge` — DTO for "waiting for OTP" state:
  - Constants: CHANNEL_EMAIL / SMS / APP / WA
  - Fields: sessionId, channel, maskedTarget (mis. 'a***@example.com'), expiresAt, attemptsRemaining
- `BaseRemoteProvider` — **abstract class implements SignatureProvider** (~26 KB) dengan template-method pattern:
  - **9 abstract methods** subclass MUST implement (getProviderName, endpoints, authHeaders, buildSignRequestPayload, parseSignResponse, parseSessionResponse, parseOtpChallenge)
  - `final sign()`: initiate → auto-poll async session dengan exponential backoff sampai completed/expired/failed
  - `final verify()`: POST ke `/verify` endpoint dengan base64 envelope + content, maps JSON back to Verdict
  - `final capabilities()` returns `getCaps()` (overridable hook, default L2)
  - Public multi-step API: `initiate()`, `submitOtp()`, `getSession()`
  - `final protected httpGet/httpPost/httpPut` — auto-inject `X-Request-Id` for idempotency, merge `authHeaders()` + default_headers
  - `final protected requireSuccess()` — maps HTTP status ke canonical exception:
    * 401/403 → `AccessDeniedException`
    * 404 → `NotFoundException`
    * 400/422 → `ValidationException`
    * others → `EzdocException`
  - Extracts provider error code/message/request_id dari JSON error envelope
  - **Retry**: 3 attempts (configurable), exponential backoff dengan jitter, retryable on network error + 5xx + 429
  - Optional `Logger` injection → silent `audit()` calls on state transitions

### Added — PSrE Provider stubs (2 files, ~32 KB, 34 TODO markers)
`src/Signature/Providers/*` — Peruri + Privy stubs dengan STRUCTURED TODO for real API integration
- `PeruriProvider` (423 lines, **19 `TODO(v0.7-real)` markers**):
  - `getProviderName(): 'peruri'`
  - Config required: `base_url`, `client_id`, `client_secret`, `signer_id` (NIK)
  - Config optional: `timeout` (default 30), `callback_url` for async, `test_mode`
  - `getCaps()`: level=3, formats=[pkcs7, pades], supportsTimestamping=true
  - Auth: `Bearer $accessToken` (TODO markers explain OAuth2 client credentials OR API key + HMAC)
  - Static `fromConfig(array): self` helper
  - Extensive docblocks: sandbox onboarding pointers, test cert acquisition, likely auth paths
- `PrivyProvider` (425 lines, **15 `TODO(v0.7-real)` markers**):
  - `getProviderName(): 'privy'`
  - Config: `base_url`, `client_id`, `client_secret`, `merchant_id` (Privy-specific), `signer_email`
  - `getCaps()`: level=3, formats=[pkcs7, pades], supportsTimestamping=true
  - Privy uses **signer_email** lookup (not NIK)
  - Mobile-push default (`PRIVY_APP`) dengan OTP fallback
  - Includes `maskEmail()` helper untuk OtpChallenge target masking

### Added — Documentation
`docs/PSRE-INTEGRATION.md` (14 KB, 388 lines):
- Overview: what PSrE is, why L3, legal validity under UU ITE 2016
- Supported providers status (Peruri stub v0.7, Privy stub v0.7, VIDA + BSrE planned v0.9)
- Architecture: BaseRemoteProvider abstract, HttpClient interface, SignSession/OtpChallenge state, Envelope layer
- Getting started dengan Peruri sample: register di dev portal, config setup, multi-step sign code sample
- Envelope format PKCS#7 (RFC 5652): pack/unpack examples, cert chain + CA bundle verify
- Testing without live API: FakeRemoteProvider (planned v0.7.1), test vectors
- Cost estimates per PSrE vendor (rough)
- Legal validity checklist (UU ITE requirements, registered PSrE cert, timestamping v0.8)
- Known limitations di v0.7 (stubs need real API mapping, no webhook handler yet, no cert revocation check)

### Design highlights

**PKCS7 pack signature contradiction resolved** — Agent flagged spec conflict:
- Spec: "signature already computed by SignatureProvider, this just wraps"
- Reality: PKCS#7 SignedData **cannot** be built dari pre-computed raw signature — needs signer info (issuer, serial, signedAttrs) yang cuma bisa dari signing operation
- **Resolution**: `pack()` requires `X509Certificate $cert` + `PrivateKey` via `options['private_key']`, `$signature` parameter di-ignore. Documented di docblock.

**HTTP client fully swappable** — consumer bisa inject Guzzle, Symfony HttpClient, PSR-18 sebagai gantinya (implements HttpClient interface). Default `CurlHttpClient` untuk immediate usability.

**Idempotency built-in** — `BaseRemoteProvider` auto-injects `X-Request-Id` UUID di setiap POST/PUT untuk prevent duplicate charges/sessions kalau PSrE endpoint idempotent-key aware.

**Canonical exception mapping** — HTTP status → domain exception:
- Auth failures (401/403) → `AccessDeniedException`
- Missing resources (404) → `NotFoundException`
- Validation errors (400/422) → `ValidationException`
- Others → `EzdocException`

Consumer bisa catch specific exception type tanpa parse HTTP status.

**Retry policy conservative** — 3 attempts dengan exponential backoff + jitter. Only retries network errors + 5xx + 429. **NEVER** retries POST /sign automatically kalau tidak idempotency-key aware (avoid duplicate PSrE charges).

**PHP 7.x/8+ dual-safe** throughout — zero `OpenSSLAsymmetricKey`/`OpenSSLCertificate` type-hints di signatures. Docblocks explain pattern untuk subclasses.

### Known limitations di v0.7 (deferred ke v0.7.1)

- **Stubs need real API mapping** — 34 total `TODO(v0.7-real)` markers between Peruri + Privy providers. Consumer library user dengan sandbox credentials tinggal fill in specifics (endpoint URLs, payload JSON schema, response field mapping, actual auth flow)
- **No FakeRemoteProvider** untuk testing tanpa live API — planned v0.7.1
- **No auto-webhook handler** untuk async callbacks — planned v0.7.1
- **No cert revocation check** (CRL/OCSP) — planned v0.8 sebagai bagian dari PAdES-LT support
- **Detached PKCS7 verify limitation** — `openssl_pkcs7_verify()` 6th `$content` parameter is OUTPUT (extracted content), not input. Untuk bare DER-detached-only envelopes, PHP verify tidak bisa cross-check content tanpa re-attach atau CLI shell-out. Documented inline di PkcsSevenEnvelope.

### Multi-agent workflow
6 agents parallel (2 research + 3 write + 1 verify). **~299K tokens, ~20 minutes wall-clock**. All 13 PHP files pass syntax + zero PHP 8+ leaks. Verify agent confirmed:
- Integration: PeruriProvider + PrivyProvider extend BaseRemoteProvider ✓
- PkcsSevenEnvelope + RawEnvelope + HmacEnvelope implement Envelope ✓
- CurlHttpClient implements HttpClient ✓
- 34 TODO markers detected between stubs (as expected)

## [0.6.6] - 2026-07-10 — "UI extension framework (ViewResolver + Config + Slot + Publish)"

### Added — UI framework core (6 files, ~26 KB)
- `src/UI/ViewResolver.php` — chain-of-responsibility view lookup dengan `addPath()` (prepend by default + de-dup on re-add so re-adding just reorders), probes `.blade.php` then `.php`, `render()` uses `EXTR_SKIP` + `ob_start()` try/catch untuk fatal-safe output buffering. Sanitizer rejects empty / non-`[a-zA-Z0-9_/-]` / `..` segments. Throws `NotFoundException::forResource('view', $name)` untuk missing view.
- `src/UI/Config.php` — nested array store dengan dot-notation `get()`/`set()`/`has()`. `set()` builds intermediate arrays. `merge()` deep-merges assoc + replaces numeric arrays. `fromArray()` + `fromFile()` factories (throws `NotFoundException`/`ValidationException`).
- `src/UI/SlotRegistry.php` — named slots dengan `register(name, callable|string, priority=10)`. Priority ASC sort dengan **monotonic sequence tiebreaker** karena `usort()` tidak stable pre-PHP 8.0. Callable results coerced to string (scalar/`__toString` check).
- `src/UI/Slot.php` — pure static facade dengan private constructor. Lazy-inits empty SlotRegistry. `setRegistry()` untuk DI override. `reset()` untuk tests + long-running worker cleanup (Swoole/RoadRunner).
- `src/UI/Theme.php` — thin wrapper on Config reading `brand.primary_color`, `brand.secondary_color`, `brand.logo_url`, `brand.favicon_url`, `brand.app_name`, `assets.custom_css`, `assets.custom_js`. Also exposes `config()` accessor for non-surfaced keys.
- `src/UI/PublishCommand.php` — programmatic publish API. `RecursiveIteratorIterator` + `FilesystemIterator::SKIP_DOTS` recursive scan. Path sanitization rejects relative + `..` traversal, accepts Unix `/…` + Windows `C:/…`. Auto-creates target dirs, verifies writability. Ignores `.DS_Store`, `Thumbs.db`, `.git*`, `*.tmp`, `*.swp`, `*.bak`, `*~` (case-insensitive fnmatch).

### Added — CLI + docs (2 files, ~12 KB)
- `cli/publish.php` — CLI entry point dengan CLI-only guard (`php_sapi_name() !== 'cli'` → exit 1). Loads autoload only (skips DB-dependent bootstrap). Commands: `views`, `assets`, `config`, `all`, `list`, `help`. Reports `[COPY]`/`[SKIP]`/`[FAIL] — reason` per file. Exit codes: 0 success, 1 any failed, 2 usage error.
- `cli/README.md` — dokumentasi CLI (migrate + publish), syntax table, flags, exit codes, sample outputs, composer integration, programmatic API example.

### Added — Starter templates (7 files, ~29 KB)
Consumer publish + edit — atau bangun sendiri di atas action endpoints:
- `views/layout.php` (68 lines) — HTML5 shell, `<style>` block injects `--ezdoc-primary/--secondary` dari Theme (Level-1 → Level-2 bridge, no build step), custom CSS/JS loops, 3 slots: `layout:head-extra`, `layout:header-extra`, `layout:footer-extra`
- `views/document/list.php` (106 lines) — header + "Buat Dokumen" CTA, search + status filter form, empty state, `.ezdoc-table` (Title/Subject/Status/Created At/Actions). Slots: `document-list:filters-extra`, per-row `document-list:actions-extra`. Uses Document VO getters — **NO** legacy `norm`/`nopen` di top level (domain-agnostic).
- `views/document/form.php` (113 lines) — template selector, title, `subject_type` + `subject_id` (generic — no hospital coupling), dynamic `field_values[]` area, signature placeholder, CSRF hidden input. Slots: `document-form:before-fields`, `document-form:after-fields`.
- `assets/css/ezdoc.css` (173 lines) — `:root` CSS-variable palette. Components: `.ezdoc-body/header/footer/logo/card/btn/table/badge` (+ draft/issued/signed/void variants), `.ezdoc-empty-state/filters/field-values`.
- `assets/js/ezdoc.js` (106 lines) — `window.Ezdoc = { version, config, slots, escapeHtml, formatDate, postJson }`. Idempotent init guard. Slot registry `register(name, cb, priority)`/`render(name, target, context)`/`list()`. Ascending priority, throw isolation per callback.
- `config/ezdoc.example.php` (52 lines) — sample consumer config dengan `brand.*`, `pages.list.*`, `pages.form.*`, `custom_css`, `custom_js`, `urls.list`. Header shows copy + `Config::fromFile()` bootstrap.
- `docs/UI-CUSTOMIZATION.md` (341 lines) — comprehensive customization guide:
  - Table of Contents + 4-level effort table
  - Level 1: Config only (5 min) — walkthrough + config-key reference table
  - Level 2: CSS override (30 min) — CSS-variable reference table
  - Level 3: View publish (1-2 jam) — `php cli/publish.php` example
  - Level 4: Full UI replacement — 4-layer architecture diagram + `Ezdoc.postJson()` sample
  - Slot registry (8 named slots documented) — PHP + JS registration + priority notes
  - Framework adapters: Laravel (pointer v0.5), Plain PHP monolith (koneksi.php bootstrap example), WordPress plugin (shortcode + WpRoleProvider)

### Design highlights
- **4-tier customization pattern** (industry-standard, mirror Laravel Filament / shadcn):
  - **Tier 1** — Config only (5 min): `Config::fromFile('/app/config/ezdoc.php')`
  - **Tier 2** — CSS override (30 min): custom CSS setelah `ezdoc.css` load
  - **Tier 3** — View publish (1-2 jam): `php cli/publish.php views ./resources/views/vendor/ezdoc` → edit copied files
  - **Tier 4** — Full replacement (days): consumer build own UI, consume `actions/*.php` endpoints
- **Slot system stable ordering**: SlotRegistry pakai monotonic sequence tiebreaker karena PHP < 8.0 `usort()` tidak stable. Priority ties preserve registration order.
- **CSS variable bridge**: layout `<head>` inline `<style>` inject `--ezdoc-primary` dari Config → bridge Level-1 config to Level-2 CSS override tanpa build step
- **Blade guard**: `ViewResolver::render()` explicitly refuses `.blade.php` (throws ValidationException) karena plain include tidak bisa execute Blade — file di-resolve tapi execution blocked. Consumer yang mau Blade harus wire Laravel adapter (v0.7+).
- **Long-running worker safe**: `Slot::reset()` explicit method untuk clear registry between requests di Swoole/RoadRunner (avoid cross-request state bleed)
- **Zero consumer coupling**: semua file baru, `page/form_pembuat_surat_*_v3.php` **tidak berubah** — additive milestone
- **CLI safety**: `publish.php` CLI-only guard, path traversal rejection, auto-mkdir dengan writability verify

### Verify agent report — PASS
- 11/11 PHP files syntax OK
- 0 PHP 8+ syntax leaks (readonly, promotion, union types, never, nullsafe, enum, trailing comma)
- 15/15 files exist
- Integration references resolve correctly (Slot::render calls, PublishCommand instantiation)

### Multi-agent workflow
4 agents parallel — 3 writer + 1 verify. **~178K tokens, ~16 minutes wall-clock** — jauh lebih cepat dari milestone sebelumnya karena:
- Semua code path INDEPENDENT (view resolver ≠ CLI ≠ views) — zero cross-agent coupling
- No complex refactor of existing code
- No crypto complexity

### Known limitations
- **PHP not on PATH** di agent shells → syntax not machine-verified during write phase (verify agent later confirmed via PowerShell path). Recommend `php -l` di target server sebelum ship
- **ViewResolver::is_readable()**: only checks `is_file()`, tidak permission — permission errors surface as include warnings, bukan NotFoundException
- **Config::fromFile()** pakai `require` (not `include`): fatal parse error di config file halts PHP. Documented di consumer setup guide
- **Slot global state**: process-wide, long-running workers wajib call `Slot::reset()` between requests

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
