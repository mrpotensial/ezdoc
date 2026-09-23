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
                    throw new \RuntimeException('EZDOC_STORAGE_PATH is not set — there is nowhere to put the files.');
                }
                echo json_encode([
                    'ok' => true,
                    'batch' => $rawat->copyToFilesBatch($filesx, $after, 200, ($_POST['apply'] ?? '') === '1'),
                ]);
                break;

            case 'periksa':
                echo json_encode([
                    'ok' => true,
                    // Penyimpan artefak ikut ditanya: pemasangan ini menaruh
                    // dokumen beku di folder lain daripada gambar tanda tangan.
                    'batch' => $rawat->checkBatch(
                        \Ezdoc\Storage\BlobStoreFactory::make($dbx),
                        $filesx,
                        $after,
                        200,
                        \Ezdoc\Storage\BlobStoreFactory::forArtifacts($dbx)
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

            case 'uji_adapter':
                // "Kelasnya ada" bukan "ia bekerja". Perender yang termuat tapi
                // kehilangan fontnya, atau pustaka QR yang gagal menulis berkas
                // sementara, baru ketahuan saat dokumen sungguhan dicetak — pada
                // orang yang sedang buru-buru. Uji ini memindahkan penemuan itu
                // ke halaman pengaturan. Tidak ada yang ditulis ke mana pun.
                $mana = (string) ($_POST['adapter'] ?? '');
                $mulai = microtime(true);
                try {
                    if ($mana === 'qr') {
                        $gambar = function_exists('ezdoc_qr_data_uri')
                            ? ezdoc_qr_data_uri('ezdoc-selftest', 120, 2)
                            : '';
                        if ($gambar === '') {
                            throw new \RuntimeException('No QR renderer produced an image.');
                        }
                        echo json_encode([
                            'ok'     => true,
                            'ms'     => (int) round((microtime(true) - $mulai) * 1000),
                            'gambar' => $gambar,
                            'pesan'  => 'QR drawn, ' . strlen($gambar) . ' bytes',
                        ]);
                        break;
                    }

                    $perender = function_exists('ezdoc_pdf_renderer') ? ezdoc_pdf_renderer() : null;
                    if ($perender === null) {
                        throw new \RuntimeException('No PDF renderer is available.');
                    }
                    $bytes = $perender->render(
                        '<!DOCTYPE html><html><body style="font-family:sans-serif">'
                        . '<h1>ezdoc self-test</h1><p>Rendered at ' . date('Y-m-d H:i:s') . '.</p></body></html>',
                        [210.0, 297.0],
                        'portrait'
                    );
                    if (strncmp($bytes, '%PDF', 4) !== 0) {
                        throw new \RuntimeException('The renderer returned something that is not a PDF.');
                    }
                    echo json_encode([
                        'ok'    => true,
                        'ms'    => (int) round((microtime(true) - $mulai) * 1000),
                        'pesan' => 'PDF rendered, ' . round(strlen($bytes) / 1024, 1) . ' KB, by ' . get_class($perender),
                    ]);
                } catch (\Throwable $e) {
                    echo json_encode(['ok' => false, 'pesan' => $e->getMessage()]);
                }
                break;

            case 'inline':
                // Satu-satunya tugas di halaman ini yang menulis ke dokumen.
                // Slot yang sudah ditandatangani dilewati — nilainya termasuk
                // yang ditandatangani, dan menukarnya membatalkan tanda tangannya.
                echo json_encode([
                    'ok' => true,
                    'batch' => $rawat->migrateInlineBatch(
                        new \Ezdoc\Storage\SignatureImageStore(\Ezdoc\Storage\BlobStoreFactory::make($dbx)),
                        $after,
                        50,
                        ($_POST['apply'] ?? '') === '1'
                    ),
                ]);
                break;

            case 'hapus_yatim':
                // Menghapus byte tidak bisa dibatalkan, jadi yang membuka halaman
                // harus MENGETIK persetujuannya — bukan sekadar menekan tombol
                // kedua. Pola "type to confirm" GitHub dan Stripe.
                if (($_POST['konfirmasi'] ?? '') !== 'DELETE') {
                    echo json_encode(['ok' => false, 'pesan' => 'Type DELETE to confirm.']);
                    break;
                }
                $store = \Ezdoc\Storage\BlobStoreFactory::make($dbx);
                $terhapus = 0;
                $byte = 0;
                foreach ($rawat->orphans(48)['objek'] as $objek) {
                    if ($store->delete($objek['key'])) {
                        $terhapus++;
                        $byte += $objek['byte'];
                        if ($filesx !== null) {
                            $filesx->deleteFile($objek['key']);
                        }
                    }
                }
                if (function_exists('ezdoc_audit_log') && $terhapus > 0) {
                    // Dicatat: ini satu-satunya jalan di aplikasi yang menghapus
                    // byte, dan "siapa menghapus apa" harus bisa ditelusuri.
                    @ezdoc_audit_log('blob.purged', [
                        'result'  => 'success',
                        'message' => $terhapus . ' unreferenced objects deleted (' . $byte . ' bytes)',
                    ]);
                }
                echo json_encode(['ok' => true, 'terhapus' => $terhapus, 'byte' => $byte]);
                break;

            case 'kesehatan':
                echo json_encode([
                    'ok' => true,
                    'kesehatan' => (new \Ezdoc\Admin\Diagnostics($dbx))->health(),
                    'penyimpanan' => $rawat->summary($filesx),
                ]);
                break;

            case 'audit':
                $diag = new \Ezdoc\Admin\Diagnostics($dbx);
                // Alamat IP mengikuti aturan yang sama dengan panel riwayat
                // dokumen: ia data pribadi, dan halaman ini bukan pengecualian
                // hanya karena yang membukanya superadmin.
                $bolehDetail = function_exists('ezdoc_can_view_audit_detail') && ezdoc_can_view_audit_detail();
                $baris = $diag->recentEvents(
                    [
                        'event_type' => (string) ($_POST['event_type'] ?? ''),
                        'result'     => (string) ($_POST['result'] ?? ''),
                        'actor'      => (string) ($_POST['actor'] ?? ''),
                        'doc_id'     => (int) ($_POST['doc_id'] ?? 0),
                    ],
                    50,
                    (int) ($_POST['offset'] ?? 0)
                );
                foreach ($baris as $i => $b) {
                    if (!$bolehDetail) {
                        unset($baris[$i]['ip_address'], $baris[$i]['actor_roles']);
                    }
                }
                echo json_encode([
                    'ok'      => true,
                    'baris'   => array_values($baris),
                    'jenis'   => $diag->eventTypes(),
                    'detail'  => $bolehDetail,
                ]);
                break;

            case 'akses':
                echo json_encode([
                    'ok'     => true,
                    'daftar' => (new \Ezdoc\Admin\Diagnostics($dbx))->templateAccess(),
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
    $tambah('Database', 'bad', 'no answer', $e->getMessage());
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
            'Database privileges',
            $berisiko === [] ? 'ok' : 'warn',
            $berisiko === [] ? 'limited' : implode(', ', $berisiko),
            $berisiko === [] ? '' : 'The application needs SELECT/INSERT/UPDATE/DELETE, plus CREATE/ALTER while migrating.'
        );
    }
} catch (\Throwable $e) {
    // Hak akses tidak bisa dibaca bukan kesalahan — sebagian pemasangan memang menutupnya.
    $tambah('Database privileges', 'warn', 'not readable', $e->getMessage());
}

// Penyimpan objek
$backend = \Ezdoc\Storage\BlobStoreFactory::configuredBackend();
$rootAda = \Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot();
$tambah('Object storage', 'ok', $backend, $backend === 'mirror' ? 'column and files, both written' : '');
if ($rootAda !== null) {
    $bisaTulis = is_dir($rootAda) && is_writable($rootAda);
    $tambah('Object folder', $bisaTulis ? 'ok' : 'bad', $rootAda, $bisaTulis ? '' : 'missing, or PHP cannot write to it');

    // Di dalam folder yang dilayani web? Itu bukan kesalahan kalau ditutup,
    // tapi penutupnya harus ada dan harus terbukti.
    $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $nyataRoot = (string) (realpath($rootAda) ?: $rootAda);
    $diDalamWeb = $docroot !== '' && strpos($nyataRoot, (string) (realpath($docroot) ?: $docroot)) === 0;
    if ($diDalamWeb) {
        $punyaHtaccess = is_file($rootAda . '/.htaccess');
        $tambah(
            'Reachable over the web?',
            $punyaHtaccess ? 'warn' : 'bad',
            'inside the document root',
            $punyaHtaccess
                ? 'An .htaccess is present. It does NOT apply on nginx or with AllowOverride None — open the folder URL, 403 is the right answer.'
                : 'No .htaccess. Anyone holding the URL can fetch a signature image.'
        );
    } else {
        $tambah('Reachable over the web?', 'ok', 'outside the document root');
    }
} elseif ($backend !== 'database') {
    $tambah('Object folder', 'bad', 'not set', 'EZDOC_STORAGE_PATH is empty while the backend needs files.');
}

// Perender PDF dan QR — kontrak opsional, jalur usang tetap dihitung.
$pdfAdapter = function_exists('ezdoc_pdf_renderer') ? ezdoc_pdf_renderer() : ($ctxq->pdf ?? null);
if ($pdfAdapter !== null) {
    $tambah(
        'PDF renderer',
        'ok',
        get_class($pdfAdapter),
        ($ctxq->pdf ?? null) !== null ? 'wired in by the host application' : "library default, using the host's dompdf"
    );
} else {
    $tambah('PDF renderer', 'bad', 'none', 'Printing PDFs and freezing documents will fail.');
}

$qrAdapter = $ctxq->qr ?? null;
if ($qrAdapter !== null) {
    $tambah('QR renderer', 'ok', get_class($qrAdapter));
} elseif (function_exists('generateQrForDompdf')) {
    $tambah('QR renderer', 'warn', 'legacy function', 'Deprecated path. Wire the QrRenderer contract through Context::withQr().');
} else {
    $tambah('QR renderer', 'warn', 'none', 'QR codes in documents will not be drawn.');
}

// Kunci tanda tangan — keberadaannya saja, bukan isinya.
try {
    $adaKunci = \Ezdoc\Signature\SigningKey::isConfigured();
    $tambah('Signing key', $adaKunci ? 'ok' : 'bad', $adaKunci ? 'configured' : 'missing',
        $adaKunci ? '' : 'Signatures will not be recorded into ezdoc_signatures.');
} catch (\Throwable $e) {
    $tambah('Signing key', 'warn', 'not readable', $e->getMessage());
}

// Penyedia role
$tambah(
    'Role provider',
    function_exists('ezdoc_has_role') ? 'ok' : 'bad',
    function_exists('ezdoc_has_role') ? implode(', ', array_slice(ezdoc_current_user_roles(), 0, 6)) ?: 'no roles' : 'none',
    'roles of the user viewing this page'
);

$setelanAktif = (new \Ezdoc\Admin\Diagnostics($dbq))->settings($config);

// Bahasa
$tambah('Interface language', 'ok', function_exists('ezdoc_locale') ? ezdoc_locale() : 'en', 'app.locale');
$tambah(
    'Document language',
    'ok',
    function_exists('ezdoc_document_locale') ? ezdoc_document_locale() : 'en',
    'document.locale — empty follows the interface language'
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
<section class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
    <header>
        <h1 class="text-xl font-semibold tracking-tight text-gray-900">Settings</h1>
        <p class="mt-1 text-sm text-gray-500">
            Storage, adapters and database migrations for this installation.
        </p>
    </header>

    <?php if ($actionMsg): ?>
        <?php
        $kabarCls = $actionType === 'error'
            ? 'border-red-200 bg-red-50 text-red-800'
            : ($actionType === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-gray-200 bg-gray-50 text-gray-700');
        ?>
        <div class="mt-4 rounded-md border px-4 py-3 text-sm <?= $kabarCls ?>">
            <?= htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- Tab, bukan satu halaman panjang: empat urusan berbeda yang jarang
         dikerjakan bersamaan, dan yang paling sering dipakai ada di depan. -->
    <div class="mt-6 border-b border-gray-200">
        <nav id="ezTabs" class="-mb-px flex gap-1 overflow-x-auto" aria-label="Sections">
            <?php foreach ([
                'overview'   => 'Overview',
                'storage'    => 'Storage',
                'adapters'   => 'Adapters',
                'access'     => 'Access',
                'audit'      => 'Audit log',
                'config'     => 'Config',
                'migrations' => 'Migrations',
            ] as $kunci => $judul): ?>
                <button type="button" data-ez-tab="<?= $kunci ?>"
                        class="whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm font-medium text-gray-500 hover:text-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-400">
                    <?= $judul ?>
                    <?php if ($kunci === 'migrations' && ($pendingCount + $orphanCount) > 0): ?>
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-gray-600"><?= $pendingCount + $orphanCount ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- ─── Overview ─────────────────────────────────────────────────── -->
    <div data-ez-panel="overview" class="hidden pt-6">
        <div id="ezSehat" class="space-y-4">
            <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 text-sm text-gray-400">Loading…</div>
        </div>
    </div>

    <!-- ─── Storage ──────────────────────────────────────────────────── -->
    <div data-ez-panel="storage" class="hidden pt-6">
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Stored objects</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Signature images and frozen PDFs. Copying them to files does not change the
                    database — an older installation reading the column sees exactly what it saw before.
                </p>
            </div>

            <dl id="ezStorageRingkas" class="grid grid-cols-2 divide-x divide-y divide-gray-100 sm:grid-cols-3 lg:grid-cols-6 lg:divide-y-0">
                <div class="px-5 py-4 text-sm text-gray-400">Loading…</div>
            </dl>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-4">
                <button type="button" data-ez-tugas="salin" data-ez-apply="1"
                        class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-1"
                        style="background-color: var(--ezdoc-primary, #111827);">
                    Copy signatures to files
                </button>
                <button type="button" data-ez-tugas="salin" data-ez-apply="0"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Preview only
                </button>
                <button type="button" data-ez-tugas="periksa"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Verify integrity
                </button>
                <button type="button" data-ez-tugas="yatim"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Unreferenced objects
                </button>
                <button type="button" data-ez-tugas="inline" data-ez-apply="1"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Move inline images out
                </button>
            </div>

            <div id="ezStorageBar" class="hidden px-5">
                <div class="h-1 w-full overflow-hidden rounded-full bg-gray-100">
                    <div id="ezStorageBarIsi" class="h-full transition-all" style="width:0; background-color: var(--ezdoc-primary, #111827);"></div>
                </div>
            </div>
            <pre id="ezStorageLog" class="mx-5 mt-3 hidden max-h-64 overflow-auto whitespace-pre-wrap rounded-md bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 ring-1 ring-inset ring-gray-200"></pre>

            <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">
                Moving inline images out skips slots that are already signed: a signature covers the
                value of its slot, so swapping it would invalidate the signature.
            </p>
        </div>

        <!-- Menghapus byte: dipisah dari tugas lain, dan harus diketik. -->
        <div class="mt-4 rounded-lg border border-red-200 bg-white">
            <div class="border-b border-red-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-red-800">Delete unreferenced objects</h2>
                <p class="mt-0.5 text-sm text-gray-600">
                    Removes objects that no document and no archive points at, older than 48 hours.
                    The bytes are gone afterwards — read the list above first. Objects newer than that
                    are kept: a save that stored an image can still be rejected and retried.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 px-5 py-4">
                <input id="ezHapusKonfirmasi" type="text" spellcheck="false" placeholder="Type DELETE"
                       class="w-40 rounded-md border-gray-300 text-sm shadow-sm">
                <button type="button" data-ez-tugas="hapus_yatim"
                        class="inline-flex items-center rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-800 shadow-sm transition hover:bg-red-100">
                    Delete them
                </button>
                <span class="text-xs text-gray-500">Recorded in the audit log.</span>
            </div>
        </div>
    </div>

    <!-- ─── Adapters ─────────────────────────────────────────────────── -->
    <div data-ez-panel="adapters" class="hidden pt-6">
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Adapters &amp; permissions</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    What the host application has wired in, and whether the permissions around it are safe.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-5 py-3">
                <button type="button" data-ez-uji="pdf"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Render a test PDF
                </button>
                <button type="button" data-ez-uji="qr"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Draw a test QR
                </button>
                <span id="ezUjiKabar" class="text-xs text-gray-500"></span>
                <img id="ezUjiGambar" alt="" class="ml-auto hidden h-14 w-14 rounded border border-gray-200">
            </div>

            <ul class="divide-y divide-gray-100">
                <?php foreach ($adapters as $a): ?>
                    <?php
                    $titik = $a['status'] === 'ok' ? 'bg-green-500' : ($a['status'] === 'warn' ? 'bg-amber-500' : 'bg-red-500');
                    ?>
                    <li class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-baseline sm:gap-4">
                        <span class="flex w-44 shrink-0 items-center gap-2 text-sm font-medium text-gray-700">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full <?= $titik ?>" aria-hidden="true"></span>
                            <?= htmlspecialchars($a['nama'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="min-w-0 flex-1 break-all font-mono text-xs text-gray-900"><?= htmlspecialchars($a['nilai'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($a['catatan'] !== ''): ?>
                            <span class="flex-1 text-xs <?= $a['status'] === 'ok' ? 'text-gray-500' : 'text-gray-700' ?>"><?= htmlspecialchars($a['catatan'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- ─── Access ───────────────────────────────────────────────────── -->
    <div data-ez-panel="access" class="hidden pt-6">
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Who can do what</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Per template, from its access config. A template with no rules of its own falls back to
                    the library defaults — the state most often mistaken for "already restricted".
                </p>
            </div>
            <div id="ezAkses" class="px-5 py-4 text-sm text-gray-400">Loading…</div>
        </div>
    </div>

    <!-- ─── Audit log ────────────────────────────────────────────────── -->
    <div data-ez-panel="audit" class="hidden pt-6">
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Recent events</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Everything the library records, newest first. Until now this was only visible one
                    document at a time, so a failure like <code class="rounded bg-gray-100 px-1 py-0.5">doc.freeze_failed</code>
                    stayed invisible unless someone opened that document.
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-2 border-b border-gray-100 px-5 py-3">
                <label class="text-xs text-gray-600">
                    Event
                    <select id="ezAuditJenis" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">all</option>
                    </select>
                </label>
                <label class="text-xs text-gray-600">
                    Result
                    <select id="ezAuditHasil" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">all</option>
                        <option value="success">success</option>
                        <option value="denied">denied</option>
                        <option value="error">error</option>
                    </select>
                </label>
                <label class="text-xs text-gray-600">
                    Actor
                    <input id="ezAuditPelaku" type="text" class="mt-1 block w-28 rounded-md border-gray-300 text-sm shadow-sm" placeholder="id">
                </label>
                <label class="text-xs text-gray-600">
                    Document
                    <input id="ezAuditDok" type="text" inputmode="numeric" class="mt-1 block w-24 rounded-md border-gray-300 text-sm shadow-sm" placeholder="id">
                </label>
                <button type="button" id="ezAuditMuat"
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Apply
                </button>
                <span id="ezAuditKabar" class="ml-auto text-xs text-gray-500"></span>
            </div>

            <div id="ezAuditIsi" class="max-h-[32rem] overflow-auto px-5 py-4 text-sm text-gray-400">Loading…</div>

            <div class="flex items-center justify-between border-t border-gray-100 px-5 py-3">
                <button type="button" id="ezAuditSebelum"
                        class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Newer
                </button>
                <button type="button" id="ezAuditSesudah"
                        class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    Older
                </button>
            </div>
        </div>
    </div>

    <!-- ─── Config ───────────────────────────────────────────────────── -->
    <div data-ez-panel="config" class="hidden pt-6">
        <!-- Yang berlaku SEKARANG, ditulis untuk dibaca. Pertanyaan "setelannya
             apa?" jauh lebih sering muncul daripada "buatkan berkasnya", jadi
             jawabannya ada di atas, bukan di bawah formulir. -->
        <div class="mb-4 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">What is in effect now</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    What this installation is actually using, and where each value comes from.
                    &ldquo;Library default&rdquo; means nobody ever set it.
                </p>
            </div>
            <ul class="divide-y divide-gray-100">
                <?php foreach ($setelanAktif as $baris): ?>
                    <?php $bawaan = strpos($baris['asal'], 'Library default') === 0 || strpos($baris['asal'], 'Not set') === 0; ?>
                    <li class="px-5 py-3 sm:flex sm:items-baseline sm:gap-4">
                        <span class="block w-56 shrink-0 text-sm text-gray-500"><?= htmlspecialchars($baris['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="block min-w-0 flex-1">
                            <span class="block break-words text-sm font-medium text-gray-900"><?= htmlspecialchars($baris['nilai'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="mt-0.5 block text-xs text-gray-500"><?= htmlspecialchars($baris['arti'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <span class="mt-1 block shrink-0 text-xs sm:mt-0 <?= $bawaan ? 'text-amber-700' : 'text-gray-400' ?>">
                            <?= htmlspecialchars($baris['asal'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Config file</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Builds the contents of <code class="rounded bg-gray-100 px-1 py-0.5">public/config.php</code> to copy.
                    This page never writes a PHP file — a page on the web does not write code that the server executes.
                </p>
            </div>

            <!-- Formulir dimulai dari yang berlaku sekarang: berkas config yang
                 dibuat dari halaman ini nyaris selalu berupa perubahan kecil atas
                 yang sudah ada, bukan tulisan dari nol. -->
            <div class="grid gap-4 px-5 py-4 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="text-gray-700">Interface language <span class="text-gray-400">(app.locale)</span></span>
                    <?php $locAktif = (string) $config->get('app.locale', 'en'); ?>
                    <select id="ezCfgLocale" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="en" <?= $locAktif === 'en' ? 'selected' : '' ?>>en</option>
                        <option value="id" <?= $locAktif === 'id' ? 'selected' : '' ?>>id</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-700">Document language <span class="text-gray-400">(document.locale)</span></span>
                    <?php $docAktif = trim((string) $config->get('document.locale', '')); ?>
                    <select id="ezCfgDocLocale" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="" <?= $docAktif === '' ? 'selected' : '' ?>>same as interface</option>
                        <option value="id" <?= $docAktif === 'id' ? 'selected' : '' ?>>id</option>
                        <option value="en" <?= $docAktif === 'en' ? 'selected' : '' ?>>en</option>
                    </select>
                    <span class="mt-1 block text-xs text-gray-500">Dates and numbers inside documents, printouts and frozen PDFs.</span>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-700">Object storage <span class="text-gray-400">(EZDOC_STORAGE_BACKEND)</span></span>
                    <?php $backendAktif = \Ezdoc\Storage\BlobStoreFactory::configuredBackend(); ?>
                    <select id="ezCfgBackend" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="database" <?= $backendAktif === 'database' ? 'selected' : '' ?>>database — bytes in the column</option>
                        <option value="mirror" <?= $backendAktif === 'mirror' ? 'selected' : '' ?>>mirror — column and files</option>
                        <option value="disk" <?= $backendAktif === 'disk' ? 'selected' : '' ?>>disk — files only</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-700">Object folder <span class="text-gray-400">(EZDOC_STORAGE_PATH)</span></span>
                    <input id="ezCfgPath" type="text" spellcheck="false"
                           class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs shadow-sm"
                           value="<?= htmlspecialchars((string) (\Ezdoc\Storage\BlobStoreFactory::configuredDiskRoot() ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="text-gray-700">Host bootstrap file <span class="text-gray-400">(the file that creates <code>$conn</code>, relative to public/)</span></span>
                    <input id="ezCfgBootstrap" type="text" spellcheck="false"
                           class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs shadow-sm" value="../../koneksi.php">
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-4">
                <button type="button" id="ezCfgBuat"
                        class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:opacity-90"
                        style="background-color: var(--ezdoc-primary, #111827);">Generate</button>
                <button type="button" id="ezCfgSalin"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">Copy</button>
                <span id="ezCfgKabar" class="text-xs text-gray-500"></span>
            </div>
            <pre id="ezCfgHasil" class="mx-5 mb-5 hidden max-h-80 overflow-auto rounded-md bg-gray-50 p-3 text-xs leading-relaxed text-gray-800 ring-1 ring-inset ring-gray-200"></pre>
        </div>
    </div>

    <!-- ─── Migrations ───────────────────────────────────────────────── -->
    <div data-ez-panel="migrations" class="hidden pt-6">
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-sm font-semibold text-gray-900">Database migrations</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Migrations already run at bootstrap. This is for explicit control and for seeing what happened.
                </p>
            </div>

            <dl class="grid grid-cols-2 divide-x divide-y divide-gray-100 sm:grid-cols-4 sm:divide-y-0">
                <?php foreach ([
                    ['Applied', $appliedCount, 'has file, in registry'],
                    ['Pending', $pendingCount, 'has file, not run'],
                    ['Orphan', $orphanCount, 'registry entry, no file'],
                    ['Templates', (int) ($floatingStats['total'] ?? 0), 'with content'],
                ] as [$judul, $nilai, $catatan]): ?>
                    <div class="px-5 py-4">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500"><?= $judul ?></dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900"><?= (int) $nilai ?></dd>
                        <dd class="mt-0.5 text-[11px] text-gray-400"><?= $catatan ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-4">
                <form method="POST" onsubmit="return confirm('Run <?= $pendingCount ?> pending migration(s)?');">
                    <input type="hidden" name="action" value="run_pending">
                    <button type="submit" <?= $pendingCount > 0 ? '' : 'disabled' ?>
                            class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium shadow-sm transition <?= $pendingCount > 0 ? 'text-white hover:opacity-90' : 'cursor-not-allowed border border-gray-200 bg-gray-50 text-gray-400' ?>"
                            <?= $pendingCount > 0 ? 'style="background-color: var(--ezdoc-primary, #111827);"' : '' ?>>
                        Run <?= $pendingCount ?> pending
                    </button>
                </form>

                <?php if ($orphanCount > 0): ?>
                <form method="POST" onsubmit="return confirm('Remove <?= $orphanCount ?> registry entries that have no migration file? Nothing is dropped from the database.');">
                    <input type="hidden" name="action" value="prune_orphan_registry">
                    <button type="submit"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                        Prune <?= $orphanCount ?> orphan entries
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($floatingStats && (int) $floatingStats['has_embedded_markers'] > 0): ?>
                <form method="POST" onsubmit="return confirm('Migrate <?= (int) $floatingStats['has_embedded_markers'] ?> template(s) with embedded floating markers to sidecar JSON? Back up the database first.');">
                    <input type="hidden" name="action" value="migrate_floating">
                    <button type="submit"
                            class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 shadow-sm transition hover:bg-amber-100">
                        Migrate floating elements (<?= (int) $floatingStats['has_embedded_markers'] ?>)
                    </button>
                </form>
                <?php endif; ?>

                <span class="text-xs text-gray-500">
                    Destructive operations (reset, drop) stay in the CLI: <code class="rounded bg-gray-100 px-1 py-0.5">php cli/migrate.php reset</code>
                </span>
            </div>

            <div class="max-h-96 overflow-auto border-t border-gray-100">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Migration</th>
                            <th class="px-5 py-2 font-medium">Status</th>
                            <th class="px-5 py-2 font-medium">Executed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($allMigrations as $m): ?>
                            <?php
                            $titik = $m['status'] === 'applied' ? 'bg-green-500' : ($m['status'] === 'pending' ? 'bg-amber-500' : 'bg-gray-400');
                            ?>
                            <tr>
                                <td class="px-5 py-2 font-mono text-xs text-gray-700"><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-5 py-2">
                                    <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                                        <span class="h-1.5 w-1.5 rounded-full <?= $titik ?>" aria-hidden="true"></span>
                                        <?= $m['status'] === 'orphan' ? 'Orphan (no file)' : ucfirst($m['status']) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-2 text-xs tabular-nums text-gray-500">
                                    <?= $m['executed_at'] ? htmlspecialchars($m['executed_at'], ENT_QUOTES, 'UTF-8') : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    // ── Tab ──
    // Pilihan terakhir diingat, dan `?tab=` menang atasnya: tautan ke satu tab
    // harus membuka tab itu, bukan tab yang terakhir dibuka orangnya.
    const tombolTab = Array.from(document.querySelectorAll('[data-ez-tab]'));

    // Isi tab diambil saat tab itu dibuka, sekali saja: halaman yang
    // menanyakan semuanya di muka membuat orang menunggu tujuh kueri untuk
    // satu panel yang dilihatnya.
    const pemuat = {};
    const sudahDimuat = {};
    let tabAktif = '';
    const muatTab = (kunci) => {
        if (sudahDimuat[kunci] || !pemuat[kunci]) return;
        sudahDimuat[kunci] = true;
        pemuat[kunci]();
    };
    const panel = (k) => document.querySelector('[data-ez-panel="' + k + '"]');
    const aktifkan = (kunci) => {
        if (!panel(kunci)) kunci = tombolTab[0].dataset.ezTab;
        tombolTab.forEach((b) => {
            const aktif = b.dataset.ezTab === kunci;
            b.classList.toggle('text-gray-900', aktif);
            b.classList.toggle('text-gray-500', !aktif);
            b.classList.toggle('border-transparent', !aktif);
            b.style.borderColor = aktif ? 'var(--ezdoc-primary, #111827)' : '';
            b.setAttribute('aria-selected', aktif ? 'true' : 'false');
            panel(b.dataset.ezTab).classList.toggle('hidden', !aktif);
        });
        tabAktif = kunci;
        try { localStorage.setItem('ezdoc_admin_tab', kunci); } catch (e) {}
        muatTab(kunci);
    };
    tombolTab.forEach((b) => b.addEventListener('click', () => aktifkan(b.dataset.ezTab)));
    let awal = new URLSearchParams(window.location.search).get('tab');
    if (!awal) { try { awal = localStorage.getItem('ezdoc_admin_tab'); } catch (e) {} }
    aktifkan(awal || 'storage');

    // ── Penyimpanan objek ──
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
    const kunci = (nyala) => tombol.forEach((b) => {
        b.disabled = nyala;
        b.classList.toggle('opacity-40', nyala);
        b.classList.toggle('cursor-not-allowed', nyala);
    });
    const angka = (n) => (typeof n === 'number' ? n.toLocaleString() : n);
    const esc = (t) => { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };

    async function minta(data) {
        const fd = new FormData();
        Object.keys(data).forEach((k) => fd.append(k, data[k]));
        const resp = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
        const teks = await resp.text();
        try {
            return JSON.parse(teks);
        } catch (e) {
            throw new Error('Reply was not JSON: ' + teks.slice(0, 200));
        }
    }

    async function muatRingkasan() {
        try {
            const j = await minta({ storage_action: 'ringkas' });
            if (!j.ok) throw new Error(j.pesan || 'failed');
            const r = j.ringkas;
            const kartu = [
                ['Objects', r.objek, 'rows in ezdoc_blobs'],
                ['In column', r.di_kolom, 'bytes in the database'],
                ['On disk', r.di_disk, 'bytes in files only'],
                ['File copies', r.berkas < 0 ? '—' : r.berkas, 'files on disk'],
                ['Inline', r.inline, 'images inside documents'],
                ['Referenced', r.dirujuk, 'by documents or archives'],
            ];
            ringkasEl.innerHTML = kartu.map(([judul, nilai, catatan]) =>
                '<div class="px-5 py-4">'
                + '<dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">' + judul + '</dt>'
                + '<dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">' + angka(nilai) + '</dd>'
                + '<dd class="mt-0.5 text-[11px] text-gray-400">' + catatan + '</dd>'
                + '</div>').join('');
        } catch (e) {
            ringkasEl.innerHTML = '<div class="px-5 py-4 text-sm text-red-700">Summary failed to load: ' + e.message + '</div>';
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
            if (!j.ok) throw new Error(j.pesan || 'failed');
            const b = j.batch;
            ['disalin', 'sudah', 'rusak', 'gagal', 'utuh', 'dipindah', 'dilewati', 'dokumen'].forEach((k) => {
                if (b[k]) jumlah[k] = (jumlah[k] || 0) + b[k];
            });
            (b.masalah || []).forEach((m) => {
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
        log.textContent = '';
        log.classList.remove('hidden');
        try {
            const r = await minta({ storage_action: 'ringkas' });
            const total = r.ok ? r.ringkas.objek : 0;

            if (tugas === 'hapus_yatim') {
                const j = await minta({
                    storage_action: 'hapus_yatim',
                    konfirmasi: document.getElementById('ezHapusKonfirmasi').value.trim(),
                });
                if (!j.ok) throw new Error(j.pesan || 'failed');
                tulis('Deleted ' + angka(j.terhapus) + ' objects, ' + Math.round(j.byte / 1024) + ' KB.');
                document.getElementById('ezHapusKonfirmasi').value = '';
                muatRingkasan();
                return;
            }

            if (tugas === 'inline') {
                const h = await batch('inline', apply, 0);
                tulis('Done. Moved: ' + angka(h.dipindah) + ', left signed: ' + angka(h.dilewati)
                    + ', failed: ' + h.gagal + ', documents rewritten: ' + angka(h.dokumen) + '.');
                muatRingkasan();
                return;
            }

            if (tugas === 'yatim') {
                const j = await minta({ storage_action: 'yatim' });
                if (!j.ok) throw new Error(j.pesan || 'failed');
                tulis('Unreferenced for more than 48 hours: ' + angka(j.jumlah) + ' objects, ' + Math.round(j.byte / 1024) + ' KB');
                j.objek.forEach((o) => tulis('  ' + o.key.slice(0, 16) + '…  ' + o.byte + ' bytes  ' + o.created_at));
                if (j.jumlah > j.objek.length) tulis('  … and ' + (j.jumlah - j.objek.length) + ' more');
                tulis('Nothing was deleted.');
                return;
            }

            tulis(tugas === 'periksa'
                ? 'Verifying objects…'
                : (apply ? 'Copying to files…' : 'Preview only, nothing is written…'));
            const h = await batch(tugas, apply, total);
            if (tugas === 'periksa') {
                tulis('Done. Intact: ' + angka(h.utuh) + ', problems: ' + angka(h.masalah) + '.');
            } else {
                tulis('Done. ' + (apply ? 'Copied' : 'Would copy') + ': ' + angka(h.disalin)
                    + ', already there: ' + angka(h.sudah) + ', corrupt: ' + h.rusak + ', failed: ' + h.gagal + '.');
                tulis('The database was not modified.');
                if (apply) muatRingkasan();
            }
        } catch (e) {
            tulis('FAILED: ' + e.message);
        } finally {
            kunci(false);
        }
    }

    tombol.forEach((b) => b.addEventListener('click', () => jalankan(b.dataset.ezTugas, b.dataset.ezApply === '1')));
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
            ' * Mount config for public/index.php.',
            ' *',
            ' * The host bootstrap supplies $conn, the user id and the roles.',
            ' */',
            '',
            "require_once __DIR__ . '/" + bootstrap.replace(/^\.\//, '') + "';",
            '',
        ];
        if (backend !== 'database') {
            baris.push(
                '// Object storage. `mirror` writes the column AND files — used while',
                '// another installation still reads ezdoc_blobs.content.',
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
            '    // Identity and roles travel together.',
            "    'app.author_id'    => $author_id ?? null,",
            "    'app.author_roles' => $author_role_array ?? [],",
            '',
            '    // Interface language.',
            "    'app.locale' => '" + locale + "',",
        );
        if (docLocale) {
            baris.push(
                '',
                '    // Language of the DOCUMENT CONTENT — dates and numbers inside',
                '    // documents, printouts and frozen PDFs. Empty follows app.locale.',
                "    'document.locale' => '" + docLocale + "',",
            );
        }
        baris.push('];', '');

        cfgHasil.textContent = baris.join('\n');
        cfgHasil.classList.remove('hidden');
        cfgKabar.textContent = 'Copy this into public/config.php.';
    });

    document.getElementById('ezCfgSalin').addEventListener('click', async function () {
        if (cfgHasil.classList.contains('hidden')) { cfgKabar.textContent = 'Press Generate first.'; return; }
        try {
            await navigator.clipboard.writeText(cfgHasil.textContent);
            cfgKabar.textContent = 'Copied.';
        } catch (e) {
            // Halaman lewat HTTP bukan secure context: clipboard API mati.
            const r = document.createRange();
            r.selectNodeContents(cfgHasil);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(r);
            cfgKabar.textContent = 'Clipboard unavailable — the text is selected, press Ctrl+C.';
        }
    });
    // ── Uji adapter ──
    const ujiKabar = document.getElementById('ezUjiKabar');
    const ujiGambar = document.getElementById('ezUjiGambar');
    Array.from(document.querySelectorAll('[data-ez-uji]')).forEach((b) => {
        b.addEventListener('click', async () => {
            const mana = b.dataset.ezUji;
            ujiKabar.textContent = 'Testing…';
            ujiKabar.className = 'text-xs text-gray-500';
            ujiGambar.classList.add('hidden');
            try {
                const j = await minta({ storage_action: 'uji_adapter', adapter: mana });
                ujiKabar.textContent = (j.ok ? '' : 'Failed: ') + j.pesan + (j.ok ? ' · ' + j.ms + ' ms' : '');
                ujiKabar.className = 'text-xs ' + (j.ok ? 'text-green-700' : 'text-red-700');
                if (j.ok && j.gambar) {
                    ujiGambar.src = j.gambar;
                    ujiGambar.classList.remove('hidden');
                }
            } catch (e) {
                ujiKabar.textContent = 'Failed: ' + e.message;
                ujiKabar.className = 'text-xs text-red-700';
            }
        });
    });

    // ── Overview ──
    const kartu = (judul, nilai, catatan, nada) =>
        '<div class="px-5 py-4">'
        + '<dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">' + judul + '</dt>'
        + '<dd class="mt-1 text-2xl font-semibold tabular-nums ' + (nada || 'text-gray-900') + '">' + angka(nilai) + '</dd>'
        + '<dd class="mt-0.5 text-[11px] text-gray-400">' + catatan + '</dd>'
        + '</div>';

    const blok = (judul, keterangan, dalam) =>
        '<div class="rounded-lg border border-gray-200 bg-white">'
        + '<div class="border-b border-gray-100 px-5 py-4">'
        + '<h2 class="text-sm font-semibold text-gray-900">' + judul + '</h2>'
        + '<p class="mt-0.5 text-sm text-gray-500">' + keterangan + '</p></div>'
        + dalam + '</div>';

    const kisi = (isi) => '<dl class="grid grid-cols-2 divide-x divide-y divide-gray-100 sm:grid-cols-4 sm:divide-y-0">' + isi + '</dl>';

    pemuat.overview = async () => {
        const wadah = document.getElementById('ezSehat');
        try {
            const j = await minta({ storage_action: 'kesehatan' });
            if (!j.ok) throw new Error(j.pesan || 'failed');
            const k = j.kesehatan, p = j.penyimpanan, hari = k.jendela_hari;
            const ttd = k.tanda_tangan, otp = k.otp, dok = k.dokumen, audit = k.audit;
            const persen = otp.total > 0 ? Math.round(otp.terverifikasi * 100 / otp.total) : 0;
            const masalah = Object.keys(audit.per_jenis || {});

            wadah.innerHTML =
                blok('Signatures', 'Every recorded signature, by verification status.',
                    kisi(kartu('Recorded', ttd.total, 'signatures on file')
                        + kartu('Valid', (ttd.per_status || {}).valid || 0, 'content still matches')
                        + kartu('Needs attention', ttd.bermasalah, 'anything other than valid',
                            ttd.bermasalah > 0 ? 'text-red-700' : 'text-gray-900')))
                + blok('Signing by OTP', 'Last ' + hari + ' days.',
                    kisi(kartu('Requested', otp.total, 'codes sent')
                        + kartu('Verified', otp.terverifikasi, persen + '% of requests')
                        + kartu('Expired', otp.kedaluwarsa, 'never used')
                        + kartu('Wrong code', otp.gagal, 'failed attempts')))
                + blok('Documents', 'A frozen archive exists only for documents signed after archiving was switched on.',
                    kisi(kartu('Total', dok.total, 'all versions')
                        + kartu('Signed', dok.bertanda_tangan, 'at least one signature')
                        + kartu('Without archive', dok.tanpa_arsip, 'no frozen copy of what was signed')
                        + kartu('Locked', dok.terkunci, 'cannot be edited')))
                + blok('Stored objects', 'Signature images and frozen PDFs.',
                    kisi(kartu('Objects', p.objek, 'rows in ezdoc_blobs')
                        + kartu('File copies', p.berkas < 0 ? '—' : p.berkas, 'on disk')
                        + kartu('Inline', p.inline, 'still inside documents')
                        + kartu('Referenced', p.dirujuk, 'by documents or archives')))
                + blok('Events that did not succeed', 'Last ' + hari + ' days. Anything other than success.',
                    masalah.length === 0
                        ? '<p class="px-5 py-4 text-sm text-gray-500">Nothing — every recorded event succeeded.</p>'
                        : '<ul class="divide-y divide-gray-100">' + masalah.map((m) =>
                            '<li class="flex items-baseline justify-between px-5 py-2 text-sm">'
                            + '<span class="font-mono text-xs text-gray-700">' + esc(m) + '</span>'
                            + '<span class="tabular-nums text-gray-900">' + angka(audit.per_jenis[m]) + '</span></li>').join('')
                          + '</ul>');
        } catch (e) {
            wadah.innerHTML = '<div class="rounded-lg border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">Failed to load: ' + esc(e.message) + '</div>';
        }
    };

    // ── Access ──
    pemuat.access = async () => {
        const wadah = document.getElementById('ezAkses');
        try {
            const j = await minta({ storage_action: 'akses' });
            if (!j.ok) throw new Error(j.pesan || 'failed');
            if (!j.daftar.length) { wadah.innerHTML = '<p class="text-sm text-gray-500">No templates yet.</p>'; return; }

            wadah.innerHTML = '<table class="min-w-full text-sm"><thead class="text-left text-[11px] uppercase tracking-wide text-gray-500">'
                + '<tr><th class="py-2 pr-4 font-medium">Template</th><th class="py-2 pr-4 font-medium">Mode</th><th class="py-2 font-medium">Rules</th></tr>'
                + '</thead><tbody class="divide-y divide-gray-100">'
                + j.daftar.map((t) => {
                    const aturan = Object.keys(t.akses || {});
                    return '<tr>'
                        + '<td class="py-2 pr-4 align-top"><span class="font-medium text-gray-900">' + esc(t.nama) + '</span>'
                        + '<span class="ml-1 text-xs text-gray-400">#' + t.id + ' v' + t.versi + '</span></td>'
                        + '<td class="py-2 pr-4 align-top text-xs text-gray-600">' + (esc(t.mode) || '—') + '</td>'
                        + '<td class="py-2 align-top">'
                        + (t.bawaan
                            ? '<span class="inline-flex items-center gap-1.5 text-xs text-gray-600">'
                              + '<span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>library defaults — nothing set on this template</span>'
                            : '<ul class="space-y-0.5">' + aturan.map((a) =>
                                '<li class="text-xs text-gray-700"><span class="font-medium">' + esc(a) + '</span>: ' + esc(t.akses[a]) + '</li>').join('') + '</ul>')
                        + '</td></tr>';
                }).join('')
                + '</tbody></table>';
        } catch (e) {
            wadah.innerHTML = '<p class="text-sm text-red-700">Failed to load: ' + esc(e.message) + '</p>';
        }
    };

    // ── Audit log ──
    let auditOffset = 0;
    const auditIsi = document.getElementById('ezAuditIsi');
    const auditKabar = document.getElementById('ezAuditKabar');
    const warnaHasil = (h) => (h === 'success' ? 'bg-green-500' : (h === 'denied' ? 'bg-amber-500' : 'bg-red-500'));

    const saringAudit = () => ({
        storage_action: 'audit',
        event_type: document.getElementById('ezAuditJenis').value,
        result: document.getElementById('ezAuditHasil').value,
        actor: document.getElementById('ezAuditPelaku').value.trim(),
        doc_id: document.getElementById('ezAuditDok').value.trim(),
        offset: auditOffset,
    });

    pemuat.audit = async () => {
        try {
            const j = await minta(saringAudit());
            if (!j.ok) throw new Error(j.pesan || 'failed');

            const pilih = document.getElementById('ezAuditJenis');
            if (pilih.options.length <= 1) {
                j.jenis.forEach((t) => {
                    const o = document.createElement('option');
                    o.value = t;
                    o.textContent = t;
                    pilih.appendChild(o);
                });
            }

            auditKabar.textContent = j.baris.length
                ? 'Showing ' + (auditOffset + 1) + '–' + (auditOffset + j.baris.length)
                : 'Nothing matches';

            auditIsi.innerHTML = j.baris.length === 0
                ? '<p class="text-sm text-gray-500">No events for this filter.</p>'
                : '<table class="min-w-full text-sm"><thead class="text-left text-[11px] uppercase tracking-wide text-gray-500">'
                  + '<tr><th class="py-2 pr-4 font-medium">When</th><th class="py-2 pr-4 font-medium">Event</th>'
                  + '<th class="py-2 pr-4 font-medium">Actor</th><th class="py-2 pr-4 font-medium">Document</th>'
                  + '<th class="py-2 font-medium">Message</th></tr></thead><tbody class="divide-y divide-gray-100">'
                  + j.baris.map((b) =>
                      '<tr>'
                      + '<td class="whitespace-nowrap py-2 pr-4 align-top text-xs tabular-nums text-gray-500">' + esc(b.occurred_at) + '</td>'
                      + '<td class="py-2 pr-4 align-top"><span class="inline-flex items-center gap-1.5 font-mono text-xs text-gray-700">'
                      + '<span class="h-1.5 w-1.5 shrink-0 rounded-full ' + warnaHasil(b.result) + '"></span>' + esc(b.event_type) + '</span></td>'
                      + '<td class="py-2 pr-4 align-top text-xs text-gray-600">' + (esc(b.actor_id) || '—')
                      + (b.ip_address ? '<span class="block text-[11px] text-gray-400">' + esc(b.ip_address) + '</span>' : '') + '</td>'
                      + '<td class="py-2 pr-4 align-top text-xs tabular-nums text-gray-600">' + (b.doc_id ? '#' + b.doc_id : '—') + '</td>'
                      + '<td class="py-2 align-top text-xs text-gray-500">' + esc(b.message) + '</td>'
                      + '</tr>').join('')
                  + '</tbody></table>';
        } catch (e) {
            auditIsi.innerHTML = '<p class="text-sm text-red-700">Failed to load: ' + esc(e.message) + '</p>';
        }
    };

    const muatAudit = () => { sudahDimuat.audit = true; pemuat.audit(); };
    document.getElementById('ezAuditMuat').addEventListener('click', () => { auditOffset = 0; muatAudit(); });
    document.getElementById('ezAuditSesudah').addEventListener('click', () => { auditOffset += 50; muatAudit(); });
    document.getElementById('ezAuditSebelum').addEventListener('click', () => {
        auditOffset = Math.max(0, auditOffset - 50);
        muatAudit();
    });

    // Tab yang sudah terbuka saat halaman dimuat ikut diisi. Perendernya baru
    // terdaftar di bawah sini, jadi pemanggilan pertama ada di sini juga.
    muatTab(tabAktif);
})();
</script>
