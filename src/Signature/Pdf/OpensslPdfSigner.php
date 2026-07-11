<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Pdf\OpensslPdfSigner — reference implementation PdfSigner
 * berbasis shell-out ke `openssl` CLI dan (opsional) `pdfsig` dari
 * poppler-utils untuk verify.
 *
 * ## Scope realistis
 *
 * `embedSignature()` di sini SENGAJA STUB. Alasan:
 *
 *   - Membangun incremental xref + AcroForm /Sig field + /ByteRange
 *     placeholder + patch offsets adalah 300+ baris PDF plumbing yang
 *     ekivalen dengan mini-parser PDF. Tanpa test corpus PDF yang lengkap,
 *     implementasi in-tree berisiko produce PDF yang "valid di Adobe tapi
 *     ditolak PSrE".
 *   - Konsumer sudah punya opsi mature: JSignPdf (Java CLI, PAdES-B-LT
 *     full support), SetaPDF-Signer (commercial), TCPDF::setSignature()
 *     (PAdES-B-B), atau signing service PSrE via `ExternalPdfSigner`.
 *
 * `extractSignature()` dan `verifyPdf()` DIIMPLEMENTASIKAN sepenuhnya
 * (parsing `/ByteRange` + `/Contents`, shell-out ke `pdfsig` untuk
 * verifikasi tambahan). Ini cukup untuk verifier-side / archive
 * pipelines, tanpa dependency Java.
 *
 * ## Config
 *
 *   - 'openssl_path'  (string, default 'openssl')
 *   - 'pdfsig_path'   (string, default 'pdfsig')  — dari poppler-utils
 *   - 'temp_dir'      (string, default sys_get_temp_dir())
 *   - 'reserved_signature_size' (int, default 32768 = 32KB)
 *
 * ## Keamanan shell-out
 *
 * Semua argumen di-pass via `escapeshellarg()`. Stdin PDF di-pipe via
 * temp file yang di-chmod 0600 dan di-unlink di finally. Tidak ada
 * concatenation user-input ke commandline string.
 *
 * PHP 7.4+ compatible.
 */
final class OpensslPdfSigner implements PdfSigner
{
    /** @var string */
    const DEFAULT_OPENSSL = 'openssl';

    /** @var string */
    const DEFAULT_PDFSIG = 'pdfsig';

    /** @var int default reserved bytes untuk /Contents (32KB, cukup untuk chain + TSA + OCSP kecil) */
    const DEFAULT_RESERVED_SIZE = 32768;

    /** @var string */
    private $opensslPath;

    /** @var string */
    private $pdfsigPath;

    /** @var string */
    private $tempDir;

