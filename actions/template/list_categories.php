<?php
/**
 * POST action=list_categories
 *
 * List distinct template categories dengan count untuk autocomplete + filter pill.
 * Read-only. Auth: template manager.
 *
 * Response: { success: true, categories: [{ name, count }, ...] }
 *
 * ## v0.9.9 refactor — Connection.fetchAll (aggregate SQL)
 */

use Ezdoc\Db\Mysqli\MysqliConnection;

global $conn;

ezdoc_require_manage_templates('Tidak berhak melihat kategori template');

$db = new MysqliConnection($conn);
$rows = $db->fetchAll(
    "SELECT category, COUNT(*) AS c
     FROM ezdoc_templates
     WHERE category != ''
     GROUP BY category
     ORDER BY category ASC"
);

$cats = array_map(function ($r) {
    return ['name' => $r['category'], 'count' => (int) $r['c']];
}, $rows);

ezdoc_respond_success(['categories' => $cats]);
