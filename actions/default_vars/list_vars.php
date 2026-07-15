<?php
/**
 * POST action=list_vars
 *
 * List semua default variables dari `ezdoc_default_vars`, ordered by name.
 * Read-only — designer memakainya untuk populate autocomplete + variable panel.
 *
 * Auth: template manager (consistent dengan add_var/delete_var).
 *
 * Response: { success: true, vars: [{ id, var_name, description }, ...] }
 *
 * ## v0.9.9 refactor
 *
 * Thin controller — persistence via `Ezdoc\DefaultVars\DefaultVarsRepository`.
 */

global $conn;

ezdoc_require_manage_templates('Tidak berhak melihat default variables');

$repo = new \Ezdoc\DefaultVars\DefaultVarsRepository($conn);
$vars = $repo->listAll();

ezdoc_respond_success(['vars' => $vars]);
