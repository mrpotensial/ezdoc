<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

use Ezdoc\Db\Connection;
use Ezdoc\Exceptions\EzdocException;

/**
 * Picks the backend the host asked for.
 *
 * Configuration reaches this the same way the signing key does — a constant, or
 * the environment. That precedent exists because the code that needs the setting
 * runs deep inside action files which never see the host's config object, and
 * inventing a second mechanism for the same problem would leave two places to
 * look when a setting appears not to apply.
 *
 *   EZDOC_STORAGE_BACKEND   'database' (default) | 'disk'
 *   EZDOC_STORAGE_PATH      absolute path; required when the backend is 'disk'
 *
 * The default is deliberate, not an accident of ordering. At the size signature
 * PNGs actually are (~3.5 KB after normalisation) the database backend is the one
 * that measures better — see BlobStore for the numbers. A host storing something
 * larger sets the constant and gets the filesystem.
 */
final class BlobStoreFactory
{
    public const BACKEND_DATABASE = DatabaseBlobStore::NAME;
    public const BACKEND_DISK = DiskBlobStore::NAME;

    /** @var array<string,BlobStore> Per-process, keyed by backend+root */
    private static $cache = [];

    /**
     * @throws EzdocException when the host asks for a backend it has not configured
     */
    public static function make(Connection $db, ?string $backend = null, ?string $diskRoot = null): BlobStore
    {
        $backend = $backend ?? self::configuredBackend();

        if ($backend === self::BACKEND_DISK) {
            $root = $diskRoot ?? self::configuredDiskRoot();
            if ($root === null) {
                // Refused rather than quietly falling back to the database.
                // A silent fallback would put images somewhere the operator did
                // not intend and did not back up, and the mistake would only
                // surface as a storage-size surprise months later.
                throw new EzdocException(
                    'EZDOC_STORAGE_BACKEND is "disk" but EZDOC_STORAGE_PATH is not set. '
                    . 'Point it at a writable directory OUTSIDE the document root — signature '
                    . 'images are not public assets, and as static files anyone holding the URL '
                    . 'can read them.'
                );
            }

            $cacheKey = self::BACKEND_DISK . '|' . $root;

            return self::$cache[$cacheKey] ?? (self::$cache[$cacheKey] = new DiskBlobStore($db, $root));
        }

        if ($backend !== self::BACKEND_DATABASE) {
            throw new EzdocException(
                'Unknown storage backend "' . $backend . '". Known backends: '
                . self::BACKEND_DATABASE . ', ' . self::BACKEND_DISK . '.'
            );
        }

        return self::$cache[self::BACKEND_DATABASE]
            ?? (self::$cache[self::BACKEND_DATABASE] = new DatabaseBlobStore($db));
    }

    /**
     * Penyimpan untuk dokumen yang dibekukan — **default-nya disk**.
     *
     * Bawaan umum di atas dipilih untuk gambar tanda tangan: ~3,5 KB, jauh di
     * bawah titik silang ~256 KB, di mana kolom database menang. Dokumen yang
     * sudah dirender bukan itu. Satu PDF bertanda tangan berukuran ratusan KB
     * sampai beberapa MB — sisi lain titik silang yang sama.
     *
     * Karena itu bawaannya dibalik di sini, bukan diwarisi. Memakai satu
     * pengaturan untuk dua jenis benda yang berbeda dua orde besaran berarti
     * salah satunya pasti salah tempat.
     *
     *   EZDOC_ARTIFACT_STORAGE_BACKEND  'disk' (bawaan) | 'database'
     *   EZDOC_ARTIFACT_STORAGE_PATH     path absolut; jatuh ke EZDOC_STORAGE_PATH
     *
     * `database` tetap boleh, tapi harus **dinyatakan**. Ada pemasangan yang
     * memang menginginkannya — tanpa berkas bersama antar-server, atau ingin
     * cadangannya satu transaksi dengan datanya. Yang tidak boleh adalah
     * kejadian itu berlangsung tanpa ada yang memilihnya.
     *
     * @throws EzdocException kalau disk dipilih tapi tidak ada path yang disetel
     */
    public static function forArtifacts(Connection $db): BlobStore
    {
        $backend = self::readSetting('EZDOC_ARTIFACT_STORAGE_BACKEND');
        $backend = $backend === null || $backend === '' ? self::BACKEND_DISK : strtolower($backend);

        if ($backend === self::BACKEND_DATABASE) {
            return self::make($db, self::BACKEND_DATABASE);
        }

        $root = self::readSetting('EZDOC_ARTIFACT_STORAGE_PATH');
        $root = $root === null || trim($root) === '' ? self::configuredDiskRoot() : trim($root);

        if ($root === null) {
            throw new EzdocException(
                'Dokumen beku disimpan di disk, tapi belum ada tempatnya. '
                . 'Setel EZDOC_ARTIFACT_STORAGE_PATH (atau EZDOC_STORAGE_PATH) ke direktori '
                . 'yang bisa ditulis DI LUAR document root — dokumen beku bukan aset publik, '
                . 'dan sebagai berkas statis siapa pun yang memegang URL-nya bisa membacanya. '
                . 'Kalau memang ingin di database, nyatakan EZDOC_ARTIFACT_STORAGE_BACKEND=database.'
            );
        }

        return self::make($db, self::BACKEND_DISK, $root);
    }

    public static function configuredBackend(): string
    {
        $raw = self::readSetting('EZDOC_STORAGE_BACKEND');

        return $raw === null || $raw === '' ? self::BACKEND_DATABASE : strtolower($raw);
    }

    public static function configuredDiskRoot(): ?string
    {
        $raw = self::readSetting('EZDOC_STORAGE_PATH');

        return $raw === null || trim($raw) === '' ? null : trim($raw);
    }

    /** Constant first, then environment — the same order SigningKey uses. */
    private static function readSetting(string $name): ?string
    {
        if (defined($name)) {
            return (string) constant($name);
        }

        $env = getenv($name);

        return is_string($env) ? $env : null;
    }

    /**
     * Drop the per-process cache.
     *
     * For tests, which change the configuration between cases. Production code
     * has no reason to call it — the whole point of the cache is that one request
     * builds one store.
     */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
