# Cross-Language Ecosystem Strategy

Ezdoc dirancang sebagai **spec-first ecosystem** — satu kontrak (`ezdoc-spec/`)
di-honor oleh **multiple native packages** per bahasa. Consumer aplikasi pakai
package language-native, bukan generate struct sendiri dari YAML.

**Status saat ini**: hanya PHP port yang tersedia. Native ports lain masih di
roadmap ([PRD.md](PRD.md) section 6.16-6.18).

---

## Table of Contents

- [Ecosystem architecture](#ecosystem-architecture)
- [Why spec-first](#why-spec-first)
- [For consumer applications](#for-consumer-applications)
- [For port implementers](#for-port-implementers)
- [`ezdoc-spec/` structure](#ezdoc-spec-structure)
- [Regeneration workflow](#regeneration-workflow)
- [Conformance testing](#conformance-testing)
- [Roadmap](#roadmap)

---

## Ecosystem architecture

```
                        ezdoc-spec/                 ◄─── contract (single source of truth)
                     schema + protocol + ddl
                              │
             ┌────────────────┼────────────────┬────────────────┐
             ▼                ▼                ▼                ▼
         ezdoc (PHP)      ezdoc-go         ezdoc-ts         ezdoc-rs
       ─ Packagist       ─ Go modules       ─ npm            ─ crates.io
       ─ v1.0 target     ─ v1.5 planned    ─ v2.0 planned   ─ stretch
       (current)         (roadmap)         (roadmap)        (roadmap)

       Semua honor contract yang sama:
       - Same DB schema (ezdoc_documents, ezdoc_templates, ...)
       - Same signature envelope format (L1 HMAC, L2 PKCS7, L3 PAdES)
       - Same content-hash algo (canonical JSON → SHA-256)
       - Same audit event schema
       - Same QR verify payload format
```

**Bit-exact identical output** antar port adalah goal — dokumen yang di-sign
PHP client harus bisa di-verify Go client end-to-end.

---

## Why spec-first

Alternative approach yang **kita hindari**:
- **Reference PHP impl** → port lain reverse-engineer PHP source. Fragile — PHP
  refactor break Go/TS ports.
- **API-only contract (REST OpenAPI)** → cocok untuk service-oriented, tapi
  ezdoc adalah embeddable library, bukan pure service.
- **Single-language monolith + gRPC bridge** → deployment complexity, no
  offline/edge support.

**Spec-first** benefit:
- Port implementer punya definitive contract, bukan best-effort reverse-eng
- PHP impl adalah first reference implementation — semua port lain wajib match
  conformance test (bit-exact identical output)
- Cross-lang compat guaranteed via CI
- Consumer aplikasi tinggal `composer require` / `go get` / `npm i` — spec
  invisible ke mereka

Industri precedent: **Prisma** (schema.prisma → codegen per lang), **Atlas**
(HCL schema → DDL per DB), **Ent** (Go schema-as-code + codegen).

---

## For consumer applications

Kalau kamu building app yang butuh document generation + signing:

### Pilih package sesuai bahasa

| Bahasa | Package | Cara install | Status |
|---|---|---|---|
| **PHP** | `mrpotensial/ezdoc` | `composer require mrpotensial/ezdoc` | ✅ v1.0 target |
| **Go** | `github.com/mrpotensial/ezdoc-go` | `go get github.com/mrpotensial/ezdoc-go` | ⏳ v1.5 planned |
| **TypeScript** | `@mrpotensial/ezdoc` | `npm i @mrpotensial/ezdoc` | ⏳ v2.0 planned |
| **Rust** | `mrpotensial-ezdoc` | `cargo add mrpotensial-ezdoc` | ⏳ stretch |

### Jangan langsung parse spec files

`ezdoc-spec/schema/tables.yaml` **bukan** dimaksudkan untuk consumer akhir
generate struct sendiri. Itu untuk port implementer. Consumer pakai package
language-native yang sudah honor spec.

### Kalau butuh Go/TS/Rust sekarang

Sementara native port belum tersedia:

1. **Run PHP ezdoc sebagai HTTP microservice** — expose actions/ endpoints,
   call dari Go/TS/Rust via HTTP client. Verify UI + document generation
   works, tapi bandingkan latency (~10-50ms overhead per call).

2. **Sponsor native port** — kontak maintainer, bantu conformance testing.

3. **Build custom port** yang honor `ezdoc-spec/` — lihat [For port implementers](#for-port-implementers)
   section. Ideally coordinated dgn upstream biar tidak fragmented.

---

## For port implementers

Kalau kamu mau bantu bikin `ezdoc-go` / `ezdoc-rs` / `ezdoc-ts`, `ezdoc-spec/`
adalah **kontrak wajib**.

### Contract elements

| Element | File | Purpose |
|---|---|---|
| DB schema descriptor | `schema/tables.{json,yaml}` | Column names, types, indexes, FKs — cross-lang readable |
| Enum values | `schema/enums.yaml` (v1.1) | Status enums, signature levels, etc |
| Generated DDL | `ddl/*.sql` | Reference DDL per platform (mysql/mariadb/sqlite/postgres/sqlserver) |
| Protocol constants | `protocol/*.json` | Hash algos, signature levels, envelope types |
| Signature format | `protocol/signature-envelope.md` (v1.1) | Exact byte layout PKCS7/PAdES |
| Verify protocol | `protocol/verify-protocol.md` (v1.1) | Step-by-step verify chain state machine |
| Content hash algo | `protocol/content-hash.md` (v1.1) | Canonical JSON → SHA-256 formula |
| QR payload format | `protocol/qr-payload.md` (v1.1) | QR content structure + versioning |
| Conformance tests | `conformance/test-vectors.json` (v1.1) | Input → expected output pairs |
| Certificate samples | `conformance/signatures/*.pem` (v1.1) | Reference cert + signature bytes |

**v0.9.9 shipping**: schema/ + ddl/ + protocol/*.json (constants) + meta/.
**v1.1 shipping**: full protocol/*.md + conformance/ (repo split ke standalone `ezdoc-spec`).

### Steps to write a port

1. **Clone `ezdoc-spec`** (subfolder di ezdoc repo sekarang, standalone di v1.1+)

2. **Setup native project** (Go modules / Cargo / npm)

3. **Codegen DB layer**:
   - Parse `schema/tables.yaml`
   - Emit language-native struct + query interface (e.g. Go structs dgn `sqlx` tags)
   - Reference `ddl/{your-target-db}.sql` untuk expected schema

4. **Implement domain layer**:
   - Document, Template, Signature, AuditLog value objects
   - Repository interfaces
   - Service layer (document lifecycle, template versioning)

5. **Implement signature layer**:
   - Content hash algo (canonical JSON → SHA-256 per `protocol/content-hash.md`)
   - Envelope formats: L1 HMAC, L2 PKCS7, L3 PAdES
   - Per level, honor `protocol/signature-levels.json` constants

6. **Implement verify layer**:
   - Verify chain state machine per `protocol/verify-protocol.md`
   - QR payload parse per `protocol/qr-payload.md`

7. **Run conformance suite**:
   - Import `conformance/test-vectors.json`
   - Assert: given input X, produce output Y bit-exact identical
   - CI job: fail if not all vectors pass

8. **Publish** ke language ecosystem (crates.io / npm / pkg.go.dev)

### Conformance guarantee

Port dianggap "ready" kalau:
- ✅ Lulus **semua** conformance test vectors
- ✅ Signature output bit-exact identical dgn PHP reference
- ✅ Content hash formula sama byte-for-byte
- ✅ QR payload interoperable (PHP scan → Go verify → sama result)

**Non-conforming ports tidak boleh pakai nama `ezdoc-*` official** — bisa
fork dgn nama lain kalau intentional divergence.

---

## `ezdoc-spec/` structure

```
ezdoc-spec/
├── README.md                     "How to consume"
├── schema/
│   ├── tables.json               DB schema descriptor (canonical)
│   ├── tables.yaml               (mirror, human-readable)
│   └── enums.yaml                (v1.1) enum values yg stable
├── ddl/
│   ├── mysql.sql                 Generated CREATE TABLE per platform
│   ├── mariadb.sql
│   ├── sqlite.sql
│   ├── postgres.sql
│   └── sqlserver.sql
├── protocol/
│   ├── hash-algos.json           SHA-256/SHA-512 identifiers
│   ├── signature-levels.json     L1/L2/L3/A1 metadata
│   ├── envelope-types.json       hmac/pkcs7/pades/cades/xades constants
│   ├── content-hash.md           (v1.1) canonical JSON algo detail
│   ├── signature-envelope.md     (v1.1) PKCS7/PAdES byte layout
│   ├── verify-protocol.md        (v1.1) verify chain state machine
│   └── qr-payload.md             (v1.1) QR payload structure
├── meta/
│   ├── version.json              spec version + checksum
│   └── checksum.txt              SHA-256 of all artifacts (CI gate)
└── conformance/                  (v1.1)
    ├── test-vectors.json         input → expected output pairs
    ├── signatures/*.pem          reference cert + signed bytes
    └── qr-payloads/*.txt         reference QR payloads
```

---

## Regeneration workflow

`ezdoc-spec/` adalah **generated artifacts** — jangan edit manual. Source of
truth: `migrations/blueprints/*.php` (PHP Blueprint DSL) + hardcoded protocol
constants di generator.

### Regenerate

```bash
php cli/spec-dump.php
```

Output: overwrite `ezdoc-spec/schema/*`, `ddl/*`, `meta/*`.

### CI gate

Setiap PR wajib run:

```bash
php cli/spec-dump.php --check
```

Exit code 1 kalau spec out-of-date dari source. Ini enforce: kalau kontributor
edit Blueprint tapi lupa regen spec, CI reject.

### Workflow saat schema change

1. Edit `migrations/blueprints/*.php` (add column, change index, etc)
2. Run `php cli/spec-dump.php` (regen spec)
3. Commit BOTH source + generated together
4. CI verify checksum match

---

## Conformance testing

**Status**: infra planned v1.1 — untuk sekarang, PHP reference impl is the
de-facto standard.

### Test vector format (v1.1 draft)

```json
{
  "version": "1.0",
  "vectors": [
    {
      "id": "content-hash-basic",
      "description": "SHA-256 of canonical JSON, single field",
      "input": { "field_values": { "name": "Alice", "age": 30 } },
      "expected": {
        "canonical_json": "{\"age\":30,\"name\":\"Alice\"}",
        "sha256_hex": "..."
      }
    },
    {
      "id": "signature-l1-hmac",
      "description": "L1 HMAC envelope with test secret",
      "input": {
        "content_hash": "...",
        "secret_hex": "..."
      },
      "expected": {
        "envelope_bytes_hex": "...",
        "envelope_format": "hmac"
      }
    },
    // ... more vectors
  ]
}
```

Port harus assert bit-exact match untuk semua expected fields.

### CI integration

Each port repo (`ezdoc-go`, `ezdoc-ts`, ...) punya CI job:

```yaml
- run: git submodule update --init ezdoc-spec  # atau download via URL
- run: go test ./conformance/...               # import test-vectors.json
```

Fail → PR blocked. Non-negotiable.

---

## Roadmap

Cross-lang milestones dari [PRD.md](PRD.md):

- [x] **v0.9.9** — spec bootstrap: `ezdoc-spec/` sebagai subfolder, dgn schema
      + DDL + protocol constants generated dari Blueprint. CI gate via `--check`.
- [ ] **v1.0** — PHP release via Packagist
- [ ] **v1.1** — Spec extraction:
      - Split `ezdoc-spec/` ke standalone repo publik (`mrpotensial/ezdoc-spec`)
      - Add conformance test vectors
      - Enrich protocol/*.md
      - PHP jadi first reference implementation yang lulus conformance
- [ ] **v1.5** — Go port (`mrpotensial/ezdoc-go`)
      - Native Go implementation
      - Import `ezdoc-spec/conformance/` di CI
      - CLI tool `ezdoc verify --file doc.pdf`
      - Docker image untuk verify microservice
- [ ] **v2.0** — TypeScript port + full ecosystem
      - `@mrpotensial/ezdoc` (Node/Bun/Deno)
      - Next.js sample app
      - Browser verify UI (WebCrypto)
- [ ] **v2.5+** — Rust port (stretch)
      - Cloudflare Workers deployment
      - Extreme perf verify service

Total timeline (single dev): ~50-51 weeks dari v0.9.9 ke v2.0 (~12 bulan).

---

## Contributing

Kalau kau mau bantu native port:

1. Open issue di ezdoc PHP repo dgn label `[cross-lang]` — coordinate scope
2. Fork `ezdoc-spec/` (atau subscribe untuk update)
3. Implement per contract
4. Submit conformance suite results (bit-exact match)
5. Publish di language ecosystem

Semua contribution welcome. Rewrite PHP impl kalau conformance test reveal
ambiguity di spec — spec adalah source of truth, PHP impl bisa salah (rare).

---

## See also

- [DB-ABSTRACTION.md](DB-ABSTRACTION.md) — PHP-side DB layer detail
- [PRD.md](PRD.md) — full roadmap + design rationale (section 5.7, 6.16-6.18)
- [SIGNATURE.md](SIGNATURE.md) — L1/L2/L3 signature adapter detail
- [PADES-TSA.md](PADES-TSA.md) — PAdES + RFC 3161 TSA detail
- [../ezdoc-spec/](../ezdoc-spec/) — the actual spec artifacts
