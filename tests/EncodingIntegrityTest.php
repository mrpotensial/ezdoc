<?php

declare(strict_types=1);

namespace Ezdoc\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Berkas yang dikirim harus UTF-8 bersih, tanpa BOM.
 *
 * Ini lahir dari kerusakan nyata. Dua berkas view disunting dengan alat yang
 * membaca UTF-8 sebagai Windows-1252 lalu menulisnya kembali sebagai UTF-8.
 * Hasilnya setiap karakter non-ASCII jadi ganda: `·` berubah jadi `Â·`, `—`
 * jadi `â€"`. Kode tetap jalan, tes tetap hijau, PHPStan tetap bersih — dan
 * pengguna melihat sampah di layar selama berhari-hari.
 *
 * Kerusakan yang tidak menjatuhkan apa pun adalah kerusakan yang paling lama
 * hidup. Karena itu ia butuh pemeriksa sendiri.
 *
 * Dua hal yang dijaga:
 *
 *   1. **Tidak ada BOM.** Tiga byte di depan `<?php` ikut terkirim sebagai
 *      keluaran — sebelum header sempat dikirim — dan muncul sebagai karakter
 *      hantu di puncak halaman.
 *   2. **Tidak ada jejak ganda-encode.** Urutan byte seperti `Ã¢â‚¬` tidak
 *      pernah muncul dari penulisan yang benar; ia hanya lahir dari UTF-8 yang
 *      diperlakukan sebagai encoding satu-byte.
 */
final class EncodingIntegrityTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Urutan byte yang mustahil muncul dari teks yang ditulis benar.
     *
     * Semuanya berawal `Ã` atau `Â` diikuti byte yang hanya masuk akal kalau
     * satu karakter sudah di-encode dua kali.
     */
    private const JEJAK_RUSAK = [
        'â€ (em dash / kutip melengkung)' => "\xC3\xA2\xE2\x82\xAC",
        'Â + tanda baca'                  => '/\xC3\x82[\xA0-\xBF]/',
        'Ã— (tanda kali)'                 => "\xC3\x83\xE2\x80\x94",
    ];

    /** @return list<string> */
    private function berkasTerkirim(): array
    {
        $root = dirname(__DIR__);

        // Yang diperiksa hanya yang benar-benar dikirim ke pemakai. `vendor/`
        // milik orang lain, dan `.git` bukan teks.
        $lewati = ['vendor', '.git', 'node_modules'];

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile() || !in_array($f->getExtension(), ['php', 'sql', 'json'], true)) {
                continue;
            }

            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            foreach ($lewati as $l) {
                if (strncmp($rel, $l . '/', strlen($l) + 1) === 0) {
                    continue 2;
                }
            }

            $out[] = $rel;
        }

        sort($out);

        return $out;
    }

    public function testTheProjectHasFilesToCheck(): void
    {
        // Penjaga untuk penjaganya. Pemindai yang tidak menemukan apa-apa akan
        // lulus selamanya tanpa memeriksa apa pun.
        self::assertGreaterThan(100, count($this->berkasTerkirim()));
    }

    /**
     * BOM membuat tiga byte terkirim sebelum baris pertama dieksekusi. Di
     * berkas yang menghasilkan halaman, itu berarti header tidak bisa lagi
     * dikirim dan ada karakter hantu di puncak keluaran.
     */
    public function testNoShippedFileStartsWithAByteOrderMark(): void
    {
        $kotor = [];

        foreach ($this->berkasTerkirim() as $rel) {
            $awal = (string) file_get_contents(dirname(__DIR__) . '/' . $rel, false, null, 0, 3);
            if ($awal === self::BOM) {
                $kotor[] = $rel;
            }
        }

        self::assertSame([], $kotor, 'berkas ber-BOM: ' . implode(', ', $kotor));
    }

    public function testNoShippedFileCarriesDoubleEncodedText(): void
    {
        $kotor = [];

        foreach ($this->berkasTerkirim() as $rel) {
            // Berkas ini sendiri memuat contoh jejaknya, jadi ia dikecualikan —
            // kalau tidak, pemeriksanya menuduh dirinya sendiri.
            if ($rel === 'tests/EncodingIntegrityTest.php') {
                continue;
            }

            $isi = (string) file_get_contents(dirname(__DIR__) . '/' . $rel);

            foreach (self::JEJAK_RUSAK as $nama => $pola) {
                $ada = strncmp($pola, '/', 1) === 0
                    ? preg_match($pola, $isi) === 1
                    : strpos($isi, $pola) !== false;

                if ($ada) {
                    $kotor[] = $rel . ' (' . $nama . ')';
                    break;
                }
            }
        }

        self::assertSame([], $kotor, "ganda-encode:\n  " . implode("\n  ", $kotor));
    }

    /**
     * Berkas yang bukan UTF-8 sah akan patah di tempat yang tidak terduga —
     * `mb_substr`, `json_encode`, dan keluaran halaman semuanya menganggapnya
     * UTF-8.
     */
    public function testEveryShippedFileIsValidUtf8(): void
    {
        $kotor = [];

        foreach ($this->berkasTerkirim() as $rel) {
            $isi = (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
            if (!mb_check_encoding($isi, 'UTF-8')) {
                $kotor[] = $rel;
            }
        }

        self::assertSame([], $kotor, 'bukan UTF-8 sah: ' . implode(', ', $kotor));
    }
}
