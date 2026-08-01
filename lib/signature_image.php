<?php

declare(strict_types=1);

/**
 * Render-side resolution of a signature slot value.
 *
 * Sits in lib/ rather than in a class because the render path is a pair of long
 * procedural functions in views/document/generate.php that take `$conn` as a
 * parameter and build no container. Wrapping that in a service would mean
 * threading it through two signatures that already have thirteen arguments.
 *
 * What it does: turn whatever a slot holds into something an `<img src>` accepts,
 * or an empty string when there is nothing to draw.
 *
 *   ''                          → ''      belum ditandatangani
 *   'qr:v1'                     → ''      ditandatangani mode QR, tidak ada gambar
 *   'data:image/png;base64,…'   → apa adanya (gambar inline lama)
 *   'blob:sha256-…'             → data URI hasil membuka objeknya
 *
 * Byte-nya dibuka ke dalam halaman, bukan dilayani lewat URL tersendiri. URL
 * gambar tanda tangan yang bisa diambil sendiri akan bocor lewat riwayat browser,
 * log proxy, dan header referer; di dalam halaman, byte-nya ikut kontrol akses
 * halaman itu. Jalur PDF menuntut hal yang sama karena alasan berbeda — dompdf
 * tidak membawa sesi, jadi URL terlindung tidak akan bisa diambilnya.
 */

if (!function_exists('ezdoc_resolve_signature_image')) {
    /**
     * @param mixed $conn Handle apa pun yang diterima Context/Repository; null aman.
     */
    function ezdoc_resolve_signature_image($conn, string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === \Ezdoc\Signature\SignatureValue::QR_ACT) {
            return '';
        }

        // Gambar inline lama: tidak perlu apa pun, dan tidak boleh menyentuh DB.
        // Ini juga jalur yang dipakai dokumen lama, jadi ia harus tetap bekerja
        // walau tabel objeknya belum pernah dibuat.
        if (\Ezdoc\Storage\SignatureImageStore::isDataUri($value)) {
            return $value;
        }

        if (!\Ezdoc\Storage\Blob::isReference($value)) {
            return '';
        }

        try {
            $store = ezdoc_signature_image_store($conn);
            if ($store === null) {
                return '';
            }

            // Null di sini berarti objeknya hilang atau gagal checksum-nya. Yang
            // benar adalah tidak menggambar apa pun: dokumennya tetap terbuka,
            // dan bukti kriptografis tanda tangannya tidak tersentuh — yang hilang
            // tanda visualnya, bukan buktinya.
            return $store->resolveToDataUri($value) ?? '';
        } catch (\Throwable $e) {
            @error_log('[ezdoc:storage] gagal membuka gambar tanda tangan: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('ezdoc_recorded_signature_slots')) {
    /**
     * Id slot yang sudah punya baris di `ezdoc_signatures` untuk dokumen ini.
     *
     * Dipakai jalur render untuk memutuskan slot mana yang tidak boleh lagi
     * menawarkan ubah/hapus. Sengaja dibedakan dari "slotnya ada isinya": slot
     * bisa berisi gambar tanpa record — dokumen yang dibuat sebelum pencatatan
     * diaktifkan, atau tanda tangan yang belum sempat tersimpan. Yang membuat
     * server menolak perubahan adalah recordnya, jadi itu juga yang harus
     * menentukan tampilannya.
     *
     * Gagal apa pun mengembalikan daftar kosong: dokumen tetap bisa dibuka, dan
     * batas sebenarnya tetap dijaga guard §7b di sisi simpan.
     *
     * @param mixed $conn
     * @return list<string>
     */
    function ezdoc_recorded_signature_slots($conn, int $docId): array
    {
        if ($docId <= 0) {
            return [];
        }

        // Tanpa cache, sengaja. Fungsi ini dipanggil sekali per render — bukan
        // per slot — jadi cache tidak menghemat apa pun di jalur normal, tapi
        // membuat hasilnya bertahan lebih lama dari yang seharusnya: satu
        // request yang menyimpan lalu merender ulang akan melihat keadaan
        // sebelum simpan. Satu query berindeks lebih murah daripada kelas bug itu.
        $slots = [];
        try {
            $db = $conn instanceof \Ezdoc\Db\Connection
                ? $conn
                : \Ezdoc\Context::default()->connection();

            $rows = $db->fetchAll(
                'SELECT `metadata` FROM `ezdoc_signatures` WHERE `document_id` = ? AND `deleted_at` IS NULL',
                [$docId]
            );
            foreach ($rows as $row) {
                $meta = json_decode((string) ($row['metadata'] ?? ''), true);
                if (is_array($meta) && !empty($meta['ttd_id'])) {
                    $slots[] = (string) $meta['ttd_id'];
                }
            }
        } catch (\Throwable $e) {
            @error_log('[ezdoc:signature] gagal membaca slot bertanda tangan: ' . $e->getMessage());
        }

        return array_values(array_unique($slots));
    }
}

if (!function_exists('ezdoc_signature_image_store')) {
    /**
     * Penyimpan gambar untuk request ini, atau null kalau belum tersedia.
     *
     * Di-cache per proses: satu halaman bisa merender selusin tanda tangan, dan
     * tanpa cache tiap satu akan menanyakan ulang skema ke database.
     *
     * @param mixed $conn
     */
    function ezdoc_signature_image_store($conn): ?\Ezdoc\Storage\SignatureImageStore
    {
        /** @var array{ready: bool, store: \Ezdoc\Storage\SignatureImageStore|null}|null $cached */
        static $cached = null;
        if ($cached !== null) {
            return $cached['store'];
        }

        $cached = ['ready' => true, 'store' => null];

        try {
            $db = $conn instanceof \Ezdoc\Db\Connection
                ? $conn
                : \Ezdoc\Context::default()->connection();

            // Consumer yang belum menjalankan migrasi `ezdoc_blobs` tetap harus
            // bisa membuka dokumennya. Tidak ada referensi yang bisa dibuka, dan
            // itu memang benar — gambar mereka masih inline.
            if ((new \Ezdoc\Db\Schema\ColumnIntrospector($db))->columns('ezdoc_blobs') === []) {
                return null;
            }

            $cached['store'] = new \Ezdoc\Storage\SignatureImageStore(
                \Ezdoc\Storage\BlobStoreFactory::make($db)
            );
        } catch (\Throwable $e) {
            @error_log('[ezdoc:storage] penyimpan gambar tidak tersedia: ' . $e->getMessage());
        }

        return $cached['store'];
    }
}
