# PSrE Integration Guide (v0.7+)

Panduan integrasi Penyelenggara Sertifikasi Elektronik (PSrE) tersertifikasi
Kominfo — Peruri, Privy, VIDA, BSrE — dengan library ezdoc.

## Overview

**PSrE** (Penyelenggara Sertifikasi Elektronik) adalah entitas berlisensi
Kominfo yang menerbitkan sertifikat digital untuk **Tanda Tangan Elektronik
Tersertifikasi (TTE Tersertifikasi)** — setara "qualified electronic signature"
di EU eIDAS.

### Kenapa Level 3?

Ezdoc mendefinisikan 3 level signature:

| Level | Mekanisme                    | Non-repudiation | Contoh pemakaian                     |
|-------|------------------------------|-----------------|--------------------------------------|
| L1    | HMAC-SHA256                  | Tidak           | QR verify slug, integrity URL        |
| L2    | Local X.509 + OpenSSL        | Terbatas        | Internal signing, self-CA            |
| L3    | PSrE tersertifikasi + TSA    | Ya (UU ITE)     | Kontrak, SPK, faktur pajak, ijazah   |

L3 memberikan bukti hukum yang **presumed valid** di pengadilan Indonesia
di bawah UU ITE No. 11/2008 (dimutakhirkan UU 19/2016) Pasal 11 & Pasal 55,
serta PP 71/2019.

### Legal validity — UU ITE checklist

Sebuah TTE dianggap sah kalau memenuhi 6 syarat Pasal 11 UU ITE:

1. Data pembuatan tanda tangan **hanya terkait pada Penanda Tangan**.
2. Data pembuatan tanda tangan **hanya berada dalam kuasa Penanda Tangan**.
3. Segala perubahan atas TTE yang terjadi **setelah** penandatanganan
   **dapat diketahui**.
4. Segala perubahan pada Informasi Elektronik yang ditandatangani
   **dapat diketahui**.
5. Terdapat cara tertentu untuk **mengidentifikasi Penanda Tangan**.
6. Terdapat cara tertentu untuk menunjukkan Penanda Tangan telah
   **memberikan persetujuan** (mis. OTP, biometric, password).

L3 provider di ezdoc (Peruri, Privy) memenuhi 1–6 karena:

- Private key di HSM PSrE, akses via OTP/biometric consent (poin 1, 2, 6)
- CMS/PAdES envelope + content hash (poin 3, 4)
- Cert X.509 dengan identitas signer terverifikasi PSrE (poin 5)

---

## Supported providers

| Provider | Status v0.7 | Envelope        | Consent flow            | Roadmap        |
|----------|-------------|-----------------|-------------------------|----------------|
| Peruri   | **Stub**    | PKCS#7, PAdES   | OTP (SMS/WA/Email)      | Real v0.7.1    |
| Privy    | **Stub**    | PKCS#7, PAdES   | Push (Privy App) / OTP  | Real v0.7.1    |
| VIDA     | Planned     | PKCS#7, PAdES   | WebSDK / mobile         | v0.9           |
| BSrE     | Planned     | PKCS#7, PAdES   | Passphrase (ASN only)   | v0.9           |

> **Catatan v0.7**: `PeruriProvider` dan `PrivyProvider` adalah SKELETON.
> Struktur adapter, DTO, dan capabilities lengkap; endpoint URL + payload
> JSON schema + response mapping masih placeholder (ditandai
> `TODO(v0.7-real)`). Real integration di v0.7.1 saat sandbox credentials
> tersedia.

---

## Architecture

```
+-----------------------------+
|  SignatureProvider (iface)  |   <-- generic contract (Envelope L1/L2/L3)
+--------------+--------------+
               |
     +---------+---------+
     |                   |
     v                   v
+---------+     +----------------------+
| Local   |     |  BaseRemoteProvider  |   <-- shared remote plumbing
| /Hmac   |     +----------+-----------+
+---------+                |
                +----------+-----------+
                v                      v
        +---------------+       +---------------+
        | PeruriProvider|       | PrivyProvider |
        +---------------+       +---------------+

           uses ↓                    uses ↓
        +---------------------+
        |   HttpClient (iface) |
        +---------+-----------+
                  |
                  v
        +---------------------+       +--------------------+
        |  CurlHttpClient     |  or   |  Consumer's own    |
        |  (default)          |       |  (Guzzle wrapper)  |
        +---------------------+       +--------------------+
```

