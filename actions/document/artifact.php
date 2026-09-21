<?php
/**
 * POST _doc_action=artifact  doc_id, [cycle]
 *
 * Sajikan PDF beku satu siklus. Tanpa `cycle`, yang disajikan siklus yang
 * sedang berlaku.
 *
 * Siklus lama sengaja tetap bisa dibuka. Ia jawaban atas pertanyaan "apa yang
 * dilihat penanda tangan pertama saat ia menandatangani" — dan pertanyaan itu
 * justru muncul ketika siklusnya sudah tidak berlaku lagi.
 *
 * Hak aksesnya sama dengan melihat riwayat dokumen: `view` pada templatenya.
 * Arsip tidak lebih rahasia daripada dokumennya, dan tidak kurang.
 *
 * Template dikaitkan lewat `template_id`, bukan `template_uuid`: versi-versi
 * sebuah template berbagi uuid, dan join lewat uuid bisa memilih access_config
 * dari versi yang salah.
 */

$docId = (int) ($_POST['doc_id'] ?? 0);
$cycle = (int) ($_POST['cycle'] ?? 0);

if ($docId <= 0) {
    ezdoc_respond_error(t('response.incomplete_parameters', [], 'Incomplete parameters'));
}

$db = \Ezdoc\Context::default()->connection();

$doc = $db->fetchOne(
    'SELECT d.id, t.access_config
     FROM ezdoc_documents d
     LEFT JOIN ezdoc_templates t ON t.id = d.template_id
     WHERE d.id = ? LIMIT 1',
    [$docId]
);
if (!$doc) {
    ezdoc_respond_error(t('response.document_not_found', [], 'Document not found'), 404);
}

ezdoc_require_on_template(
    ezdoc_parse_access_config($doc['access_config'] ?? null),
    'view',
    'Tidak berhak melihat dokumen dari template ini'
);

$repo = new \Ezdoc\Document\ArtifactRepository($db);

$artefak = null;
if ($cycle > 0) {
    foreach ($repo->historyFor($docId) as $a) {
        if ($a->getCycle() === $cycle) {
            $artefak = $a;
            break;
        }
    }
} else {
    $artefak = $repo->activeFor($docId);
}

if ($artefak === null) {
    ezdoc_respond_error(t('response.archive_not_found', [], 'No display archive for this document'), 404);
}

try {
    $bytes = (new \Ezdoc\Document\FreezeService($db))->bytes($artefak);
} catch (\Throwable $e) {
    @error_log('[ezdoc:artifact] gagal membaca artefak doc_id=' . $docId . ': ' . $e->getMessage());
    $bytes = null;
}

if ($bytes === null || $bytes === '') {
    // Barisnya ada, berkasnya tidak. Itu bukan "belum dibekukan" — itu bukti yang
    // hilang dari penyimpanan, dan pesannya harus mengatakan persis itu.
    @error_log(sprintf(
        '[ezdoc:artifact] artefak doc_id=%d siklus %d ada di basis data tapi berkasnya tidak terbaca (key %s)',
        $docId,
        $artefak->getCycle(),
        $artefak->getBlobKey()
    ));
    ezdoc_respond_error(t('response.archive_file_missing', [], 'The archive is recorded but its file cannot be read'), 500);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="arsip-dokumen-' . $docId . '-siklus-' . $artefak->getCycle() . '.pdf"');
header('Content-Length: ' . strlen($bytes));
header('X-Ezdoc-Frozen: cycle=' . $artefak->getCycle());
echo $bytes;
exit;
