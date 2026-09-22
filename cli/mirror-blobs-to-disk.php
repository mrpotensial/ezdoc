<?php

declare(strict_types=1);

/**
 * CLI — copy the objects stored in `ezdoc_blobs.content` to files.
 *
 * ```
 * EZDOC_BOOTSTRAP=/path/to/host-bootstrap.php EZDOC_STORAGE_PATH=/path/to/storage \
 *   php ezdoc/cli/mirror-blobs-to-disk.php            # dry run, default
 * ... php ezdoc/cli/mirror-blobs-to-disk.php apply
 * ```
 *
 * The "migrate" step of expand → migrate → contract (see MirrorBlobStore).
 *
 * **It never writes to the database.** Rows, the `content` column and the
 * documents stay exactly as they are, so an installation that still reads the
 * column keeps working unchanged. Clearing the column is the later "contract"
 * step, and it is not here.
 *
 * Every object is checked against its key before it is copied, and read back
 * after. A row whose content does not match its key is reported and left alone:
 * copying it would only give a corrupt object a second home.
 *
 * Run it as the web server's user, so the files belong to the process that will
 * read them. Safe to run again — objects already on disk are skipped.
 */

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$mode = $argv[1] ?? 'dry-run';
if (!in_array($mode, ['dry-run', 'apply'], true)) {
    fwrite(STDERR, "Usage: php mirror-blobs-to-disk.php [dry-run|apply]\n");
    exit(2);
}
$apply = $mode === 'apply';

// A caller may hand over a connection (the tests do); otherwise the host's
// bootstrap supplies one, the same way the other CLI scripts here reach it.
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
    fwrite(STDERR, "Table `ezdoc_blobs` does not exist — nothing to copy.\n");
    exit(0);
}

$diskRoot = \Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot();
if ($diskRoot === null) {
    fwrite(STDERR, "EZDOC_STORAGE_PATH is not set. Point it at the folder the files should go to —\n"
        . "outside the document root, or protected from direct web access.\n");
    exit(2);
}
$files = new \Ezdoc\Storage\DiskBlobStore($db, $diskRoot);

printf("Target   : %s\n", $files->root());
printf("Mode     : %s\n\n", $apply ? 'APPLY — files will be written (the database is not touched)' : 'dry run — nothing is written');

$counts = ['copied' => 0, 'already' => 0, 'corrupt' => 0, 'failed' => 0];
$bytesCopied = 0;
$lastId = 0;

// Keyset pagination: one batch of bytes in memory at a time, and a stable walk
// even if rows are added while it runs.
while (true) {
    $rows = $db->fetchAll(
        'SELECT id, `key`, content FROM ezdoc_blobs'
        . ' WHERE id > ? AND deleted_at IS NULL AND content IS NOT NULL ORDER BY id LIMIT 200',
        [$lastId]
    );
    if ($rows === []) {
        break;
    }

    foreach ($rows as $row) {
        $lastId = (int) $row['id'];
        $key = (string) $row['key'];
        $bytes = (string) $row['content'];

        if (!\Ezdoc\Storage\Blob::verify($key, $bytes)) {
            $counts['corrupt']++;
            printf("  %s  CORRUPT — the column does not match its key; left alone\n", substr($key, 0, 16) . '…');
            continue;
        }

        if ($files->readFile($key) !== null) {
            $counts['already']++;
            continue;
        }

        if (!$apply) {
            $counts['copied']++;
            $bytesCopied += strlen($bytes);
            continue;
        }

        try {
            $files->writeFile($bytes);
        } catch (\Throwable $e) {
            $counts['failed']++;
            printf("  %s  FAILED — %s\n", substr($key, 0, 16) . '…', $e->getMessage());
            continue;
        }

        if ($files->readFile($key) !== $bytes) {
            $counts['failed']++;
            printf("  %s  FAILED — the file did not read back identically\n", substr($key, 0, 16) . '…');
            continue;
        }

        $counts['copied']++;
        $bytesCopied += strlen($bytes);
    }
}

// Images still inline in documents are not objects — there is no row to copy
// them from. Reported so the total adds up; see migrate-signature-images.php.
$inline = 0;
foreach ($db->fetchAll("SELECT signature_values FROM ezdoc_documents WHERE signature_values LIKE '%data:image%'") as $row) {
    foreach ((array) json_decode((string) $row['signature_values'], true) as $value) {
        if (is_string($value) && \Ezdoc\Storage\SignatureImageStore::isDataUri($value)) {
            $inline++;
        }
    }
}

printf("%s : %d objects, %.1f KB\n", $apply ? 'Copied  ' : 'To copy ', $counts['copied'], $bytesCopied / 1024);
printf("On disk  : %d objects already there, skipped\n", $counts['already']);
if ($counts['corrupt'] > 0) {
    printf("Corrupt  : %d — the column does not match the key; investigate, nothing was copied for these\n", $counts['corrupt']);
}
if ($counts['failed'] > 0) {
    printf("Failed   : %d — run again once the cause is fixed\n", $counts['failed']);
}
printf("Inline   : %d images still inside documents — not objects, left as they are\n", $inline);
echo $apply
    ? "\nThe database was not modified.\n"
    : "\nNothing was written. Re-run with `apply` when the numbers look right.\n";

exit($counts['failed'] > 0 || $counts['corrupt'] > 0 ? 1 : 0);