### BaseRemoteProvider (abstract, planned v0.7.1)

Consolidates cross-cutting concerns for all PSrE adapters:

- Constructor validation via `assertRequired(array $config, array $keys)`
- HTTP request wrapper: retry with exponential backoff on 5xx (max 3),
  never retries POST /sign without idempotency key
- Automatic `X-Request-Id` UUIDv4 generation for idempotent POSTs
- JSON body decode + error taxonomy mapping (`decodeJsonBody`,
  `mapProviderError`)
- ISO-8601 UTC timestamp helper (`nowIso8601Ms`)
- Public `sign()`/`verify()` implementations that delegate to subclass
  abstracts: `initiateSigningEndpoint`, `authHeaders`,
  `buildSignRequestPayload`, `parseSignResponse`, `parseSessionResponse`,
  `parseOtpChallenge`, `getCaps`

### HttpClient interface (planned v0.7.1)

```php
interface HttpClient {
    public function get(string $url, array $headers = []): HttpResponse;
    public function post(string $url, $body, array $headers = []): HttpResponse;
}
```

`HttpResponse` is a value object with `getStatusCode()`, `getBody()`,
`getHeaders()`.

Default implementation `CurlHttpClient` uses PHP built-in cURL — no
external composer deps required. Consumers who already have Guzzle can
wrap it and inject via constructor:

```php
$provider = new PeruriProvider(new GuzzleAdapter($guzzle), $config);
```

### SignSession + OtpChallenge DTOs (planned v0.7.1)

Multi-step signing lifecycle is modeled explicitly:

```php
final class SignSession {
    // session_id, provider, document_ref, status, created_at,
    // expires_at, callback_url
}

final class OtpChallenge {
    // session_id, channel (SMS|EMAIL|WA|PRIVY_APP),
    // masked_target, attempts_remaining, resend_available_at
}
```

Status enum (string constants): `PENDING`, `AWAITING_OTP`,
`AWAITING_APPROVAL`, `SIGNED`, `FAILED`, `EXPIRED`, `CANCELLED`.

### Envelope layer

PKCS#7 (RFC 5652) envelope untuk L3 signature. Consumer boleh:

- Persist envelope bytes langsung (BLOB kolom) + verify pakai cert dari
  metadata payload
- Extract PKCS7 SignedData + verify pakai `openssl_pkcs7_verify()` dengan
  CA bundle PSrE

Untuk PAdES (PDF-embedded), envelope adalah signature dictionary bytes
yang sudah embedded di PDF byte-range. Consumer terima PDF ter-signed
utuh via `metadata.document_url` atau `envelope_b64` (base64 PDF penuh).

---

## Getting started — Peruri sample

### 1. Register di Peruri developer portal

- Portal: <https://developer.peruri.co.id> (verifikasi URL saat integrasi)
- Butuh akta perusahaan, NPWP, use case description
- Onboarding via akun manager Peruri

### 2. Get test signing certificate

Setelah account approved:

- Login portal → menu **Sandbox → Signers**
- Buat signer test dengan NIK dummy (Peruri kasih range NIK sandbox)
- Download test intermediate CA bundle → simpan sebagai
  `config/psre/peruri-ca-bundle.pem`

### 3. Config setup

```php
use Ezdoc\Signature\Providers\PeruriProvider;
use Ezdoc\Signature\Providers\CurlHttpClient;  // v0.7.1

$provider = new PeruriProvider(new CurlHttpClient(), [
    'base_url'      => 'https://api-sandbox.peruri.co.id',
    'client_id'     => getenv('PERURI_CLIENT_ID'),
    'client_secret' => getenv('PERURI_CLIENT_SECRET'),
    'signer_id'     => 'NIK_PLACEHOLDER',        // NIK signer terdaftar
    'callback_url'  => 'https://app.example.com/psre/callback',
    'test_mode'     => true,
    'timeout'       => 30,
]);
```

