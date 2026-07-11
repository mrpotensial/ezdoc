# PAdES + Timestamp Integration Guide (v0.8+)

This guide covers ezdoc's PAdES (PDF Advanced Electronic Signature) envelope
implementation and the RFC 3161 Time-Stamp Authority (TSA) integration
introduced in v0.8. Read this alongside `SIGNATURE.md` (envelope model) and
`PSRE-INTEGRATION.md` (provider migration path).

---

## Overview

**PAdES** (ETSI EN 319 142) is the European standard for embedding PKCS#7
(CMS) signatures inside a PDF's byte-range slot so the signed document remains
a valid, viewable PDF while carrying cryptographic evidence of its author and
integrity. It is the profile Adobe Reader, Foxit, and most government e-sign
portals validate against.

PAdES defines four **conformance profiles**, each building on the previous:

| Profile     | What it adds                                                | Use case                                    |
|-------------|-------------------------------------------------------------|---------------------------------------------|
| **B-B**     | Basic PKCS#7 signature over the PDF byte range              | Short-lived docs, internal sign-off         |
| **B-T**     | + RFC 3161 timestamp token over the signature bytes         | Proof the signature existed at time `T`     |
| **B-LT**    | + Long-Term Validation data (CRL/OCSP + cert chain embedded)| Legal archives, invoices, contracts         |
| **B-LTA**   | + Archive timestamp, refreshed periodically                 | Multi-decade retention, notarial documents  |

### Legal validity in Indonesia

Under **UU ITE No. 11 Tahun 2008** (as amended by UU 19/2016) and
**PP 71/2019 Pasal 60**, an electronic signature is legally equivalent to a
wet signature when it is (a) uniquely linked to the signer, (b) under the
signer's sole control, and (c) can detect subsequent changes to the document.
For long-term evidentiary weight — particularly for records that must survive
the signing certificate's expiry — **PAdES-B-LT is the recommended profile**.
The embedded CRL/OCSP responses and cert chain let a validator prove the
certificate was valid at signing time even after it expires or is revoked.

### The role of an RFC 3161 timestamp

Without a trusted timestamp, a signature only proves *who* signed, not *when*.
An RFC 3161 TSA hashes the signature bytes, signs that hash with a
TSA-controlled certificate, and returns a timestamp token (TST). Embedding the
TST inside the signature's unsigned attributes upgrades a B-B signature to
B-T. This is critical for:

- Post-dating protection (proof the signature is not backdated).
- Long-term validation when the signing cert later expires.
- Regulatory compliance for financial and healthcare records.

---

## Architecture

ezdoc separates three concerns to keep providers pluggable:

- **`SignatureProvider`** (e.g. `LocalPkiProvider`, future `PSrEProvider`) —
  produces a detached PKCS#7 envelope over an arbitrary byte string.
- **`PdfSigner`** — knows how to reserve a `/ByteRange` slot in a PDF and
  splice a PKCS#7 blob into it. Implementations wrap external tools.
- **`PadesEnvelope`** — orchestrator. Takes a raw envelope from a provider,
  hands it and the source PDF to a `PdfSigner`, and returns a signed PDF.
- **`TimestampClient`** — talks to an RFC 3161 TSA. Called after signing to
  obtain a TST that is then embedded as an unsigned attribute.

```
                  +-------------------+
   unsigned.pdf ->|  PadesEnvelope    |-> signed.pdf
                  |    ::pack()       |
                  +---------+---------+
                            |
              +-------------+-------------+
              |                           |
              v                           v
    +-----------------+         +-------------------+
    | SignatureProvider|        |    PdfSigner      |
    | (LocalPki/PSrE)  |        | (JSignPdf/Setasign)|
    +--------+--------+         +---------+---------+
             |                            |
             v                            v
      PKCS#7 envelope             /ByteRange placeholder
             |                            |
             +--------+-------------------+
                      v
              +---------------+     +---------------+
              | TimestampClient|--->|   RFC 3161    |
              | (optional)     |    |     TSA       |
              +---------------+     +---------------+
                      |
                      v
              TST embedded in
              unsigned attrs
                      |
                      v
              signed PDF written
```

Flow in one line: **PDF → byte-range hash → provider signs hash → optional
TSA countersigns signature → PdfSigner embeds PKCS#7 (+TST) → signed PDF.**

---

## Choosing a PdfSigner implementation

