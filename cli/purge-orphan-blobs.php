<?php

declare(strict_types=1);

/**
 * CLI — remove stored objects that no document references any more.
 *
 * ## Usage
 *
 * ```
 * php ezdoc/cli/purge-orphan-blobs.php             # dry run, default
 * php ezdoc/cli/purge-orphan-blobs.php apply
 * ```
 *
 * ## Why this exists rather than a foreign key
 *
 * Objects are addressed by their content, so one object can be referenced by any
 * number of documents — the same signature image genuinely recurs. There is no
 * single parent row to hang a cascade on, so reachability has to be computed by
 * scanning references. Rails ActiveStorage has exactly this shape and exactly
 * this answer: `Blob.unattached`, purged on a schedule.
 *
 * ## Grace period
 *
 * Objects newer than the grace period are kept even when nothing references them.
 * A signature is stored during a save that can still be rejected afterwards — by
 * the §7b content-freeze guard, by a validation failure — and a purge running in
 * that window would delete an object a retry is about to reference. Time is a
 * cheap way to stay out of that race.
 *
 * Deleted documents are counted as referencing their objects, including
 * soft-deleted ones. A soft delete is reversible; purging the images it points at
 * would quietly make it not.
 *
 * ## Every table that references objects
 *
 * Signature slots are not the only referrer. Frozen display artifacts
 * (`ezdoc_document_artifacts.blob_key`) are objects too — and they are evidence
 * with a retention period. An earlier version collected references from
 * `signature_values` only, so `apply` would have deleted every frozen PDF older
 * than the grace period. Superseded cycles count as referenced: they answer
 * "what did the first signer see".
 */

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$mode = $argv[1] ?? 'dry-run';
if (!in_array($mode, ['dry-run', 'apply'], true)) {
    fwrite(STDERR, "Usage: php purge-orphan-blobs.php [dry-run|apply]\n");
    exit(2);
}
$apply = $mode === 'apply';

/** Hours an unreferenced object is kept before it can be purged. */
$graceHours = (int) (getenv('EZDOC_BLOB_GRACE_HOURS') ?: 48);

// A caller may hand over a connection (the tests do); otherwise the host's
// bootstrap supplies one.
if (!isset($db) || !($db instanceof \Ezdoc\Db\Connection)) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $bootstrap = getenv('EZDOC_BOOTSTRAP') ?: '';
        if ($bootstrap === '' || !is_file($bootstrap)) {
            fwrite(STDERR, "No database handle. Point EZDOC_BOOTSTRAP at the file that creates \$conn.\n");
            exit(2);
        }
        require_once $bootstrap;
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        fwrite(STDERR, "The bootstrap file did not produce a mysqli \$conn.\n");
        exit(2);
    }

    $db = new \Ezdoc\Db\Mysqli\MysqliConnection($conn);
}

if ((new \Ezdoc\Db\Schema\ColumnIntrospector($db))->columns('ezdoc_blobs') === []) {
    fwrite(STDERR, "Table `ezdoc_blobs` does not exist — nothing to purge.\n");
    exit(0);
}

$store = \Ezdoc\Storage\BlobStoreFactory::make($db);
$diskRoot = \Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot();
$files = $diskRoot !== null ? new \Ezdoc\Storage\DiskBlobStore($db, $diskRoot) : null;

printf("Backend  : %s\n", $store->name());
printf("Grace    : %d hours\n", $graceHours);
printf("Mode     : %s\n\n", $apply ? 'APPLY — objects will be deleted' : 'dry run — nothing is deleted');

// Every key any document mentions. Soft-deleted documents included on purpose:
// a soft delete is reversible, and purging what it points at would make it not.
$referenced = [];
foreach ($db->fetchAll('SELECT signature_values FROM ezdoc_documents WHERE signature_values IS NOT NULL') as $row) {
    $values = json_decode((string) $row['signature_values'], true);
    if (!is_array($values)) {
        continue;
    }
    foreach ($values as $value) {
        if (!is_string($value)) {
            continue;
        }
        $key = \Ezdoc\Storage\Blob::keyFromReference($value);
        if ($key !== null) {
            $referenced[$key] = true;
        }
    }
}

$bySignatures = count($referenced);

// Frozen display artifacts — every cycle, superseded ones included.
if ((new \Ezdoc\Db\Schema\ColumnIntrospector($db))->columns('ezdoc_document_artifacts') !== []) {
    foreach ($db->fetchAll('SELECT blob_key FROM ezdoc_document_artifacts') as $row) {
        $key = (string) $row['blob_key'];
        if (preg_match('/^[0-9a-f]{64}$/', $key) === 1) {
            $referenced[$key] = true;
        }
    }
}

printf("Referenced by signature slots: %d objects\n", $bySignatures);
printf("Referenced in total (incl. frozen artifacts): %d objects\n", count($referenced));

$cutoff = (new \DateTimeImmutable('-' . $graceHours . ' hours'))->format('Y-m-d H:i:s');
$candidates = $db->fetchAll(
    'SELECT `key`, byte_size, created_at FROM ezdoc_blobs'
    . ' WHERE deleted_at IS NULL AND created_at < ? ORDER BY created_at',
    [$cutoff]
);

$orphans = 0;
$orphanBytes = 0;
$failed = 0;

foreach ($candidates as $row) {
    $key = (string) $row['key'];
    if (isset($referenced[$key])) {
        continue;
    }

    $orphans++;
    $orphanBytes += (int) $row['byte_size'];

    if (!$apply) {
        printf("  %s  %6.1f KB  created %s\n",
            substr($key, 0, 16) . '…', ((int) $row['byte_size']) / 1024, $row['created_at']);
        continue;
    }

    if ($store->delete($key)) {
        // Salinan berkasnya ikut, kalau ada: di mode cermin setiap objek kolom
        // juga punya berkas. Barisnya yang menentukan objek itu ada, jadi berkas
        // yang tertinggal tidak terbaca lagi — tapi tetap memakan tempat.
        if ($files !== null) {
            $files->deleteFile($key);
        }
        printf("  %s  deleted\n", substr($key, 0, 16) . '…');
    } else {
        $failed++;
        printf("  %s  FAILED\n", substr($key, 0, 16) . '…');
    }
}

echo "\n";
printf("Unreferenced past the grace period: %d objects, %.1f KB%s\n",
    $orphans, $orphanBytes / 1024, $apply ? ' — deleted' : ' (would delete)');
if ($failed > 0) {
    printf("Failed: %d\n", $failed);
}
if (!$apply && $orphans > 0) {
    echo "\nNothing was deleted. Re-run with `apply` when the list looks right.\n";
}

exit($failed > 0 ? 1 : 0);
