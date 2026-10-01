<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Template;

use Ezdoc\Template\Example;
use Ezdoc\UI\Config;
use PHPUnit\Framework\TestCase;

/**
 * Kop contoh datang dari konfigurasi aplikasi pemakai; berkasnya tetap netral.
 *
 * Diminta pemakai: "kopnya apa tidak bisa langsung [pakai milik saya]? atau
 * kita tambahkan konfigurasi ... agar defaultnya berubah nama, logo, alamat",
 * lalu diperjelas: "maksudku agar contoh muncul sebagai hasil konfigurasi,
 * tapi berkas contohnya tetap netral".
 *
 * Dua sisi yang dijaga di sini, dan keduanya perlu:
 *
 * - Dengan konfigurasi, yang tampil DAN yang tersimpan jadi template adalah
 *   kop aplikasi itu. Kalau cuma tampil, template hasil simpan membawa kop
 *   karangan dan baru ketahuan di kertas.
 * - Tanpa konfigurasi, berkasnya apa adanya. Pustaka ini dipakai lebih dari
 *   satu tempat; menanam nama satu organisasi di dalamnya membuat contohnya
 *   salah untuk semua yang lain.
 */
final class ExampleLetterheadTest extends TestCase
{
    private const SLUG = 'skor-bishop';

    private static function kop(string $html, string $kelas, string $tag = 'p'): string
    {
        $pola = '/<' . $tag . '[^>]*class="[^"]*' . preg_quote($kelas, '/') . '[^"]*"[^>]*>(.*?)<\/' . $tag . '>/s';
        self::assertSame(1, preg_match($pola, $html, $m), 'penanda ' . $kelas . ' tidak ada');

        return $m[1];
    }

    private static function config(array $tambahan = []): Config
    {
        return Config::fromArray(array_merge([
            'letterhead.name' => 'RUMAH SAKIT UJI COBA',
            'letterhead.address' => 'Jalan Uji No. 1',
            'letterhead.city' => 'Kota Uji',
        ], $tambahan));
    }

    // ── Dengan konfigurasi ───────────────────────────────────────────────

    /** Nama, alamat, dan kota diganti isi konfigurasinya. */
    public function testKopDiambilDariKonfigurasi(): void
    {
        $contoh = Example::load(self::SLUG, self::config());
        self::assertNotNull($contoh);
        $html = (string) $contoh['template_html'];

        self::assertSame('RUMAH SAKIT UJI COBA', self::kop($html, 'ezdoc-kop-nama'));
        self::assertSame('Jalan Uji No. 1', self::kop($html, 'ezdoc-kop-alamat'));
        self::assertSame('Kota Uji', self::kop($html, 'ezdoc-kop-kota', 'span'));

        self::assertStringNotContainsString('RUMAH SAKIT CONTOH SEJAHTERA', $html, 'kop karangan masih tersisa');
        self::assertStringNotContainsString('Kota Contoh', $html, 'nama kota karangan masih tersisa');
    }

    /** Alamat boleh lebih dari satu baris. */
    public function testAlamatBolehBerupaLarik(): void
    {
        $contoh = Example::load(self::SLUG, self::config([
            'letterhead.address' => ['Baris satu', '', 'Baris dua'],
        ]));

        self::assertSame('Baris satu<br>Baris dua', self::kop((string) $contoh['template_html'], 'ezdoc-kop-alamat'));
    }

    /**
     * Teks konfigurasi tetap teks.
     *
     * Ia datang dari berkas aplikasi pemakai, tapi ia mendarat di dokumen yang
     * dibaca orang lain — dan template hasil simpan menyimpannya selamanya.
     */
    public function testTeksKonfigurasiTidakJadiMarkup(): void
    {
        $contoh = Example::load(self::SLUG, self::config([
            'letterhead.name' => 'RS <script>alert(1)</script>',
        ]));
        $html = (string) $contoh['template_html'];

        self::assertStringNotContainsString('<script>', $html, 'markup dari config ikut tertanam');
        self::assertStringContainsString('&lt;script&gt;', self::kop($html, 'ezdoc-kop-nama'));
    }

    /** Logo kop ikut konfigurasi, dan ia yang tersimpan di pengaturan halaman. */
    public function testLogoDariBerkasJadiDataUri(): void
    {
        $png = self::pngSementara(10, 10);

        try {
            $contoh = Example::load(self::SLUG, self::config(['letterhead.logo' => $png]));
            $kepala = json_decode((string) $contoh['config_header'], true);

            self::assertIsArray($kepala);
            self::assertStringStartsWith('data:image/png;base64,', (string) $kepala['logos']['kop']);
            self::assertNotSame(
                'data:image/png;base64,',
                (string) $kepala['logos']['kop'],
                'logonya kosong'
            );
        } finally {
            @unlink($png);
        }
    }

