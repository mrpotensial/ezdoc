# Signature system (v0.6+)

Ezdoc separates *what* you sign (the content bytes) from *how* you sign it
(the provider). All providers implement `Ezdoc\Signature\SignatureProvider`,
so you can swap L1 → L2 → L3 without touching call sites.

## Levels overview

| Level | Provider class                                    | Envelope format(s) | Cert? | Non-repudiation | Typical use                                          |
| ----- | ------------------------------------------------- | ------------------ | ----- | --------------- | ---------------------------------------------------- |
| L1    | `Ezdoc\Signature\Providers\HmacProvider`          | `hmac` (hex)       | no    | no              | QR verify slug, integrity URL, internal tamper check |
| L2    | `Ezdoc\Signature\Providers\LocalPkiProvider`      | `raw`, `pkcs7`     | X.509 | yes (in-org)    | Internal signed PDF/JSON, self-issued CA             |
| L3    | *(future)* `Ezdoc\Signature\Providers\PsreProvider` | `pades`          | X.509 (PSrE) + TSA | yes (legal, ID) | e-Materai, PSrE-issued cert, RFC 3161 timestamp     |

Level maps 1:1 to the `level` column in `ezdoc_signatures`.

## Choosing a provider

- **L1 HMAC** — need a short, deterministic integrity token you can embed in
  a URL or QR. Fast, no key management beyond a shared secret. Does *not*
  prove identity — anyone with the secret can forge.
- **L2 LocalPKI** — you control the CA / self-signed cert and just need
  non-repudiation inside your organization. Signature is cryptographically
  bound to a private key that only the signer holds. Suitable for internal
  memos, dispensing letters, HR docs.
- **L3 PSrE** — the document must be legally recognized under UU ITE / PP 71,
  or you need RFC 3161 timestamping. Requires certificate issued by a
  registered PSrE (Peruri, Privy, VIDA, dsb). Same `SignatureProvider`
  interface — code stays the same, only the adapter changes.

Rule of thumb: **L1 for links, L2 for internal documents, L3 for external
legal artifacts.**

## Quick start L1 (HMAC)

Set the shared secret via env var (min. 32 bytes):

```bash
export EZDOC_HMAC_SECRET="$(openssl rand -hex 32)"
```

```php
use Ezdoc\Signature\Providers\HmacProvider;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\VerifyContext;

// Instantiate from env (or `new HmacProvider($secret, 'sha256')` directly).
$provider = HmacProvider::fromEnv('sha256');

// Sign.
$req = new SignRequest([
    'contentBytes'   => $canonicalJson,     // raw bytes you want to bind
    'signerId'       => 'user:42',
    'envelopeFormat' => 'hmac',
]);
$result = $provider->sign($req);

// $result->getEnvelope()       → hex digest string
// $result->getEnvelopeFormat() → 'hmac'
// $result->getLevel()          → 1

// Verify.
$ctx = new VerifyContext([
    'contentBytes'     => $canonicalJson,
    'expectedSignerId' => 'user:42',
]);
$verdict = $provider->verify($result->getEnvelope(), $ctx);

if ($verdict->isValid()) {
    // OK
} else {
    // $verdict->getStatus() = 'tampered' | 'error' | ...
    // $verdict->getReason() = human-readable message
}
```

## Quick start L2 (LocalPKI self-signed)

### Step 1 — Generate a self-signed cert

```bash
# Private key (RSA 2048; use 3072/4096 for stronger)
openssl genpkey -algorithm RSA -out private.key -pkeyopt rsa_keygen_bits:2048

# Self-signed X.509 cert, 1 year validity
openssl req -new -x509 -key private.key -out certificate.crt -days 365 \
    -subj "/CN=Signer Name/O=OrgName"

# Lock down the private key
chmod 600 private.key
```

### Step 2 — Lay out the FileKeyStore folder

`FileKeyStore` reads `{alias}.key`, `{alias}.crt`, and optionally
`{alias}.chain` from a single root directory. Alias is regex-restricted to
`[A-Za-z0-9_-]+` (path-traversal safe).

```
/etc/ezdoc/keys/            ← rootDir
├── default.key             ← PEM private key   (chmod 600)
├── default.crt             ← PEM leaf cert
└── default.chain           ← concatenated PEM chain (optional)
```

Move the generated files into place:

```bash
sudo mkdir -p /etc/ezdoc/keys
sudo mv private.key   /etc/ezdoc/keys/default.key
sudo mv certificate.crt /etc/ezdoc/keys/default.crt
sudo chmod 700 /etc/ezdoc/keys
sudo chmod 600 /etc/ezdoc/keys/default.key
sudo chown -R www-data:www-data /etc/ezdoc/keys
```

### Step 3 — Instantiate the provider

```php
use Ezdoc\Signature\KeyStore\FileKeyStore;
use Ezdoc\Signature\Providers\LocalPkiProvider;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\VerifyContext;

$keyStore = new FileKeyStore('/etc/ezdoc/keys', /* passphrase */ '');
$provider = new LocalPkiProvider($keyStore, 'default', 'sha256');

$result = $provider->sign(new SignRequest([
    'contentBytes'   => $pdfBytes,
    'signerId'       => 'pegawai:1234',
    'envelopeFormat' => 'raw',
]));

// $result->getEnvelope()       → raw binary RSA signature
// $result->getCertificatePem() → PEM string (persist this alongside envelope)
// $result->getLevel()          → 2

// Verify — pass the cert PEM back via metadata so cert rotation on the
// keystore doesn't invalidate historical signatures.
$verdict = $provider->verify($result->getEnvelope(), new VerifyContext([
    'contentBytes' => $pdfBytes,
    'metadata'     => ['certificate_pem' => $result->getCertificatePem()],
]));
```

