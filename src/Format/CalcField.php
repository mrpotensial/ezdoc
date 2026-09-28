<?php

declare(strict_types=1);

namespace Ezdoc\Format;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Table\FormulaEvaluator;

/**
 * Field yang nilainya dihitung dari field lain.
 *
 * Dipakai untuk skor dan jumlah: "Skor Bishop" yang menjumlahkan lima
 * butirnya, "total biaya" yang menjumlahkan beberapa isian, "selisih" antara
 * dua tanggal ukur, dan seterusnya. Sebelumnya penjumlahan hanya ada untuk
 * tabel yang datang dari kueri database (`{{tabledb.ns.sum:…}}`) — isian
 * formulir tidak punya apa pun, jadi totalnya diketik ulang orang.
 *
 * ## Bahasanya sama dengan rumus tabel
 *
 * Sengaja memakai {@see FormulaEvaluator} yang sudah ada, bukan bahasa baru:
 * `+ - * / ( )`, angka, dan nama field. Satu bahasa rumus untuk seluruh
 * produk — yang sudah paham rumus tabel tidak perlu belajar lagi, dan
 * penjaganya (tanpa `eval()`, tanpa pemanggilan fungsi) sudah teruji.
 *
 *   data-formula="bishop_pembukaan + bishop_pendataran + bishop_penurunan"
 *   data-formula="(berat_lahir / 1000) * 2"
 *
 * ## Nilai yang tidak terisi dihitung nol
 *
 * Formulir skor diisi bertahap. Kalau satu butir kosong membuat totalnya ikut
 * kosong, angkanya baru muncul di isian terakhir — dan sampai saat itu orang
 * tidak tahu apakah rumusnya bekerja. Jadi yang kosong dan yang bukan angka
 * dihitung nol.
 *
 * Kecualinya satu: kalau SEMUA field yang dirujuk kosong, hasilnya kosong juga.
 * Formulir yang belum disentuh siapa pun tidak boleh tercetak dengan "0" di
 * kolom skor, karena nol itu keterangan medis yang tidak pernah ditulis orang.
 *
 * PHP 7.4+ compatible.
 */
final class CalcField
{
    /**
     * Nama field yang dirujuk sebuah rumus, sesuai urutan kemunculan.
     *
     * Dipakai dua tempat: penjaga "semua kosong" di bawah, dan sisi peramban
     * yang perlu tahu isian mana yang harus diawasi perubahannya.
     *
     * @return array<int,string>
     */
    public static function rujukan(string $formula): array
    {
        if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $formula, $m) !== false) {
            return array_values(array_unique($m[0]));
        }

        return [];
    }

    /**
     * Isi nilai semua field hitung yang ada di sebuah HTML ke dalam peta nilai.
     *
     * Dipanggil SEBELUM blok bersyarat dievaluasi dan sebelum field dirender.
     * Alasannya satu: syarat seperti `total_bishop<6` membaca peta nilai, dan
     * kalau nilai hitungnya belum ada di sana, syaratnya membandingkan dengan
     * kosong — yang dibaca nol, jadi kalimat "skor di bawah 6" muncul pada
     * dokumen yang skornya 8.
     *
     * Dua putaran: field hitung boleh merujuk field hitung lain. Lebih dari itu
     * tidak dikejar — rantai sepanjang itu lebih baik ditulis satu rumus.
     *
     * @param array<string,mixed> $nilai
     * @return array<string,mixed> peta nilai yang sudah lengkap
     */
    public static function lengkapiNilai(string $html, array $nilai): array
    {
        if (strpos($html, 'data-formula=') === false) {
            return $nilai;
        }

        $pola = '/<span[^>]*class=["\']field-placeholder[^"\']*["\']([^>]*)>\{\{([^}:]+)(?::text)?\}\}<\/span>/';
        if (!preg_match_all($pola, $html, $cocok, PREG_SET_ORDER)) {
            return $nilai;
        }

        for ($putaran = 0; $putaran < 2; $putaran++) {
            foreach ($cocok as $m) {
                $atribut = $m[1];
                $nama = $m[2];
                if (strpos($atribut, 'data-type="calc"') === false && strpos($atribut, "data-type='calc'") === false) {
                    continue;
                }
                $rumus = '';
                if (preg_match('/data-formula=["\']([^"\']*)["\']/', $atribut, $fm)) {
                    $rumus = html_entity_decode($fm[1], ENT_QUOTES, 'UTF-8');
                }
                $desimal = 0;
                if (preg_match('/data-decimals=["\'](\d+)["\']/', $atribut, $dm)) {
                    $desimal = (int) $dm[1];
                }
                $hasil = self::hitung($rumus, $nilai, $desimal);
                $nilai[$nama] = $hasil === null ? '' : $hasil;
            }
        }

        return $nilai;
    }

    /**
     * Hitung nilainya. Mengembalikan null kalau tidak ada yang bisa dihitung —
     * pemanggil yang memutuskan apa artinya di layar (biasanya: kosong).
     *
     * @param array<string,mixed> $nilai peta nama field → nilainya
     */
    public static function hitung(string $formula, array $nilai, int $desimal = 0): ?string
    {
        $formula = trim($formula);
        if ($formula === '') {
            return null;
        }

        $rujukan = self::rujukan($formula);
        if ($rujukan === []) {
            return null;
        }

        // Semua kosong = belum ada yang mengisi apa pun.
        $adaIsi = false;
        $konteks = [];
        foreach ($rujukan as $nama) {
            $mentah = $nilai[$nama] ?? '';
            if (is_bool($mentah)) {
                $mentah = $mentah ? '1' : '0';
            }
            $mentah = is_scalar($mentah) ? trim((string) $mentah) : '';

            // Kotak centang menyimpan '1' atau kosong; 'Ya'/'true' datang dari
            // jalur lama. Keduanya berarti satu.
            if ($mentah !== '' && !is_numeric($mentah)) {
                $mentah = in_array(strtolower($mentah), ['ya', 'yes', 'true', 'on'], true) ? '1' : '';
            }

            if ($mentah !== '') {
                $adaIsi = true;
                $konteks[$nama] = $mentah + 0;
            } else {
                $konteks[$nama] = 0;
            }
        }

        if (!$adaIsi) {
            return null;
        }

        try {
            // Kurung kurawal dilepas dulu. Penulisnya boleh memakai `{a} + {b}`
            // seperti di rumus tabel, sedangkan evaluateBare() membungkus SETIAP
            // nama dengan kurawal — yang sudah berkurawal jadi berkurawal dua,
            // dan itu bukan nama field mana pun.
            $hasil = FormulaEvaluator::evaluateBare(str_replace(['{', '}'], '', $formula), $konteks);
        } catch (ValidationException $e) {
            // Rumus yang salah tulis. Di layar ia tampil kosong, bukan pesan
            // galat: dokumen yang dicetak tidak boleh memuat keluhan program.
            // Yang menulis rumusnya melihatnya di designer.
            return null;
        }

        if ($hasil === null) {
            return null;
        }

        $desimal = max(0, min(6, $desimal));

        return number_format($hasil, $desimal, '.', '');
    }
}
