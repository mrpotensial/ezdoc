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

// Force error display untuk debug (admin page — safe untuk display errors).
// Kalau ada PHP fatal error, user akan lihat error message bukan blank page.
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

// Defensive: kalau bootstrap helpers tidak available (edge case: routing
// misconfiguration), show clear error message bukan blank/crash.
if (!function_exists('ezdoc_has_role')) {
    echo '<pre style="padding:20px;font-family:monospace;background:#fee;color:#900;">';
    echo "Admin migrate: ezdoc bootstrap helpers not loaded.\n";
    echo "Expected function: ezdoc_has_role() (from lib/authorization.php)\n";
    echo "Check: ezdoc/bootstrap.php require in your app entry.\n";
    echo '</pre>';
    return;
}

// Auth gate — superadmin only. Use ezdoc_has_role (not ezdoc_require_role)
// karena require_role → JSON 403 response (untuk AJAX), tapi ini VIEW page →
// perlu HTML response. Show 403 message inline kalau tidak authorized.
if (!ezdoc_has_role('superadmin')) {
    ?>
    <section class="max-w-2xl mx-auto p-6">
        <div class="rounded-lg border-l-4 border-red-400 bg-red-50 p-4">
            <h1 class="text-lg font-semibold text-red-800 mb-2">Access Denied</h1>
            <p class="text-sm text-red-700">
                Admin migration dashboard hanya dapat diakses oleh <strong>superadmin</strong>.
            </p>
            <p class="mt-2 text-xs text-red-600">
                Kalau kamu superadmin tapi tetap dapat error ini, cek role provider config
                atau login ulang.
            </p>
        </div>
    </section>
    <?php
    return;
}

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

            case 'prune_orphan_registry':
                // Remove registry entries yg corresponding migration file tidak
                // ada lagi (mis. legacy files deleted di v0.9.12 cleanup).
                // Idempotent — safe re-run.
                $files = ezdoc_scan_migration_files();
                $fileNames = [];
                foreach ($files as $path) {
                    $spec = @include $path;
                    if (is_array($spec) && !empty($spec['name'])) {
                        $fileNames[$spec['name']] = true;
                    } else {
                        $fileNames[basename($path, '.php')] = true;
                    }
                }
                $rs = mysqli_query($conn, "SELECT id, migration FROM ezdoc_migrations");
                $orphans = [];
                if ($rs) {
                    while ($row = mysqli_fetch_assoc($rs)) {
                        if (!isset($fileNames[$row['migration']])) {
                            $orphans[] = $row;
                        }
                    }
                }
                $pruned = 0;
                foreach ($orphans as $orphan) {
                    $stmt = mysqli_prepare($conn, "DELETE FROM ezdoc_migrations WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, 'i', $orphan['id']);
                    if (mysqli_stmt_execute($stmt)) $pruned++;
                    mysqli_stmt_close($stmt);
                }
                $actionMsg = "Pruned {$pruned} orphan registry entries" . ($pruned > 0 ? ': ' . implode(', ', array_map(fn($o) => $o['migration'], $orphans)) : ' (registry sudah clean).');
                $actionType = 'success';
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

// ─── Penyimpanan objek: tugas per batch, dijawab sebagai JSON ───
//
// Per batch, bukan sekali jalan: satu permintaan web punya batas waktu, dan
// menyalin ribuan objek tidak muat di dalamnya. Peramban memanggil berulang
// sambil membawa `after_id` — pola yang sama dengan backfill bertahap Rails dan
// importer WordPress. Logikanya sendiri ada di BlobMaintenance, dipakai bersama
// dengan kedua CLI, supaya tidak ada versi kedua yang bisa berbeda diam-diam.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['storage_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $dbx = \Ezdoc\Context::default()->connection();
        $rawat = new \Ezdoc\Storage\BlobMaintenance($dbx);
        $rootx = \Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot();
        $filesx = $rootx !== null ? new \Ezdoc\Storage\DiskBlobStore($dbx, $rootx) : null;
        $after = (int) ($_POST['after_id'] ?? 0);

        switch ((string) $_POST['storage_action']) {
            case 'ringkas':
                echo json_encode(['ok' => true, 'ringkas' => $rawat->summary($filesx)]);
                break;

            case 'salin':
                if ($filesx === null) {
                    throw new \RuntimeException('EZDOC_STORAGE_PATH belum disetel — tidak ada tempat menaruh berkasnya.');
                }
                echo json_encode([
                    'ok' => true,
                    'batch' => $rawat->copyToFilesBatch($filesx, $after, 200, ($_POST['apply'] ?? '') === '1'),
                ]);
                break;

            case 'periksa':
                echo json_encode([
                    'ok' => true,
                    'batch' => $rawat->checkBatch(
                        \Ezdoc\Storage\BlobStoreFactory::make($dbx),
                        $filesx,
                        $after,
                        200
                    ),
                ]);
                break;

            case 'yatim':
                // Melihat saja. Penghapusannya tetap lewat CLI: yang hilang di
                // sini adalah byte, dan tombol di halaman web bukan tempat yang
                // tepat untuk keputusan yang tidak bisa dibatalkan.
                $yatim = $rawat->orphans(48);
                echo json_encode([
                    'ok' => true,
                    'jumlah' => count($yatim['objek']),
                    'byte' => $yatim['byte'],
                    'objek' => array_slice($yatim['objek'], 0, 50),
                ]);
                break;

            default:
                echo json_encode(['ok' => false, 'pesan' => 'Unknown storage action.']);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'pesan' => $e->getMessage()]);
    }
    exit;
}