| Signer              | PAdES profile   | Cost       | Dependencies             | Notes                                   |
|---------------------|-----------------|------------|--------------------------|-----------------------------------------|
| `SetasignPdfSigner` | B-LT+           | Commercial | setasign/fpdi + plugin   | Best quality, PHP-native, no JVM        |
| `JSignPdfSigner`    | B-LT            | Free       | Java 11+ + jsignpdf.jar  | Full-featured, cross-platform, CLI      |
| `OpensslPdfSigner`  | Verify-only     | Free       | openssl + poppler-utils  | Extraction/verify only, no signing yet  |
| `ExternalPdfSigner` | Any (delegated) | Depends    | Consumer callable        | Cloud signing (BSrE, DigiCert Trust)    |

**Recommended for production**: `JSignPdfSigner` (free, mature, B-LT capable)
or `SetasignPdfSigner` when a commercial license is acceptable and JVM cannot
be installed. Use `ExternalPdfSigner` when signing is delegated to a HSM-
backed cloud service such as BSrE's e-sign API.

---

## Choosing a TimestampClient implementation

| Client                     | Sign  | Verify | Dependencies       |
|----------------------------|-------|--------|--------------------|
| `OpensslTimestampClient`   | Yes   | Yes    | openssl CLI        |
| `HttpTimestampClient`      | Yes*  | No     | HTTP transport only|

`*` `HttpTimestampClient` requires the consumer to supply pre-built RFC 3161
ASN.1 request bytes (via a small helper such as `phpseclib\File\ASN1` or an
external process). It exists for environments where the `openssl ts` binary
is unavailable but outbound HTTPS is permitted.

---

## Common TSA endpoints

| Provider    | Endpoint                              | Notes                                    |
|-------------|---------------------------------------|------------------------------------------|
| **BSrE**    | `https://tsa.bssn.go.id`              | Indonesian gov TSA — requires registered API credential; production only |
| **FreeTSA** | `https://freetsa.org/tsr`             | Free, no auth, CA bundle at `/files/cacert.pem` — dev/test only |
| **DigiCert**| `http://timestamp.digicert.com`       | Commercial, RFC 3161, no auth on request |
| **Sectigo** | `http://timestamp.sectigo.com`        | Commercial, RFC 3161, no auth on request |

Config examples (`config/ezdoc.php`):

```php
// BSrE (production)
'tsa' => [
    'driver'   => 'openssl',
    'url'      => 'https://tsa.bssn.go.id',
    'ca_file'  => '/etc/ezdoc/tsa/bsre-ca.pem',
    'auth'     => ['type' => 'basic', 'user' => env('BSRE_USER'), 'pass' => env('BSRE_PASS')],
    'hash_alg' => 'sha256',
],

// FreeTSA (dev)
'tsa' => [
    'driver'   => 'openssl',
    'url'      => 'https://freetsa.org/tsr',
    'ca_file'  => '/etc/ezdoc/tsa/freetsa-cacert.pem',
    'hash_alg' => 'sha256',
],

// DigiCert (commercial, unauthenticated)
'tsa' => [
    'driver'   => 'openssl',
    'url'      => 'http://timestamp.digicert.com',
    'hash_alg' => 'sha256',
],
```

---

## Quick start: Sign PDF with PAdES-B-B (basic)

```php
use Ezdoc\Pki\FileKeyStore;
use Ezdoc\Signature\LocalPkiProvider;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Pdf\PdfBytesRange;
use Ezdoc\Pdf\PadesEnvelope;
use Ezdoc\Pdf\JSignPdfSigner;

// 1. Load PDF + key
$pdfBytes = file_get_contents('unsigned.pdf');
$keyStore = new FileKeyStore('/etc/ezdoc/keys');
$cert     = $keyStore->loadCertificate('my-alias');

// 2. Compute PDF byte-range hash (over everything except the /Contents slot)
$byteRange = PdfBytesRange::fromPdf($pdfBytes);
$hash      = PdfBytesRange::computeHash($pdfBytes, $byteRange, 'sha256');

// 3. Sign hash with LocalPKI provider
$signer      = new LocalPkiProvider($keyStore, 'my-alias');
$signRequest = new SignRequest([
    'contentBytes' => $hash,
    'signerId'     => 'user@example.com',
]);
$signResult  = $signer->sign($signRequest);

// 4. Embed PKCS#7 into PDF via PadesEnvelope + PdfSigner
$pdfSigner     = new JSignPdfSigner(['jsignpdf_path' => '/opt/jsignpdf/JSignPdf.jar']);
$padesEnvelope = new PadesEnvelope($pdfSigner);
$signedPdf     = $padesEnvelope->pack($signResult->getEnvelope(), $pdfBytes, $cert);

file_put_contents('signed.pdf', $signedPdf);
```

