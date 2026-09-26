<?php

declare(strict_types=1);

namespace Ezdoc\Template;

/**
 * Contoh template yang hidup sebagai BERKAS, bukan baris database.
 *
 * Dipakai dua tempat: halaman contoh (`?ezdoc_page=contoh`) yang membedahnya,
 * dan halaman pembuatan dokumen (`?ezdoc_page=generate&template_id=contoh`)
 * yang menampilkannya sebagai dokumen sungguhan — baca saja.
 *
 * Satu pemuat untuk keduanya, karena contoh yang berbeda di dua halaman lebih
 * buruk daripada tidak ada contoh: yang menyalinnya tidak tahu mana yang benar.
 *
 * Bentuk yang dikembalikan {@see muat()} sengaja sama dengan baris
 * `ezdoc_templates` (nama kolomnya, termasuk config yang masih berupa teks
 * JSON), supaya kode yang sudah ada tidak perlu tahu contohnya datang dari
 * mana.
 *
 * Nama folder datang dari query string, jadi ia diperlakukan sebagai masukan
 * tidak tepercaya: hanya huruf kecil, angka, dan tanda hubung, lalu hasil
 * realpath-nya wajib berada di dalam folder contoh — pola yang sama dengan
 * {@see \Ezdoc\Http\AssetHandler}.
 *
 * PHP 7.4+ compatible.
 */
final class Contoh
{
    /** Contoh yang dibuka kalau tidak disebut namanya. */
    public const BAWAAN = 'surat-keterangan-tindakan';

    /** Folder tempat semua contoh tinggal. */
    public static function dasar(): string
    {
        return dirname(__DIR__, 2) . '/contoh';
    }

    /**
     * Daftar contoh yang tersedia: slug => nama templatenya.
     *
     * @return array<string,string>
     */
    public static function daftar(): array
    {
        $keluar = [];
        $dasar = self::dasar();
        if (!is_dir($dasar)) {
            return $keluar;
        }
        foreach ((array) scandir($dasar) as $nama) {
            if (!is_string($nama) || $nama === '.' || $nama === '..') {
                continue;
            }
            $muat = self::muat($nama);
            if ($muat !== null) {
                $keluar[$nama] = (string) $muat['nama_template'];
            }
        }

        return $keluar;
    }

    /**
     * Muat satu contoh. Mengembalikan null kalau namanya tidak sah atau
     * berkasnya tidak lengkap — pemanggil yang memutuskan apa artinya.
     *
     * @return array<string,mixed>|null
     */
    public static function muat(string $slug = self::BAWAAN): ?array
    {
        $slug = $slug === '' ? self::BAWAAN : $slug;
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return null;
        }

        $dasar = realpath(self::dasar());
        $dir = $dasar === false ? false : realpath($dasar . '/' . $slug);
        if ($dasar === false || $dir === false || strpos($dir, $dasar) !== 0 || !is_dir($dir)) {
            return null;
        }

        $html = $dir . '/template.html';
        $json = $dir . '/contoh.json';
        if (!is_file($html) || !is_file($json)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($json), true);
        if (!is_array($data)) {
            return null;
        }

        $gambar = '';
        foreach (['asal.jpg', 'asal.png'] as $kandidat) {
            if (is_file($dir . '/' . $kandidat)) {
                $gambar = $dir . '/' . $kandidat;
                break;
            }
        }

        return [
            // ── Bentuk baris template ──
            'id' => 0,
            'uuid' => '',
            'version' => 1,
            'nama_template' => (string) ($data['nama_template'] ?? $slug),
            'category' => (string) ($data['kategori'] ?? 'Contoh'),
            'doc_scope' => (string) ($data['doc_scope'] ?? 'general'),
            'template_html' => (string) file_get_contents($html),
            'floating_elements' => null,
            'config_ttd' => '[]',
            'config_header' => (string) json_encode($data['config_header'] ?? []),
            'verify_config' => isset($data['verify_config'])
                ? (string) json_encode($data['verify_config'])
                : null,
            'access_config' => null,
            // TIDAK terkunci: contoh ini memang untuk dicoba-coba, baik di
            // halaman pembuatan dokumen maupun di designer. Yang menjaganya
            // bukan gembok melainkan ketiadaan sasaran — tidak ada baris
            // template dan tidak ada dokumen yang bisa ditimpa.
            'is_locked' => 0,

            // ── Tambahan khusus contoh ──
            'slug' => $slug,
            'dir' => $dir,
            'gambar' => $gambar,
            'nilai' => isset($data['nilai_contoh']) && is_array($data['nilai_contoh']) ? $data['nilai_contoh'] : [],
            // Baris siap-pakai untuk tabel kueri. Contohnya harus terlihat
            // TERISI, sementara tidak satu pun kueri boleh berjalan — kueri
            // yang di config tetap ada supaya bisa dibaca dan disalin.
            'baris_tabel' => isset($data['baris_tabel_contoh']) && is_array($data['baris_tabel_contoh'])
                ? $data['baris_tabel_contoh']
                : [],
            'peta' => isset($data['peta']) && is_array($data['peta']) ? $data['peta'] : [],
        ];
    }
}
