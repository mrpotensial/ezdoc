<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

/**
 * Ezdoc\Signature\SignatureProvider — adapter contract untuk backend
 * signature (HMAC L1, X.509 / PKCS#7 L2, PAdES L3 dengan TSA, dst).
 *
 * ## Kontrak
 *
 * Provider wajib idempotent secara logic: `sign($req)` boleh menghasilkan
 * envelope berbeda antar pemanggilan (mis. HMAC deterministic vs RSA-PSS
 * randomized), namun `verify($envelope, $ctx)` harus konsisten memutuskan
 * VALID/TAMPERED berdasarkan content yang sama.
 *
 * ## Envelope format
 *
 * `SignResult::envelopeFormat` MUST match salah satu dari
 * `capabilities()->getSupportsEnvelopeFormats()`. Format yang dikenal:
 *   - "raw"    : signature bytes mentah (tanpa wrapper), consumer wajib
 *                simpan sendiri metadata algoritma & signer.
 *   - "hmac"   : hex-encoded HMAC digest (level 1 / integrity-only).
 *   - "pkcs7"  : CMS SignedData (CAdES/PKCS#7 detached atau attached).
 *   - "pades"  : PDF PAdES-B/B-LT/B-LTA (level 2/3, biasanya menempel di
 *                PDF byte range, envelope adalah signature dictionary bytes).
 *
 * ## Verification input
 *
 * `verify()` menerima envelope + `VerifyContext` yang membawa
 * `contentBytes` original untuk tamper detection. Provider tidak boleh
 * mengakses DB atau state global — semua data verifikasi harus lewat
 * `VerifyContext`.
 *
 * ## Verdict
 *
 * Return `Verdict` — jangan throw untuk kegagalan bisnis (tampered,
 * expired, untrusted). Throw `EzdocException` hanya untuk kegagalan
 * teknis (mis. secret hilang, key file corrupt) yang bukan bagian dari
 * hasil verifikasi.
 *
 * PHP 7.4+ compatible.
 */
interface SignatureProvider
{
    /**
     * Sign content dan hasilkan envelope siap simpan/tempel.
     *
     * @param SignRequest $req
     * @return SignResult
     * @throws \Ezdoc\Exceptions\EzdocException technical failure only
     */
    public function sign(SignRequest $req): SignResult;

    /**
     * Verifikasi envelope terhadap content asli di `$ctx->contentBytes`.
     * Return `Verdict` object — jangan throw untuk hasil VALID/TAMPERED/dll.
     *
     * @param string        $envelope raw bytes envelope (format sesuai
     *                                capabilities); untuk 'hmac' format ini
     *                                adalah hex-encoded digest.
     * @param VerifyContext $ctx
     * @return Verdict
     */
    public function verify(string $envelope, VerifyContext $ctx): Verdict;

    /**
     * Deklarasi kemampuan provider — dipakai router/registry untuk pilih
     * provider yang cocok dengan level & format yang diminta.
     *
     * @return ProviderCapabilities
     */
    public function capabilities(): ProviderCapabilities;
}
