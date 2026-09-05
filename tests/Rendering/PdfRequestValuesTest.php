<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Tahap 1b — perender isi PDF tidak lagi membaca permintaan yang berjalan.
 *
 * Selama `renderContentForPdf()` membaca `$_POST`/`$_GET` sendiri, memanggilnya
 * dari jalur simpan berarti ia mengambil dari permintaan **simpan**, bukan
 * permintaan **cetak**. Itu bisa saja kebetulan menghasilkan yang benar — dan
 * "kebetulan benar" persis yang tidak boleh ada di jalur pembuat bukti.
 *
 * Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md §4.
 *
 * Yang diuji di sini keputusan perakitannya, bukan sumbernya. Aturan "ambil yang
 * pertama tidak kosong" gampang salah dengan cara yang tidak kelihatan: yang
 * kosong harus DILEWATI, bukan menang. Kalau ia menang, `?nama=` di URL akan
 * menghapus nilai yang sudah ada di `$_POST` — dan yang terlihat cuma nama yang
 * hilang dari PDF, tanpa galat apa pun.
 */
final class PdfRequestValuesTest extends TestCase
{
    public function testSumberPertamaMenang(): void
    {
        self::assertSame(
            ['nama' => 'dari post'],
            ezdoc_pdf_request_values(['nama' => 'dari post'], ['nama' => 'dari get'])
        );
    }

    /**
     * Yang kosong dilewati, bukan menang.
     *
     * Inilah perilaku yang dulu tertanam di dalam perendernya, dan yang paling
     * mudah hilang kalau perakitannya ditulis ulang sebagai array_merge biasa.
     */
    public function testYangKosongDilewati(): void
    {
        self::assertSame(
            ['nama' => 'dari get'],
            ezdoc_pdf_request_values(['nama' => ''], ['nama' => 'dari get'])
        );
    }

    public function testSumberBerikutnyaMengisiYangBelumAda(): void
    {
        self::assertSame(
            ['a' => '1', 'b' => '2'],
            ezdoc_pdf_request_values(['a' => '1'], ['b' => '2'])
        );
    }

    public function testSemuaKosongMenghasilkanKosong(): void
    {
        self::assertSame([], ezdoc_pdf_request_values(['a' => '', 'b' => ''], []));
    }

    /**
     * `'0'` BUKAN kosong.
     *
     * Aturannya `!== ''`, bukan `empty()`. Memakai `empty()` akan membuang nol,
     * dan nol adalah jawaban yang sah di banyak field.
     */
    public function testNolBukanKosong(): void
    {
        self::assertSame(['n' => '0'], ezdoc_pdf_request_values(['n' => '0'], ['n' => '9']));
    }

    public function testTanpaSumberSamaSekali(): void
    {
        self::assertSame([], ezdoc_pdf_request_values());
    }

    /**
     * Perilakunya sama persis dengan rantai `if` yang dulu ada di dalam
     * perendernya — diperiksa lawan tiruannya, bukan lawan ingatan.
     *
     * @dataProvider kasus
     * @param array<string,mixed> $post
     * @param array<string,mixed> $get
     */
    public function testSamaDenganPerilakuLama(array $post, array $get, string $kunci, string $harap): void
    {
        // Persis rantai lama: dbFields -> POST -> GET, masing-masing "tidak kosong".
        $lama = static function (string $k) use ($post, $get) {
            if (isset($post[$k]) && $post[$k] !== '') {
                return $post[$k];
            }
            if (isset($get[$k]) && $get[$k] !== '') {
                return $get[$k];
            }

            return '';
        };

        $baru = ezdoc_pdf_request_values($post, $get);
        $lewatBaru = (isset($baru[$kunci]) && $baru[$kunci] !== '') ? $baru[$kunci] : '';

        self::assertSame($harap, $lama($kunci), 'tiruan perilaku lama meleset dari harapan kasus ini');
        self::assertSame($lama($kunci), $lewatBaru, 'perakitan baru menyimpang dari perilaku lama');
    }

    /** @return array<string,array{array<string,mixed>,array<string,mixed>,string,string}> */
    public static function kasus(): array
    {
        return [
            'post menang'            => [['n' => 'p'], ['n' => 'g'], 'n', 'p'],
            'post kosong'            => [['n' => ''], ['n' => 'g'], 'n', 'g'],
            'hanya get'              => [[], ['n' => 'g'], 'n', 'g'],
            'hanya post'             => [['n' => 'p'], [], 'n', 'p'],
            'dua-duanya kosong'      => [['n' => ''], ['n' => ''], 'n', ''],
            'tidak ada di mana pun'  => [[], [], 'n', ''],
            'nol di post'            => [['n' => '0'], ['n' => 'g'], 'n', '0'],
            'spasi bukan kosong'     => [['n' => ' '], ['n' => 'g'], 'n', ' '],
        ];
    }

    // ─── Penjaga: perendernya benar-benar berhenti membaca permintaan ───

    /** Badan `renderContentForPdf()`, tanpa komentar. */
    private function badanPerender(): string
    {
        $sumber = (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/document/generate.php'
        );

        $awal = strpos($sumber, 'function renderContentForPdf(');
        self::assertNotFalse($awal, 'renderContentForPdf() hilang — premis tes ini perlu ditinjau');

        $kode = '';
        $dalam = 0;
        $mulai = false;

        foreach (token_get_all('<?php ' . substr($sumber, $awal)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $teks = is_array($t) ? $t[1] : $t;
            $kode .= $teks;

            if ($teks === '{') {
                $dalam++;
                $mulai = true;
            } elseif ($teks === '}') {
                $dalam--;
                if ($mulai && $dalam === 0) {
                    break;
                }
            }
        }

        return $kode;
    }

    public function testPerenderTidakMenyentuhPermintaan(): void
    {
        $badan = $this->badanPerender();

        self::assertGreaterThan(
            5000,
            strlen($badan),
            'badan fungsinya terpotong — pemindaian kurungnya perlu ditinjau'
        );

        foreach (['$_POST', '$_GET', '$_REQUEST', '$_SESSION', '$_SERVER'] as $global) {
            self::assertSame(
                0,
                substr_count($badan, $global),
                "renderContentForPdf() kembali membaca {$global} — dipanggil dari jalur "
                . 'simpan, ia akan mengambil dari permintaan simpan alih-alih permintaan cetak'
            );
        }
    }

    /**
     * Pemanggil HTTP-nya yang merakit, dan merakitnya persis seperti dulu.
     *
     * `show_verify_qr` sengaja TIDAK ikut peta datar: ia menanyakan "apakah
     * nilainya tepat '1'", bukan "apa nilai pertama yang tidak kosong". Digabung,
     * `_show_verify_qr=0` di POST akan menutupi `=1` di GET — pergeseran diam
     * yang cuma terlihat sebagai QR yang tidak muncul.
     */
    public function testPemanggilMerakitKetiganyaSecaraTerpisah(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/document/generate.php'
        );

        self::assertStringContainsString(
            "'values'         => ezdoc_pdf_request_values(\$_POST, \$_GET),",
            $view
        );

        self::assertStringContainsString(
            "'show_verify_qr' => (\$_POST['_show_verify_qr'] ?? '') === '1'",
            $view,
            'show_verify_qr ikut diratakan — nilai 0 di POST akan menutupi 1 di GET'
        );

        self::assertStringContainsString(
            "'verify_doc_id'  => (int) (\$_GET['doc_id'] ?? \$_POST['_doc_id'] ?? 0),",
            $view
        );
    }
}
