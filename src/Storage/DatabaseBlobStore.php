<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

use Ezdoc\Db\Connection;
use Ezdoc\Exceptions\EzdocException;

/**
 * Bytes in the `ezdoc_blobs.content` column.
 *
 * The default backend, and for signature-sized objects the measured better one —
 * see BlobStore for the numbers. What it buys over the filesystem is not speed:
 * it is that a write participates in the surrounding transaction, a backup covers
 * one place, and a restore lands on one point in time. A file written to disk
 * cannot be rolled back when the save that produced it fails.
 *
 * Objects still leave the document row, which is the point of the exercise. With
 * ROW_FORMAT=DYNAMIC an InnoDB row keeps variable-length columns inline while the
 * row fits in half a page, so a ~3.5 KB image sits in the clustered index and
 * every lookup of a document reads it whether or not the query asked for it.
 * Moving the bytes to their own table makes document rows narrow unconditionally,
 * regardless of what the images grow into.
 */
final class DatabaseBlobStore implements BlobStore
{
    public const NAME = 'database';

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function put(string $bytes, string $contentType): Blob
    {
        if ($bytes === '') {
            throw new EzdocException('Refusing to store an empty blob.');
        }

        $key = Blob::keyFor($bytes);
        $size = strlen($bytes);

        // Already there? Content addressing makes this the normal case for a
        // recurring image, and re-inserting would be both wasteful and a unique
        // key violation.
        $existing = $this->find($key);
        if ($existing !== null) {
            return new Blob($key, $existing->byteSize(), $existing->contentType(), self::NAME, $bytes);
        }

        $this->db->execute(
            'INSERT INTO ezdoc_blobs (`key`, checksum, byte_size, content_type, backend, content, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ' . $this->nowExpression() . ')',
            [$key, 'sha256:' . $key, $size, $contentType, self::NAME, $bytes]
        );

        return new Blob($key, $size, $contentType, self::NAME, $bytes);
    }

    public function get(string $key): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT content FROM ezdoc_blobs WHERE `key` = ? AND deleted_at IS NULL LIMIT 1',
            [$key]
        );
        if ($row === null || $row['content'] === null) {
            return null;
        }

        $bytes = (string) $row['content'];

        // Treated as absent, not returned. The key is a hash of the content; if
        // the two disagree the row was written around this class, and handing the
        // bytes back would make a content-addressed store lie about its content.
        if (!Blob::verify($key, $bytes)) {
            @error_log('[ezdoc:storage] blob ' . $key . ' failed its own checksum — content was altered outside this store');
            return null;
        }

        return $bytes;
    }

    public function find(string $key): ?Blob
    {
        if (preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
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
        if (preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
            return false;
        }
        if (!$this->exists($key)) {
            return false;
        }

        // Hard delete. Soft-deleting a blob would keep the bytes while claiming
        // they were removed — the opposite of what a deletion is for, and the
        // reason to run the purge in the first place.
        $this->db->execute('DELETE FROM ezdoc_blobs WHERE `key` = ?', [$key]);

        return true;
    }

    /**
     * SQLite has no NOW(); it spells the same thing CURRENT_TIMESTAMP.
     *
     * Written as SQL rather than a PHP timestamp so the value comes from one
     * clock — the database's — the way every other created_at in this schema does.
     */
    private function nowExpression(): string
    {
        return $this->db->grammar()->name() === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
    }
}
