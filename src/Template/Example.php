<?php

declare(strict_types=1);

namespace Ezdoc\Template;

use Ezdoc\UI\Config;

/**
 * Contoh template yang hidup sebagai BERKAS, bukan baris database.
 *
 * Dipakai dua tempat: halaman contoh (`?ezdoc_page=example`) yang membedahnya,
 * dan halaman pembuatan dokumen (`?ezdoc_page=generate&template_id=contoh`)
 * yang menampilkannya sebagai dokumen sungguhan — baca saja.
 *
 * Satu pemuat untuk keduanya, karena contoh yang berbeda di dua halaman lebih
 * buruk daripada tidak ada contoh: yang menyalinnya tidak tahu mana yang benar.
 *
 * Bentuk yang dikembalikan {@see load()} sengaja sama dengan baris
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
final class Example
{
    /** Contoh yang dibuka kalau tidak disebut namanya. */
    public const DEFAULT_SLUG = 'surat-keterangan-tindakan';

    /** Folder tempat semua contoh tinggal. */
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/examples';
    }

    /**
     * Daftar contoh yang tersedia: slug => nama templatenya.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        $keluar = [];
        foreach (self::catalogue() as $slug => $satu) {
            $keluar[$slug] = $satu['name'];
        }

        return $keluar;
    }

    /**
     * Semua contoh beserta penggolongannya.
     *
     * Dua penanda, karena dua itu yang membedakan isinya saat dicari: apakah
     * ia memakai papan grafik, dan apakah ia dokumen pasien. Daftar empat
     * belas nama tanpa pengelompokan memaksa yang mencari membaca semuanya.
     *
     * Penggolongannya dihitung dari berkasnya sendiri, bukan diketik di
     * manifes: contoh yang ditambahkan nanti ikut tergolong tanpa ada yang
     * perlu ingat menandainya.
     *
     * @return array<string,array{name: string, scope: string, has_chart: bool}>
     */
    public static function catalogue(): array
    {
        $keluar = [];
        $dasar = self::root();
        if (!is_dir($dasar)) {
            return $keluar;
        }
        foreach ((array) scandir($dasar) as $nama) {
            if (!is_string($nama) || $nama === '.' || $nama === '..') {
                continue;
            }
            $muat = self::load($nama);  // tanpa config: kopnya tidak dipakai di sini
            if ($muat === null) {
                continue;
            }

            $keluar[$nama] = [
                'name' => (string) $muat['nama_template'],
                'scope' => (string) $muat['doc_scope'],
                'has_chart' => strpos((string) $muat['template_html'], 'chart-placeholder') !== false,
            ];
        }

        return $keluar;
    }

    /**
     * Muat satu contoh. Mengembalikan null kalau namanya tidak sah atau
     * berkasnya tidak lengkap — pemanggil yang memutuskan apa artinya.
     *
     * Kop suratnya dipasang dari konfigurasi aplikasi pemakai kalau ada
     * ({@see pasangKop()}). Berkas contohnya sendiri tetap netral.
     *
     * @return array<string,mixed>|null
     */
    public static function load(string $slug = self::DEFAULT_SLUG, ?Config $config = null): ?array
    {
        $slug = $slug === '' ? self::DEFAULT_SLUG : $slug;
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return null;
        }

        $dasar = realpath(self::root());
        $dir = $dasar === false ? false : realpath($dasar . '/' . $slug);
        if ($dasar === false || $dir === false || strpos($dir, $dasar) !== 0 || !is_dir($dir)) {
            return null;
        }

        $html = $dir . '/template.html';
        $json = $dir . '/example.json';
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

        $kop = self::letterhead($config);
        $isi = self::applyLetterhead((string) file_get_contents($html), $kop);

        // Logo kop ikut konfigurasi kalau disediakan; kalau tidak, yang di
        // berkas contoh yang dipakai.
        $kepala = isset($data['config_header']) && is_array($data['config_header']) ? $data['config_header'] : [];
        $logo = self::letterheadLogo((string) $kop['logo']);
        if ($logo !== '') {
            $kepala['logos'] = isset($kepala['logos']) && is_array($kepala['logos']) ? $kepala['logos'] : [];
            $kepala['logos']['kop'] = $logo;
        }

        return [
            // ── Bentuk baris template ──
            'id' => 0,
            'uuid' => '',
            'version' => 1,
            'nama_template' => (string) ($data['nama_template'] ?? $slug),
            'category' => (string) ($data['category'] ?? 'Contoh'),
            'doc_scope' => (string) ($data['doc_scope'] ?? 'general'),
            'template_html' => $isi,
            'floating_elements' => null,
            'config_ttd' => '[]',
            'config_header' => (string) json_encode($kepala),
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
            'nilai' => isset($data['sample_values']) && is_array($data['sample_values']) ? $data['sample_values'] : [],
            // Baris siap-pakai untuk tabel kueri. Contohnya harus terlihat
            // TERISI, sementara tidak satu pun kueri boleh berjalan — kueri
            // yang di config tetap ada supaya bisa dibaca dan disalin.
            'table_rows' => isset($data['sample_table_rows']) && is_array($data['sample_table_rows'])
                ? $data['sample_table_rows']
                : [],
            'feature_map' => isset($data['feature_map']) && is_array($data['feature_map']) ? $data['feature_map'] : [],
        ];
    }

    /**
     * Pasang kop surat aplikasi pemakai ke dalam isi contoh.
     *
     * Diminta pemakai: "agar contoh muncul sebagai hasil konfigurasi, tapi
     * berkas contohnya tetap netral".
     *
     * Jadi berkasnya memakai kop karangan, dan tiga tempat di dalamnya diberi
     * penanda: nama, alamat, dan kota. Kalau aplikasi pemakainya menyebutkan
     * miliknya sendiri, yang tampil dan yang tersimpan jadi template adalah
     * miliknya. Kalau tidak, contohnya tetap seperti apa adanya — pustaka ini
     * tidak memihak satu organisasi pun.
     *
     *   'letterhead.name'    => 'RUMAH SAKIT ...',
     *   'letterhead.address' => 'Jalan ...',        // boleh larik, jadi dua baris
     *   'letterhead.city'    => 'Kota ...',
     *   'letterhead.logo'    => '/path/logo.png',   // atau data URI
     *
     * Nilainya di-escape sebelum disisipkan: config datang dari berkas
     * aplikasi pemakai, tapi ia tetap teks yang masuk ke dokumen orang lain.
     */
    private static function applyLetterhead(string $html, array $kop): string
    {
        if (($kop['name'] ?? '') !== '') {
            $html = self::gantiIsi($html, 'p', 'ezdoc-kop-nama', self::aman((string) $kop['name']));
        }

        $alamat = $kop['address'] ?? '';
        if (is_array($alamat)) {
            $baris = [];
            foreach ($alamat as $satu) {
                $satu = trim((string) $satu);
                if ($satu !== '') {
                    $baris[] = self::aman($satu);
                }
            }
            $alamat = implode('<br>', $baris);
        } else {
            $alamat = self::aman(trim((string) $alamat));
        }
        if ($alamat !== '') {
            $html = self::gantiIsi($html, 'p', 'ezdoc-kop-alamat', $alamat);
        }

        if (($kop['city'] ?? '') !== '') {
            $html = self::gantiIsi($html, 'span', 'ezdoc-kop-kota', self::aman((string) $kop['city']));
        }

        return $html;
    }

    /**
     * Kop yang berlaku: yang disimpan lewat halaman contoh menang atas config.
     *
     * Diminta pemakai: "maksudku konfigurasi di halaman example, karena tiap
     * consumer kan beda beda". Config di berkas mount tetap ada sebagai nilai
     * awal — pemasangan baru tidak perlu mengisi apa pun dulu — dan apa yang
     * diketik di halaman itu menimpanya. Mengosongkan satu kolom berarti
     * kembali ke config, bukan jadi kosong.
     *
     * @return array{name:string,address:mixed,city:string,logo:string}
     */
    public static function letterhead(?Config $config = null): array
    {
        $kop = [
            'name' => $config !== null ? trim((string) $config->get('letterhead.name', '')) : '',
            'address' => $config !== null ? $config->get('letterhead.address', '') : '',
            'city' => $config !== null ? trim((string) $config->get('letterhead.city', '')) : '',
            'logo' => $config !== null ? trim((string) $config->get('letterhead.logo', '')) : '',
        ];

        foreach (self::storedLetterhead() as $kunci => $nilai) {
            if (!array_key_exists($kunci, $kop)) {
                continue;
            }
            if (is_array($nilai) ? $nilai !== [] : trim((string) $nilai) !== '') {
                $kop[$kunci] = $nilai;
            }
        }

        return $kop;
    }

    /**
     * Kop yang tersimpan lewat halaman contoh.
     *
     * Berupa berkas JSON di folder penyimpanan, bukan baris basis data: ini
     * pengaturan satu pemasangan, bukan data dokumen. Tidak ada tabel baru yang
     * perlu dibuat, dan mengembalikannya ke semula cukup dengan menghapus satu
     * berkas.
     *
     * @return array<string,mixed>
     */
    public static function storedLetterhead(): array
    {
        $berkas = self::letterheadFile();
        if ($berkas === null || !is_file($berkas)) {
            return [];
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        return is_array($isi) ? $isi : [];
    }

    /**
     * Simpan kop. Larik kosong menghapusnya kembali ke nilai config.
     *
     * @param array<string,mixed> $kop
     */
    public static function saveLetterhead(array $kop): bool
    {
        $berkas = self::letterheadFile();
        if ($berkas === null) {
            return false;
        }

        if ($kop === []) {
            return !is_file($berkas) || @unlink($berkas);
        }

        $dir = dirname($berkas);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($kop, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        // Tulis lewat berkas sementara lalu pindahkan: pembaca yang kebetulan
        // datang di tengah penulisan tidak pernah melihat JSON separuh.
        $sementara = $berkas . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($sementara, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($sementara, $berkas)) {
            @unlink($sementara);
            return false;
        }

        return true;
    }

    /**
     * Tempat kop tersimpan, atau null kalau pemasangan ini tidak punya folder
     * penyimpanan — halaman contohnya tetap terbuka, kopnya saja yang tidak
     * bisa diatur dari layar.
     */
    public static function letterheadFile(): ?string
    {
        foreach (['EZDOC_LETTERHEAD_PATH', 'EZDOC_STORAGE_PATH', 'EZDOC_ARTIFACT_STORAGE_PATH'] as $tetapan) {
            $nilai = defined($tetapan) ? (string) constant($tetapan) : (string) getenv($tetapan);
            $nilai = trim($nilai);
            if ($nilai === '') {
                continue;
            }

            return $tetapan === 'EZDOC_LETTERHEAD_PATH'
                ? $nilai
                : rtrim($nilai, '/\\') . '/letterhead.json';
        }

        return null;
    }

    /** Ganti isi elemen berkelas tertentu, atributnya dibiarkan apa adanya. */
    private static function gantiIsi(string $html, string $tag, string $kelas, string $isi): string
    {
        $pola = '/(<' . $tag . '[^>]*class="[^"]*' . preg_quote($kelas, '/') . '[^"]*"[^>]*>)(.*?)(<\/' . $tag . '>)/s';

        return (string) preg_replace($pola, '$1' . str_replace('$', '\$', $isi) . '$3', $html, 1);
    }

    /**
     * Logo kop: data URI dipakai apa adanya, berkas dibaca lalu dikecilkan.
     *
     * Umum supaya aksi penyimpan kop memakai jalur yang sama dengan pemuat —
     * dua tempat yang mengecilkan gambar dengan caranya sendiri akan berbeda
     * hasilnya, dan bedanya baru terlihat di kertas.
     *
     * Berkas yang tidak terbaca atau kelewat besar menghasilkan string kosong,
     * bukan galat: kop bawaan contohnya masih ada, dan halaman tetap terbuka.
     * Logo ikut tersimpan ke tiap template yang dibuat dari contoh, jadi yang
     * kelewat besar bukan cuma lambat — ia menggandakan diri.
     */
    public static function letterheadLogo(string $sumber): string
    {
        $sumber = trim($sumber);
        if ($sumber === '') {
            return '';
        }
        if (strpos($sumber, 'data:') === 0) {
            return $sumber;
        }
        if (!is_file($sumber) || !is_readable($sumber)) {
            return '';
        }
        $ukuran = (int) filesize($sumber);
        if ($ukuran <= 0 || $ukuran > 2 * 1024 * 1024) {
            return '';
        }

        $jenis = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        ];
        $ext = strtolower((string) pathinfo($sumber, PATHINFO_EXTENSION));
        if (!isset($jenis[$ext])) {
            return '';
        }

        $isi = file_get_contents($sumber);
        if ($isi === false) {
            return '';
        }

        $kecil = self::kecilkan($isi);
        if ($kecil !== '') {
            return 'data:image/png;base64,' . base64_encode($kecil);
        }

        return 'data:' . $jenis[$ext] . ';base64,' . base64_encode($isi);
    }

    /**
     * Kecilkan gambar kop yang kelewat lebar.
     *
     * Logo ikut tertanam di tiap template yang dibuat dari contoh, dan di tiap
     * dokumen yang dicetak dari template itu. Berkas 1414 piksel yang dicetak
     * selebar 68 piksel berarti setengah megabita yang digandakan terus-menerus
     * tanpa satu piksel pun terlihat.
     *
     * Mengembalikan string kosong kalau tidak bisa atau tidak perlu — yang
     * memanggil memakai berkas aslinya. Tanpa ekstensi gambar pun halaman tetap
     * terbuka; yang hilang cuma penghematannya.
     */
    private static function kecilkan(string $isi): string
    {
        $lebarMaks = 400;
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return '';
        }

        $ukuran = @getimagesizefromstring($isi);
        if (!is_array($ukuran) || (int) $ukuran[0] <= $lebarMaks) {
            return '';
        }

        $asal = @imagecreatefromstring($isi);
        if ($asal === false) {
            return '';
        }

        $lebar = (int) $ukuran[0];
        $tinggi = (int) $ukuran[1];
        $baru = (int) round($tinggi * ($lebarMaks / $lebar));
        $kecil = imagecreatetruecolor($lebarMaks, $baru > 0 ? $baru : 1);
        if ($kecil === false) {
            imagedestroy($asal);
            return '';
        }

        // Latar tembus pandang dipertahankan: logo kop hampir selalu PNG
        // beralpha, dan yang kehilangan alpha mendarat di atas kotak hitam.
        imagealphablending($kecil, false);
        imagesavealpha($kecil, true);
        imagecopyresampled($kecil, $asal, 0, 0, 0, 0, $lebarMaks, $baru, $lebar, $tinggi);

        ob_start();
        $jadi = imagepng($kecil, null, 9);
        $keluar = (string) ob_get_clean();

        imagedestroy($asal);
        imagedestroy($kecil);

        return ($jadi && $keluar !== '') ? $keluar : '';
    }

    /** Teks dari config tetap teks — ia masuk ke dokumen orang lain. */
    private static function aman(string $teks): string
    {
        return htmlspecialchars($teks, ENT_QUOTES, 'UTF-8');
    }
}