// ─── Adapter yang terpasang, dan apakah izinnya aman ───
//
// Pustaka ini menerima adapter dari aplikasi pemakai: koneksi, perender PDF,
// perender QR, penyimpan objek, penyedia role. Kalau salah satunya tidak
// terpasang, kegagalannya muncul jauh dari sebabnya — QR yang hilang di
// dokumen, tanda tangan yang tidak tersimpan. Daftar ini menjawabnya di satu
// tempat, sebelum ada yang mencarinya di log.
$ctxq = \Ezdoc\Context::default();
$dbq = $ctxq->connection();
$adapters = [];
$tambah = static function (string $nama, string $status, string $nilai, string $catatan = '') use (&$adapters): void {
    $adapters[] = ['nama' => $nama, 'status' => $status, 'nilai' => $nilai, 'catatan' => $catatan];
};

// Basis data
try {
    $grammar = $dbq->grammar()->name();
    $versi = '';
    try {
        $row = $dbq->fetchOne('SELECT VERSION() AS v');
        $versi = (string) ($row['v'] ?? '');
    } catch (\Throwable $e) {
        $versi = '';
    }
    $tambah('Database', 'ok', $grammar . ($versi !== '' ? ' ' . $versi : ''), get_class($dbq));
} catch (\Throwable $e) {
    $tambah('Database', 'bad', 'tidak terjawab', $e->getMessage());
}

// Hak akses pengguna basis data — hanya MySQL/MariaDB yang bisa ditanya begini.
try {
    if (strpos($dbq->grammar()->name(), 'mysql') !== false || strpos($dbq->grammar()->name(), 'maria') !== false) {
        $hak = [];
        foreach ($dbq->fetchAll('SHOW GRANTS FOR CURRENT_USER()') as $baris) {
            $hak[] = (string) reset($baris);
        }
        $teks = strtoupper(implode(' ', $hak));
        $berisiko = [];
        foreach (['ALL PRIVILEGES', 'DROP', 'FILE', 'SUPER', 'GRANT OPTION'] as $kata) {
            if (strpos($teks, $kata) !== false) {
                $berisiko[] = $kata;
            }
        }
        $tambah(
            'Hak pengguna DB',
            $berisiko === [] ? 'ok' : 'warn',
            $berisiko === [] ? 'terbatas' : implode(', ', $berisiko),
            $berisiko === [] ? '' : 'Aplikasi hanya butuh SELECT/INSERT/UPDATE/DELETE, dan CREATE/ALTER saat migrasi.'
        );
    }
} catch (\Throwable $e) {
    // Hak akses tidak bisa dibaca bukan kesalahan — sebagian pemasangan memang menutupnya.
    $tambah('Hak pengguna DB', 'warn', 'tidak terbaca', $e->getMessage());
}

