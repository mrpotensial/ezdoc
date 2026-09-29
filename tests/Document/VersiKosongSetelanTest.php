<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use PHPUnit\Framework\TestCase;

/**
 * Pemasangan boleh menutup pilihan "versi baru dimulai kosong".
 *
 * Ada tempat yang versi barunya selalu revisi dari yang sebelumnya. Di sana
 * pilihan "kosong" bukan cuma tak terpakai — terukur di satu basis data
 * pemakai, 51 dari 152 versi lanjutan lahir kosong karena orang mengklik,
 * bukan memilih.
 *
 * `version.allow_blank => false` membuat dialognya tinggal konfirmasi. Tapi
 * dialog cuma tampilan: halaman yang sudah lama terbuka masih membawa dialog
 * versi lama, dan permintaan bisa dikirim tanpa halaman sama sekali. Yang
 * benar-benar menutupnya harus server — dan itulah yang diuji di sini, lewat
 * action-nya sendiri, bukan lewat sumbernya.
 *
 * Yang ditutup bukan pembuatan versinya, melainkan kekosongannya: permintaan
 * tetap dilayani, dibawa ke bentuk yang diizinkan, dan itu dicatat.
 */
final class VersiKosongSetelanTest extends TestCase
{
    /**
     * @return array{jawaban:array<string,mixed>,keadaan:array<string,mixed>}
     */
    private static function jalankan(bool $bolehKosong, int $sourceVersion, int $versiAwal): array
    {
        $keluaran = (string) shell_exec(sprintf(
            'php %s %s %d %d 2>&1',
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_versi_baru.php'),
            $bolehKosong ? '1' : '0',
            $sourceVersion,
            $versiAwal
        ));

        $bagian = explode("\n---\n", $keluaran);
        self::assertCount(2, $bagian, 'keluaran fixture tidak seperti yang diharapkan: ' . $keluaran);

        $jawaban = json_decode(trim($bagian[0]), true);
        $keadaan = json_decode(trim($bagian[1]), true);
        self::assertIsArray($jawaban, $keluaran);
        self::assertIsArray($keadaan, $keluaran);

        return ['jawaban' => $jawaban, 'keadaan' => $keadaan];
    }

    /**
     * Setelan tertutup: permintaan kosong menyalin versi terakhir.
     *
     * Isinya yang menunjukkan, bukan namanya: `{"nama":"v2"}` hanya bisa
     * datang dari v2.
     */
    public function testPermintaanKosongMenyalinVersiTerakhir(): void
    {
        ['jawaban' => $jawaban, 'keadaan' => $keadaan] = self::jalankan(false, 0, 2);

        self::assertTrue($jawaban['success'], 'permintaannya ditolak, bukan dialihkan');
        self::assertSame(3, $keadaan['version']);
        self::assertSame('{"nama":"v2"}', $keadaan['isi'], 'versi barunya lahir kosong');
    }

    /** Dan yang tercatat adalah yang terjadi, bukan yang diminta. */
    public function testPengalihannyaTercatat(): void
    {
        ['keadaan' => $keadaan] = self::jalankan(false, 0, 2);

        $meta = json_decode($keadaan['metadata'], true);
        self::assertIsArray($meta);
        self::assertSame(2, $meta['source_version'], 'catatan masih menyebut sumber 0');
        self::assertTrue($meta['blank_redirected'], 'pengalihannya tidak tercatat');
        self::assertStringContainsString('diminta kosong', $keadaan['catatan']);
    }

    /**
     * Versi PERTAMA di sebuah slot tetap lahir kosong.
     *
     * Tidak ada yang bisa disalin. Setelan yang melarang kekosongan di sini
     * berarti melarang dokumen pertama — dan itu bukan yang dimintanya.
     */
    public function testVersiPertamaTetapBolehKosong(): void
    {
        ['jawaban' => $jawaban, 'keadaan' => $keadaan] = self::jalankan(false, 0, 0);

        self::assertTrue($jawaban['success'], 'dokumen pertama ikut tertutup');
        self::assertSame(1, $keadaan['version']);
        self::assertSame('{}', $keadaan['isi']);
    }

    /** Permintaan salin yang jelas tidak ikut dialihkan ke versi lain. */
    public function testPermintaanSalinTidakDiubah(): void
    {
        ['keadaan' => $keadaan] = self::jalankan(false, 1, 2);

        self::assertSame('{"nama":"v1"}', $keadaan['isi'], 'yang disalin bukan versi yang diminta');

        $meta = json_decode($keadaan['metadata'], true);
        self::assertIsArray($meta);
        self::assertFalse($meta['blank_redirected'], 'permintaan yang wajar ikut ditandai dialihkan');
    }

    /**
     * Setelan terbuka: perilakunya persis seperti sebelum setelan ini ada.
     *
     * Bawaannya `true`, jadi pemasangan yang tidak tahu-menahu tidak berubah.
     */
    public function testSetelanTerbukaTidakMengubahApaPun(): void
    {
        ['keadaan' => $keadaan] = self::jalankan(true, 0, 2);

        self::assertSame(3, $keadaan['version']);
        self::assertSame('{}', $keadaan['isi'], 'permintaan kosong tidak lagi menghasilkan kosong');

        $meta = json_decode($keadaan['metadata'], true);
        self::assertIsArray($meta);
        self::assertSame(0, $meta['source_version']);
    }

    /** Bawaannya memang `true` — tidak disebut di config pun, pilihannya ada. */
    public function testBawaannyaMembolehkan(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/actions/document/new_version.php');

        self::assertSame(
            1,
            preg_match("/\\\$config->get\('version\.allow_blank', true\)/", $sumber),
            'bawaan setelannya bukan true — pemasangan lain ikut berubah tanpa diminta'
        );
    }
}
