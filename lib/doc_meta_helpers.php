<?php
/**
 * ezdoc doc_meta_helpers — small, stateless read helpers for the document
 * render pipeline (cetak_v3 / cetak_v2). Extracted from inline blocks di
 * `page/form_pembuat_surat_cetak_v3.php` (v0.6.5 refactor).
 *
 * All helpers are pure reads (SELECT-only), no mutations, no audit.
 * Safe to call from render path, AJAX action, or CLI.
 */

if (defined('EZDOC_DOC_META_HELPERS_LOADED')) return;
define('EZDOC_DOC_META_HELPERS_LOADED', true);

/**
 * Lookup a pegawai's display name by id_pegawai.
 *
 * @param mysqli      $conn
 * @param string|int  $idPegawai  id_pegawai value (schema stores as varchar in some deployments)
 * @return string|null           nama_pegawai atau null jika tidak ketemu / error
 */
function ezdoc_fetch_creator_name($conn, $idPegawai)
{
    if (!$conn || $idPegawai === null || $idPegawai === '') return null;

    $stmt = mysqli_prepare($conn, "SELECT nama_pegawai FROM pegawai WHERE id_pegawai = ? LIMIT 1");
    if (!$stmt) return null;

    $idStr = (string)$idPegawai;
    mysqli_stmt_bind_param($stmt, "s", $idStr);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }

    $res = mysqli_stmt_get_result($stmt);
    $name = null;
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $name = $row['nama_pegawai'] ?? null;
    }
    mysqli_stmt_close($stmt);
    return $name;
}

/**
 * Load whitelisted default variables from `surat_default_vars`.
 * Used by resolveDefault() to gate $variable interpolation in template defaults.
 *
 * @param mysqli $conn
 * @return string[]  Array of var_name (empty on failure — fail-closed)
 */
function ezdoc_load_whitelisted_vars($conn)
{
    $out = [];
    if (!$conn) return $out;

    $res = @mysqli_query($conn, "SELECT var_name FROM surat_default_vars");
    if (!$res) return $out;
    while ($row = mysqli_fetch_assoc($res)) {
        if (!empty($row['var_name'])) $out[] = $row['var_name'];
    }
    return $out;
}
