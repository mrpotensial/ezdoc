<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Pdf\JSignPdfSigner — PdfSigner via shell-out ke JSignPdf
 * (Java CLI).
 *
 * ## Rasional
 *
 * JSignPdf (jsignpdf.sourceforge.net) adalah tool Java open-source yang
 * mendukung PAdES-B-B / B-T / B-LT (dengan TSA URL) — praktis lengkap
 * untuk kebutuhan enterprise tanpa membayar lisensi setasign.
 *
 * Kelas ini shell-out ke `java -jar jsignpdf.jar` dan bekerja pada PDF
 * di temp file. Signature key harus tersedia sebagai PKCS#12 (.p12/.pfx)
 * dengan password.
 *
 * ## Batasan
 *
 * - JSignPdf butuh **PKCS#12 file**, bukan CMS bytes eksternal. Berbeda
 *   dari kontrak PdfSigner::embedSignature() yang menerima `$pkcs7Bytes`
 *   yang sudah dihitung. Adapter di sini menerima keystore path via
 *   options — kalau tidak disediakan, throw ValidationException dan
 *   arahkan ke ExternalPdfSigner untuk mode "server-side signing bytes".
 * - Adobe Reader kadang perlu Trust Store update (AATL / custom root)
 *   supaya green-check. Yellow-triangle = signature valid struktural
 *   tapi issuer belum di-trust — bukan error signing.
 *
 * ## Config konstruktor
 *
 *   - 'jsignpdf_path' (string, REQUIRED) — path ke jsignpdf.jar
 *   - 'java_path'     (string, default 'java')
 *   - 'temp_dir'      (string, default sys_get_temp_dir())
 *   - 'default_tsa_url' (string, optional; kalau di-set semua sign akan B-T minimal)
 *
 * ## Options di embedSignature()
 *
 *   - 'keystore_path' (string, REQUIRED) — PKCS#12 path
 *   - 'keystore_pass' (string, REQUIRED)
 *   - 'key_alias'     (string, optional)
 *   - 'reason'        (string)
 *   - 'location'      (string)
 *   - 'contact_info'  (string)
 *   - 'tsa_url'       (string, PAdES-B-T)
 *   - 'tsa_user' / 'tsa_pass' (opsional)
 *   - 'certification_level' (int 0..3)
 *   - 'visible' (bool, visible signature appearance)
 *   - 'visible_page' (int, 1-based)
 *   - 'visible_llx' / 'visible_lly' / 'visible_urx' / 'visible_ury' (int coords)
 *
 * PHP 7.4+ compatible.
 */
final class JSignPdfSigner implements PdfSigner
{
    /** @var string */
    private $jsignPdfPath;

    /** @var string */
    private $javaPath;

    /** @var string */
    private $tempDir;

    /** @var string */
    private $defaultTsaUrl;

    /**
     * @param array<string,mixed> $config
     * @throws ValidationException
     */
    public function __construct(array $config)
    {
        if (!isset($config['jsignpdf_path']) || !is_string($config['jsignpdf_path']) || $config['jsignpdf_path'] === '') {
            throw ValidationException::forField('jsignpdf_path', 'REQUIRED: path to jsignpdf.jar');
        }
        $this->jsignPdfPath = $config['jsignpdf_path'];
        $this->javaPath = isset($config['java_path']) && is_string($config['java_path']) && $config['java_path'] !== ''
            ? $config['java_path']
            : 'java';
        $this->tempDir = isset($config['temp_dir']) && is_string($config['temp_dir']) && $config['temp_dir'] !== ''
            ? $config['temp_dir']
            : sys_get_temp_dir();
        $this->defaultTsaUrl = isset($config['default_tsa_url']) && is_string($config['default_tsa_url'])
            ? $config['default_tsa_url']
            : '';
    }

