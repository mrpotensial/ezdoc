<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Pdf\PdfSigner — kontrak abstraksi untuk PDF signing
 * (PAdES: PDF Advanced Electronic Signatures, ETSI EN 319 142).
 *
 * ## Kenapa dipisah dari Envelope?
 *
 * `PadesEnvelope` bertanggung jawab atas kontrak envelope (pack/unpack/verify
 * seragam lintas format). PDF signing sendiri butuh manipulasi struktur
 * PDF yang kompleks — incremental xref, /ByteRange placeholder, /Contents
 * padded to reserved length — sehingga di-delegasikan ke implementor
 * `PdfSigner` yang bisa berupa openssl CLI shell-out, JSignPdf jar,
 * SetaPDF-Signer commercial, atau HTTP call ke PSrE (Peruri, Privy).
 *
 * ## Kenapa interface, bukan abstract class?
 *
 * Consumer bebas mengganti backend runtime (JSignPdf di dev, SetaPDF di
 * prod, ExternalPdfSigner untuk cloud HSM) tanpa mengubah PadesEnvelope
 * atau audit-trail path. Testability naik: mock signer trivial.
 *
 * ## Kontrak
 *
 * - `embedSignature()` menerima PDF bytes + PKCS#7/CMS detached bytes
 *   (yang sudah dihitung external — provider/HSM/PSrE) + signer cert,
 *   dan menghasilkan PDF bertanda tangan (incremental update yang embed
 *   /Sig dict + /Contents).
 * - `extractSignature()` mem-parse /ByteRange dan /Contents dari signed
 *   PDF untuk verifier / archiver.
 * - `verifyPdf()` verifikasi structural + kriptografis (digest
 *   /ByteRange match, chain, TSA kalau ada).
 *
 * ## Options di embedSignature (baseline):
 *
 *   - 'signature_field_name' (string, default 'Signature1')
 *   - 'reason' (string, isi /Reason di /Sig dict)
 *   - 'location' (string, /Location)
 *   - 'contact_info' (string, /ContactInfo)
 *   - 'timestamp_token' (string, RFC 3161 TSR bytes untuk PAdES-B-T)
 *   - 'signing_time' (int, unix timestamp untuk /M)
 *   - 'sub_filter' (string, default 'ETSI.CAdES.detached')
 *   - 'reserved_size' (int, default 32768; ukuran placeholder /Contents)
 *
 * PHP 7.4+ compatible. NO union/enum/readonly.
 */
interface PdfSigner
{
    /**
     * Embed CMS SignedData bytes ke PDF sebagai PAdES signature.
     *
     * Flow:
     *   1. Sisipkan AcroForm signature field + /Sig dict via incremental update.
     *   2. Isi /Contents dengan placeholder 0x00 sebesar reserved_size.
     *   3. Compute /ByteRange offsets, patch placeholder di tempat.
     *   4. Isi /Contents dengan hex-encoded $pkcs7Bytes, right-pad 0x00.
     *
     * Implementor boleh recompute PKCS#7 sendiri kalau butuh (mis. embedded
     * timestamp), tapi kontrak minimum: $pkcs7Bytes DER CMS SignedData
     * yang sudah signed atas hash /ByteRange.
     *
     * @param string           $pdfBytes  original PDF (harus valid, boleh sudah tersigned
     *                                    — implementor akan append incremental update)
     * @param string           $pkcs7Bytes CMS SignedData DER bytes
     * @param X509Certificate  $cert       signer cert (untuk populasi /Sig dict fields)
     * @param array<string,mixed> $options  lihat baseline di kelas doc
     * @return string signed PDF bytes
     * @throws \Ezdoc\Exceptions\EzdocException kalau embedding gagal
     */
    public function embedSignature(string $pdfBytes, string $pkcs7Bytes, X509Certificate $cert, array $options = []): string;

    /**
     * Extract signature bytes + metadata dari signed PDF.
     *
     * Return array shape:
     *   [
     *     'signature_bytes' => string  (raw CMS DER bytes, hex-decoded),
     *     'byte_range'      => array   [start1, length1, start2, length2],
     *     'cert_pem'        => string  (PEM signer cert; '' kalau tidak bisa extract),
     *     'sig_info'        => array{
     *       field_name?: string, sub_filter?: string, reason?: string,
     *       location?: string, contact_info?: string, signing_time?: int,
     *       has_timestamp?: bool
     *     },
     *   ]
     *
     * @param string $pdfBytes
     * @return array<string,mixed>
     * @throws \Ezdoc\Exceptions\EzdocException kalau PDF tidak mengandung /Sig
     */
    public function extractSignature(string $pdfBytes): array;

    /**
     * Verifikasi signature di PDF.
     *
     * Return array shape:
     *   [
     *     'valid'           => bool,
     *     'reason'          => string,
     *     'checks'          => array<string,mixed> (byte_range_covers_all, digest_match,
     *                          chain_ok, tsa_ok, ...),
     *     'signer_cert_pem' => string,
     *     'signed_at'       => int|null (unix timestamp; null kalau tidak diketahui),
     *   ]
     *
     * @param string              $pdfBytes
     * @param array<string,mixed> $options ('ca_bundle', 'trust_roots_pem', 'strict_coverage', dsb)
     * @return array<string,mixed>
     */
    public function verifyPdf(string $pdfBytes, array $options = []): array;
}