The resulting `signed.pdf` opens in Adobe Reader with a signature panel and,
if the signer's CA is trusted, a green checkmark.

---

## Quick start: PAdES-B-T (with Timestamp)

Extend the B-B flow by requesting a TST **after** the provider signs and
attaching it to the envelope:

```php
use Ezdoc\Timestamp\OpensslTimestampClient;

// ... steps 1-3 as above ...

// 4. Request timestamp over the raw signature value
$tsaClient = new OpensslTimestampClient([
    'url'      => 'https://freetsa.org/tsr',
    'ca_file'  => '/etc/ezdoc/tsa/freetsa-cacert.pem',
    'hash_alg' => 'sha256',
]);
$tstToken  = $tsaClient->timestamp($signResult->getSignatureValue());

// 5. Embed PKCS#7 with TST as an unsigned attribute
$pdfSigner     = new JSignPdfSigner(['jsignpdf_path' => '/opt/jsignpdf/JSignPdf.jar']);
$padesEnvelope = new PadesEnvelope($pdfSigner);
$signedPdf     = $padesEnvelope->pack(
    $signResult->getEnvelope(),
    $pdfBytes,
    $cert,
    ['timestamp_token' => $tstToken]     // <— upgrades B-B → B-T
);

file_put_contents('signed-bt.pdf', $signedPdf);
```

For **B-LT**, additionally pass `['ltv' => true]` — the `PdfSigner`
implementation will fetch CRL/OCSP for the signing cert and chain, and embed
them in the PDF's DSS dictionary.

---

## Quick start: Verify signed PDF

```php
use Ezdoc\Pdf\PadesEnvelope;
use Ezdoc\Pdf\OpensslPdfSigner;
use Ezdoc\Timestamp\OpensslTimestampClient;
use Ezdoc\Signature\Verdict;

$signedBytes = file_get_contents('signed-bt.pdf');

// 1. Extract PKCS#7 blob + signed byte range
$verifier = new OpensslPdfSigner(['openssl_bin' => '/usr/bin/openssl']);
$envelope = (new PadesEnvelope($verifier))->unpack($signedBytes);

// 2. Verify PKCS#7 signature (chain + integrity)
$sigVerdict = $verifier->verifySignature($envelope, $signedBytes);

// 3. If PAdES-B-T, verify the timestamp separately
$tsVerdict = Verdict::NOT_APPLICABLE;
if ($envelope->hasTimestamp()) {
    $tsaClient = new OpensslTimestampClient([
        'ca_file' => '/etc/ezdoc/tsa/freetsa-cacert.pem',
    ]);
    $tsVerdict = $tsaClient->verify(
        $envelope->getTimestampToken(),
        $envelope->getSignatureValue()
    );
}

// 4. Combine into a single Verdict
$final = Verdict::combine($sigVerdict, $tsVerdict);
echo $final->isValid() ? "OK: signed at " . $final->getSigningTime() : "INVALID: " . $final->getReason();
```

---

## Adobe Reader validation

For a **green checkmark** (Signature is VALID, signed by ...) Adobe requires:

1. The signing certificate's issuer chain terminates at a CA in Adobe's
   **Approved Trust List (AATL)** *or* the user's local Windows/macOS trust
   store *or* a manually imported Adobe trust anchor.
2. The signature covers the entire document (no unsigned incremental
   updates) — ezdoc's `PdfSigner` implementations enforce this.
3. If PAdES-B-T, the TSA cert must also chain to a trusted anchor.

**Adding your CA to Adobe's Trust List** (per-workstation):

1. Open Adobe Reader → *Preferences* → *Signatures* → *Identities & Trusted
   Certificates* → *More…*
2. *Trusted Certificates* → *Import* → select your CA `.cer` file.
3. Check **"Use this certificate as a trusted root"** and **"Certified
   documents"**.

**Troubleshooting the yellow warning** ("At least one signature has problems"
or "Signer's identity is unknown"):

| Symptom                            | Likely cause                              | Fix                                       |
|------------------------------------|-------------------------------------------|-------------------------------------------|
| Yellow triangle, "identity unknown"| Signer's CA not trusted                   | Import CA to Adobe Trusted Certificates   |
| Red X, "document has been altered" | Signature does not cover full byte range  | Re-sign with a `PdfSigner` that supports non-incremental sign |
| "Signing time is from clock"       | No timestamp attached (B-B only)          | Upgrade to B-T with a `TimestampClient`   |
| "Revocation info missing"          | B-LT expected but CRL/OCSP absent         | Enable `ltv => true` on `PdfSigner`       |

