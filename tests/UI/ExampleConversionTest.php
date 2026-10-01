<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Halaman contoh konversi: satu dokumen kertas dibedah jadi template.
 *
 * Diminta pemakai: "bantu aku buat contoh konversi dokumen dari gambar jadi
 * ezdoc, dengan menggunakan berbagai fitur, tapi jangan di database, halaman
 * baru yang mudah dipahami AI lain."
 *
 * Contoh yang salah lebih berbahaya daripada tidak ada contoh: ia disalin.
 * Karena itu yang diuji di sini bukan kalimat penjelasannya, melainkan bahwa
 * templatenya BENAR-BENAR dirender perender yang mencetak dokumen sungguhan,
 * tidak menyisakan satu pun penanda, dan setiap fitur yang dijanjikan peta
 * memang ada di dalamnya.
 */
final class ExampleConversionTest extends TestCase
{
    private const DIR = '/examples/surat-keterangan-tindakan';

    private static function akar(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function template(): string
    {
        return (string) file_get_contents(self::akar() . self::DIR . '/template.html');
    }

    /** @return array<string,mixed> */
    private static function data(): array
    {
        $isi = json_decode((string) file_get_contents(self::akar() . self::DIR . '/example.json'), true);
        self::assertIsArray($isi, 'example.json bukan JSON yang sah');

        return $isi;
    }

    private static function halaman(string $boleh = 'ya'): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example.php'),
            escapeshellarg($boleh)
        ), $baris);

        return implode("\n", $baris);
    }

    // ── Contohnya sendiri ────────────────────────────────────────────────

    /**
     * Templatenya benar-benar bisa dirender, dan tidak menyisakan penanda.
     *
     * Ini inti halamannya. Penanda yang tertinggal berarti bentuk markupnya
     * tidak dikenali perender — persis kesalahan yang paling mahal untuk
     * disalin orang, karena baru terlihat setelah dokumen dicetak.
     */
    public function testContohBenarBenarDirenderTanpaSisaPenanda(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('Contoh konversi dokumen', $halaman);
        self::assertStringNotContainsString('Pratinjau gagal dirender', $halaman, $halaman);

        // Pratinjau ditanam sebagai srcdoc; ambil isinya lalu periksa.
        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/s', $halaman, $m), 'pratinjau tidak ada di halaman');
        $pratinjau = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        foreach (['field-placeholder', 'ttd-placeholder', 'materai-placeholder', 'logo-placeholder', 'qr-placeholder', '{{'] as $penanda) {
            self::assertStringNotContainsString($penanda, $pratinjau, 'penanda ' . $penanda . ' tidak diproses perender');
        }

        // Nilai contohnya benar-benar masuk ke dokumen.
        self::assertStringContainsString('Siti Rahmawati', $pratinjau);
        self::assertStringContainsString('042/SKT/IX/2026', $pratinjau);

        // Termasuk tabel kuerinya, yang barisnya datang dari berkas — bukan
        // dari kueri, yang memang tidak dijalankan di halaman ini.
        self::assertStringContainsString('Konsultasi dokter umum', $pratinjau, 'tabel contoh kosong');
        self::assertStringContainsString('595.000', $pratinjau, 'penjumlahan tabel tidak terhitung');
    }

    /**
     * Blok bersyarat dievaluasi, bukan sekadar ikut tercetak.
     *
     * Umur contohnya 16, jadi kalimat persetujuan wali HARUS ada. Kalau
     * syaratnya tidak pernah dievaluasi, kalimat itu juga ada — karena itu
     * nilainya dibalik di sini lewat pembacaan templatenya sendiri.
     */
    public function testBlokBersyaratIkutDievaluasi(): void
    {
        $data = self::data();
        self::assertLessThan(17, (int) $data['sample_values']['umur'], 'contoh harus memenuhi syarat wali');

        $halaman = self::halaman();
        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/s', $halaman, $m));
        $pratinjau = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        self::assertStringContainsString('Bambang Rahmawan', $pratinjau, 'blok wali hilang padahal umur < 17');
        self::assertStringContainsString('conditional-section', self::template(), 'blok bersyarat tidak dipakai di contoh');
    }

    /** Setiap fitur yang dijanjikan peta memang ada di templatenya. */
    public function testSemuaFiturYangDijanjikanAdaDiTemplate(): void
    {
        $tpl = self::template();

        $wajib = [
            'logo sebaris' => 'class="logo-placeholder" data-logo="kop"',
            'logo mengambang' => 'logo-placeholder floating behind',
            'pembungkus mengambang' => 'class="floating-only"',
            'field teks' => 'data-type="text"',
            'field tanggal' => 'data-type="date"',
            'field angka' => 'data-type="number"',
            'field centang' => 'data-type="checkbox"',
            'nilai bawaan' => 'data-default="date:d F Y"',
            'kolom tabel' => '{{tabledb.tindakan.nama_tindakan}}',
            'rumus per baris' => '{{tabledb.tindakan.=jumlah*tarif|number:0}}',
            'penjumlahan' => '{{tabledb.tindakan.sum:=jumlah*tarif|number:0}}',
            'blok bersyarat' => 'class="conditional-section" data-cond="umur&lt;17"',
            'ttd dengan OTP' => 'data-require-otp="1"',
            'sumber nomor OTP' => 'data-otp-phone-source="nomor_hp_pasien"',
            'umur OTP' => 'data-otp-ttl="300"',
            'ttd boleh gambar atau QR' => 'data-ttd-modes="image,qr"',
            'ttd dibatasi peran' => 'data-allowed-roles="perawat,kepala_ruangan"',
            'ttd mode QR saja' => 'data-ttd-modes="qr"',
            'nama penanda tangan bawaan' => 'data-default-nama="dr. Andi Pratama"',
            'qr' => 'class="qr-placeholder" data-qr="link_verifikasi"',
        ];

        foreach ($wajib as $apa => $petikan) {
            self::assertStringContainsString($petikan, $tpl, 'fitur ' . $apa . ' tidak ada di contoh');
        }
    }

    /**
     * Bentuk TTD dan materai persis yang dicari perender.
     *
     * Perendernya mencocokkan STRUKTUR: TTD tiga div berurutan, materai satu
     * div di dalamnya. Satu div tambahan membuat tanda tangan tidak tercetak,
     * dan gagalnya diam — dokumennya terbit tanpa tanda tangan.
     */
    public function testBentukTtdSesuaiYangDicariPerender(): void
    {
        $tpl = self::template();

        $ttd = '/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<\/div>/s';
        self::assertSame(3, preg_match_all($ttd, $tpl), 'bentuk TTD tidak cocok dengan yang dibaca perender');
    }

    /**
     * Contoh tidak memakai fitur yang belum selesai.
     *
     * Dilaporkan pemakai: "materai fitur yang belum selesai sudah digunakan".
     * Contoh itu disalin orang — memakai yang belum selesai sama saja
     * menyebarkannya, dan yang menyalin tidak punya cara tahu.
     */
    public function testContohTidakMemakaiFiturYangBelumSelesai(): void
    {
        self::assertStringNotContainsString('materai', self::template(), 'fitur materai belum selesai');
        self::assertStringNotContainsString('materai', json_encode(self::data()['feature_map']), 'peta masih menyebut materai');
        self::assertStringContainsString('Materai TIDAK dipakai', (string) file_get_contents(self::akar() . '/views/example.php'), 'alasannya harus tertulis');
    }

    /**
     * Cap mengambangnya berada di dalam kertas.
     *
     * Dilaporkan pemakai: "posisi stempel salah" — capnya menclok di tengah
     * teks, bukan menimpa tanda tangan seperti di kertas aslinya. Koordinatnya
     * piksel dari sudut kiri-atas kertas, jadi angka yang keliru tidak
     * menghasilkan galat apa pun: ia cuma mendarat di tempat yang salah.
     */
    public function testCapMengambangBeradaDiDalamKertas(): void
    {
        $tpl = self::template();

        self::assertSame(1, preg_match('/data-logo="stempel"[^>]*data-pos-x="(\d+)" data-pos-y="(\d+)"/', $tpl, $m), 'koordinat cap tidak ketemu');
        [$x, $y] = [(int) $m[1], (int) $m[2]];

        self::assertSame(1, preg_match('/data-logo="stempel" data-width="(\d+)px"/', $tpl, $w), 'lebar cap tidak ketemu');
        $sisi = (int) $w[1];

        // A4 tegak pada 96 dpi: 794 x 1123 piksel.
        self::assertGreaterThan(0, $x);
        self::assertGreaterThan(0, $y);
        self::assertLessThanOrEqual(794, $x + $sisi, 'cap melewati tepi kanan kertas');
        self::assertLessThanOrEqual(1123, $y + $sisi, 'cap melewati tepi bawah kertas');

        // Dan ia memang berada di paruh bawah, tempat tanda tangan berkumpul —
        // seperti cap di kertas aslinya.
        self::assertGreaterThan(560, $y, 'cap mendarat di tengah teks, bukan di area tanda tangan');
    }

    /** Tiap field yang dipakai template punya nilai contoh — pratinjaunya tidak setengah kosong. */
    public function testSetiapFieldPunyaNilaiContoh(): void
    {
        $data = self::data();
        preg_match_all('/\{\{([a-z_][a-z0-9_]*)\}\}/', self::template(), $m);

        $dipakai = array_unique($m[1]);
        self::assertNotEmpty($dipakai);

        foreach ($dipakai as $field) {
            self::assertArrayHasKey($field, $data['sample_values'], 'field ' . $field . ' tidak punya nilai contoh');
        }

        // Nama penanda tangan dan isi QR juga field — keduanya dirujuk atribut.
        foreach (['nama_perawat', 'nama_dokter', 'link_verifikasi', 'nomor_hp_pasien'] as $field) {
            self::assertArrayHasKey($field, $data['sample_values'], 'field rujukan ' . $field . ' tidak punya nilai contoh');
        }
    }

    /** Kueri contohnya hanya membaca, dan kolomnya cocok dengan yang dirujuk template. */
    public function testKueriContohHanyaMembacaDanKolomnyaCocok(): void
    {
        $data = self::data();
        $kueri = $data['config_header']['tableDbQueries']['tindakan'];

        self::assertSame('SELECT', strtoupper(substr(ltrim($kueri['sql']), 0, 6)), 'kueri contoh harus SELECT');
        foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE'] as $terlarang) {
            self::assertStringNotContainsStringIgnoringCase($terlarang, $kueri['sql']);
        }

        preg_match_all('/\{\{tabledb\.tindakan\.([^|}]+)\}\}/', self::template(), $m);
        foreach ($m[1] as $selektor) {
            preg_match_all('/[a-z_][a-z0-9_]*/', (string) $selektor, $kata);
            foreach ($kata[0] as $nama) {
                if (in_array($nama, ['sum', 'avg', 'count', 'min', 'max'], true)) {
                    continue;
                }
                self::assertContains($nama, $kueri['columns'], 'kolom ' . $nama . ' tidak ada di kueri contoh');
            }
        }
    }

    /** Petanya lengkap: tiap baris punya nomor, pin di dalam gambar, dan penjelasan. */
    public function testPetaLengkapDanPinnyaDiDalamGambar(): void
    {
        $peta = self::data()['feature_map'];
        self::assertGreaterThanOrEqual(10, count($peta), 'peta terlalu sedikit untuk disebut contoh berbagai fitur');

        $nomor = [];
        foreach ($peta as $baris) {
            foreach (['no', 'pin', 'bagian', 'fitur', 'markup', 'simpan', 'catatan'] as $kunci) {
                self::assertArrayHasKey($kunci, $baris, 'peta kehilangan ' . $kunci);
                self::assertNotSame('', (string) (is_array($baris[$kunci]) ? 'ada' : $baris[$kunci]));
            }
            self::assertGreaterThanOrEqual(0, $baris['pin']['x']);
            self::assertLessThanOrEqual(100, $baris['pin']['x']);
            self::assertGreaterThanOrEqual(0, $baris['pin']['y']);
            self::assertLessThanOrEqual(100, $baris['pin']['y']);
            $nomor[] = (int) $baris['no'];
        }
        self::assertSame($nomor, array_unique($nomor), 'nomor peta berulang');
    }

    // ── Halamannya ───────────────────────────────────────────────────────

    /** Halaman menampilkan peta, berkas, dan ringkasan siap salin. */
    public function testHalamanMenampilkanPetaBerkasDanRingkasan(): void
    {
        $halaman = self::halaman();
        $peta = self::data()['feature_map'];

        self::assertSame(count($peta), substr_count($halaman, 'data-ez-pin="'), 'jumlah pin tidak sama dengan peta');
        self::assertSame(count($peta), substr_count($halaman, 'data-ez-baris="'));
        self::assertStringContainsString('Ringkasan untuk AI', $halaman);
        self::assertStringContainsString('Aturan yang sering bikin gagal', $halaman);
        self::assertStringContainsString('examples/surat-keterangan-tindakan/', $halaman);
    }

    /**
     * Gambar logo tidak ikut dicetak utuh ke layar.
     *
     * Data URI-nya puluhan ribu karakter. Ditampilkan apa adanya, blok config
     * di halaman berubah jadi dinding karakter yang tidak terbaca siapa pun.
     */
    public function testConfigDitampilkanTanpaDindingBase64(): void
    {
        $halaman = self::halaman();

        self::assertStringContainsString('(dipotong', $halaman);

        // Yang diperiksa blok config-nya saja. Pratinjau di atasnya memang
        // memuat gambar logonya — di sana ia gambar, bukan teks untuk dibaca.
        self::assertSame(1, preg_match('/<pre id="ezCfg"[^>]*>([\s\S]*?)<\/pre>/', $halaman, $m), 'blok config tidak ada');
        $data = self::data();
        $logo = (string) $data['config_header']['logos']['kop'];
        self::assertStringNotContainsString(substr($logo, -200), $m[1], 'data URI logo ikut tercetak utuh di blok config');
        self::assertLessThan(4000, strlen($m[1]), 'blok config terlalu panjang untuk dibaca orang');
    }

    /** Halaman ini untuk yang membuat template; yang lain melihat penjelasan, bukan isinya. */
    public function testHalamanTertutupUntukYangTidakMengelolaTemplate(): void
    {
        $halaman = self::halaman('tidak');

        self::assertStringNotContainsString('data-ez-pin', $halaman);
        self::assertStringNotContainsString('Ringkasan untuk AI', $halaman);
        self::assertStringContainsString('template', strtolower($halaman));
    }

    /** Halamannya punya alamat sendiri, dan pintunya ada di menu. */
    public function testRutenyaTerdaftarDanAdaDiMenu(): void
    {
        $router = new \ReflectionClass(\Ezdoc\Http\Router::class);
        $daftar = $router->getStaticPropertyValue('PAGE_WHITELIST');
        self::assertContains('example', $daftar, 'rute contoh tidak masuk daftar putih halaman');

        $sumber = (string) file_get_contents(self::akar() . '/src/Http/Router.php');
        self::assertStringContainsString("\$this->handlers['example']", $sumber);

        $layout = (string) file_get_contents(self::akar() . '/views/layout.php');
        self::assertStringContainsString("'page' => 'example'", $layout, 'halaman contoh tidak punya pintu di menu');
        self::assertStringContainsString('ezdoc_can_manage_templates()', $layout, 'pintu menu harus ikut hak kelola template');
    }

    /** Contohnya berkas, bukan baris database. */
    public function testContohTidakMenyentuhDatabase(): void
    {
        $view = (string) file_get_contents(self::akar() . '/views/example.php');

        foreach (['ezdoc_templates', 'mysqli_query', '$conn->', 'INSERT', 'UPDATE '] as $jejak) {
            self::assertStringNotContainsString($jejak, $view, 'halaman contoh menyentuh database lewat ' . $jejak);
        }
        self::assertStringContainsString("'conn' => null", $view);
    }
}
