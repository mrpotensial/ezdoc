<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

use Ezdoc\Db\Connection;
use Ezdoc\Exceptions\EzdocException;

/**
 * Bytes on the filesystem, metadata in `ezdoc_blobs` with `content` NULL.
 *
 * The same split Rails ActiveStorage and Django's storage backends use: the row
 * owns the identity, the service owns the bytes. Built so that outgrowing
 * signature-sized objects is a config change rather than a migration.
 *
 * Two properties are not optional here, and both come from the plan's conditions
 * for putting signature images on disk at all:
 *
 * 1. **The checksum lives in the database.** It does, by construction — the
 *    filename *is* the SHA-256 of the content. A swapped file cannot pass
 *    `get()`, so tampering shows up as a missing object rather than as different
 *    bytes quietly served in place of the originals.
 *
 * 2. **Files sit outside the document root.** Signatures are not public assets.
 *    Served as static files, anyone holding the URL sees the signature, and URLs
 *    leak through browser history, proxy logs and referer headers. Reading them
 *    goes through an action that checks access first.
 *
 * What disk genuinely costs, stated rather than glossed: a backup must cover two
 * places and restore both to the same point in time, a write cannot be rolled
 * back with the transaction that produced it, and unreferenced files accumulate
 * until something purges them. ActiveStorage has the same problem and answers it
 * with a scheduled purge, which is what `BlobPurger` is for.
 */
final class DiskBlobStore implements BlobStore
{
    public const NAME = 'disk';

    /** @var Connection */
    private $db;

    /** @var string Absolute, no trailing separator */
    private $root;

    public function __construct(Connection $db, string $root)
    {
        // Spasi ikut dipangkas, bukan cuma pemisah path. Root berisi spasi akan
        // lolos pemeriksaan "tidak kosong" lalu jadi direktori bernama spasi di
        // direktori kerja proses — tempat yang tidak pernah dimaksudkan siapa pun,
        // dan baru ketahuan setelah ada gambar tanda tangan di sana.
        $root = rtrim(str_replace('\\', '/', trim($root)), '/');
        if ($root === '') {
            throw new EzdocException('DiskBlobStore requires a storage root path.');
        }

        $this->db = $db;
        $this->root = $root;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Two-character fan-out, e.g. `3f/3fa8b2…`.
     *
     * A single flat directory with tens of thousands of entries slows down
     * directory operations on most filesystems, and makes `ls` on a support call
     * unusable. Git, Docker and ActiveStorage all fan out for the same reason.
     *
     * The key is validated to 64 hex characters before it reaches this method, so
     * no caller-supplied string can walk out of the root.
     */
    private function pathFor(string $key): string
    {
        return $this->root . '/' . substr($key, 0, 2) . '/' . $key;
    }

    private static function validKey(string $key): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $key) === 1;
    }

    public function put(string $bytes, string $contentType): Blob
    {
        if ($bytes === '') {
            throw new EzdocException('Refusing to store an empty blob.');
        }

        $key = Blob::keyFor($bytes);
        $size = strlen($bytes);
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new EzdocException('Cannot create blob directory: ' . $dir);
            }

            // Written to a temporary name then renamed. A partial file under the
            // final name would be indistinguishable from a complete one, and
            // because the name is the content hash it would fail its own checksum
            // forever — a permanently broken object created by a single
            // interrupted write. rename() within a directory is atomic.
            $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.part';
            if (@file_put_contents($tmp, $bytes, LOCK_EX) !== $size) {
                @unlink($tmp);
                throw new EzdocException('Failed writing blob to disk: ' . $path);
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                // Lost a race with another writer? Identical bytes, so the object
                // is already correct and there is nothing to repair.
                if (!is_file($path)) {
                    throw new EzdocException('Failed finalising blob on disk: ' . $path);
                }
            }
        }

        $existing = $this->find($key);
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO ezdoc_blobs (`key`, checksum, byte_size, content_type, backend, content, created_at)'
                . ' VALUES (?, ?, ?, ?, ?, NULL, ' . $this->nowExpression() . ')',
                [$key, 'sha256:' . $key, $size, $contentType, self::NAME]
            );
        }

        return new Blob($key, $size, $contentType, self::NAME, $bytes);
    }

    public function get(string $key): ?string
    {
        if (!self::validKey($key)) {
            return null;
        }

        // The row is consulted first so a purged object stays gone even if a
        // stray file survives on disk. The database decides what exists.
        if (!$this->exists($key)) {
            return null;
        }

        $path = $this->pathFor($key);
        if (!is_file($path)) {
            @error_log('[ezdoc:storage] blob ' . $key . ' has a row but no file at ' . $path
                . ' — disk and database are out of sync (restore mismatch?)');
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        if (!Blob::verify($key, $bytes)) {
            @error_log('[ezdoc:storage] blob ' . $key . ' failed its own checksum — the file on disk was altered');
            return null;
        }

        return $bytes;
    }

    public function find(string $key): ?Blob
    {
        if (!self::validKey($key)) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT byte_size, content_type, backend FROM ezdoc_blobs'
            . ' WHERE `key` = ? AND deleted_at IS NULL LIMIT 1',
            [$key]
        );
        if ($row === null) {
            return null;
        }

        return new Blob(
            $key,
            (int) $row['byte_size'],
            (string) ($row['content_type'] ?? 'application/octet-stream'),
            (string) ($row['backend'] ?? self::NAME)
        );
    }

    public function exists(string $key): bool
    {
        return $this->find($key) !== null;
    }

    public function delete(string $key): bool
    {
        if (!self::validKey($key) || !$this->exists($key)) {
            return false;
        }

        // Row first. If the unlink fails the object is already unreachable, and a
        // leftover file is a cleanup problem; the reverse order would leave a row
        // pointing at nothing, which reads as corruption.
        $this->db->execute('DELETE FROM ezdoc_blobs WHERE `key` = ?', [$key]);
        @unlink($this->pathFor($key));

        return true;
    }

    /** Absolute path, for the purge routine and for diagnostics. */
    public function root(): string
    {
        return $this->root;
    }

    private function nowExpression(): string
    {
        return $this->db->grammar()->name() === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
    }
}
