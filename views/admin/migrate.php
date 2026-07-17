<?php
/**
 * Admin migration dashboard — visual alternative to CLI migrate.
 *
 * Auto-migrate sudah aktif at bootstrap (EZDOC_AUTO_MIGRATE). View ini
 * untuk explicit control + status visibility + one-shot bulk migrations
 * (mis. `migrate-floating-elements.php`) via web button.
 *
 * Precedent: Laravel Nova/Filament dashboards, Django admin migrations
 * view, Rails webconsole, WordPress upgrade.php.
 *
 * Auth: superadmin (destructive DB operations).
 *
 * @var \Ezdoc\UI\Config    $config
 * @var \Ezdoc\UI\Translator $translator
 */

// Auth gate — superadmin only
ezdoc_require_role('superadmin', 'Admin migration hanya untuk superadmin');

// Handle POST actions
$actionMsg = '';
$actionType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $conn = \Ezdoc\Context::default()->db;

    try {
        switch ($action) {
            case 'run_pending':
                $result = ezdoc_migrate($conn);
                $applied = count($result['applied']);
                $failed = count($result['failed']);
                if ($failed > 0) {
                    $actionMsg = "Migration selesai dgn error. Applied: {$applied}, Failed: {$failed}. Check server log for details.";
                    $actionType = 'error';
                } elseif ($applied > 0) {
                    $actionMsg = "Berhasil apply {$applied} pending migration(s): " . implode(', ', $result['applied']);
                    $actionType = 'success';
                } else {
                    $actionMsg = 'Tidak ada pending migrations. Semua up-to-date.';
                    $actionType = 'info';
                }
                break;

            case 'migrate_floating':
                // Bulk migrate legacy floating markers → sidecar JSON
                $stats = ['scanned' => 0, 'migrated' => 0, 'skipped' => 0, 'errors' => 0];
                $rs = mysqli_query($conn, "SELECT id, content, floating_elements FROM ezdoc_templates WHERE content IS NOT NULL");
                while ($row = mysqli_fetch_assoc($rs)) {
                    $stats['scanned']++;
                    if ($row['floating_elements'] !== null && $row['floating_elements'] !== '' && $row['floating_elements'] !== 'null') {
                        $stats['skipped']++;
                        continue;
                    }
                    $extracted = \Ezdoc\Template\FloatingExtractor::extract($row['content']);
                    if (empty($extracted['floating'])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $floatingJson = \Ezdoc\Template\FloatingExtractor::toJson($extracted['floating']);
                    $stmt = mysqli_prepare($conn, "UPDATE ezdoc_templates SET content = ?, floating_elements = ? WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, 'ssi', $extracted['html'], $floatingJson, $row['id']);
                    if (mysqli_stmt_execute($stmt)) {
                        $stats['migrated']++;
                    } else {
                        $stats['errors']++;
                    }
                    mysqli_stmt_close($stmt);
                }
                $actionMsg = sprintf(
                    'Floating migration: scanned %d, migrated %d, skipped %d, errors %d.',
                    $stats['scanned'], $stats['migrated'], $stats['skipped'], $stats['errors']
                );
                $actionType = $stats['errors'] > 0 ? 'error' : 'success';
                break;

            default:
                $actionMsg = 'Unknown action.';
                $actionType = 'error';
        }
    } catch (\Throwable $e) {
        $actionMsg = 'Error: ' . $e->getMessage();
        $actionType = 'error';
    }
}

// Query migration status
$conn = \Ezdoc\Context::default()->db;
$applied = [];
$rs = @mysqli_query($conn, "SELECT name, executed_at FROM ezdoc_migrations ORDER BY executed_at ASC");
if ($rs) {
    while ($row = mysqli_fetch_assoc($rs)) {
        $applied[$row['name']] = $row['executed_at'];
    }
}

// All migration files
$migrationFiles = ezdoc_scan_migration_files();
$allMigrations = [];
foreach ($migrationFiles as $path) {
    $name = basename($path, '.php');
    $allMigrations[$name] = [
        'name' => $name,
        'applied' => isset($applied[$name]),
        'executed_at' => $applied[$name] ?? null,
    ];
}
ksort($allMigrations);

$pendingCount = count(array_filter($allMigrations, fn($m) => !$m['applied']));
$appliedCount = count($allMigrations) - $pendingCount;

// Query floating migration stats
$floatingStats = null;
$rs = @mysqli_query($conn, "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN floating_elements IS NOT NULL AND floating_elements != '' AND floating_elements != 'null' THEN 1 ELSE 0 END) AS migrated,
    SUM(CASE WHEN content LIKE '%-placeholder floating%' THEN 1 ELSE 0 END) AS has_embedded_markers
FROM ezdoc_templates
WHERE content IS NOT NULL");
if ($rs) {
    $floatingStats = mysqli_fetch_assoc($rs);
}
?>
<section class="max-w-5xl mx-auto p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Migration Dashboard</h1>
        <p class="mt-1 text-sm text-gray-500">
            Auto-migration sudah aktif di bootstrap. Dashboard ini untuk explicit control + status visibility.
        </p>
    </div>

    <?php if ($actionMsg): ?>
    <div class="mb-4 p-4 rounded-md <?= $actionType === 'error' ? 'bg-red-50 border-l-4 border-red-400 text-red-800' : ($actionType === 'success' ? 'bg-green-50 border-l-4 border-green-400 text-green-800' : 'bg-blue-50 border-l-4 border-blue-400 text-blue-800') ?>">
        <?= htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Applied</div>
            <div class="mt-1 text-2xl font-semibold text-green-700"><?= $appliedCount ?></div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Pending</div>
            <div class="mt-1 text-2xl font-semibold <?= $pendingCount > 0 ? 'text-amber-700' : 'text-gray-400' ?>"><?= $pendingCount ?></div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Total Migrations</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900"><?= count($allMigrations) ?></div>
        </div>
    </div>

    <!-- Actions -->
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Actions</h2>
        <div class="flex flex-wrap gap-3">
            <form method="POST" onsubmit="return confirm('Run <?= $pendingCount ?> pending migration(s)?');">
                <input type="hidden" name="action" value="run_pending">
                <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 <?= $pendingCount > 0 ? '' : 'opacity-50 cursor-not-allowed' ?>"
                        style="background-color: <?= $pendingCount > 0 ? '#2563eb' : '#9ca3af' ?>;"
                        <?= $pendingCount > 0 ? '' : 'disabled' ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Run <?= $pendingCount ?> Pending Migration<?= $pendingCount !== 1 ? 's' : '' ?>
                </button>
            </form>

            <?php if ($floatingStats && (int)$floatingStats['has_embedded_markers'] > 0): ?>
            <form method="POST" onsubmit="return confirm('Bulk migrate <?= (int)$floatingStats['has_embedded_markers'] ?> template(s) dgn embedded floating markers ke sidecar JSON? Backup DB dulu!');">
                <input type="hidden" name="action" value="migrate_floating">
                <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 shadow-sm hover:bg-amber-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M7 3a1 1 0 011-1h4a1 1 0 011 1v1h3a1 1 0 110 2h-1v10a2 2 0 01-2 2H6a2 2 0 01-2-2V6H3a1 1 0 010-2h3V3z"/>
                    </svg>
                    Migrate Floating Elements (<?= (int)$floatingStats['has_embedded_markers'] ?> pending)
                </button>
            </form>
            <?php endif; ?>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            <strong>Note:</strong> Auto-migrate sudah aktif at bootstrap. Button ini untuk manual trigger — biasanya tidak perlu.
            Untuk destructive ops (reset, drop), pakai CLI: <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">php cli/migrate.php reset</code>
        </p>
    </div>

    <!-- Floating elements migration stats -->
    <?php if ($floatingStats): ?>
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Sidecar Migration Status (v0.9.12)</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Total Templates</div>
                <div class="mt-1 text-xl font-semibold text-gray-900"><?= (int)$floatingStats['total'] ?></div>
            </div>
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Sidecar Populated</div>
                <div class="mt-1 text-xl font-semibold text-green-700"><?= (int)$floatingStats['migrated'] ?></div>
            </div>
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Legacy (HTML Markers)</div>
                <div class="mt-1 text-xl font-semibold <?= (int)$floatingStats['has_embedded_markers'] > 0 ? 'text-amber-700' : 'text-gray-400' ?>">
                    <?= (int)$floatingStats['has_embedded_markers'] ?>
                </div>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            Legacy templates dgn floating markers embedded in HTML tetap render correctly (backward-compat).
            Bulk migrate optional — akan auto-migrate on next save.
        </p>
    </div>
    <?php endif; ?>

    <!-- Migration list -->
    <div class="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">All Migrations</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Name</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Executed At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($allMigrations as $m): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-700"><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-4 py-2">
                            <?php if ($m['applied']): ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 ring-1 ring-inset ring-green-200">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Applied
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 ring-1 ring-inset ring-amber-200">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                Pending
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500">
                            <?= $m['executed_at'] ? htmlspecialchars($m['executed_at'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-400">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