## Storage

Envelopes persist in `ezdoc_signatures` (see
`migrations/2026_01_01_000005_create_ezdoc_signatures.php`). Multi-signer
support via `signature_id_within_doc` (0..N per `document_id`). Key columns:

| Column               | Notes                                              |
| -------------------- | -------------------------------------------------- |
| `document_id`        | FK → `ezdoc_documents.id`, `ON DELETE RESTRICT`    |
| `provider`           | `hmac` \| `local_pki` \| `peruri`                  |
| `level`              | 1 / 2 / 3                                          |
| `envelope_format`    | `hmac` \| `raw` \| `pkcs7` \| `pades`              |
| `envelope`           | `MEDIUMBLOB` — raw bytes (base64 at transport)     |
| `content_hash`       | SHA-256 hex of the signed bytes                    |
| `certificate_pem`    | Full leaf cert (L2/L3) — snapshotted at sign time  |
| `certificate_serial` | Indexed for revocation lookup                      |
| `signed_at`          | `DATETIME(3)` — millisecond precision              |
| `verify_status`      | `valid` \| `tampered` \| `expired` \| `revoked` \| `untrusted` \| `error` \| `pending` |

List documents with at least one currently-valid signature:

```sql
SELECT d.id, d.uuid, d.judul,
       COUNT(s.id) AS valid_sigs,
       MAX(s.signed_at) AS last_signed_at
FROM   ezdoc_documents d
JOIN   ezdoc_signatures s ON s.document_id = d.id
WHERE  s.verify_status = 'valid'
  AND  s.deleted_at IS NULL
  AND  d.deleted_at IS NULL
GROUP  BY d.id, d.uuid, d.judul
ORDER  BY last_signed_at DESC;
```

## Verification chain

Whatever the provider, `verify()` walks the same six checks:

1. **Envelope shape** — non-empty; for `hmac` the string must match
   `^[0-9a-f]+$` (case-insensitive).
2. **Length parity** — recomputed HMAC / expected signature length matches
   the envelope's length; mismatch → `tampered`.
3. **Cryptographic compare** — `hash_equals` for HMAC, `openssl_verify` for
   PKI. Result 0 → `tampered`, −1 → `error`.
4. **Content hash** — re-hash `contentBytes` and compare to `content_hash`
   stored in metadata; catches truncation / re-encoding.
5. **Signer match** — if `expectedSignerId` was passed, verify it matches
   the envelope's signer.
6. **Validity window (L2/L3)** — `X509Certificate::isValidAt(now)`; expired
   or not-yet-valid → `Verdict::STATUS_EXPIRED` with `not_before` /
   `not_after` in the details.

Providers return a `Verdict` — they never throw for business-level failures
(tampered, expired, untrusted). Only technical failures (missing secret,
corrupt key file) raise `EzdocException`.

## When you upgrade to L3 (PSrE)

The `SignatureProvider` contract is stable. Migration is a two-line change:

```php
// Before
$provider = new LocalPkiProvider($keyStore, 'default');

// After — assumes future PsreProvider ships in the same namespace
$provider = new PsreProvider($psreClient, 'certificate-serial-xxx');
```

Everything downstream — the `sign()` call site, envelope storage, and the
`verify()` code path — stays the same. What changes:

- Envelope format shifts from `raw` to `pades` (PDF signature dictionary)
  or `pkcs7`.
- `certificate_pem` now contains a PSrE-issued cert; `certificate_issuer`
  points to a registered CA.
- A `tsa_response` (RFC 3161 timestamp token) gets stored in the reserved
  `MEDIUMBLOB` column.
- `verify_status` may transition to `revoked` if OCSP/CRL check fails.

## Security considerations

- **Private key permissions** — `chmod 600` (owner-only) and
  `chmod 700` the containing dir. Owner should be the PHP-FPM user (e.g.
  `www-data`), not `root`. `FileKeyStore` opens the key with the process
  UID; anything readable by other users is a leak.
- **Env var vs file store** — L1 secret in `EZDOC_HMAC_SECRET` is fine
  because it's symmetric and used for integrity only. L2/L3 private keys
  MUST live on disk with strict perms (or an HSM) — never in env or DB. Do
  not commit any key material to git; add `*.key` to `.gitignore`.
- **Cert rotation impact** — L2 verify falls back to the current alias cert
  if `metadata['certificate_pem']` isn't provided. If you rotate a cert
  without persisting the old PEM into each signature row, all historical
  signatures under that alias will fail verify. Always store
  `certificate_pem` on sign; always pass it back on verify.
- **Signature immutability** — once written, envelope rows must never be
  UPDATEd. `ezdoc_signatures` uses `ON DELETE RESTRICT` and a `deleted_at`
  soft-delete column for exactly this reason. Corrections happen by
  appending a new row with an incremented `signature_id_within_doc`, not by
  mutating the old one.
- **Secret / key length** — HMAC secret enforced ≥ 32 bytes at
  construction. RSA keys should be ≥ 2048 bits; prefer 3072/4096 for
  long-lived certs.
- **Constant-time compare** — L1 uses `hash_equals`; L2 relies on
  `openssl_verify` internals. Do *not* short-circuit with `===` in
  application-level checks around envelopes.
