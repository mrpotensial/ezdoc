<?php
/**
 * Jalankan CLI penyimpanan objek dengan basis data SQLite berkas, di prosesnya sendiri.
 *
 *   php run_cli_blob.php <mirror|purge> <dry-run|apply> <berkas.sqlite> <folder-simpan>
 *
 * Berkas SQLite dibuat dan diisi pada panggilan pertama, lalu dipakai ulang —
 * jadi menjalankan dua kali menguji apa yang terjadi pada jalankan kedua.
 *
 * Isinya (semua dibuat jauh sebelum masa tenggang purge):
 *
 *   A, B  gambar tanda tangan di kolom, dirujuk dokumen #1
 *   C     baris yang isinya TIDAK cocok dengan kuncinya
 *   D     PDF beku — hanya di disk, dirujuk ezdoc_document_artifacts saja
 *   E     gambar di kolom yang tidak dirujuk siapa pun
 *   +     satu gambar yang masih inline di signature_values
 *
 * Mencetak keluaran CLI-nya, lalu satu baris `@@HASIL@@{json}`: sidik jari
 * seluruh isi basis data sebelum dan sesudah, dan daftar berkas di folder.
 *
 * Dipakai tests/Storage/BlobCliTest.php.
 */

[$cli, $mode, $berkasDb, $folder] = array_slice($argv, 1, 4) + ['', '', '', ''];
$root = dirname(__DIR__, 2);

require $root . '/autoload.php';

$baru = !is_file($berkasDb);
$pdo = new PDO('sqlite:' . $berkasDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new \Ezdoc\Db\Pdo\PdoConnection($pdo);

if ($baru) {
    foreach ([
        '2026_01_01_000001_create_ezdoc_templates.sql',
        '2026_01_01_000002_create_ezdoc_documents.sql',
        '2026_07_30_000001_create_ezdoc_blobs.sql',
        '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
    ] as $f) {
        $pdo->exec((string) file_get_contents($root . '/migrations/sqlite/' . $f));
    }

    $lama = '2026-01-01 00:00:00';
    $isi = ['A' => 'ttd-a', 'B' => 'ttd-b', 'E' => 'yatim'];
    $kunci = [];
    foreach ($isi as $nama => $byte) {
        $kunci[$nama] = hash('sha256', $byte);
        $db->execute(
            "INSERT INTO ezdoc_blobs (\"key\", checksum, byte_size, content_type, backend, content, created_at)
             VALUES (?, ?, ?, 'image/png', 'database', ?, ?)",
            [$kunci[$nama], 'sha256:' . $kunci[$nama], strlen($byte), $byte, $lama]
        );
    }

    // C: isi kolomnya dipalsukan — kuncinya milik byte lain.
    $kunci['C'] = hash('sha256', 'asli');
    $db->execute(
        "INSERT INTO ezdoc_blobs (\"key\", checksum, byte_size, content_type, backend, content, created_at)
         VALUES (?, ?, 4, 'image/png', 'database', 'palsu', ?)",
        [$kunci['C'], 'sha256:' . $kunci['C'], $lama]
    );

    // D: PDF beku di disk.
    $pdf = '%PDF-1.7 beku';
    $kunci['D'] = hash('sha256', $pdf);
    @mkdir($folder . '/' . substr($kunci['D'], 0, 2), 0777, true);
    file_put_contents($folder . '/' . substr($kunci['D'], 0, 2) . '/' . $kunci['D'], $pdf);
    $db->execute(
        "INSERT INTO ezdoc_blobs (\"key\", checksum, byte_size, content_type, backend, content, created_at)
         VALUES (?, ?, ?, 'application/pdf', 'disk', NULL, ?)",
        [$kunci['D'], 'sha256:' . $kunci['D'], strlen($pdf), $lama]
    );

    $db->execute(
        "INSERT INTO ezdoc_templates (id, uuid, slug, version, is_current, name, category, scope, content, is_active)
         VALUES (1, 'tpl-1', 'uji', 1, 1, 'Uji', 'umum', 'patient', '<p>x</p>', 1)"
    );
    $db->execute(
        "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, title,
                                      norm, nopen, subject_id, context_id, label, version, signature_values)
         VALUES (1, 'dok-1', 1, 'tpl-1', 1, 'Uji', '1', '11', '1', '11', '-', 1, ?)",
        [json_encode([
            'ttd_a' => 'blob:sha256-' . $kunci['A'],
            'ttd_b' => 'blob:sha256-' . $kunci['B'],
            'ttd_c' => 'data:image/png;base64,iVBORw0KGgo=',
        ])]
    );
    $db->execute(
        "INSERT INTO ezdoc_document_artifacts (uuid, document_id, cycle, blob_key, frozen_at, created_at)
         VALUES ('art-1', 1, 1, ?, ?, ?)",
        [$kunci['D'], $lama, $lama]
    );
}

/** Sidik jari seluruh isi basis data — tiap tabel, tiap baris, tiap kolom. */
$sidik = static function () use ($pdo): string {
    $semua = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $t) {
        $semua[$t['name']] = $pdo->query('SELECT * FROM "' . $t['name'] . '" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }

    return hash('sha256', (string) json_encode($semua));
};

$sebelum = $sidik();

register_shutdown_function(static function () use ($sidik, $sebelum, $folder, $pdo): void {
    $berkas = [];
    if (is_dir($folder)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $berkas[basename($f->getPathname())] = (string) file_get_contents($f->getPathname());
        }
    }
    ksort($berkas);
    $kunci = [];
    foreach ($pdo->query('SELECT "key" FROM ezdoc_blobs') as $r) {
        $kunci[] = $r['key'];
    }
    echo "\n@@HASIL@@" . json_encode([
        'db_sama' => $sidik() === $sebelum,
        'berkas'  => $berkas,
        'baris'   => $kunci,
    ]) . "\n";
});

putenv('EZDOC_STORAGE_PATH=' . $folder);
$argv = [$cli, $mode];

require $root . '/cli/' . ($cli === 'purge' ? 'purge-orphan-blobs.php' : 'mirror-blobs-to-disk.php');
