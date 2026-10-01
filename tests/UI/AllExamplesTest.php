<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use Ezdoc\Template\Example;
use PHPUnit\Framework\TestCase;

/**
 * Aturan yang berlaku untuk SETIAP contoh, bukan cuma yang pertama ditulis.
 *
 * Contoh dibuat untuk disalin: yang membacanya menganggap yang di situ benar.
 * Satu contoh yang rusak lebih buruk daripada tidak ada contoh, dan yang rusak
 * biasanya bukan yang sedang dikerjakan melainkan yang sudah lama ada dan
 * ikut terbawa perubahan.
 *
 * Jadi uji ini tidak menyebut nama contoh mana pun: ia menemukan sendiri semua
 * folder di `contoh/` dan memberlakukan aturan yang sama ke semuanya. Contoh
 * baru ikut terjaga pada menit ia dibuat, tanpa ada yang perlu ingat menyalin
 * berkas uji.
 */
final class AllExamplesTest extends TestCase
{
    /** Tipe field yang benar-benar dikenali perender. */
    private const TIPE_DIKENAL = ['text', 'number', 'date', 'time', 'checkbox', 'radio', 'select', 'calc'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function contoh(): array
    {
        $keluar = [];
        foreach (array_keys(Example::all()) as $slug) {
            $keluar[$slug] = [(string) $slug];
        }

        return $keluar;
    }

    private static function template(string $slug): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . $slug . '/template.html');
    }

    /** @return array<string,mixed> */
    private static function data(string $slug): array
    {
        $isi = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . $slug . '/example.json'), true);
        self::assertIsArray($isi, $slug . ': example.json bukan JSON yang sah');

        return $isi;
    }

    private static function render(string $slug): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_render.php'),
            escapeshellarg($slug)
        ), $baris);

        return implode("\n", $baris);
    }

    /** Ada contohnya — kalau globnya salah, semua uji di bawah lulus tanpa menguji apa pun. */
    public function testAdaContohnya(): void
    {
        self::assertGreaterThanOrEqual(2, count(self::contoh()), 'contoh tidak terbaca');
    }

    /**
     * Templatenya benar-benar dirender, dan tidak menyisakan penanda.
     *
     * Penanda yang tertinggal berarti bentuk markupnya tidak dikenali perender
     * — persis kesalahan yang paling mahal untuk disalin orang, karena baru
     * terlihat setelah dokumen dicetak.
     *
     * @dataProvider contoh
     */
    public function testDirenderTanpaSisaPenanda(string $slug): void
    {
        $html = self::render($slug);

        self::assertGreaterThan(2000, strlen($html), $slug . ': hasil rendernya terlalu pendek — ' . $html);
        foreach (['field-placeholder', 'ttd-placeholder', 'logo-placeholder', 'qr-placeholder', '{{'] as $penanda) {
            self::assertStringNotContainsString($penanda, $html, $slug . ': penanda "' . $penanda . '" tidak diproses');
        }
        self::assertStringNotContainsString('Fatal error', $html, $slug);
        self::assertStringNotContainsString('Warning:', $html, $slug);
    }

    /**
     * Tiap field yang dipakai punya nilai contoh.
     *
     * Pratinjau yang setengah kosong tidak menunjukkan apa pun tentang bentuk
     * jadinya. Field hitung dikecualikan: nilainya memang tidak boleh diketik,
     * ia datang dari rumusnya.
     *
     * @dataProvider contoh
     */
    public function testSetiapFieldPunyaNilaiContoh(string $slug): void
    {
        $template = self::template($slug);
        $data = self::data($slug);

        $hitung = [];
        foreach (self::fieldHitung($template) as $nama => $rumus) {
            $hitung[$nama] = true;
        }

        preg_match_all('/\{\{([a-z_][a-z0-9_]*)\}\}/', $template, $m);
        $dipakai = array_unique($m[1]);
        self::assertNotEmpty($dipakai, $slug . ': tidak ada field sama sekali');

        foreach ($dipakai as $field) {
            if (isset($hitung[$field])) {
                self::assertArrayNotHasKey(
                    $field,
                    $data['sample_values'],
                    $slug . ': field hitung ' . $field . ' diberi nilai contoh — nilainya datang dari rumus'
                );
                continue;
            }
            self::assertArrayHasKey($field, $data['sample_values'], $slug . ': field ' . $field . ' tidak punya nilai contoh');
        }
    }

    /**
     * Nama penanda tangan juga field, dan ia dirujuk dari atribut.
     *
     * Kalau nama itu tidak punya nilai contoh, kolom tanda tangannya tercetak
     * kosong di pratinjau dan yang melihat mengira fiturnya rusak.
     *
     * @dataProvider contoh
     */
    public function testNamaPenandaTanganPunyaNilaiContoh(string $slug): void
    {
        $template = self::template($slug);
        $data = self::data($slug);

        preg_match_all('/data-nama-field="([a-z_][a-z0-9_]*)"/', $template, $m);
        foreach (array_unique($m[1]) as $field) {
            self::assertArrayHasKey($field, $data['sample_values'], $slug . ': nama penanda tangan ' . $field . ' tidak punya nilai contoh');
        }
    }

    /**
     * Rumus field hitung hanya menyebut field yang benar-benar ada.
     *
     * Nama yang salah tulis dihitung nol tanpa keluhan apa pun: totalnya
     * terlihat wajar, cuma salah. Itu jenis kesalahan yang tidak pernah
     * ketahuan dari melihat dokumennya.
     *
     * @dataProvider contoh
     */
    public function testRumusHanyaMenyebutFieldYangAda(string $slug): void
    {
        $template = self::template($slug);
        self::assertNotSame('', $template, $slug . ': templatenya kosong');

        preg_match_all('/\{\{([a-z_][a-z0-9_]*)\}\}/', $template, $m);
        $ada = array_flip($m[1]);

        foreach (self::fieldHitung($template) as $nama => $rumus) {
            preg_match_all('/[a-z_][a-z0-9_]*/', str_replace(['{', '}'], '', $rumus), $rm);
            self::assertNotEmpty($rm[0], $slug . ': rumus ' . $nama . ' tidak menyebut field apa pun');
            foreach ($rm[0] as $rujukan) {
                self::assertArrayHasKey(
                    $rujukan,
                    $ada,
                    $slug . ': rumus ' . $nama . ' menyebut "' . $rujukan . '" yang tidak ada di template'
                );
                self::assertNotSame($nama, $rujukan, $slug . ': rumus ' . $nama . ' menyebut dirinya sendiri');
            }
        }
    }

    /**
     * Pilihan yang sudah bernilai benar-benar terpilih di halaman pengisian.
     *
     * Nilai tersimpan datang dalam bentuk siap-cetak (sudah di-escape),
     * pilihannya tidak. Sebelum keduanya disamakan, pilihan yang memuat tanda
     * kurang-dari atau lebih-dari tidak pernah tercentang — formulir terbuka
     * seolah belum diisi, dan yang membukanya mengisi ulang dari nol tanpa
     * tahu jawabannya sudah pernah ada.
     *
     * @dataProvider contoh
     */
    public function testPilihanBernilaiTercentangDiHalamanPengisian(string $slug): void
    {
        $template = self::template($slug);
        $data = self::data($slug);

        // Nama field pilihan yang punya nilai contoh tidak kosong.
        preg_match_all(
            '/<span[^>]*data-type="radio"[^>]*>\{\{([a-z_][a-z0-9_]*)\}\}<\/span>/',
            $template,
            $m
        );
        $perlu = [];
        foreach (array_unique($m[1]) as $nama) {
            if (trim((string) ($data['sample_values'][$nama] ?? '')) !== '') {
                $perlu[] = $nama;
            }
        }
        if ($perlu === []) {
            self::assertNotSame('', $template, $slug . ': templatenya kosong');
            return;
        }

        $halaman = self::halamanBuat($slug);
        foreach ($perlu as $nama) {
            self::assertSame(
                1,
                preg_match('/data-field="' . preg_quote($nama, '/') . '"[\s\S]{0,1200}?<\/span>/', $halaman, $bagian),
                $slug . ': field pilihan ' . $nama . ' tidak dirender'
            );
            self::assertStringContainsString(
                'checked',
                $bagian[0],
                $slug . ': nilai contoh ' . $nama . ' tidak terpilih — bandingkan nilai mentahnya, bukan yang sudah di-escape'
            );
        }
    }

    /** Contohnya dibuka di halaman pembuatan dokumen, dirender apa adanya. */
    private static function halamanBuat(string $slug): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_generate.php'),
            escapeshellarg('example:' . $slug),
            escapeshellarg('ya')
        ), $baris);

        return implode("
", $baris);
    }

    /**
     * Tipe field yang dipakai memang dikenali perender.
     *
     * Tipe yang salah tulis jatuh ke "text" diam-diam: pilihan gandanya jadi
     * kotak ketikan, dan tidak ada yang memberi tahu.
     *
     * @dataProvider contoh
     */
    public function testTipeFieldnyaDikenali(string $slug): void
    {
        preg_match_all('/data-type="([a-z]+)"/', self::template($slug), $m);
        foreach (array_unique($m[1]) as $tipe) {
            self::assertContains($tipe, self::TIPE_DIKENAL, $slug . ': tipe field "' . $tipe . '" tidak dikenali perender');
        }
    }

    /**
     * Field pilihan selalu punya pilihannya.
     *
     * Radio atau select tanpa data-options merender kotak kosong — dan yang
     * mengisi formulir tidak punya apa pun untuk dipilih.
     *
     * @dataProvider contoh
     */
    public function testFieldPilihanPunyaPilihan(string $slug): void
    {
        $template = self::template($slug);
        self::assertNotSame('', $template, $slug . ': templatenya kosong');

        preg_match_all('/<span[^>]*class="field-placeholder"[^>]*>/', $template, $m);
        foreach ($m[0] as $tag) {
            if (preg_match('/data-type="(radio|select)"/', $tag) !== 1) {
                continue;
            }
            self::assertSame(1, preg_match('/data-options="[^"]+"/', $tag), $slug . ': field pilihan tanpa data-options — ' . $tag);
        }
    }

    /**
     * Contoh tidak memakai fitur yang belum selesai.
     *
     * Contoh itu disalin orang, dan yang menyalin tidak punya cara tahu mana
     * yang belum jadi. Materai belum selesai; pernah dipakai di contoh dan
     * langsung ditegur pemakai.
     *
     * @dataProvider contoh
     */
    public function testTidakMemakaiFiturYangBelumSelesai(string $slug): void
    {
        self::assertStringNotContainsString('materai', self::template($slug), $slug . ': memakai fitur yang belum selesai');
    }

    /**
     * Kopnya bisa diganti dari konfigurasi.
     *
     * Tiga penanda ini yang dicari pemuat contoh saat aplikasi pemakainya
     * menyebutkan kop miliknya sendiri. Contoh baru yang lupa memasangnya akan
     * selamanya membawa kop karangan ke tiap template yang dibuat darinya, dan
     * itu baru terlihat di kertas.
     *
     * @dataProvider contoh
     */
    public function testKopnyaBisaDigantiDariKonfigurasi(string $slug): void
    {
        $template = self::template($slug);

        foreach (['ezdoc-kop-nama', 'ezdoc-kop-alamat', 'ezdoc-kop-kota'] as $penanda) {
            self::assertStringContainsString(
                'class="' . $penanda . '"',
                $template,
                $slug . ': penanda ' . $penanda . ' tidak ada — kopnya tidak bisa diganti dari konfigurasi'
            );
        }
    }

    /**
     * Petanya lengkap dan pinnya masuk akal.
     *
     * Peta adalah sumber tunggal penjelasan halaman contoh: nomor di gambar,
     * tabel fitur, dan ringkasan untuk model lain dibaca dari sini.
     *
     * @dataProvider contoh
     */
    public function testPetanyaLengkap(string $slug): void
    {
        $data = self::data($slug);
        self::assertArrayHasKey('feature_map', $data, $slug);
        self::assertNotEmpty($data['feature_map'], $slug . ': petanya kosong');

        foreach ($data['feature_map'] as $i => $baris) {
            foreach (['no', 'pin', 'bagian', 'fitur', 'markup', 'simpan', 'catatan'] as $kunci) {
                self::assertArrayHasKey($kunci, $baris, $slug . ': peta baris ' . $i . ' tanpa "' . $kunci . '"');
                self::assertNotSame('', $baris[$kunci], $slug . ': peta baris ' . $i . ' kunci "' . $kunci . '" kosong');
            }
            foreach (['x', 'y'] as $sumbu) {
                self::assertArrayHasKey($sumbu, $baris['pin'], $slug);
                self::assertGreaterThanOrEqual(0, $baris['pin'][$sumbu], $slug . ': pin di luar gambar');
                self::assertLessThanOrEqual(100, $baris['pin'][$sumbu], $slug . ': pin di luar gambar');
            }
        }
    }

    /**
     * Pengaturan halamannya ada dan masuk akal.
     *
     * @dataProvider contoh
     */
    public function testPengaturanHalamannyaAda(string $slug): void
    {
        $data = self::data($slug);

        self::assertNotSame('', (string) ($data['nama_template'] ?? ''), $slug . ': tanpa nama template');
        self::assertStringContainsString('(contoh)', (string) $data['nama_template'], $slug . ': namanya tidak menandai dirinya contoh');

        self::assertArrayHasKey('config_header', $data, $slug);
        self::assertArrayHasKey('paperSize', $data['config_header'], $slug . ': ukuran kertas tidak disebut');

        // Logo yang dirujuk template harus ada gambarnya.
        preg_match_all('/data-logo="([a-z_]+)"/', self::template($slug), $m);
        foreach (array_unique($m[1]) as $logo) {
            self::assertArrayHasKey('logos', $data['config_header'], $slug);
            self::assertArrayHasKey($logo, $data['config_header']['logos'], $slug . ': logo "' . $logo . '" dirujuk tapi tidak ada gambarnya');
        }
    }

    /**
     * Nama folder dan slug yang dipakai loader cocok dengan pola yang aman.
     *
     * @dataProvider contoh
     */
    public function testNamaFoldernyaAman(string $slug): void
    {
        self::assertSame(1, preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug), 'nama folder "' . $slug . '" tidak akan dimuat loader');
    }

    /**
     * Nama field hitung => rumusnya.
     *
     * @return array<string,string>
     */
    private static function fieldHitung(string $template): array
    {
        $keluar = [];
        preg_match_all('/<span[^>]*data-type="calc"[^>]*>\{\{([a-z_][a-z0-9_]*)\}\}<\/span>/', $template, $m, PREG_SET_ORDER);
        foreach ($m as $satu) {
            if (preg_match('/data-formula="([^"]*)"/', $satu[0], $fm) === 1) {
                $keluar[$satu[1]] = html_entity_decode($fm[1], ENT_QUOTES, 'UTF-8');
            }
        }

        return $keluar;
    }
}
