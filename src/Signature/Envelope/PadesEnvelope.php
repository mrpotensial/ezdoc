<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\X509Certificate;
use Ezdoc\Signature\Pdf\PdfSigner;

/**
 * Ezdoc\Signature\Envelope\PadesEnvelope — PAdES (PDF Advanced Electronic
 * Signatures) envelope, baseline profile B-B (ETSI EN 319 142).
 *
 * ## Perbedaan dari PkcsSevenEnvelope
 *
 * PKCS#7 envelope memproduksi bare CMS SignedData bytes (S/MIME / DER)
 * yang ditransport terpisah. PAdES envelope MENYISIPKAN CMS ke dalam
 * PDF via signature dictionary + /ByteRange, sehingga hasil `pack()`
 * adalah SIGNED PDF bytes yang bisa langsung dibuka di Adobe Reader
 * dengan indikator tanda tangan digital.
 *
 * ## Kenapa butuh PdfSigner terpisah?
 *
 * Manipulasi struktur PDF (incremental xref, /ByteRange placeholder,
 * patch offsets in-place) sangat error-prone. Kelas ini delegates
 * seluruh PDF plumbing ke `PdfSigner` (interface) — konsumer bebas
 * memilih backend:
 *
 *   - `JSignPdfSigner`   — Java jsignpdf.jar, PAdES-B-LT free
 *   - `OpensslPdfSigner` — extract/verify saja (embed = stub)
 *   - `ExternalPdfSigner` — HTTP call ke PSrE / cloud HSM
 *   - `SetasignPdfSigner` — commercial (belum ada di tree)
 *
 * ## PAdES profile support
 *
 * Class ini menargetkan **PAdES-B-B** baseline. Untuk B-T (embed
 * timestamp token) konsumer boleh pass `options['timestamp_token']`
 * (RFC 3161 TSR bytes) yang akan diteruskan ke PdfSigner. B-LT / B-LTA
 * (DSS + DocTimeStamp) di luar scope kelas ini — pakai signer yang
 * memang mendukungnya (JSignPdf dengan flag TSA + revocation info).
 *
 * ## Options di pack()
 *
 *   - 'signature_field_name' (default 'Signature1')
 *   - 'reason'          (string, /Reason)
 *   - 'location'        (string, /Location)
 *   - 'contact_info'    (string, /ContactInfo)
 *   - 'timestamp_token' (string, PAdES-B-T)
 *   - 'signing_time'    (int, /M)
 *   - 'sub_filter'      (string, default 'ETSI.CAdES.detached')
 *
 * PHP 7.4+ compatible.
 */
final class PadesEnvelope implements Envelope
{
    /** @var string */
    const FORMAT = 'pades';

    /** @var string default subfilter (ETSI PAdES) */
    const DEFAULT_SUB_FILTER = 'ETSI.CAdES.detached';

    /** @var string legacy subfilter (Adobe, pre-PAdES) */
    const LEGACY_SUB_FILTER = 'adbe.pkcs7.detached';

    /** @var string PDF magic header */
    const PDF_MAGIC = '%PDF-';

    /** @var PdfSigner */
    private $pdfSigner;