// Penyimpan objek
$backend = \Ezdoc\Storage\BlobStoreFactory::configuredBackend();
$rootAda = \Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot();
$tambah('Penyimpan objek', 'ok', $backend, $backend === 'mirror' ? 'kolom + berkas, keduanya ditulis' : '');
if ($rootAda !== null) {
    $bisaTulis = is_dir($rootAda) && is_writable($rootAda);
    $tambah('Folder objek', $bisaTulis ? 'ok' : 'bad', $rootAda, $bisaTulis ? '' : 'tidak ada atau tidak bisa ditulis PHP');

    // Di dalam folder yang dilayani web? Itu bukan kesalahan kalau ditutup,
    // tapi penutupnya harus ada dan harus terbukti.
    $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $nyataRoot = (string) (realpath($rootAda) ?: $rootAda);
    $diDalamWeb = $docroot !== '' && strpos($nyataRoot, (string) (realpath($docroot) ?: $docroot)) === 0;
    if ($diDalamWeb) {
        $punyaHtaccess = is_file($rootAda . '/.htaccess');
        $tambah(
            'Objek terjangkau web?',
            $punyaHtaccess ? 'warn' : 'bad',
            'di dalam document root',
            $punyaHtaccess
                ? 'Ada .htaccess. Itu TIDAK berlaku di nginx atau AllowOverride None — buka URL foldernya, yang benar 403.'
                : 'Tidak ada .htaccess. Siapa pun yang memegang URL-nya bisa mengambil gambar tanda tangan.'
        );
    } else {
        $tambah('Objek terjangkau web?', 'ok', 'di luar document root');
    }
} elseif ($backend !== 'database') {
    $tambah('Folder objek', 'bad', 'belum disetel', 'EZDOC_STORAGE_PATH kosong padahal backend butuh berkas.');
}

// Perender PDF dan QR — kontrak opsional, jalur usang tetap dihitung.
$pdfAdapter = function_exists('ezdoc_pdf_renderer') ? ezdoc_pdf_renderer() : ($ctxq->pdf ?? null);
if ($pdfAdapter !== null) {
    $tambah(
        'Perender PDF',
        'ok',
        get_class($pdfAdapter),
        ($ctxq->pdf ?? null) !== null ? 'dipasang aplikasi pemakai' : 'bawaan pustaka, memakai dompdf milik aplikasi pemakai'
    );
} else {
    $tambah('Perender PDF', 'bad', 'tidak ada', 'Cetak PDF dan pembekuan dokumen akan gagal.');
}

$qrAdapter = $ctxq->qr ?? null;
if ($qrAdapter !== null) {
    $tambah('Perender QR', 'ok', get_class($qrAdapter));
} elseif (function_exists('generateQrForDompdf')) {
    $tambah('Perender QR', 'warn', 'fungsi lama', 'Jalur usang. Pasang kontrak QrRenderer lewat Context::withQr().');
} else {
    $tambah('Perender QR', 'warn', 'tidak ada', 'QR pada dokumen tidak akan tergambar.');
}

// Kunci tanda tangan — keberadaannya saja, bukan isinya.
try {
    $adaKunci = \Ezdoc\Signature\SigningKey::isConfigured();
    $tambah('Kunci tanda tangan', $adaKunci ? 'ok' : 'bad', $adaKunci ? 'terpasang' : 'tidak ada',
        $adaKunci ? '' : 'Tanda tangan tidak akan tercatat ke ezdoc_signatures.');
} catch (\Throwable $e) {
    $tambah('Kunci tanda tangan', 'warn', 'tidak terbaca', $e->getMessage());
}

// Penyedia role
$tambah(
    'Penyedia role',
    function_exists('ezdoc_has_role') ? 'ok' : 'bad',
    function_exists('ezdoc_has_role') ? implode(', ', array_slice(ezdoc_current_user_roles(), 0, 6)) ?: 'tanpa role' : 'tidak ada',
    'role pengguna yang sedang membuka halaman ini'
);

// Bahasa
$tambah('Bahasa layar', 'ok', function_exists('ezdoc_locale') ? ezdoc_locale() : 'en', 'app.locale');
$tambah(
    'Bahasa isi dokumen',
    'ok',
    function_exists('ezdoc_document_locale') ? ezdoc_document_locale() : 'en',
    'document.locale — kosong berarti ikut bahasa layar'
);

// Query migration status. Registry table `ezdoc_migrations` schema per
// Ezdoc\Migrations\Runner: column is `migration` (name string) + `executed_at`.
$conn = \Ezdoc\Context::default()->db;
$applied = [];
$rs = @mysqli_query($conn, "SELECT migration, executed_at FROM ezdoc_migrations ORDER BY executed_at ASC");
if ($rs) {
    while ($row = mysqli_fetch_assoc($rs)) {
        $applied[$row['migration']] = $row['executed_at'];
    }
}

