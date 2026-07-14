<?php

declare(strict_types=1);

namespace Ezdoc\Http;

/**
 * Ezdoc\Http\AssetHandler — whitelisted static-asset streaming.
 *
 * Serves CSS/JS/img/font files from a whitelisted set of roots (default:
 * `ezdoc/assets`). Applies MIME map, immutable cache headers, weak ETag,
 * and — most importantly — realpath containment to defeat `../` escape
 * and symlink attacks.
 *
 * Security invariant: `realpath($root . '/' . $relPath)` MUST have `$root`
 * as a prefix. Any candidate that does not survive the check returns 404.
 *
 * PHP 7.4+ compatible.
 */
final class AssetHandler
{
    /** @var array<int,string> Absolute (realpath'd) roots we accept requests under. */
    private $roots;

    /** @var int Cache-Control max-age (seconds). */
    private $cacheTtl;

    /** @var array<string,string> Ext → MIME map. */
    private static $MIME = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'txt'   => 'text/plain; charset=utf-8',
    ];

    /**
     * @param array<int,string> $roots Directories under which assets may live.
     */
    public function __construct(array $roots, int $cacheTtl = 86400)
    {
        $resolved = [];
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $real = realpath($root);
            if ($real === false) {
                continue;
            }
            $resolved[] = rtrim($real, DIRECTORY_SEPARATOR);
        }
        $this->roots    = $resolved;
        $this->cacheTtl = $cacheTtl > 0 ? $cacheTtl : 86400;
    }

    /**
     * Serve $relPath through $res. Writes status + headers + stream marker;
     * caller then invokes $res->emit() to flush.
     */
    public function serve(string $relPath, RequestContext $req, ResponseWriter $res): void
    {
        // 1. Sanitize input — reject null-byte injection & empty path early.
        if ($relPath === '' || strpos($relPath, "\0") !== false) {
            $res->status(400)->html('Bad request', 400);
            return;
        }

        // 2. Resolve to a real absolute path within a whitelisted root.
        $abs = $this->resolve($relPath);
        if ($abs === null) {
            $res->status(404)->html('Asset not found', 404);
            return;
        }

        $mtime = @filemtime($abs);
        $size  = @filesize($abs);
        if ($mtime === false || $size === false) {
            $res->status(404)->html('Asset not found', 404);
            return;
        }

        $etag = 'W/"' . substr(hash('sha256', $abs . '|' . $mtime . '|' . $size), 0, 16) . '"';

        // 3. Conditional GET — 304 fast path.
        $ifNone = $req->header('If-None-Match');
        if ($ifNone !== null && $ifNone === $etag) {
            $res->status(304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=' . $this->cacheTtl);
            return;
        }

        // 4. Full response — pick MIME + headers + stream marker.
        $ext  = strtolower((string) pathinfo($abs, PATHINFO_EXTENSION));
        $mime = isset(self::$MIME[$ext]) ? self::$MIME[$ext] : 'application/octet-stream';

        $res->status(200)
            ->header('Cache-Control', 'public, max-age=' . $this->cacheTtl . ', immutable')
            ->header('ETag', $etag)
            ->header('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT')
            ->header('Content-Length', (string) $size)
            ->stream($abs, $mime);
    }

    /**
     * Resolve $relPath under any whitelisted root. Returns absolute path or
     * null if not found / containment fails.
     */
    private function resolve(string $relPath): ?string
    {
        $relPath = ltrim($relPath, "/\\");
        // Normalize slashes to platform separator for concat.
        $relPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath);

        foreach ($this->roots as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . $relPath;
            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }
            // Containment check — the actual security barrier.
            $prefix = $root . DIRECTORY_SEPARATOR;
            if (strncmp($real, $prefix, strlen($prefix)) !== 0 && $real !== $root) {
                continue;
            }
            if (is_file($real)) {
                return $real;
            }
        }
        return null;
    }

    /**
     * @return array<int,string> Realpath'd roots (for diagnostics / tests).
     */
    public function getRoots(): array
    {
        return $this->roots;
    }
}
