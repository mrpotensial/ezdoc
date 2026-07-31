<?php

declare(strict_types=1);

/**
 * CLI — move inline signature images out of `ezdoc_documents.signature_values`
 * and into `ezdoc_blobs`.
 *
 * ## Usage
 *
 * ```
 * php ezdoc/cli/migrate-signature-images.php            # dry run, default
 * php ezdoc/cli/migrate-signature-images.php apply
 * ```
 *
 * ## What it will not touch, and why that is not a limitation
 *
 * A slot that already has a row in `ezdoc_signatures` is left exactly as it is.
 *
 * The signature's `content_hash` covers `ttd_value` — the slot value as it stood
 * when it was signed. Replacing an inline data URI with a reference changes that
 * value, so the recorded signature would recompute to a different hash and
 * verification would report `tampered`. The application would be invalidating its
 * own evidence to save a few kilobytes.
 *
 * The bytes are identical either way; it is the *document's* value that changes,
 * and that is the thing under signature. Those slots stay inline until the
 * document gets a new version — which is exactly what a new version is for.
 *
 * (A future payload version could canonicalise `ttd_value` to the hash of the
 * image bytes, which would make the two forms hash identically and let signed
 * slots migrate too. `content_hash_algo` already names the payload shape, so the
 * two could coexist. Out of scope here — see docs/PLAN-SIGNATURE-RECORDS.md.)
 *
 * ## What it does not do
 *
 * Nothing is deleted. The document keeps working from the reference, and the
 * original bytes now live in the blob store. Freeing the space in
 * `ezdoc_documents` is a separate, later decision, and it should only be taken
 * once the references have been read back successfully in production.
 */

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$mode = $argv[1] ?? 'dry-run';
if (!in_array($mode, ['dry-run', 'apply'], true)) {
    fwrite(STDERR, "Usage: php migrate-signature-images.php [dry-run|apply]\n");
    exit(2);
}
$apply = $mode === 'apply';

// The consumer's bootstrap supplies the connection, the same way the other CLI
// scripts here reach it.
if (!isset($conn) || !($conn instanceof mysqli)) {
    $bootstrap = getenv('EZDOC_BOOTSTRAP') ?: '';
    if ($bootstrap === '' || !is_file($bootstrap)) {
        fwrite(STDERR,
            "No database handle. Point EZDOC_BOOTSTRAP at the file that creates \$conn:\n"
            . "  EZDOC_BOOTSTRAP=/path/to/koneksi.php php " . basename(__FILE__) . " dry-run\n");
        exit(2);
    }
    require_once $bootstrap;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "The bootstrap file did not produce a mysqli \$conn.\n");
    exit(2);
}

$db = new \Ezdoc\Db\Mysqli\MysqliConnection($conn);

if ((new \Ezdoc\Db\Schema\ColumnIntrospector($db))->columns('ezdoc_blobs') === []) {
    fwrite(STDERR, "Table `ezdoc_blobs` does not exist. Run the migration first:\n"
        . "  migrations/2026_07_30_000001_create_ezdoc_blobs.php\n");
    exit(1);
}

$store = \Ezdoc\Storage\BlobStoreFactory::make($db);
$images = new \Ezdoc\Storage\SignatureImageStore($store);

printf("Backend  : %s\n", $store->name());
printf("Mode     : %s\n\n", $apply ? 'APPLY — documents will be written' : 'dry run — nothing is written');

// Slots that must not be touched.
$signedSlots = [];
foreach ($db->fetchAll('SELECT document_id, metadata FROM ezdoc_signatures WHERE deleted_at IS NULL') as $row) {
    $meta = json_decode((string) ($row['metadata'] ?? ''), true);
    if (is_array($meta) && !empty($meta['ttd_id'])) {
        $signedSlots[$row['document_id'] . '|' . $meta['ttd_id']] = true;
    }
}

$docs = $db->fetchAll(
    'SELECT id, signature_values FROM ezdoc_documents'
    . ' WHERE signature_values IS NOT NULL AND deleted_at IS NULL ORDER BY id'
);

$moved = 0;
$movedBytes = 0;
$frozen = 0;
$frozenBytes = 0;
$failed = 0;
$docsChanged = 0;

foreach ($docs as $doc) {
    $docId = (int) $doc['id'];
    $values = json_decode((string) $doc['signature_values'], true);
    if (!is_array($values)) {
        continue;
    }

    $changed = false;
    foreach ($values as $ttdId => $value) {
        if (!is_string($value) || !\Ezdoc\Storage\SignatureImageStore::isDataUri($value)) {
            continue;
        }

        if (isset($signedSlots[$docId . '|' . $ttdId])) {
            $frozen++;
            $frozenBytes += strlen($value);
            printf("  doc %-5d %-28s KEPT   %6.1f KB — already signed\n",
                $docId, $ttdId, strlen($value) / 1024);
            continue;
        }

        if (!$apply) {
            $moved++;
            $movedBytes += strlen($value);
            printf("  doc %-5d %-28s would move %6.1f KB\n", $docId, $ttdId, strlen($value) / 1024);
            continue;
        }

        $ref = $images->store($value);
        if ($ref === null) {
            $failed++;
            printf("  doc %-5d %-28s FAILED — image could not be stored\n", $docId, $ttdId);
            continue;
        }

        // Read it straight back before trusting the reference. A backfill that
        // rewrites the document first and discovers the problem afterwards has
        // already lost the only copy.
        $readBack = $images->resolveToBytes($ref);
        $original = $images->resolveToBytes($value);
        if ($readBack === null || $original === null || $readBack['bytes'] !== $original['bytes']) {
            $failed++;
            printf("  doc %-5d %-28s FAILED — stored object did not read back identically\n", $docId, $ttdId);
            continue;
        }

        $values[$ttdId] = $ref;
        $changed = true;
        $moved++;
        $movedBytes += strlen($value);
        printf("  doc %-5d %-28s moved  %6.1f KB → %s\n",
            $docId, $ttdId, strlen($value) / 1024, substr($ref, 0, 24) . '…');
    }

    if ($changed && $apply) {
        $db->execute(
            'UPDATE ezdoc_documents SET signature_values = ? WHERE id = ?',
            [(string) json_encode($values, JSON_UNESCAPED_UNICODE), $docId]
        );
        $docsChanged++;
    }
}

echo "\n";
printf("Moved    : %d images, %.1f KB%s\n", $moved, $movedBytes / 1024, $apply ? '' : ' (would move)');
printf("Kept     : %d images, %.1f KB — signed slots, see the note at the top of this file\n",
    $frozen, $frozenBytes / 1024);
if ($failed > 0) {
    printf("Failed   : %d — nothing was changed for these\n", $failed);
}
if ($apply) {
    printf("Documents rewritten: %d\n", $docsChanged);
    echo "\nThe original bytes are still in `signature_values` for documents that were\n"
       . "not rewritten, and in `ezdoc_blobs` for those that were. Nothing was deleted.\n"
       . "Verify the documents render, then reclaim the space as a separate step.\n";
} else {
    echo "\nNothing was written. Re-run with `apply` when the numbers look right.\n";
}

exit($failed > 0 ? 1 : 0);