// Build unified migration list combining:
//   1. Files on disk (from ezdoc_scan_migration_files) — status: applied|pending
//   2. Registry entries without matching file (orphans) — status: orphan
//
// User feedback: table sebelumnya hanya show files; sekarang ALSO show orphan
// registry entries (rows di ezdoc_migrations tanpa file — mis. after v0.9.12
// legacy cleanup deleted 14 legacy migration files, registry masih ada entries
// referring to them). "Riwayat migrasi di tabel" per user's insight.
$migrationFiles = ezdoc_scan_migration_files();
$allMigrations = [];

// File-backed entries
$fileNamesSet = [];
foreach ($migrationFiles as $path) {
    $name = basename($path, '.php');
    $fileNamesSet[$name] = true;
    $allMigrations[$name] = [
        'name' => $name,
        'status' => isset($applied[$name]) ? 'applied' : 'pending',
        'executed_at' => $applied[$name] ?? null,
        'has_file' => true,
    ];
}

// Orphan registry entries (dalam registry tapi file tidak ada)
foreach ($applied as $regName => $execAt) {
    if (!isset($fileNamesSet[$regName])) {
        $allMigrations[$regName] = [
            'name' => $regName,
            'status' => 'orphan',
            'executed_at' => $execAt,
            'has_file' => false,
        ];
    }
}
ksort($allMigrations);

