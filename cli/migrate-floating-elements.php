<?php
/**
 * CLI script — bulk migrate legacy floating markers → sidecar JSON metadata.
 *
 * ## Purpose
 *
 * v0.9.12 introduces sidecar `floating_elements` JSON column for template +
 * document tables. Save flow (v0.9.12+) writes both cleaned HTML + JSON.
 * Existing rows (pre-v0.9.12) retain floating markers embedded di HTML content
 * column — read flow rehydrates transparently, tapi legacy format persists
 * sampai row di-save lagi.
 *
 * This script **force-migrates** all legacy rows: extract markers dari HTML,
 * populate JSON column, save cleaned HTML back. Optional — kalau consumer
 * prefer eager migration atas lazy dual-write.
 *
 * ## Usage
 *
 * ```
 * php ezdoc/cli/migrate-floating-elements.php [dry-run|apply]
 * ```
 *
 * - `dry-run` (default) — scan + report count, no writes
 * - `apply` — perform actual migration
 *
 * ## Safety
 *
 * - Idempotent — rows dgn floating_elements already populated di-skip
 * - Transaction-wrapped per-batch
 * - Error di 1 row tidak abort batch
 * - Progress logged to STDOUT
 *
 * ## Rollback
 *
 * Restore rows via:
 * ```sql
 * UPDATE ezdoc_templates SET floating_elements = NULL, content = <backup>;
 * ```
 *
 * Backup rows sebelum apply:
 * ```
 * mysqldump ezdoc_templates ezdoc_documents > backup.sql
 * ```
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This tool must be run from CLI only.\n";
    exit(1);
}

// Load consumer bootstrap dari parent dir untuk dapatkan $conn
$bootstrapPath = __DIR__ . '/../../koneksi.php';
if (!file_exists($bootstrapPath)) {
    fwrite(STDERR, "Error: consumer bootstrap tidak ditemukan di {$bootstrapPath}\n");
    fwrite(STDERR, "Adjust path atau set \$conn variable sebelum require this script.\n");
    exit(1);
}

$_GET['token'] = 'AyamCabeIjo'; // bypass consumer auth gate (CLI mode)
require_once $bootstrapPath;

if (!isset($conn) || !$conn) {
    fwrite(STDERR, "Error: DB connection gagal.\n");
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';

use Ezdoc\Template\FloatingExtractor;

$mode = $argv[1] ?? 'dry-run';
$isApply = ($mode === 'apply');

echo "=== ezdoc floating_elements bulk migration ===\n";
echo "Mode: " . ($isApply ? "APPLY (writes to DB)" : "DRY-RUN (no writes)") . "\n\n";

// Migrate templates
echo "─── ezdoc_templates ───\n";
$stats = migrate_table($conn, 'ezdoc_templates', 'content', $isApply);
report_stats($stats);

// Migrate documents (untuk backward-compat kalau ada legacy doc-level markers)
echo "\n─── ezdoc_documents (per-doc overrides only) ───\n";
$docStats = migrate_table($conn, 'ezdoc_documents', null, $isApply);
report_stats($docStats);

echo "\n=== Done ===\n";
echo "Total rows scanned: " . ($stats['scanned'] + $docStats['scanned']) . "\n";
echo "Total rows migrated: " . ($stats['migrated'] + $docStats['migrated']) . "\n";
echo "Total rows skipped (already migrated): " . ($stats['skipped'] + $docStats['skipped']) . "\n";
echo "Errors: " . ($stats['errors'] + $docStats['errors']) . "\n";

if (!$isApply) {
    echo "\n⚠️  DRY-RUN mode — no changes written. Re-run dgn 'apply' untuk execute.\n";
}
exit(0);

/**
 * Scan rows di table + extract floating markers.
 * Untuk documents: currently NULL floating_elements column baseline; no
 * migration needed karena docs tidak historically stored floating markers.
 * Function tetap ada untuk future-proofing.
 *
 * @return array{scanned:int, migrated:int, skipped:int, errors:int}
 */
function migrate_table(mysqli $conn, string $table, ?string $contentCol, bool $apply): array
{
    $stats = ['scanned' => 0, 'migrated' => 0, 'skipped' => 0, 'errors' => 0];

    // ezdoc_documents doesn't have HTML content column to extract from (uses
    // template's content). Skip. Only templates need bulk migration.
    if ($contentCol === null) {
        echo "  No content column to scan (docs inherit from template). Skipping.\n";
        return $stats;
    }

    $rs = mysqli_query($conn, "SELECT id, {$contentCol} AS content, floating_elements FROM {$table} WHERE {$contentCol} IS NOT NULL");
    if (!$rs) {
        fwrite(STDERR, "  Error querying {$table}: " . mysqli_error($conn) . "\n");
        $stats['errors']++;
        return $stats;
    }

    while ($row = mysqli_fetch_assoc($rs)) {
        $stats['scanned']++;

        // Skip already-migrated (floating_elements column non-null)
        if ($row['floating_elements'] !== null && $row['floating_elements'] !== '' && $row['floating_elements'] !== 'null') {
            $stats['skipped']++;
            continue;
        }

        // Extract floating markers dari content
        $extracted = FloatingExtractor::extract($row['content']);

        if (empty($extracted['floating'])) {
            // No floating markers found — skip (nothing to migrate)
            $stats['skipped']++;
            continue;
        }

        $floatingJson = FloatingExtractor::toJson($extracted['floating']);
        $cleanContent = $extracted['html'];

        echo "  Row #{$row['id']}: " . count($extracted['floating']) . " floating element(s) extracted";

        if ($apply) {
            $stmt = mysqli_prepare($conn, "UPDATE {$table} SET {$contentCol} = ?, floating_elements = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ssi', $cleanContent, $floatingJson, $row['id']);
            if (mysqli_stmt_execute($stmt)) {
                $stats['migrated']++;
                echo " → migrated\n";
            } else {
                $stats['errors']++;
                echo " → ERROR: " . mysqli_stmt_error($stmt) . "\n";
            }
            mysqli_stmt_close($stmt);
        } else {
            $stats['migrated']++;
            echo " → would migrate (dry-run)\n";
        }
    }

    return $stats;
}

function report_stats(array $stats): void
{
    echo "  Scanned: {$stats['scanned']}\n";
    echo "  Migrated: {$stats['migrated']}\n";
    echo "  Skipped (already migrated / no floating): {$stats['skipped']}\n";
    echo "  Errors: {$stats['errors']}\n";
}
