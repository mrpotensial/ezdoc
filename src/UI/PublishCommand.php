<?php

declare(strict_types=1);

namespace Ezdoc\UI;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\UI\PublishCommand — programmatic API untuk copy library assets
 * (views, static assets, sample config) ke consumer app directory.
 *
 * Framework-agnostic. Tidak menyentuh DB — pure filesystem operation.
 *
 * ## Contoh
 *
 * ```php
 * $publisher = new PublishCommand('/abs/path/to/ezdoc');
 * $results = $publisher->publishViews('/var/www/app/resources/views/ezdoc', false);
 * foreach ($results as $r) {
 *     echo "[{$r['status']}] {$r['file']}\n";
 * }
 * ```
 *
 * ## Status values (return array)
 *
 * - `copied`  — file berhasil di-copy
 * - `skipped` — target sudah ada & $force = false
 * - `failed`  — copy gagal (permission, disk full, dll) — cek `reason`
 *
 * PHP 7.4+ compatible.
 */
final class PublishCommand
{
    /** @var string Absolute path ke ezdoc/ root folder (source). */
    private $libraryRoot;

    /**
     * Ignore patterns — files/dirs yang di-skip saat copy (glob-ish substrings).
     * Case-insensitive match terhadap basename.
     *
     * @var array<int,string>
     */
    private static $ignorePatterns = [
        '.DS_Store',
        'Thumbs.db',
        '.gitignore',
        '.gitkeep',
        '.git',
        '*.tmp',
        '*.swp',
        '*.bak',
        '*~',
    ];

    public function __construct(string $libraryRoot)
    {
        $libraryRoot = rtrim(str_replace('\\', '/', $libraryRoot), '/');
        if ($libraryRoot === '' || !is_dir($libraryRoot)) {
            throw ValidationException::forField(
                'libraryRoot',
                "Library root tidak valid atau bukan directory: {$libraryRoot}"
            );
        }
        $this->libraryRoot = $libraryRoot;
    }

    // ─── Publish operations ─────────────────────────────────────────────

    /**
     * Copy semua file di ezdoc/views/ ke $targetDir.
     *
     * @return array<int,array<string,string>> list of ['file'=>..., 'status'=>..., 'reason'=>?]
     */
    public function publishViews(string $targetDir, bool $force = false): array
    {
        return $this->copyDirectory(
            $this->libraryRoot . '/views',
            $this->sanitizeTargetDir($targetDir),
            $force
        );
    }

    /**
     * Copy semua file di ezdoc/assets/ ke $targetDir.
     *
     * @return array<int,array<string,string>>
     */
    public function publishAssets(string $targetDir, bool $force = false): array
    {
        return $this->copyDirectory(
            $this->libraryRoot . '/assets',
            $this->sanitizeTargetDir($targetDir),
            $force
        );
    }

    /**
     * Copy sample config (config.sample.php atau config.php) ke $targetDir.
     *
     * @return array<int,array<string,string>>
     */
    public function publishConfig(string $targetDir, bool $force = false): array
    {
        $targetDir = $this->sanitizeTargetDir($targetDir);
        $this->ensureWritable($targetDir);

        $candidates = [
            $this->libraryRoot . '/config.sample.php',
            $this->libraryRoot . '/config.php',
        ];

        $results = [];
        foreach ($candidates as $src) {
            if (!is_file($src)) continue;
            $dstName = basename($src) === 'config.sample.php' ? 'config.sample.php' : 'ezdoc.config.php';
            $dst = $targetDir . '/' . $dstName;
            $results[] = $this->copyFile($src, $dst, $force);
            break; // ambil first available saja
        }

        if ($results === []) {
            throw new NotFoundException(
                'Sample config tidak ditemukan di library root: ' . $this->libraryRoot
            );
        }
        return $results;
    }

    /**
     * Copy views + assets + config sekaligus.
     * Sub-target dibuat otomatis: $targetDir/views, $targetDir/assets, $targetDir/config.
     *
     * @return array<int,array<string,string>>
     */
    public function publishAll(string $targetDir, bool $force = false): array
    {
        $targetDir = $this->sanitizeTargetDir($targetDir);
        $this->ensureWritable($targetDir);

        $results = [];
        if (is_dir($this->libraryRoot . '/views')) {
            $results = array_merge($results, $this->publishViews($targetDir . '/views', $force));
        }
        if (is_dir($this->libraryRoot . '/assets')) {
            $results = array_merge($results, $this->publishAssets($targetDir . '/assets', $force));
        }
        if (is_file($this->libraryRoot . '/config.sample.php') || is_file($this->libraryRoot . '/config.php')) {
            $results = array_merge($results, $this->publishConfig($targetDir . '/config', $force));
        }
        return $results;
    }