    /** Logo berupa data URI dipakai apa adanya. */
    public function testLogoDataUriDipakaiApaAdanya(): void
    {
        $uri = 'data:image/png;base64,iVBORw0KGgo=';
        $contoh = Example::load(self::SLUG, self::config(['letterhead.logo' => $uri]));
        $kepala = json_decode((string) $contoh['config_header'], true);

        self::assertSame($uri, $kepala['logos']['kop']);
    }

    /**
     * Logo yang kelewat lebar dikecilkan.
     *
     * Ia ikut tertanam di tiap template yang dibuat dari contoh, lalu di tiap
     * dokumen yang dicetak dari template itu. Berkas 1400 piksel yang dicetak
     * selebar 68 piksel menggandakan dirinya tanpa satu piksel pun terlihat.
     */
    public function testLogoKelewatLebarDikecilkan(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD tidak tersedia');
        }

        $besar = self::pngSementara(1400, 1400);
        try {
            $contoh = Example::load(self::SLUG, self::config(['letterhead.logo' => $besar]));
            $kepala = json_decode((string) $contoh['config_header'], true);
            $data = (string) $kepala['logos']['kop'];

            $bin = base64_decode(substr($data, strpos($data, ',') + 1));
            $ukuran = getimagesizefromstring($bin);

            self::assertIsArray($ukuran);
            self::assertLessThanOrEqual(400, (int) $ukuran[0], 'logo tidak dikecilkan');
            self::assertGreaterThan(0, (int) $ukuran[1]);
        } finally {
            @unlink($besar);
        }
    }

    /** Berkas logo yang tidak ada tidak merusak apa pun — kop bawaannya dipakai. */
    public function testLogoYangTidakAdaTidakMerusak(): void
    {
        $bawaan = Example::load(self::SLUG);
        $contoh = Example::load(self::SLUG, self::config([
            'letterhead.logo' => sys_get_temp_dir() . '/tidak-ada-logo-ezdoc.png',
        ]));

        self::assertNotNull($contoh);
        self::assertSame(
            json_decode((string) $bawaan['config_header'], true)['logos']['kop'],
            json_decode((string) $contoh['config_header'], true)['logos']['kop'],
            'logo bawaan contohnya ikut hilang'
        );
    }

    // ── Tanpa konfigurasi ────────────────────────────────────────────────

    /** Berkas contohnya tetap netral: tanpa config, tidak ada yang berubah. */
    public function testTanpaKonfigurasiBerkasnyaApaAdanya(): void
    {
        $contoh = Example::load(self::SLUG);
        $berkas = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . self::SLUG . '/template.html');

        self::assertSame($berkas, (string) $contoh['template_html'], 'isi contohnya berubah tanpa diminta');
        self::assertStringContainsString('RUMAH SAKIT CONTOH SEJAHTERA', $berkas);
    }

    /** Config kosong sama saja dengan tanpa config. */
    public function testKonfigurasiKosongTidakMengubahApaPun(): void
    {
        $contoh = Example::load(self::SLUG, Config::fromArray([]));
        $berkas = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . self::SLUG . '/template.html');

        self::assertSame($berkas, (string) $contoh['template_html']);
    }

    /**
     * Berkas contoh tidak menyebut organisasi mana pun yang nyata.
     *
     * Pustaka ini berdiri sendiri dan boleh dipakai siapa saja. Nama sungguhan
     * di dalamnya membuat contohnya salah untuk semua pemakai lain — dan
     * menyebarkan identitas satu tempat ke repositori yang bukan miliknya.
     *
     * Yang diperiksa kata "CONTOH" di nama kopnya, bukan satu nama tertentu:
     * contohnya tidak semuanya rumah sakit — ada puskesmas, pabrik, dan
     * posyandu — dan mengunci satu nama akan memaksa kop rumah sakit dipakai
     * di kartu perawatan mesin.
     */
    public function testBerkasContohTetapNetral(): void
    {
        foreach (array_keys(Example::all()) as $slug) {
            $berkas = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . $slug . '/template.html');

            self::assertSame(
                1,
                preg_match('/class="ezdoc-kop-nama"[^>]*>([^<]+)</', $berkas, $m),
                $slug . ': kopnya tidak punya nama organisasi'
            );
            self::assertStringContainsString(
                'CONTOH',
                strtoupper($m[1]),
                $slug . ': nama kopnya bukan nama karangan'
            );
        }
    }

    /** PNG sementara berukuran tertentu. */
    private static function pngSementara(int $lebar, int $tinggi): string
    {
        $jalan = tempnam(sys_get_temp_dir(), 'ezdoc-logo-') . '.png';
        $gambar = imagecreatetruecolor($lebar, $tinggi);
        imagefilledrectangle($gambar, 0, 0, $lebar - 1, $tinggi - 1, imagecolorallocate($gambar, 20, 90, 160));
        imagepng($gambar, $jalan);
        imagedestroy($gambar);

        return $jalan;
    }
}