    /** @var int */
    private $reservedSize;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->opensslPath = isset($config['openssl_path']) && is_string($config['openssl_path']) && $config['openssl_path'] !== ''
            ? $config['openssl_path']
            : self::DEFAULT_OPENSSL;
        $this->pdfsigPath = isset($config['pdfsig_path']) && is_string($config['pdfsig_path']) && $config['pdfsig_path'] !== ''
            ? $config['pdfsig_path']
            : self::DEFAULT_PDFSIG;
        $this->tempDir = isset($config['temp_dir']) && is_string($config['temp_dir']) && $config['temp_dir'] !== ''
            ? $config['temp_dir']
            : sys_get_temp_dir();
        $this->reservedSize = isset($config['reserved_signature_size']) && is_int($config['reserved_signature_size']) && $config['reserved_signature_size'] > 0
            ? $config['reserved_signature_size']
            : self::DEFAULT_RESERVED_SIZE;
    }

    /**
     * {@inheritdoc}
     *
     * STUB — NOT IMPLEMENTED.
     *
     * Alasan design: menulis incremental xref + AcroForm /Sig field + patch
     * /ByteRange in-place membutuhkan PDF-aware writer (mini parser).
     * Bahkan TCPDF setSignature() secara internal me-render ulang PDF via
     * TCPDF sendiri — bukan generic incremental patcher.
     *
     * Rekomendasi konsumer:
     *   1. JSignPdfSigner   — free, Java, full PAdES-B-LT/LTA + TSA
     *   2. SetasignPdfSigner (belum ada di ezdoc) — commercial, best-in-class
     *   3. ExternalPdfSigner — delegate ke Peruri / Privy / BSrE PSrE API
     *   4. TCPDF setSignature (di layer builder dokumen, bukan re-embed)
     *
     * @throws EzdocException selalu — beri guidance konkret ke konsumer
     */
    public function embedSignature(string $pdfBytes, string $pkcs7Bytes, X509Certificate $cert, array $options = []): string
    {
        // TODO(pades): implement PDF incremental update + /ByteRange patch.
        // Referensi: PDF 32000-1:2008 §12.8 (Digital Signatures).
        // Sketch algorithm:
        //   1. Locate existing xref (backward search '%%EOF' → 'startxref')
        //   2. Build new /AcroForm reference + /Sig field object (indirect)
        //   3. Append incremental section: new objects + xref subsection + trailer
        //   4. Write /Contents placeholder = str_repeat("\0", reservedSize)
        //   5. Compute /ByteRange offsets, patch in-place (same width digits)
        //   6. Hex-encode $pkcs7Bytes, right-pad to reservedSize with 0x00
        //   7. Write into /Contents blob
        //
        // Tanpa test corpus + PDF-A conformance suite, in-tree impl
        // beresiko produce PDF yang "seolah valid" tapi ditolak PSrE.
        throw new EzdocException(
            'OpensslPdfSigner::embedSignature() is not implemented. '
            . 'Use JSignPdfSigner (Java jsignpdf.jar) for PAdES-B-B/T/LT, '
            . 'or ExternalPdfSigner to delegate to PSrE (Peruri/Privy/BSrE) '
            . 'or a commercial signer (setasign/SetaPDF-Signer). '
            . 'This class supports extract/verify only.',
            [
                'reserved_size' => $this->reservedSize,
                'pkcs7_len' => strlen($pkcs7Bytes),
                'cert_cn' => $cert->getSubjectCN(),
                'options' => array_keys($options),
            ]
        );
    }

    /**
     * {@inheritdoc}
     *
     * Implementasi lengkap: manual parse /ByteRange dan /Contents dari
     * bytes PDF (regex-based; tolerant terhadap whitespace).
     *
     * @throws EzdocException kalau signature tidak ditemukan
     */
    public function extractSignature(string $pdfBytes): array
    {
        if ($pdfBytes === '') {
            throw new EzdocException('OpensslPdfSigner::extractSignature: empty PDF bytes');
        }
        // 1. /ByteRange
        $byteRange = PdfBytesRange::fromPdf($pdfBytes);

        // 2. /Contents hex string — cari yang ada di antara byte end of
        // range1 dan start of range2 (menghindari false-positive di
        // /Contents key lain yang bisa muncul di PDF stream).
        $sigOffset = $byteRange->getSignatureOffset();
        $sigLen = $byteRange->getSignatureLength();
        if ($sigOffset <= 0 || $sigLen <= 2) {
            throw new EzdocException('OpensslPdfSigner::extractSignature: invalid /ByteRange gap');
        }
        $blob = substr($pdfBytes, $sigOffset, $sigLen);
        // Blob dimulai dengan '<' dan diakhiri '>'. Isinya hex string
        // padded dengan '0' di akhir. Trim delimiter + trailing zeros.
        $blob = ltrim($blob, " \t\r\n");
        if ($blob === '' || $blob[0] !== '<') {
            throw new EzdocException('OpensslPdfSigner::extractSignature: /Contents does not start with "<"');
        }
        $endPos = strpos($blob, '>');
        if ($endPos === false) {
            throw new EzdocException('OpensslPdfSigner::extractSignature: /Contents missing ">"');
        }
        $hex = substr($blob, 1, $endPos - 1);
        // Trailing zeros (padding). Buang di kanan tanpa mengganggu isi.
        $hex = rtrim($hex, "0");
        // Jika panjang hex ganjil setelah trim (kasus edge: byte terakhir
        // adalah 0x?0), tambahkan satu '0' kembali agar valid hex.
        if ((strlen($hex) % 2) === 1) {
            $hex .= '0';
        }
        $sigBytes = @hex2bin($hex);
        if ($sigBytes === false || $sigBytes === '') {
            throw new EzdocException('OpensslPdfSigner::extractSignature: /Contents hex2bin failed');
        }

        // 3. Extract signer cert via openssl_pkcs7_read (portable).
        $certPem = $this->extractCertPemFromPkcs7($sigBytes);

        // 4. Sig dict fields (best-effort regex).
        $sigInfo = $this->parseSigDictFields($pdfBytes);
        $sigInfo['sub_filter'] = isset($sigInfo['sub_filter']) ? $sigInfo['sub_filter'] : '';
        $sigInfo['has_timestamp'] = strpos($sigBytes, 'id-aa-timeStampToken') !== false; // heuristik lemah

        return [
            'signature_bytes' => $sigBytes,
            'byte_range' => $byteRange->toArray(),
            'cert_pem' => $certPem,
            'sig_info' => $sigInfo,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * Verify flow:
     *   1. Parse /ByteRange, cek isFullCoverage.
     *   2. Hash /ByteRange bytes → SHA-256.
     *   3. Kalau `pdfsig` tersedia → shell-out untuk full verify (chain, TSA).
     *   4. Fallback: openssl_pkcs7_verify manual (chain saja).
     */
    public function verifyPdf(string $pdfBytes, array $options = []): array
    {
        if ($pdfBytes === '') {
            return [
                'valid' => false,
                'reason' => 'empty PDF bytes',
                'checks' => ['non_empty' => false],
                'signer_cert_pem' => '',
                'signed_at' => null,
            ];
        }
        $checks = [];
        try {
            $extract = $this->extractSignature($pdfBytes);
        } catch (EzdocException $e) {
            return [
                'valid' => false,
                'reason' => 'extract failed: ' . $e->getMessage(),
                'checks' => ['extract' => false],
                'signer_cert_pem' => '',
                'signed_at' => null,
            ];
        }
        $byteRange = new PdfBytesRange($extract['byte_range']);
        $checks['byte_range_full_coverage'] = $byteRange->isFullCoverage(strlen($pdfBytes));

        // pdfsig-based verify kalau tools tersedia.
        $pdfsigOut = $this->tryPdfsigVerify($pdfBytes);
        if ($pdfsigOut !== null) {
            $checks['pdfsig_available'] = true;
            $checks['pdfsig_raw'] = $pdfsigOut['raw'];
            return [
                'valid' => $pdfsigOut['valid'],
                'reason' => $pdfsigOut['reason'],
                'checks' => $checks,
                'signer_cert_pem' => $extract['cert_pem'],
                'signed_at' => $pdfsigOut['signed_at'],
            ];
        }
        $checks['pdfsig_available'] = false;

        // Fallback: openssl_pkcs7_verify via temp file.
        // NOTE: PDF signature adalah CMS detached over hash(/ByteRange),
        // sehingga openssl_pkcs7_verify butuh kita re-attach byte-range
        // sebagai "content". Ini bekerja untuk B-B baseline; TSA / LTV
        // TIDAK di-validate di jalur ini.
        $verifyRes = $this->fallbackPkcs7Verify($extract['signature_bytes'], $byteRange->computeHashedContent($pdfBytes), $options);
        $checks = array_merge($checks, $verifyRes['checks']);
        return [
            'valid' => $verifyRes['valid'] && $checks['byte_range_full_coverage'],
            'reason' => $verifyRes['reason'] . ($checks['byte_range_full_coverage'] ? '' : '; partial byte-range coverage'),
            'checks' => $checks,
            'signer_cert_pem' => $extract['cert_pem'],
            'signed_at' => isset($extract['sig_info']['signing_time']) ? (int) $extract['sig_info']['signing_time'] : null,
        ];
    }

    /**
     * Extract signer cert PEM dari CMS bytes (PKCS#7 DER / PEM).
     */
    private function extractCertPemFromPkcs7(string $pkcs7Bytes): string
    {
        $tmpIn = $this->mkTmp('ezpdfsig_in_');
        try {
            @file_put_contents($tmpIn, $pkcs7Bytes);
            @chmod($tmpIn, 0600);
            $certs = [];
            if (@openssl_pkcs7_read($tmpIn, $certs) === true) {
                if (!empty($certs[0]) && is_string($certs[0])) {
                    return $certs[0];
                }
            }
            // Fallback: shell-out `openssl pkcs7 -inform DER -print_certs`.
            $cmd = escapeshellarg($this->opensslPath) . ' pkcs7 -inform DER -print_certs -in ' . escapeshellarg($tmpIn);
            list($rc, $stdout, ) = $this->runShell($cmd, null);
            if ($rc === 0 && $stdout !== '') {
                if (preg_match('/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/', $stdout, $m)) {
                    return $m[0];
                }
            }
            return '';
        } finally {
            $this->safeUnlink($tmpIn);
        }
    }

    /**
     * Parse fields dari /Sig dict (Reason, Location, ContactInfo, /M signing time).
     *
     * @return array<string,mixed>
     */
    private function parseSigDictFields(string $pdfBytes): array
    {
        $out = [];
        // Cari dict /Type /Sig sekitar /ByteRange. PDF /Sig dict biasanya
        // muncul dalam kontekst yang sama.
        if (preg_match('/\/Reason\s*\(([^)]*)\)/', $pdfBytes, $m)) {
            $out['reason'] = $m[1];
        }
        if (preg_match('/\/Location\s*\(([^)]*)\)/', $pdfBytes, $m)) {
            $out['location'] = $m[1];
        }
        if (preg_match('/\/ContactInfo\s*\(([^)]*)\)/', $pdfBytes, $m)) {
            $out['contact_info'] = $m[1];
        }
        if (preg_match('/\/Name\s*\(([^)]*)\)/', $pdfBytes, $m)) {
            $out['name'] = $m[1];
        }
        if (preg_match('/\/SubFilter\s*\/([A-Za-z0-9._-]+)/', $pdfBytes, $m)) {
            $out['sub_filter'] = $m[1];
        }
        // /M (D:YYYYMMDDHHMMSS+HH'MM')
        if (preg_match("/\/M\s*\(D:(\d{14})/", $pdfBytes, $m)) {
            $ts = @strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2)
                . ' ' . substr($m[1], 8, 2) . ':' . substr($m[1], 10, 2) . ':' . substr($m[1], 12, 2) . ' UTC');
            if ($ts !== false) {
                $out['signing_time'] = $ts;
            }
        }
        return $out;
    }

    /**
     * Jalankan `pdfsig` (poppler-utils) via shell-out.
     * Return null kalau tools tidak tersedia atau parsing gagal.
     *
     * @return array{valid: bool, reason: string, signed_at: int|null, raw: string}|null
     */
    private function tryPdfsigVerify(string $pdfBytes): ?array
    {
        $tmpIn = $this->mkTmp('ezpdfsig_verify_');
        try {
            if (@file_put_contents($tmpIn, $pdfBytes) === false) {
                return null;
            }
            @chmod($tmpIn, 0600);
            $cmd = escapeshellarg($this->pdfsigPath) . ' ' . escapeshellarg($tmpIn);
            list($rc, $stdout, $stderr) = $this->runShell($cmd, null);
            // Kalau binary tidak ada, biasanya rc bukan 0 dan stderr berisi 'command not found'.
            if ($rc !== 0 && (strpos((string) $stderr, 'not found') !== false || strpos((string) $stderr, 'not recognized') !== false)) {
                return null;
            }
            if ($stdout === '') {
                return null;
            }
            $raw = $stdout;
            $valid = (strpos($raw, 'Signature Validation: Signature is Valid') !== false)
                || (strpos($raw, 'Signature validates.') !== false)
                || (strpos($raw, 'Signature is Valid.') !== false);
            $reason = $valid ? 'pdfsig: signature valid' : 'pdfsig: signature invalid or untrusted';
            $signedAt = null;
            if (preg_match('/Signing Time:\s*(.+)/', $raw, $m)) {
                $ts = @strtotime(trim($m[1]));
                if ($ts !== false) {
                    $signedAt = $ts;
                }
            }
            return [
                'valid' => $valid,
                'reason' => $reason,
                'signed_at' => $signedAt,
                'raw' => $raw,
            ];
        } finally {
            $this->safeUnlink($tmpIn);
        }
    }

    /**
     * Fallback verify via openssl_pkcs7_verify.
     *
     * @param string $pkcs7DerOrPem CMS signature bytes
     * @param string $content       byte-range concat content
     * @param array<string,mixed> $options
     * @return array{valid: bool, reason: string, checks: array<string,mixed>}
     */
    private function fallbackPkcs7Verify(string $pkcs7Bytes, string $content, array $options): array
    {
        $tmpSig = $this->mkTmp('ezpdfsig_sig_');
        $tmpContent = $this->mkTmp('ezpdfsig_ct_');
        try {
            // openssl_pkcs7_verify menerima input file S/MIME (PEM). Kalau
            // input kita DER, konversi ke PEM inline.
            $isPem = strpos($pkcs7Bytes, '-----BEGIN') !== false;
            $sigPem = $isPem ? $pkcs7Bytes
                : "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode($pkcs7Bytes), 64, "\n") . "-----END PKCS7-----\n";
            if (@file_put_contents($tmpSig, $sigPem) === false) {
                return ['valid' => false, 'reason' => 'temp write failed', 'checks' => ['fallback' => false]];
            }
            @chmod($tmpSig, 0600);
            if (@file_put_contents($tmpContent, $content) === false) {
                return ['valid' => false, 'reason' => 'temp write failed', 'checks' => ['fallback' => false]];
            }
            @chmod($tmpContent, 0600);

            $flags = PKCS7_BINARY | PKCS7_NOVERIFY; // chain check tergantung ca_bundle
            $caArg = [];
            if (isset($options['ca_bundle']) && is_string($options['ca_bundle']) && is_file($options['ca_bundle'])) {
                $flags = PKCS7_BINARY;
                $caArg = [$options['ca_bundle']];
            }
            // Drain error queue.
            while (openssl_error_string() !== false) { /* noop */ }

            // openssl_pkcs7_verify($filename, $flags, $signer_cert=null, $ca_info=[],
            //                      $extra_certs=null, $content=null, $output=null)
            // PHP API BUG: $content param di sebagian versi adalah PATH INPUT,
            // di versi lain adalah output. Untuk detached CMS, kita bypass
            // ini via shell-out ke openssl cms/smime -verify -content.
            $cmd = escapeshellarg($this->opensslPath)
                . ' cms -verify -binary -inform PEM'
                . ' -in ' . escapeshellarg($tmpSig)
                . ' -content ' . escapeshellarg($tmpContent);
            if (!empty($caArg)) {
                $cmd .= ' -CAfile ' . escapeshellarg($caArg[0]);
            } else {
                $cmd .= ' -noverify';
            }
            $cmd .= ' -out ' . escapeshellarg($tmpContent . '.out');
            list($rc, , $stderr) = $this->runShell($cmd, null);
            $this->safeUnlink($tmpContent . '.out');
            $valid = ($rc === 0);
            return [
                'valid' => $valid,
                'reason' => $valid ? 'openssl cms verify ok' : 'openssl cms verify failed: ' . trim((string) $stderr),
                'checks' => [
                    'fallback' => true,
                    'chain_check' => !empty($caArg),
                    'openssl_rc' => $rc,
                ],
            ];
        } finally {
            $this->safeUnlink($tmpSig);
            $this->safeUnlink($tmpContent);
        }
    }

    /**
     * Jalankan shell command, return [exitCode, stdout, stderr].
     *
     * @param string      $cmd   commandline (harus sudah di-escapeshellarg)
     * @param string|null $stdin optional data ke stdin process
     * @return array{0:int,1:string,2:string}
     */
    protected function runShell(string $cmd, ?string $stdin): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [-1, '', 'proc_open failed'];
        }
        if ($stdin !== null && $stdin !== '') {
            @fwrite($pipes[0], $stdin);
        }
        @fclose($pipes[0]);
        $stdout = @stream_get_contents($pipes[1]);
        @fclose($pipes[1]);
        $stderr = @stream_get_contents($pipes[2]);
        @fclose($pipes[2]);
        $rc = proc_close($proc);
        return [(int) $rc, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
    }

    /**
     * @throws EzdocException
     */
    private function mkTmp(string $prefix): string
    {
        $path = @tempnam($this->tempDir, $prefix);
        if ($path === false) {
            throw new EzdocException('OpensslPdfSigner: tempnam() failed in ' . $this->tempDir);
        }
        return $path;
    }

    private function safeUnlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
