<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

/**
 * Bytes in the database AND on disk — the backend for moving between the two.
 *
 * Moving objects from the `content` column to files is not a switch that can be
 * flipped while something else still reads the column. An older installation
 * sharing the same database keeps reading `ezdoc_blobs.content`; the moment a new
 * object is written with `content` NULL, that installation shows a missing
 * signature. And the disk backend on its own cannot read the objects already in
 * the column — switching straight to it would make every existing signature
 * disappear.
 *
 * So the move happens the way online data migrations are done — expand, migrate,
 * contract (Stripe's "dual writing → backfill → read from new → stop writing old"):
 *
 *   1. **expand** — this store. Every write goes to the column *and* to a file.
 *      The row is written exactly as DatabaseBlobStore writes it, so anything
 *      that reads the column cannot tell the difference.
 *   2. **migrate** — `cli/mirror-blobs-to-disk.php` copies the objects already in
 *      the column to files. It reads the table and never writes to it.
 *   3. **contract** — later, once nothing reads the column: switch to the disk
 *      backend and clear the column. Not done here, deliberately.
 *
 * Rails ActiveStorage ships the same shape as `MirrorService` ("keeps multiple
 * services in sync … useful while migrating between services").
 *
 * ## Reads
 *
 * The file first, then the column. A file that is missing — an object written
 * by an installation that only knows the column — or that fails its checksum
 * falls through to the column, and the column's bytes are then written to the
 * file (read repair). The row decides what exists: an object whose row was
 * purged stays gone even if its file survives.
 *
 * ## A failed file write does not fail the save
 *
 * The column already holds the bytes, and it is the copy everything can read.
 * Refusing a signature because its second copy could not be written would trade
 * the signature for a storage detail. The failure is logged, and the next read
 * or the backfill writes the file.
 */
final class MirrorBlobStore implements BlobStore
{
    public const NAME = 'mirror';

    /** @var DatabaseBlobStore */
    private $database;

    /** @var DiskBlobStore */
    private $files;

    public function __construct(DatabaseBlobStore $database, DiskBlobStore $files)
    {
        $this->database = $database;
        $this->files = $files;
    }

    /**
     * 'mirror' — the configuration name.
     *
     * The row itself records 'database', because the bytes ARE in the column.
     * That keeps the `backend` column meaning what it always meant ("content
     * NULL ⇔ disk"), which is what the older reader relies on.
     */
    public function name(): string
    {
        return self::NAME;
    }

    public function put(string $bytes, string $contentType): Blob
    {
        $blob = $this->database->put($bytes, $contentType);

        try {
            $this->files->writeFile($bytes);
        } catch (\Throwable $e) {
            @error_log('[ezdoc:storage] mirror: blob ' . $blob->key() . ' saved to the database, '
                . 'but not to disk (' . $e->getMessage() . ') — the next read or the backfill will copy it');
        }

        return $blob;
    }

    public function get(string $key): ?string
    {
        if (!$this->database->exists($key)) {
            return null;
        }

        $bytes = $this->files->readFile($key);
        if ($bytes !== null) {
            return $bytes;
        }

        $bytes = $this->database->get($key);
        if ($bytes !== null) {
            try {
                $this->files->writeFile($bytes);
            } catch (\Throwable $e) {
                @error_log('[ezdoc:storage] mirror: read repair for blob ' . $key . ' failed: ' . $e->getMessage());
            }
        }

        return $bytes;
    }

    public function find(string $key): ?Blob
    {
        return $this->database->find($key);
    }

    public function exists(string $key): bool
    {
        return $this->database->exists($key);
    }

    public function delete(string $key): bool
    {
        $deleted = $this->database->delete($key);
        $this->files->deleteFile($key);

        return $deleted;
    }

    /** Where the file copies live, for the backfill and for diagnostics. */
    public function root(): string
    {
        return $this->files->root();
    }
}
