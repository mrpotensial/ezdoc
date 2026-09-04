<?php

/**
 * Kembalikan SATU versi yang disembunyikan ke daftar.
 *
 * Lihat docs/260901-hapus-versi-dan-izinnya.md §5 (P2).
 *
 * ## Kenapa ini ada, padahal `restore_slot` sudah ada
 *
 * `restore_slot` mengembalikan **seluruh slot** sekaligus — semua versi yang
 * pernah disembunyikan, termasuk yang memang sengaja disingkirkan.
 *
 * Menyembunyikan mengenai satu versi. Perbaikannya harus mengenai satu versi
 * juga. Kalau tidak, salah ketuk pada v3 hanya bisa dibatalkan dengan ikut
 * menghidupkan v1 dan v2 yang memang tidak diinginkan lagi — dan orangnya lebih
 * memilih membiarkannya daripada merusak lebih banyak.
 *
 * `restore_slot` tetap ada untuk kasus aslinya: satu slot yang seluruhnya
 * dibuang lalu perlu dihidupkan kembali.
 *
 * ## Haknya sama persis dengan menyembunyikan
 *
 * Yang bisa menyembunyikan harus bisa mengembalikan. Kalau tidak, tiap salah
 * ketuk jadi tiket ke superadmin — dan izin yang setengah begitu justru membuat
 * orang enggan memakai fiturnya sama sekali.
 *
 * Aturannya diambil dari {@see ezdoc_can_hide_versions()}, satu tempat untuk
 * empat pemanggil.
 */

use Ezdoc\Context;

global $author_id;

$did = (int) ($_POST['doc_id'] ?? 0);
if ($did <= 0) {
    ezdoc_respond_error(t('response.invalid_doc_id', [], 'Invalid document ID'));
}

$db = Context::default()->connection();

// Sengaja TANPA `deleted_at IS NULL` — yang dicari justru yang tersembunyi.
$doc = $db->fetchOne(
    'SELECT d.id, d.template_id, d.version, d.label, d.deleted_at, t.access_config
       FROM ezdoc_documents d
       JOIN ezdoc_templates t ON t.id = d.template_id
      WHERE d.id = ?',
    [$did]
);

if ($doc === null) {
    ezdoc_respond_error(t('response.document_not_found', [], 'Document not found'), 404);
}

ezdoc_require_hide_versions(ezdoc_parse_access_config($doc['access_config'] ?? null));

// Yang tidak tersembunyi tidak perlu dikembalikan. Dijawab sebagai kekeliruan
// permintaan, bukan didiamkan dengan "berhasil" — pemanggil yang mengira
// melakukan sesuatu padahal tidak akan mengulanginya.
if (empty($doc['deleted_at'])) {
    ezdoc_respond_error(
        t('response.version_not_hidden', [], 'This version is not hidden — there is nothing to restore.'),
        409
    );
}

try {
    $db->execute(
        'UPDATE ezdoc_documents SET deleted_at = NULL, deleted_by = NULL WHERE id = ?',
        [$did]
    );
} catch (\Throwable $e) {
    ezdoc_respond_error(
        t('response.restore_failed', ['error' => $e->getMessage()], 'Failed to restore: {error}')
    );
}

ezdoc_audit_log('doc.restored', [
    'target_type' => 'document',
    'target_id'   => (string) $did,
    'template_id' => (int) $doc['template_id'],
    'doc_id'      => $did,
    'metadata'    => [
        'scope'   => 'version',
        'version' => (int) $doc['version'],
        'label'   => $doc['label'],
        // Sejak kapan ia tersembunyi. Tanpa ini, catatan cuma menyebut bahwa
        // sesuatu dikembalikan — bukan berapa lama ia sempat tidak terlihat.
        'hidden_since' => $doc['deleted_at'],
    ],
    'message' => "Versi v{$doc['version']} dikembalikan ke daftar",
]);

ezdoc_respond_success(
    ['version' => (int) $doc['version']],
    t('response.version_restored', ['version' => (int) $doc['version']], 'Version v{version} is back in the list.')
);