    /**
     * @param PdfSigner $pdfSigner backend PDF signing (JSignPdf / External / Setasign / dsb)
     */
    public function __construct(PdfSigner $pdfSigner)
    {
        $this->pdfSigner = $pdfSigner;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormat(): string
    {
        return self::FORMAT;
    }

    /**
     * {@inheritdoc}
     *
     * Semantik parameter:
     *
     *   - `$content`   = original PDF bytes (BUKAN cover PDF; PAdES sign
     *                    dokumen final).
     *   - `$signature` = PKCS#7 / CMS SignedData bytes yang sudah dihitung
     *                    provider eksternal (mis. HSM, PSrE remote sign).
     *                    Boleh empty kalau backend PdfSigner adalah
     *                    JSignPdfSigner yang menandatangani sendiri via
     *                    keystore (dalam kasus itu $signature di-ignore).
     *   - `$cert`      = X509 signer cert (WAJIB kalau backend butuh —
     *                    JSignPdf misalnya ambil dari keystore).
     *
     * @throws ValidationException
     * @throws EzdocException
     */
    public function pack(string $signature, string $content, X509Certificate $cert = null, array $options = []): string
    {
        if ($content === '') {
            throw ValidationException::forField('content', 'PAdES pack() requires PDF bytes in $content');
        }
        if (!self::looksLikePdf($content)) {
            throw ValidationException::forField('content', 'content does not look like a PDF (missing %PDF- header)');
        }
        // $cert boleh null untuk backend yang self-signing dari keystore;
        // kalau backend memaksa, dia akan throw sendiri.
        if ($cert === null) {
            // Beri cert dummy? Tidak — biarkan backend enforce. Tapi
            // PdfSigner::embedSignature butuh X509Certificate non-null;
            // panggil dengan fake cert tidak benar. Kita throw kalau
            // signature non-empty (jelas mode external CMS) tapi cert null.
            if ($signature !== '') {
                throw ValidationException::forField(
                    'cert',
                    'X509Certificate required when packaging pre-computed PKCS#7 bytes'
                );
            }
            throw ValidationException::forField('cert', 'X509Certificate required for PAdES envelope');
        }

        // Default sub_filter kalau tidak dikirim.
        if (!isset($options['sub_filter']) || !is_string($options['sub_filter']) || $options['sub_filter'] === '') {
            $options['sub_filter'] = self::DEFAULT_SUB_FILTER;
        }
        if (!isset($options['signature_field_name']) || !is_string($options['signature_field_name']) || $options['signature_field_name'] === '') {
            $options['signature_field_name'] = 'Signature1';
        }
        if (!isset($options['signing_time'])) {
            $options['signing_time'] = time();
        }

        return $this->pdfSigner->embedSignature($content, $signature, $cert, $options);
    }

    /**
     * {@inheritdoc}
     *
     * Delegates ke `PdfSigner::extractSignature()`, kemudian normalisasi
     * ke shape Envelope kontrak:
     *
     *   [
     *     'signature'       => raw CMS bytes,
     *     'signer_cert_pem' => PEM cert atau null,
     *     'timestamp'       => signing_time (int) atau null,
     *     'metadata'        => byte_range + sig_info,
     *   ]
     *
     * @throws EzdocException
     */
    public function unpack(string $envelopeBytes): array
    {
        if ($envelopeBytes === '') {
            throw new EzdocException('PadesEnvelope::unpack(): empty envelope');
        }
        if (!self::looksLikePdf($envelopeBytes)) {
            throw new EzdocException('PadesEnvelope::unpack(): not a PDF (missing %PDF- header)');
        }
        $result = $this->pdfSigner->extractSignature($envelopeBytes);

        $sigBytes = isset($result['signature_bytes']) && is_string($result['signature_bytes'])
            ? $result['signature_bytes']
            : '';
        $certPem = isset($result['cert_pem']) && is_string($result['cert_pem']) && $result['cert_pem'] !== ''
            ? $result['cert_pem']
            : null;
        $sigInfo = isset($result['sig_info']) && is_array($result['sig_info']) ? $result['sig_info'] : [];
        $timestamp = isset($sigInfo['signing_time']) ? (int) $sigInfo['signing_time'] : null;

        return [
            'signature' => $sigBytes,
            'signer_cert_pem' => $certPem,
            'timestamp' => $timestamp,
            'metadata' => [
                'format' => self::FORMAT,
                'byte_range' => isset($result['byte_range']) && is_array($result['byte_range']) ? $result['byte_range'] : [],
                'sig_info' => $sigInfo,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Delegates ke PdfSigner::verifyPdf(). Return array di-shape ke
     * kontrak Envelope::verify().
     *
     * Note: `$originalContent` di PAdES **tidak diperlukan** — signature
     * detached-CMS di-hash atas /ByteRange di dalam PDF itu sendiri, jadi
     * envelope bytes sudah self-contained. Parameter tetap di-accept
     * untuk konsistensi Envelope contract; kalau non-empty, verifier
     * boleh cross-check kalau butuh (tapi biasanya sama identiknya).
     */
    public function verify(string $envelopeBytes, string $originalContent, array $options = []): array
    {
        if ($envelopeBytes === '') {
            return [
                'valid' => false,
                'reason' => 'empty envelope',
                'signer_info' => [],
                'checks' => ['non_empty' => false],
            ];
        }
        if (!self::looksLikePdf($envelopeBytes)) {
            return [
                'valid' => false,
                'reason' => 'not a PDF (missing %PDF- header)',
                'signer_info' => [],
                'checks' => ['pdf_magic' => false],
            ];
        }

        $verdict = $this->pdfSigner->verifyPdf($envelopeBytes, $options);

        $signerInfo = [];
        if (isset($verdict['signer_cert_pem']) && is_string($verdict['signer_cert_pem']) && $verdict['signer_cert_pem'] !== '') {
            try {
                $cert = X509Certificate::fromPem($verdict['signer_cert_pem']);
                $signerInfo = [
                    'signer_cert_pem' => $cert->getPem(),
                    'subject_cn' => $cert->getSubjectCN(),
                    'issuer_cn' => $cert->getIssuerCN(),
                    'serial' => $cert->getSerialNumber(),
                    'not_before' => $cert->getNotBefore(),
                    'not_after' => $cert->getNotAfter(),
                ];
            } catch (ValidationException $e) {
                $signerInfo = ['error' => 'signer cert parse failed: ' . $e->getMessage()];
            }
        }
        unset($originalContent);

        return [
            'valid' => !empty($verdict['valid']),
            'reason' => isset($verdict['reason']) && is_string($verdict['reason']) ? $verdict['reason'] : '',
            'signer_info' => $signerInfo,
            'checks' => isset($verdict['checks']) && is_array($verdict['checks']) ? $verdict['checks'] : [],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * PDF signature adalah attached by design (signature bytes tersimpan
     * di dalam PDF via /Sig dict). Detached tidak applicable.
     */
    public function canDetached(): bool
    {
        return false;
    }

    /**
     * Sniff apakah bytes adalah PDF yang mengandung /Sig dict.
     *
     * Heuristik cepat:
     *   1. Header %PDF- di 1KB pertama.
     *   2. Ada substring '/Sig' atau '/Type /Sig' atau '/ByteRange'.
     *
     * False positive: PDF yang punya form field bernama /Signature1
     * tanpa isi bisa lolos. Consumer yang butuh certainty harus
     * memanggil `unpack()` dan menangkap exception-nya.
     */
    public static function isPadesSignedPdf(string $bytes): bool
    {
        if (!self::looksLikePdf($bytes)) {
            return false;
        }
        // /ByteRange adalah marker paling reliable untuk signed PDF.
        if (strpos($bytes, '/ByteRange') !== false) {
            return true;
        }
        // /Sig dict presence (fallback).
        if (strpos($bytes, '/Type /Sig') !== false || strpos($bytes, '/Type/Sig') !== false) {
            return true;
        }
        return false;
    }

    /**
     * True kalau bytes punya PDF magic di 1KB pertama.
     */
    private static function looksLikePdf(string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) < 5) {
            return false;
        }
        $head = substr($bytes, 0, 1024);
        return strpos($head, self::PDF_MAGIC) !== false;
    }
}