    /**
     * List semua file yang bisa di-publish (dry-run inventory).
     *
     * @return array<int,array<string,string>> list of ['source'=>abs, 'targetSuggestion'=>rel, 'type'=>'view'|'asset'|'config']
     */
    public function listPublishable(): array
    {
        $out = [];

        foreach ($this->scanFiles($this->libraryRoot . '/views') as $abs) {
            $out[] = [
                'source' => $abs,
                'targetSuggestion' => 'views/' . $this->relativeTo($abs, $this->libraryRoot . '/views'),
                'type' => 'view',
            ];
        }
        foreach ($this->scanFiles($this->libraryRoot . '/assets') as $abs) {
            $out[] = [
                'source' => $abs,
                'targetSuggestion' => 'assets/' . $this->relativeTo($abs, $this->libraryRoot . '/assets'),
                'type' => 'asset',
            ];
        }
        foreach (['config.sample.php', 'config.php'] as $cfg) {
            $abs = $this->libraryRoot . '/' . $cfg;
            if (is_file($abs)) {
                $out[] = [
                    'source' => $abs,
                    'targetSuggestion' => 'config/' . ($cfg === 'config.sample.php' ? 'config.sample.php' : 'ezdoc.config.php'),
                    'type' => 'config',
                ];
                break;
            }
        }
        return $out;
    }

    // ─── Internal helpers ───────────────────────────────────────────────

    /**
     * Sanitize & normalize target dir: no path traversal, absolute path only.
     */
    private function sanitizeTargetDir(string $dir): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            throw ValidationException::forField('targetDir', 'Target directory tidak boleh kosong.');
        }
        // Normalize separators
        $dir = str_replace('\\', '/', $dir);
        // Reject path traversal segments
        $segments = explode('/', $dir);
        foreach ($segments as $seg) {
            if ($seg === '..') {
                throw ValidationException::forField(
                    'targetDir',
                    'Target directory mengandung path traversal (..) — tidak diizinkan.'
                );
            }
        }
        // Require absolute path (Unix / atau Windows drive letter)
        $isAbs = (isset($dir[0]) && $dir[0] === '/')
              || (bool) preg_match('#^[A-Za-z]:/#', $dir);
        if (!$isAbs) {
            throw ValidationException::forField(
                'targetDir',
                "Target directory harus absolute path: {$dir}"
            );
        }
        return rtrim($dir, '/');
    }

    /**
     * Ensure directory exists & writable — buat kalau perlu.
     */
    private function ensureWritable(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw ValidationException::forField(
                    'targetDir',
                    "Gagal buat directory: {$dir}"
                );
            }
        }
        if (!is_writable($dir)) {
            throw ValidationException::forField(
                'targetDir',
                "Directory tidak writable: {$dir}"
            );
        }
    }

    /**
     * Recursive copy — return list status per file.
     *
     * @return array<int,array<string,string>>
     */
    private function copyDirectory(string $srcDir, string $dstDir, bool $force): array
    {
        if (!is_dir($srcDir)) {
            throw new NotFoundException("Source directory tidak ditemukan: {$srcDir}");
        }
        $this->ensureWritable($dstDir);

        $results = [];
        foreach ($this->scanFiles($srcDir) as $srcFile) {
            $rel = $this->relativeTo($srcFile, $srcDir);
            $dstFile = $dstDir . '/' . $rel;
            $dstParent = dirname($dstFile);
            if (!is_dir($dstParent)) {
                if (!@mkdir($dstParent, 0755, true) && !is_dir($dstParent)) {
                    $results[] = [
                        'file' => $dstFile,
                        'status' => 'failed',
                        'reason' => "Gagal buat parent directory: {$dstParent}",
                    ];
                    continue;
                }
            }
            $results[] = $this->copyFile($srcFile, $dstFile, $force);
        }
        return $results;
    }

    /**
     * Copy single file dengan status tracking.
     *
     * @return array<string,string>
     */
    private function copyFile(string $src, string $dst, bool $force): array
    {
        if (!is_file($src)) {
            return [
                'file' => $dst,
                'status' => 'failed',
                'reason' => "Source file hilang: {$src}",
            ];
        }
        if (file_exists($dst) && !$force) {
            return [
                'file' => $dst,
                'status' => 'skipped',
                'reason' => 'Target sudah ada — pakai --force untuk overwrite.',
            ];
        }
        if (!@copy($src, $dst)) {
            $err = error_get_last();
            return [
                'file' => $dst,
                'status' => 'failed',
                'reason' => isset($err['message']) ? $err['message'] : 'copy() gagal (unknown)',
            ];
        }
        return [
            'file' => $dst,
            'status' => 'copied',
            'reason' => '',
        ];
    }

    /**
     * Recursive scan — return absolute file paths, filter by ignore patterns.
     *
     * @return array<int,string>
     */
    private function scanFiles(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $entry) {
            if (!$entry->isFile()) continue;
            $abs = str_replace('\\', '/', $entry->getPathname());
            if ($this->isIgnored($abs)) continue;
            $out[] = $abs;
        }
        sort($out);
        return $out;
    }

    /**
     * Match against ignore patterns — check basename of any segment.
     */
    private function isIgnored(string $absPath): bool
    {
        $parts = explode('/', $absPath);
        foreach ($parts as $seg) {
            if ($seg === '') continue;
            foreach (self::$ignorePatterns as $pat) {
                if (fnmatch($pat, $seg, FNM_CASEFOLD)) return true;
            }
        }
        return false;
    }

    /**
     * Compute relative path of $abs against $base (both normalized to /).
     */
    private function relativeTo(string $abs, string $base): string
    {
        $abs = str_replace('\\', '/', $abs);
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if (strpos($abs, $base . '/') === 0) {
            return substr($abs, strlen($base) + 1);
        }
        return basename($abs);
    }
}
