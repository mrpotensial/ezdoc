<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

/**
 * Where binary objects live.
 *
 * Two implementations ship: `DatabaseBlobStore` keeps the bytes in a column,
 * `DiskBlobStore` keeps them on the filesystem. Both share the same metadata row,
 * so moving between them is a config change and a copy — not a schema change and
 * not a rewrite. That separation is the part worth borrowing from ActiveStorage:
 * the record is the source of truth for identity, the service is swappable.
 *
 * Which one is right depends on object size, and the answer is measured rather
 * than assumed. "To BLOB or Not To BLOB" (Sears, van Ingen, Gray, 2006) puts the
 * crossover at roughly 256 KB: below it the database wins, above ~1 MB the
 * filesystem does. Normalised signature PNGs measure ~3.5 KB — far below — where
 * a 4 KB filesystem block plus an inode per object costs more space than the
 * packed column, and every read becomes its own open/read/close.
 *
 * So the default is the database backend, and the disk backend exists for when
 * the objects are not signature-sized.
 */
interface BlobStore
{
    /**
     * Store bytes and return the resulting blob.
     *
     * Idempotent by construction: the key is the content hash, so storing the
     * same bytes twice yields the same key and must not duplicate anything.
     *
     * @throws \Ezdoc\Exceptions\EzdocException when the bytes cannot be stored
     */
    public function put(string $bytes, string $contentType): Blob;

    /**
     * Bytes for a key, or null when the object is unknown.
     *
     * Implementations MUST verify the bytes still hash to the key and treat a
     * mismatch as absence rather than returning them. Returning content that does
     * not match its key would defeat the reason the key is a hash.
     */
    public function get(string $key): ?string;

    /** Metadata without reading the bytes. Null when unknown. */
    public function find(string $key): ?Blob;

    public function exists(string $key): bool;

    /**
     * Remove an object.
     *
     * Callers must not call this because one document stopped referencing the
     * object — content addressing means several documents can share one key, and
     * the same signature image genuinely recurs. Reachability is decided by the
     * purge routine, not by an individual save.
     *
     * @return bool false when the object was already absent
     */
    public function delete(string $key): bool;

    /** Name recorded on the blob row: 'database' | 'disk'. */
    public function name(): string;
}