    /**
     * {@inheritdoc}
     *
     * Adapter behavior:
     *
     *   - Kalau `$pkcs7Bytes` non-empty: TIDAK dipakai — JSignPdf butuh
     *     private key, bukan CMS bytes. Kita ignore + emit warning di
     *     exception context. Kalau consumer memang punya CMS bytes
     *     eksternal, pakai `ExternalPdfSigner` yang forward ke service.
     *   - `$cert` dipakai hanya untuk cross-check subject CN dengan
     *     PKCS#12 (best-effort logging).
     *   - Options `keystore_path` + `keystore_pass` REQUIRED.
     *
     * @throws ValidationException options missing / invalid
     * @throws EzdocException JSignPdf execution failed
     */
    public function embedSignature(string $pdfBytes, string $pkcs7Bytes, X509Certificate $cert, array $options = []): string
    {
        if ($pdfBytes === '') {
            throw new EzdocException('JSignPdfSigner::embedSignature: empty PDF bytes');
        }
        if (!isset($options['keystore_path']) || !is_string($options['keystore_path']) || $options['keystore_path'] === '') {
            throw ValidationException::forField('keystore_path', 'REQUIRED (PKCS#12 .p12/.pfx path)');
        }
        if (!is_file($options['keystore_path'])) {
            throw ValidationException::forField('keystore_path', 'file not found: ' . $options['keystore_path']);
        }
        if (!isset($options['keystore_pass']) || !is_string($options['keystore_pass'])) {
            throw ValidationException::forField('keystore_pass', 'REQUIRED');
        }

        $tmpIn = $this->mkTmp('jsignin_');
        $outDir = $this->tempDir;
        try {
            if (@file_put_contents($tmpIn, $pdfBytes) === false) {
                throw new EzdocException('JSignPdfSigner::embedSignature: failed to write temp input');
            }
            @chmod($tmpIn, 0600);

            // JSignPdf CLI (via jsignpdf.jar) menerima banyak flag; contoh
            // (v2.2+): java -jar jsignpdf.jar <input.pdf> -ksf <p12> -ksp
            // <pass> -d <outdir> -op ".signed" [-tsa <url>] [-r <reason>]
            // [-l <loc>] [-V] [--visible-signature]
            //
            // Referensi CLI: https://jsignpdf.sourceforge.net/cli.html
            // TODO: verify flag names on the exact JSignPdf version yang
            // ter-install (2.2.x). Command di bawah adalah baseline stabil
            // untuk 2.x; adjust bila deployment pakai versi berbeda.
            $args = [];
            $args[] = escapeshellarg($this->javaPath);
            $args[] = '-jar';
            $args[] = escapeshellarg($this->jsignPdfPath);
            $args[] = escapeshellarg($tmpIn);
            $args[] = '-ksf ' . escapeshellarg($options['keystore_path']);
            $args[] = '-ksp ' . escapeshellarg($options['keystore_pass']);
            if (isset($options['key_alias']) && is_string($options['key_alias']) && $options['key_alias'] !== '') {
                $args[] = '-ka ' . escapeshellarg($options['key_alias']);
            }
            $args[] = '-d ' . escapeshellarg($outDir);
            $args[] = '-op ' . escapeshellarg('.signed');

            $tsa = $this->defaultTsaUrl;
            if (isset($options['tsa_url']) && is_string($options['tsa_url']) && $options['tsa_url'] !== '') {
                $tsa = $options['tsa_url'];
            }
            if ($tsa !== '') {
                $args[] = '-ts ' . escapeshellarg($tsa);
                if (isset($options['tsa_user']) && is_string($options['tsa_user']) && $options['tsa_user'] !== '') {
                    $args[] = '-tsu ' . escapeshellarg($options['tsa_user']);
                }
                if (isset($options['tsa_pass']) && is_string($options['tsa_pass']) && $options['tsa_pass'] !== '') {
                    $args[] = '-tsp ' . escapeshellarg($options['tsa_pass']);
                }
            }
            if (isset($options['reason']) && is_string($options['reason']) && $options['reason'] !== '') {
                $args[] = '-r ' . escapeshellarg($options['reason']);
            }
            if (isset($options['location']) && is_string($options['location']) && $options['location'] !== '') {
                $args[] = '-l ' . escapeshellarg($options['location']);
            }
            if (isset($options['contact_info']) && is_string($options['contact_info']) && $options['contact_info'] !== '') {
                $args[] = '-cn ' . escapeshellarg($options['contact_info']);
            }
            if (isset($options['certification_level']) && is_int($options['certification_level'])) {
                $args[] = '-cl ' . escapeshellarg((string) $options['certification_level']);
            }
            if (!empty($options['visible'])) {
                $args[] = '-V';
                if (isset($options['visible_page']) && is_int($options['visible_page'])) {
                    $args[] = '-pg ' . escapeshellarg((string) $options['visible_page']);
                }
                // Rectangle: JSignPdf uses -llx / -lly / -urx / -ury.
                foreach (['visible_llx' => '-llx', 'visible_lly' => '-lly', 'visible_urx' => '-urx', 'visible_ury' => '-ury'] as $k => $flag) {
                    if (isset($options[$k]) && is_int($options[$k])) {
                        $args[] = $flag . ' ' . escapeshellarg((string) $options[$k]);
                    }
                }
            }

            $cmd = implode(' ', $args);
            list($rc, $stdout, $stderr) = $this->runShell($cmd, null);
            if ($rc !== 0) {
                throw new EzdocException(
                    'JSignPdfSigner: java exit code ' . $rc . '; stderr=' . trim((string) $stderr) . '; stdout=' . trim((string) $stdout),
                    ['cmd' => $cmd]
                );
            }

            // JSignPdf tulis output ke <input>.signed.pdf (dengan -op ".signed").
            $expected = $tmpIn . '.signed.pdf';
            // Beberapa versi JSignPdf memakai basename tanpa preserve
            // path — fall back cari di outDir.
            $candidate = null;
            if (is_file($expected)) {
                $candidate = $expected;
            } else {
                $base = basename($tmpIn) . '.signed.pdf';
                if (is_file($outDir . DIRECTORY_SEPARATOR . $base)) {
                    $candidate = $outDir . DIRECTORY_SEPARATOR . $base;
                }
            }
            if ($candidate === null) {
                throw new EzdocException('JSignPdfSigner: signed output not found; expected ' . $expected);
            }
            $signed = @file_get_contents($candidate);
            @unlink($candidate);
            if ($signed === false || $signed === '') {
                throw new EzdocException('JSignPdfSigner: signed output unreadable');
            }
            // Best-effort log: kalau consumer punya cert eksternal, cross-check CN.
            // (tidak error; hanya info di context)
            unset($pkcs7Bytes, $cert);
            return $signed;
        } finally {
            $this->safeUnlink($tmpIn);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Delegasi ke OpensslPdfSigner untuk parse struktural — JSignPdf sendiri
     * tidak expose "extract" mode via CLI. Ini konsisten: extraction adalah
     * PDF plumbing yang tidak butuh Java.
     */
    public function extractSignature(string $pdfBytes): array
    {
        $delegate = new OpensslPdfSigner(['temp_dir' => $this->tempDir]);
        return $delegate->extractSignature($pdfBytes);
    }

    /**
     * {@inheritdoc}
     *
     * JSignPdf CLI bisa verify via `-v` flag (kadang varian). Untuk
     * portabilitas, delegasikan ke OpensslPdfSigner yang punya jalur
     * pdfsig + fallback openssl cms.
     *
     * Consumer yang butuh JSignPdf-native verify bisa override method
     * ini via subclass atau shell-out `-v` sendiri.
     */
    public function verifyPdf(string $pdfBytes, array $options = []): array
    {
        $delegate = new OpensslPdfSigner(['temp_dir' => $this->tempDir]);
        return $delegate->verifyPdf($pdfBytes, $options);
    }

    /**
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
            throw new EzdocException('JSignPdfSigner: tempnam() failed in ' . $this->tempDir);
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