### 4. Multi-step sign

```php
use Ezdoc\Signature\SignRequest;

$req = new SignRequest([
    'contentBytes'   => file_get_contents('kontrak.pdf'),
    'signerId'       => 'user:42',
    'envelopeFormat' => 'pades',
    'metadata'       => ['doc_id' => 100, 'title' => 'Kontrak Kerja'],
]);

// Step 1: initiate — hasilkan session, Peruri kirim OTP ke user
$session = $provider->initiate($req);
// $session->getSessionId(), ->getStatus() === 'AWAITING_OTP'
// $session->getExpiresAt() → deadline OTP

// Step 2: user input OTP (dari SMS/WA/Email) via web form kita
$userOtp = $_POST['otp'];  // sudah divalidasi CSRF/rate-limit di layer atas

// Step 3: submit OTP → dapat envelope final
$result = $provider->submitOtp($session->getSessionId(), $userOtp);
// $result instanceof SignResult
// $result->getEnvelope() → PKCS7 / PAdES bytes
// $result->getCertificatePem() → cert signer untuk verify offline
```

### Async callback flow (planned v0.7.1)

Registrasi `callback_url` di request → Peruri POST balik saat SIGNED:

```
POST https://app.example.com/psre/callback
Headers:
  X-Provider: peruri
  X-Signature: <HMAC-SHA256 body pakai webhook secret>
Body:
  { "session_id": "...", "status": "SIGNED", "envelope_b64": "...", ... }
```

Handler wajib verify `X-Signature` HMAC sebelum trust body, dan idempotent
(correlate by `session_id`).

---

## Envelope format PKCS#7 (RFC 5652)

CMS SignedData — struktur:

```
ContentInfo ::= SEQUENCE {
    contentType  ContentType,        -- id-signedData
    content      SignedData
}
SignedData ::= SEQUENCE {
    version           CMSVersion,
    digestAlgorithms  SET OF DigestAlgorithmIdentifier,
    encapContentInfo  EncapsulatedContentInfo,  -- detached: no content
    certificates      [0] IMPLICIT CertificateSet OPTIONAL,
    crls              [1] IMPLICIT RevocationInfoChoices OPTIONAL,
    signerInfos       SET OF SignerInfo
}
```

### Pack/unpack via openssl_pkcs7_*

```php
// Sign (dilakukan di sisi PSrE, tapi bisa juga lokal utk L2)
openssl_pkcs7_sign($inFile, $outEnvelope, $cert, $key, [], PKCS7_DETACHED);

// Verify (di sisi kita, offline setelah dapat envelope)
$ok = openssl_pkcs7_verify(
    $signedFile,      // envelope
    PKCS7_DETACHED,
    $outCertsFile,    // signer certs extracted
    ['/path/to/psre-ca-bundle.pem'],  // trust anchors
    '/path/to/psre-ca-bundle.pem',
    $originalContentFile
);
// $ok === true → chain valid + signature match
```

### Verify tanpa live PSrE call

Setelah envelope + cert dipersist ke storage lokal, verify offline:

1. Extract `SignerInfo` dari envelope
2. Ambil signer cert (dari envelope `certificates` field atau `metadata.signer_cert_pem`)
3. Validate cert chain dengan CA bundle PSrE — `openssl_x509_checkpurpose()`
4. Verify signature bytes atas hash content asli
5. Cek `notBefore` ≤ `signedAt` ≤ `notAfter` cert
6. (Opsional, v0.8) Cek TSA timestamp + CRL/OCSP revocation

---

## Testing without live API

### FakeRemoteProvider (planned v0.7.1)

```php
$fake = new FakeRemoteProvider([
    'auto_sign_after_otp' => true,
    'fixture_envelope'    => file_get_contents('tests/fixtures/pkcs7-sample.der'),
    'fixture_cert'        => file_get_contents('tests/fixtures/signer.pem'),
]);

$session = $fake->initiate($req);
$result  = $fake->submitOtp($session->getSessionId(), '123456');
// $result → deterministic SignResult from fixtures
```

### Test vectors

Fixtures direncanakan di `tests/fixtures/psre/`:

