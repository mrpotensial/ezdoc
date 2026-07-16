<?php
/**
 * POST action=list_categories
 *
 * List distinct template categories dengan count untuk autocomplete + filter pill.
 * Read-only. Auth: template manager.
 *
 * Response: { success: true, categories: [{ name, count }, ...] }
 *
 * ## History
 * - v0.9.9 — refactor to Connection.fetchAll (aggregate SQL)
 * - v0.9.10 — Context::default()->db replaces `global $conn` (library-standalone)
 */

use Ezdoc\Context;
use Ezdoc\Db\Mysqli\MysqliConnection;

ezdoc_require_manage_templates('Tidak berhak melihat kategori template');

// Library-standalone data access — Context DI container returns mysqli
// (auto-init dari consumer globals via Context::fromGlobals() kalau consumer
// belum inject explicit). No direct `global $conn` needed.
$db = new MysqliConnection(Context::default()->db);
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
