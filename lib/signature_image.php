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