---

## Known limitations in v0.8

- `OpensslPdfSigner::embedSignature()` is **not implemented**. Splicing a
  PKCS#7 blob into a PDF's `/ByteRange` slot requires low-level PDF
  cross-reference rewriting that is out of scope for the openssl wrapper.
  Use `JSignPdfSigner` or `SetasignPdfSigner` for signing; `OpensslPdfSigner`
  remains available for **verify-only** flows.
- `SetasignPdfSigner` requires a **commercial license** for
  `setasign/fpdi-pdf-parser` when signing PDFs above 1.4. Consumers must
  purchase and place the plugin in their vendor tree.
- `JSignPdfSigner` requires a **Java 11+ runtime** on the host — this is our
  recommended production path when JVM is acceptable.
- **PAdES-B-LTA** (archive-timestamp with periodic refresh) is **not yet
  implemented** — planned for v0.8.1.
- `HttpTimestampClient::verify()` returns `NOT_APPLICABLE` — no verification
  path yet; use `OpensslTimestampClient` for verify flows.
- TODO: async batch signing API for high-volume invoicing pipelines.
- TODO: HSM support via PKCS#11 (currently file-based `FileKeyStore` only).

---

## Migration path from L2 (LocalPKI) → L3 (PSrE) with PAdES

The PAdES envelope layer is **provider-agnostic**. The only differences when
moving from a locally issued key (`LocalPkiProvider`) to a PSrE-issued key
(`PSrEProvider` — see `PSRE-INTEGRATION.md`) are:

1. Swap `LocalPkiProvider` for `PSrEProvider` (constructor takes PSrE API
   credentials instead of a `FileKeyStore`).
2. Optionally swap `JSignPdfSigner` for `ExternalPdfSigner` if the PSrE also
   offers a server-side "sign PDF" endpoint (BSrE does).
3. Everything else — `PadesEnvelope::pack()`, `TimestampClient` calls,
   verification flow — remains identical.

```php
// L2 (local key)
$provider = new LocalPkiProvider($keyStore, 'my-alias');

// L3 (PSrE, drop-in replacement)
$provider = new PSrEProvider([
    'endpoint' => 'https://esign.bsre.bssn.go.id/api',
    'nik'      => env('PSRE_NIK'),
    'passphrase' => env('PSRE_PASSPHRASE'),
]);

// PadesEnvelope call is byte-identical from here on.
```

---

## Testing without a live TSA

Two supported patterns for CI and offline development:

**1. Fixture-driven `HttpTimestampClient` mock.**  Capture a real TST response
once (`curl -s -H 'Content-Type: application/timestamp-query' --data-binary
@req.tsq https://freetsa.org/tsr > golden.tsr`), then in tests replace the
HTTP transport with a stub that returns the fixture bytes verbatim. The
signature verification path in `OpensslTimestampClient::verify()` will still
run; the *time* it reports will be the fixture's time, so pin test assertions
to that.

**2. Local openssl-based TSA.**  Spin up a one-shot TSA using
`openssl ts -reply` with a locally generated CA. See
`tests/fixtures/tsa/local-tsa.sh` for the reference script. Point
`OpensslTimestampClient` at `http://127.0.0.1:8318` and pass the local CA via
`ca_file`. Useful for integration tests that need real signature bytes but
must run air-gapped.

---

## References

- **ETSI EN 319 142** (PAdES baseline profiles):
  https://www.etsi.org/deliver/etsi_en/319100_319199/31914201/
- **RFC 3161** (Time-Stamp Protocol):
  https://datatracker.ietf.org/doc/rfc3161/
- **RFC 5652** (Cryptographic Message Syntax — PKCS#7 successor):
  https://datatracker.ietf.org/doc/rfc5652/
- **BSrE portal** (Indonesian PSrE): https://bsre.bssn.go.id
- **JSignPdf**: https://jsignpdf.sourceforge.net/
- **setasign FPDI + PDF signature plugin**:
  https://www.setasign.com/products/fpdi/
- **Adobe Approved Trust List (AATL) program**:
  https://helpx.adobe.com/acrobat/kb/approved-trust-list1.html
- **UU ITE (Indonesia)** — UU 11/2008 as amended by UU 19/2016.
- **PP 71/2019** — pelaksanaan sistem transaksi elektronik.
