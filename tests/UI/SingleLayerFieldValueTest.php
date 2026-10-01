<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Nilai field di-escape TEPAT SEKALI saat dirender.
 *
 * Dilaporkan pemakai: nama pasien "NIZAR RIFA'I" tercetak jadi
 * "NIZAR RIFA&amp;amp;#039;I", dan tanda tangan kedua di dokumen itu ditolak
 * dengan "Changed: nama_pasien" padahal tidak ada yang mengubahnya.
 *
 * Sebabnya satu: nilainya di-escape dua kali. Yang dikirim formulir kembali ke
 * server bukan "RIFA'I" melainkan teks "RIFA&#039;I" — sungguhan, bukan
 * tampilan — lalu tersimpan begitu. Tiap kali dokumennya dibuka dan disimpan,
 * satu lapis bertambah. Dan karena nilai tersimpan tidak lagi sama dengan yang
 * dikirim ulang, penjaga field beku menyimpulkan isinya berubah dan menolak
 * tanda tangan berikutnya. Penjaganya benar; yang diberi tahu salah.
 *
 * Yang dijaga di sini adalah satu kesamaan sederhana: apa yang akan dikirim
 * peramban kembali harus sama persis dengan apa yang tersimpan. Selama itu
 * benar, lapisannya tidak bisa bertambah.
 */
final class SingleLayerFieldValueTest extends TestCase
{
    /**
     * Nilai yang selama ini lolos karena tidak punya huruf istimewa, dan yang
     * tidak.
     *
     * @return array<string, array{0: string}>
     */
    public static function nilai(): array
    {
        return [
            'petik satu' => ["NIZAR RIFA'I"],
            'petik dua' => ['Ruang "Melati"'],
            'ampersand' => ['Ibu & Anak'],
            'kurang dari' => ['Usia < 17 tahun'],
            'campur' => ['A&B <c> "d" \'e\''],
            'tanpa huruf istimewa' => ['Siti Rahmawati'],
        ];
    }

    /**
     * Yang akan dikirim peramban kembali sama dengan yang tersimpan.
     *
     * Peramban memulihkan entitas SEKALI saat membaca atribut value. Jadi
     * nilai yang benar di halaman adalah yang, dipulihkan sekali, kembali jadi
     * nilai aslinya. Dua lapis berarti yang terkirim adalah teks entitasnya.
     *
     * @dataProvider nilai
     */
    public function testYangDikirimKembaliSamaDenganYangTersimpan(string $asli): void
    {
        $halaman = self::halaman($asli);

        self::assertSame(
            1,
            preg_match('/name="rencana_lain" value="([^"]*)"/', $halaman, $m),
            'isian tersembunyi field itu tidak ada di halaman'
        );

        self::assertSame(
            $asli,
            html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'),
            'nilai yang akan dikirim peramban berbeda dari yang tersimpan — escape-nya berlapis'
        );
    }

    /**
     * Yang terlihat di layar juga nilai aslinya, bukan teks entitasnya.
     *
     * @dataProvider nilai
     */
    public function testYangTerlihatDiLayarNilaiAslinya(string $asli): void
    {
        $halaman = self::halaman($asli);

        self::assertSame(
            1,
            preg_match('/<span class="f" contenteditable="true" data-field="rencana_lain">([^<]*)<\/span>/', $halaman, $m),
            'field itu tidak dirender sebagai isian'
        );

        self::assertSame($asli, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Nilai bawaan pun tidak berlapis.
     *
     * Ia datang lewat jalur lain (`data-default`), dan jalur lain adalah
     * tempat lapis kedua paling mudah tertinggal.
     */
    public function testNilaiBawaanTidakBerlapis(): void
    {
        $halaman = self::halaman('');

        // Tanggal surat memakai data-default="date:d F Y". Bulan Indonesia
        // tidak punya huruf istimewa, jadi yang diperiksa bentuknya: tidak ada
        // entitas yang tertinggal di nilainya.
        self::assertSame(1, preg_match('/name="tanggal_surat" value="([^"]*)"/', $halaman, $m));
        self::assertStringNotContainsString('&amp;', $m[1], 'nilai bawaan ikut di-escape dua kali');
    }

    /**
     * Perendernya yang meng-escape, bukan pemanggilnya.
     *
     * Kesamaan di atas bisa saja kembali benar secara kebetulan kalau
     * seseorang menambah escape di satu tempat dan menghapusnya di tempat
     * lain. Ini menyatakan kontraknya: yang masuk perender itu nilai mentah.
     */
    public function testPerenderYangMengEscape(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');

        self::assertSame(
            0,
            preg_match('/\$val = v\(\$name\);/', $sumber),
            'nilai yang sudah di-escape dikirim ke perender — ia akan di-escape lagi'
        );
        self::assertSame(
            2,
            preg_match_all('/\$val = vRaw\(\$name\);/', $sumber),
            'kedua jalur penanda field harus mengambil nilai mentah'
        );
        self::assertStringContainsString('$hVal = h($val);', $sumber, 'perendernya tidak meng-escape sama sekali');
    }

    /** Halaman pembuatan dokumen dengan satu nilai dititipkan lewat query. */
    private static function halaman(string $nilai): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_example_generate.php'),
            escapeshellarg('example:panggul-dalam'),
            escapeshellarg('ya'),
            escapeshellarg('b64:' . base64_encode('rencana_lain=' . rawurlencode($nilai)))
        ), $baris);

        return implode("\n", $baris);
    }
}