$appliedCount = count(array_filter($allMigrations, fn($m) => $m['status'] === 'applied'));
$pendingCount = count(array_filter($allMigrations, fn($m) => $m['status'] === 'pending'));
$orphanCount  = count(array_filter($allMigrations, fn($m) => $m['status'] === 'orphan'));

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
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Applied</div>
            <div class="mt-1 text-2xl font-semibold text-green-700"><?= $appliedCount ?></div>
            <div class="mt-0.5 text-[10px] text-gray-400">has file + in registry</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Pending</div>
            <div class="mt-1 text-2xl font-semibold <?= $pendingCount > 0 ? 'text-amber-700' : 'text-gray-400' ?>"><?= $pendingCount ?></div>
            <div class="mt-0.5 text-[10px] text-gray-400">has file, not yet run</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Orphan</div>
            <div class="mt-1 text-2xl font-semibold <?= $orphanCount > 0 ? 'text-purple-700' : 'text-gray-400' ?>"><?= $orphanCount ?></div>
            <div class="mt-0.5 text-[10px] text-gray-400">registry entry, no file</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-gray-500 font-medium">Total Shown</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900"><?= count($allMigrations) ?></div>
            <div class="mt-0.5 text-[10px] text-gray-400">files + orphans combined</div>
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

            <?php if ($orphanCount > 0): ?>
            <form method="POST" onsubmit="return confirm('Prune <?= $orphanCount ?> orphan registry entries (rows without corresponding migration file)?');">
                <input type="hidden" name="action" value="prune_orphan_registry">
                <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    Prune <?= $orphanCount ?> Orphan Registry Entries
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
                    <tr class="hover:bg-gray-50 <?= $m['status'] === 'orphan' ? 'bg-purple-50/30' : '' ?>">
                        <td class="px-4 py-2 font-mono text-xs <?= $m['status'] === 'orphan' ? 'text-purple-800 line-through' : 'text-gray-700' ?>"><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-4 py-2">
                            <?php if ($m['status'] === 'applied'): ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 ring-1 ring-inset ring-green-200">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Applied
                            </span>
                            <?php elseif ($m['status'] === 'pending'): ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 ring-1 ring-inset ring-amber-200">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                Pending
                            </span>
                            <?php else: /* orphan */ ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-700 ring-1 ring-inset ring-purple-200" title="Registry entry exists tapi file sudah dihapus (mis. v0.9.12 legacy cleanup). Prune button di atas untuk cleanup.">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                Orphan (no file)
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

    <!-- ─── Penyimpanan objek ─────────────────────────────────────────── -->
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm mb-6">
        <h2 class="text-lg font-semibold text-gray-900">Object storage</h2>
        <p class="mt-1 text-sm text-gray-500">
            Gambar tanda tangan dan PDF beku. Menyalin ke berkas TIDAK mengubah tabel —
            pemasangan lama yang membaca kolom tetap melihat isi yang sama.
        </p>

        <div id="ezStorageRingkas" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 my-4 text-sm text-gray-500">
            Memuat ringkasan…
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" data-ez-tugas="salin" data-ez-apply="0"
                    class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                Lihat dulu (tanpa menulis)
            </button>
            <button type="button" data-ez-tugas="salin" data-ez-apply="1"
                    class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90"
                    style="background-color:#2563eb;">
                Salin tanda tangan ke berkas
            </button>
            <button type="button" data-ez-tugas="periksa"
                    class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                Periksa kesehatan
            </button>
            <button type="button" data-ez-tugas="yatim"
                    class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                Objek tak dirujuk
            </button>
        </div>

        <div id="ezStorageBar" class="mt-4 hidden">
            <div class="h-1.5 w-full overflow-hidden rounded bg-gray-100">
                <div id="ezStorageBarIsi" class="h-full bg-blue-500 transition-all" style="width:0"></div>
            </div>
        </div>
        <pre id="ezStorageLog" class="mt-3 hidden max-h-64 overflow-auto rounded-md bg-gray-50 p-3 text-xs text-gray-700 ring-1 ring-inset ring-gray-200"></pre>
        <p class="mt-3 text-xs text-gray-400">
            Menghapus objek yang tak dirujuk sengaja tidak ada di sini — yang hilang adalah byte,
            dan itu lewat <code>cli/purge-orphan-blobs.php</code> setelah daftarnya dibaca.
        </p>
    </div>

    <!-- ─── Adapter & izin ────────────────────────────────────────────── -->
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm mb-6">
        <h2 class="text-lg font-semibold text-gray-900">Adapters &amp; permissions</h2>
        <p class="mt-1 text-sm text-gray-500">
            Apa yang terpasang dari aplikasi pemakai, dan apakah izinnya aman.
        </p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($adapters as $a): ?>
                    <tr>
                        <td class="py-2 pr-4 font-medium text-gray-700 whitespace-nowrap"><?= htmlspecialchars($a['nama'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="py-2 pr-4">
                            <?php $warna = $a['status'] === 'ok' ? 'bg-green-100 text-green-700 ring-green-200'
                                : ($a['status'] === 'warn' ? 'bg-amber-100 text-amber-700 ring-amber-200' : 'bg-red-100 text-red-700 ring-red-200'); ?>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset <?= $warna ?>">
                                <?= $a['status'] === 'ok' ? 'OK' : ($a['status'] === 'warn' ? 'Perhatikan' : 'Bermasalah') ?>
                            </span>
                        </td>
                        <td class="py-2 pr-4 font-mono text-xs text-gray-900 break-all"><?= htmlspecialchars($a['nilai'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="py-2 text-xs text-gray-500"><?= htmlspecialchars($a['catatan'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ─── Pembuat config ────────────────────────────────────────────── -->
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm mb-6">
        <h2 class="text-lg font-semibold text-gray-900">Config helper</h2>
        <p class="mt-1 text-sm text-gray-500">
            Menghasilkan isi <code>public/config.php</code> untuk disalin. Halaman ini tidak
            menulis berkas PHP apa pun — berkas yang dieksekusi tidak ditulis dari web.
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-sm">
                <span class="block text-gray-700">Bahasa layar (app.locale)</span>
                <select id="ezCfgLocale" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    <option value="en">en</option>
                    <option value="id">id</option>
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-gray-700">Bahasa isi dokumen (document.locale)</span>
                <select id="ezCfgDocLocale" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    <option value="">(ikut bahasa layar)</option>
                    <option value="id">id</option>
                    <option value="en">en</option>
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-gray-700">Penyimpan objek (EZDOC_STORAGE_BACKEND)</span>
                <select id="ezCfgBackend" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    <option value="database">database — byte di kolom</option>
                    <option value="mirror">mirror — kolom + berkas</option>
                    <option value="disk">disk — berkas saja</option>
                </select>
            </label>
            <label class="text-sm">
                <span class="block text-gray-700">Folder objek (EZDOC_STORAGE_PATH)</span>
                <input id="ezCfgPath" type="text" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs"
                       value="<?= htmlspecialchars((string) (\Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot() ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="text-sm sm:col-span-2">
                <span class="block text-gray-700">Berkas bootstrap aplikasi pemakai (yang membuat $conn)</span>
                <input id="ezCfgBootstrap" type="text" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs"
                       value="../../koneksi.php">
            </label>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="button" id="ezCfgBuat"
                    class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90"
                    style="background-color:#2563eb;">Buat</button>
            <button type="button" id="ezCfgSalin"
                    class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Salin</button>
            <span id="ezCfgKabar" class="text-xs text-gray-500"></span>
        </div>
        <pre id="ezCfgHasil" class="mt-3 hidden max-h-80 overflow-auto rounded-md bg-gray-50 p-3 text-xs text-gray-800 ring-1 ring-inset ring-gray-200"></pre>
    </div>

<script>
(function () {
    const log = document.getElementById('ezStorageLog');
    const bar = document.getElementById('ezStorageBar');
    const barIsi = document.getElementById('ezStorageBarIsi');
    const ringkasEl = document.getElementById('ezStorageRingkas');
    const tombol = Array.from(document.querySelectorAll('[data-ez-tugas]'));

    const tulis = (baris) => {
        log.classList.remove('hidden');
        log.textContent += baris + '\n';
        log.scrollTop = log.scrollHeight;
    };
    const kunci = (nyala) => tombol.forEach(b => { b.disabled = nyala; b.classList.toggle('opacity-50', nyala); });

    async function minta(data) {
        const fd = new FormData();
        Object.keys(data).forEach(k => fd.append(k, data[k]));
        const resp = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
        const teks = await resp.text();
        try {
            return JSON.parse(teks);
        } catch (e) {
            throw new Error('Jawaban bukan JSON: ' + teks.slice(0, 200));
        }
    }

    async function muatRingkasan() {
        try {
            const j = await minta({ storage_action: 'ringkas' });
            if (!j.ok) throw new Error(j.pesan || 'gagal');
            const r = j.ringkas;
            const kartu = [
                ['Objek', r.objek], ['Di kolom', r.di_kolom], ['Di disk', r.di_disk],
                ['Berkas', r.berkas < 0 ? '—' : r.berkas],
                ['Inline di dokumen', r.inline], ['Dirujuk', r.dirujuk],
            ];
            ringkasEl.innerHTML = kartu.map(([judul, nilai]) =>
                `<div class="rounded-lg border border-gray-200 bg-white p-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-500">${judul}</div>
                    <div class="mt-0.5 text-xl font-semibold text-gray-900 tabular-nums">${nilai}</div>
                 </div>`).join('');
        } catch (e) {
            ringkasEl.textContent = 'Ringkasan gagal dimuat: ' + e.message;
        }
    }

    // Satu batch pada satu waktu, membawa after_id. Progresnya diukur dari
    // jumlah objek yang sudah dilewati, bukan dari waktu.
    async function batch(tugas, apply, total) {
        let after = 0, jalan = 0;
        const jumlah = { disalin: 0, sudah: 0, rusak: 0, gagal: 0, utuh: 0, masalah: 0 };
        bar.classList.remove('hidden');
        for (;;) {
            const j = await minta({ storage_action: tugas, after_id: after, apply: apply ? '1' : '0' });
            if (!j.ok) throw new Error(j.pesan || 'gagal');
            const b = j.batch;
            ['disalin', 'sudah', 'rusak', 'gagal', 'utuh'].forEach(k => { if (b[k]) jumlah[k] += b[k]; });
            (b.masalah || []).forEach(m => {
                jumlah.masalah++;
                tulis('  ' + m.key.slice(0, 16) + '… ' + m.sebab + (m.detail ? ' — ' + m.detail : ''));
            });
            jalan += (b.diperiksa || 0) || (b.disalin + b.sudah + b.rusak + b.gagal);
            if (total > 0) barIsi.style.width = Math.min(100, Math.round(jalan * 100 / total)) + '%';
            if (b.selesai) break;
            after = b.after_id;
        }
        barIsi.style.width = '100%';
        return jumlah;
    }

    async function jalankan(tugas, apply) {
        kunci(true);
        log.classList.remove('hidden');
        log.textContent = '';
        try {
            const r = await minta({ storage_action: 'ringkas' });
            const total = r.ok ? r.ringkas.objek : 0;

            if (tugas === 'yatim') {
                const j = await minta({ storage_action: 'yatim' });
                if (!j.ok) throw new Error(j.pesan || 'gagal');
                tulis('Objek tak dirujuk melewati 48 jam: ' + j.jumlah + ' (' + Math.round(j.byte / 1024) + ' KB)');
                j.objek.forEach(o => tulis('  ' + o.key.slice(0, 16) + '…  ' + o.byte + ' byte  ' + o.created_at));
                if (j.jumlah > j.objek.length) tulis('  … dan ' + (j.jumlah - j.objek.length) + ' lagi');
                tulis('Tidak ada yang dihapus.');
                return;
            }

            tulis(tugas === 'periksa' ? 'Memeriksa objek…' : (apply ? 'Menyalin ke berkas…' : 'Melihat dulu, tanpa menulis…'));
            const h = await batch(tugas, apply, total);
            if (tugas === 'periksa') {
                tulis('Selesai. Utuh: ' + h.utuh + ', bermasalah: ' + h.masalah + '.');
            } else {
                tulis('Selesai. ' + (apply ? 'Disalin' : 'Akan disalin') + ': ' + h.disalin
                    + ', sudah ada: ' + h.sudah + ', rusak: ' + h.rusak + ', gagal: ' + h.gagal + '.');
                tulis('Basis data tidak diubah.');
                if (apply) muatRingkasan();
            }
        } catch (e) {
            tulis('GAGAL: ' + e.message);
        } finally {
            kunci(false);
        }
    }

    tombol.forEach(b => b.addEventListener('click', () => jalankan(b.dataset.ezTugas, b.dataset.ezApply === '1')));
    muatRingkasan();

    // ── Pembuat config ──
    const cfgHasil = document.getElementById('ezCfgHasil');
    const cfgKabar = document.getElementById('ezCfgKabar');

    document.getElementById('ezCfgBuat').addEventListener('click', function () {
        const locale = document.getElementById('ezCfgLocale').value;
        const docLocale = document.getElementById('ezCfgDocLocale').value;
        const backend = document.getElementById('ezCfgBackend').value;
        const path = document.getElementById('ezCfgPath').value.trim();
        const bootstrap = document.getElementById('ezCfgBootstrap').value.trim() || '../../koneksi.php';

        const baris = [
            '<' + '?php',
            '',
            '/**',
            ' * Mount config untuk public/index.php.',
            ' *',
            ' * Berkas bootstrap aplikasi pemakai menyediakan $conn, identitas, dan role.',
            ' */',
            '',
            "require_once __DIR__ . '/" + bootstrap.replace(/^\.\//, '') + "';",
            '',
        ];
        if (backend !== 'database') {
            baris.push(
                '// Penyimpan objek. `mirror` menulis kolom DAN berkas — dipakai selama',
                '// pemasangan lain masih membaca kolom `ezdoc_blobs.content`.',
                "if (!defined('EZDOC_STORAGE_BACKEND')) {",
                "    define('EZDOC_STORAGE_BACKEND', '" + backend + "');",
                '}',
            );
            if (path) {
                baris.push(
                    "if (!defined('EZDOC_STORAGE_PATH')) {",
                    "    define('EZDOC_STORAGE_PATH', '" + path.replace(/\\/g, '/').replace(/'/g, "\\'") + "');",
                    '}',
                );
            }
            baris.push('');
        }
        baris.push(
            'return [',
            "    'app.db' => $conn ?? null,",
            '',
            '    // Identitas dan role berjalan berpasangan.',
            "    'app.author_id'    => $author_id ?? null,",
            "    'app.author_roles' => $author_role_array ?? [],",
            '',
            "    // Bahasa antarmuka.",
            "    'app.locale' => '" + locale + "',",
        );
        if (docLocale) {
            baris.push(
                '',
                '    // Bahasa ISI dokumen — tanggal dan angka di dokumen, cetakan, dan',
                '    // arsip PDF bekunya. Kosong = ikut app.locale.',
                "    'document.locale' => '" + docLocale + "',",
            );
        }
        baris.push('];', '');

        cfgHasil.textContent = baris.join('\n');
        cfgHasil.classList.remove('hidden');
        cfgKabar.textContent = 'Salin isinya ke public/config.php.';
    });

    document.getElementById('ezCfgSalin').addEventListener('click', async function () {
        if (cfgHasil.classList.contains('hidden')) { cfgKabar.textContent = 'Tekan Buat dulu.'; return; }
        try {
            await navigator.clipboard.writeText(cfgHasil.textContent);
            cfgKabar.textContent = 'Tersalin.';
        } catch (e) {
            // Halaman yang dibuka lewat HTTP bukan secure context: clipboard API mati.
            const r = document.createRange();
            r.selectNodeContents(cfgHasil);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(r);
            cfgKabar.textContent = 'Clipboard tidak tersedia — teksnya sudah diblok, tekan Ctrl+C.';
        }
    });
})();
</script>
</section>
