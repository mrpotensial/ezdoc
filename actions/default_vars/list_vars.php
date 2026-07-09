<?php
/**
 * POST /page/form_pembuat_surat_v3.php  (body: ajax=1, action=list_vars)
 *
 * List semua default variables dari `ezdoc_default_vars`, ordered by name.
 * Read-only — designer memakainya untuk populate autocomplete + variable panel.
 *
 * Auth: template manager (consistent dengan add_var/delete_var).
 *
 * Response:
 *   { success: true, vars: [{ id, var_name, description }, ...] }
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak melihat default variables');

$vars = [];
$res = @mysqli_query($conn, "SELECT id, var_name, description FROM ezdoc_default_vars ORDER BY var_name");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) $vars[] = $row;
}

ezdoc_respond_success(['vars' => $vars]);