- `pkcs7-detached-sha256.der` — canonical CMS envelope
- `pades-b-lt-sample.pdf` — PDF ter-signed
- `signer-cert.pem` + `intermediate-ca.pem` + `root-ca.pem`
- `sign-response-peruri.json`, `sign-response-privy.json` — sample body

Vector dipakai unit test parse mapping tanpa perlu network.

---

## Cost estimates (per PSrE, rough — verifikasi ke vendor)

| Provider | Onboarding    | Per-signature (retail) | Enterprise volume | Notes                          |
|----------|---------------|------------------------|-------------------|--------------------------------|
| Peruri   | Rp 5–15 jt    | Rp 5.000–15.000        | Nego bulk         | BUMN — proses onboarding formal |
| Privy    | Rp 3–10 jt    | Rp 3.000–10.000        | Nego bulk         | KYC lebih fleksibel             |
| VIDA     | Rp 5–10 jt    | Rp 5.000–12.000        | Nego bulk         | WebSDK friendly                 |
| BSrE     | Gratis (ASN)  | Gratis                 | N/A               | Khusus instansi pemerintah      |

*Angka indikatif per 2024–2025; harga aktual bergantung negosiasi dan
volume komitmen tahunan.*

---

## Known limitations di v0.7

- **Stubs**: Peruri + Privy hanya skeleton. Real API mapping (endpoint,
  payload schema, response field names, auth path) baru diisi v0.7.1
  setelah sandbox credentials tersedia.
- **BaseRemoteProvider + HttpClient**: belum di-implement. Provider stub
  reference kelas ini sebagai contract yang akan datang. Autoload akan
  fatal-error sampai base class + interface di-commit di v0.7.1.
- **SignSession + OtpChallenge DTO**: belum di-implement.
- **No auto-webhook handler**: async callback dari PSrE harus diterima
  manual di route consumer — planned v0.7.1 (helper `WebhookVerifier`).
- **No cert revocation check**: CRL/OCSP lookup belum di-integrate —
  planned v0.8. Untuk sementara consumer wajib subscribe CRL PSrE dan
  refresh manual.
- **No TSA timestamping fallback**: TSA timestamp diterima dari PSrE
  response, TIDAK di-verify ulang oleh library — planned v0.8.
- **VIDA + BSrE providers**: belum di-implement, planned v0.9.

---

## Legal validity checklist (production go-live)

- [ ] Vendor PSrE terdaftar di daftar Kominfo:
      <https://kominfo.go.id/psre>
- [ ] Kontrak enterprise dengan PSrE aktif + service level agreement
- [ ] Sertifikat signer teregistrasi + belum expired
- [ ] Envelope + cert bundle dipersist ke storage tahan-tamper
      (append-only log / immutable object storage)
- [ ] Content hash (SHA-256) content asli disimpan bersama envelope
- [ ] Timestamp dari TSA PSrE terekam di metadata signature (v0.8)
- [ ] CRL/OCSP snapshot pada saat sign disimpan untuk long-term verify
      (LTV — Long-Term Validation, PAdES-LTA) — v0.8
- [ ] Audit trail lengkap: siapa initiate, kapan OTP dikirim, kapan
      OTP diverifikasi, IP+UA signer (via `Ezdoc\Audit\Logger`)
- [ ] Retention policy: signed envelope + audit ≥ 25 tahun (best practice
      untuk kontrak jangka panjang di bawah UU ITE + KUH Perdata)

---

## References

- UU ITE No. 11/2008 jo. UU 19/2016 — Pasal 11 (TTE), Pasal 55 (bukti)
- PP 71/2019 — Penyelenggaraan Sistem dan Transaksi Elektronik
- Permenkominfo 11/2018 — Penyelenggaraan Sertifikasi Elektronik
- RFC 5652 — Cryptographic Message Syntax (CMS)
- ETSI EN 319 142 — PAdES Baseline Profile (PAdES-B, B-T, B-LT, B-LTA)
- RFC 3161 — Time-Stamp Protocol (TSP)
- Peruri Developer Portal — <https://developer.peruri.co.id>
- Privy Business — <https://business.privy.id>
