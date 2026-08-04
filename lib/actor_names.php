<?php

declare(strict_types=1);

/**
 * Satu pintu untuk mengubah id pelaku jadi nama yang bisa dibaca orang.
 *
 * ## Kenapa berkas ini ada
 *
 * Sebelum ini ada tiga cara untuk pekerjaan yang sama, dan tidak ada satu pun
 * yang selesai:
 *
 * 1. `$ctx->roleProvider->displayName($id)` — dipanggil lewat `method_exists`,
 *    tidak pernah dideklarasikan di antarmuka `RoleProvider`, tidak ada satu
 *    pun implementasi di library, dan spesifikasi yang dirujuk komentarnya
 *    (`ezdoc-spec/context/role_provider.md#displayName`) tidak ada. Analisis
 *    statis menandainya sebagai pemanggilan metode yang tidak terdefinisi.
 * 2. `ezdoc_fetch_creator_name()` — menjalankan
 *    `SELECT nama_pegawai FROM pegawai` langsung dari dalam library. Nama tabel
 *    milik satu rumah sakit, dipaku ke dalam paket yang dimaksudkan untuk
 *    dipasang orang lain.
 * 3. Tidak ada apa-apa — riwayat menampilkan `#18`.
 *
 * Sekarang satu: {@see \Ezdoc\Auth\ActorDirectory}, disambung aplikasi pemakai.
 * Dua yang lama tetap dicoba sesudahnya supaya pemasangan yang sudah terlanjur
 * memakainya tidak rusak, tapi keduanya jalur usang.
 *
 * ## Kenapa sekaligus banyak
 *
 * Panel riwayat menampilkan enam puluh baris. Satu pencarian per baris berarti
 * enam puluh query untuk satu layar — persoalan N+1 yang melahirkan eager
 * loading di Doctrine dan Eloquent.
 */

if (defined('EZDOC_ACTOR_NAMES_LOADED')) {
    return;
}
define('EZDOC_ACTOR_NAMES_LOADED', true);

/**
 * Nama tampilan untuk sekumpulan id pelaku.
 *
 * Tidak pernah melempar. Ini jalur tampilan: sumber nama yang rusak boleh
 * menghilangkan namanya, bukan halamannya.
 *
 * @param array<int|string> $ids
 * @return array<int,string> id => nama, hanya untuk yang ketemu
 */
function ezdoc_actor_names(array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $clean[$n] = true;
        }
    }
    if ($clean === []) {
        return [];
    }
    $clean = array_keys($clean);

    try {
        $ctx = \Ezdoc\Context::default();
    } catch (\Throwable $e) {
        return [];
    }

    // 1. Jalur yang seharusnya dipakai.
    if ($ctx->actorDirectory !== null) {
        try {
            $names = $ctx->actorDirectory->displayNames($clean);

            return ezdoc_actor_names_clean($names);
        } catch (\Throwable $e) {
            @error_log('[ezdoc:actors] actor directory failed: ' . $e->getMessage());
        }
    }

    // 2. Usang: RoleProvider yang kebetulan punya displayName(). Satu per satu,
    //    karena bentuk itu memang tidak pernah punya versi jamak.
    if (method_exists($ctx->roleProvider, 'displayName')) {
        // Dipanggil sebagai callable, bukan `->displayName()`. Metodenya memang
        // tidak ada di antarmuka RoleProvider — itulah masalah aslinya — jadi
        // menulisnya sebagai pemanggilan metode biasa akan ditandai analisis
        // statis sebagai metode tak terdefinisi, di sini dan di setiap editor
        // yang membuka berkas ini.
        $legacy = [$ctx->roleProvider, 'displayName'];
        $names = [];
        foreach ($clean as $id) {
            try {
                /** @var mixed $name */
                $name = is_callable($legacy) ? $legacy($id) : null;
                if (is_string($name) && $name !== '') {
                    $names[$id] = $name;
                }
            } catch (\Throwable $e) {
                // Lewati id ini, lanjutkan sisanya.
            }
        }
        if ($names !== []) {
            return $names;
        }
    }

    // 3. Usang: pencarian yang menyebut nama tabel milik satu aplikasi. Tetap
    //    ada supaya pemasangan lama tidak kehilangan nama secara mendadak.
    //
    //    Dimuat di sini kalau belum, bukan diandaikan sudah ada. Berkas itu dulu
    //    hanya di-require oleh halaman generate, sehingga nama muncul di halaman
    //    itu dan hilang di panel riwayat — permintaan AJAX yang masuk lewat
    //    pintu berbeda. Ketergantungan yang cuma benar di satu jalur masuk
    //    adalah ketergantungan yang belum dinyatakan.
    if (!function_exists('ezdoc_fetch_creator_name') && defined('EZDOC_LIB')) {
        $helpers = EZDOC_LIB . '/doc_meta_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    if (function_exists('ezdoc_fetch_creator_name') && isset($GLOBALS['conn'])) {
        $names = [];
        foreach ($clean as $id) {
            try {
                $name = ezdoc_fetch_creator_name($GLOBALS['conn'], $id);
                if (is_string($name) && $name !== '') {
                    $names[$id] = $name;
                }
            } catch (\Throwable $e) {
                // Lewati id ini.
            }
        }

        return $names;
    }

    return [];
}

/**
 * Buang yang bukan string atau kosong, dan paksa kuncinya jadi int.
 *
 * Implementasi milik aplikasi pemakai bisa mengembalikan apa saja; yang
 * memanggil berhak mengasumsikan `array<int,string>` yang tidak kosong.
 *
 * @param mixed $names
 * @return array<int,string>
 */
function ezdoc_actor_names_clean($names): array
{
    if (!is_array($names)) {
        return [];
    }

    $out = [];
    foreach ($names as $id => $name) {
        $key = (int) $id;
        if ($key > 0 && is_string($name) && $name !== '') {
            $out[$key] = $name;
        }
    }

    return $out;
}
