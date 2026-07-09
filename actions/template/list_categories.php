<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=list_categories)
 *
 * List distinct template categories dengan count untuk autocomplete + filter pill.
 * Read-only.
 *
 * Auth: template manager.
 *
 * Response:
 *   { success: true, categories: [{ name, count }, ...] }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak melihat kategori template');

$cats = [];
$res = @mysqli_query($conn, "
    SELECT category, COUNT(*) AS c
    FROM ezdoc_templates
    WHERE category != ''
    GROUP BY category
    ORDER BY category ASC
");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $cats[] = [
            'name' => $r['category'],
            'count' => (int) $r['c'],
        ];
    }
}

ezdoc_respond_success(['categories' => $cats]);
