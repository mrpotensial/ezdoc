<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

use DateTimeImmutable;

/**
 * Angka satu deret: daftar titik (waktu, nilai) yang tersimpan di dokumen.
 *
 * Bentuknya satu-satunya:
 *
 *   [{"x":"2026-09-29 08:00","y":4},{"x":"2026-09-29 12:00","y":5}]
 *
 * Empat keputusan di dalamnya, masing-masing menutup satu golongan bug:
 *
 * 1. **`x` menyimpan tanggal sekaligus jam.** Pemantauan bisa melewati tengah
 *    malam. Menyimpan "08:00" saja memaksa setiap pembaca menebak harinya, dan
 *    tebakan itu biasanya berbentuk `if (jam < jamMulai) jam += 24` — benar
 *    hanya selama kejadiannya kurang dari 24 jam, dan hanya kalau semua yang
 *    membacanya ingat aturannya. Menyimpan tanggalnya menghapus seluruh
 *    golongan itu. Di layar tetap boleh tampil "08:00".
 * 2. **`x` absolut, bukan jarak dari jam mulai.** Kalau jam mulainya dikoreksi
 *    belakangan, yang bergeser sumbunya — titik-titiknya tetap di waktunya
 *    sendiri. Menyimpan relatif berarti mengoreksi jam mulai diam-diam
 *    memindahkan setiap pengukuran yang pernah dicatat.
 * 3. **Urut menurut `x`, tanpa `x` kembar.** Satu deret = satu nilai per waktu.
 *    Yang kembar: yang **terakhir** menang, karena itu yang paling belakangan
 *    diketik orang.
 * 4. **Keluarannya baku.** Nilai ini ikut di-hash tanda tangan. Dua dokumen
 *    dengan isi sama harus menghasilkan JSON yang sama persis, apa pun urutan
 *    pengetikannya — kalau tidak, "dokumen ini bisa dibangun ulang dari
 *    nilainya" tidak bisa dibuktikan.
 *
 * Nilai `y` boleh angka (panel garis) atau teks pendek (panel simbol, nanti).
 *
 * PHP 7.4+ compatible.
 */
final class SeriesValues
{
    /** Satu-satunya bentuk waktu yang diterima dan ditulis. */
    public const TIME_FORMAT = 'Y-m-d H:i';

    /** Teks `y` lebih panjang dari ini bukan simbol, melainkan salah isi. */
    public const MAX_LABEL = 16;

    private function __construct()
    {
    }

    /**
     * Baca nilai tersimpan menjadi daftar titik yang sudah rapi.
     *
     * Menerima JSON maupun array — halaman dokumen menyerahkan yang sudah
     * ter-decode, penyimpanan menyerahkan teks. Yang tidak berbentuk titik
     * dibuang diam-diam, bukan dilempar sebagai galat: satu baris rusak di
     * tengah tidak boleh membuat seluruh lembar berhenti tergambar.
     *
     * @param mixed $raw
     * @return array<int,array{x:string,y:float|int|string}>
     */
    public static function parse($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $byTime = [];
        foreach ($raw as $point) {
            if (!is_array($point) || !isset($point['x'])) {
                continue;
            }

            $time = self::normaliseTime((string) $point['x']);
            if ($time === null) {
                continue;
            }

            $value = self::normaliseValue($point['y'] ?? null);
            if ($value === null) {
                continue;
            }

            // Yang terakhir menang — lihat keputusan (3) di docblock kelas.
            $byTime[$time] = $value;
        }

        ksort($byTime, SORT_STRING);

        $points = [];
        foreach ($byTime as $time => $value) {
            $points[] = ['x' => (string) $time, 'y' => $value];
        }

        return $points;
    }

    /**
     * Tulis daftar titik ke bentuk bakunya.
     *
     * Lewat {@see self::parse()} lebih dulu, selalu. Menulis apa adanya berarti
     * urutan pengetikan ikut tersimpan, dan dua dokumen beridentik isi
     * menghasilkan hash berbeda.
     *
     * @param mixed $raw
     */
    public static function toJson($raw): string
    {
        $json = json_encode(self::parse($raw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '[]' : $json;
    }

    /**
     * Titik paling awal di antara beberapa deret, untuk menebak awal sumbu.
     *
     * @param array<int,array<int,array{x:string,y:float|int|string}>> $seriesList
     */
    public static function earliest(array $seriesList): ?string
    {
        $earliest = null;
        foreach ($seriesList as $points) {
            if ($points === []) {
                continue;
            }
            $first = $points[0]['x'];
            if ($earliest === null || strcmp($first, $earliest) < 0) {
                $earliest = $first;
            }
        }

        return $earliest;
    }

    /**
     * Waktu yang bisa dibaca, dalam satu bentuk baku — atau null.
     *
     * Diterima juga bentuk yang keluar dari isian `datetime-local` peramban
     * ("2026-09-29T08:00") dan yang membawa detik. Ketiganya ditulis kembali
     * tanpa detik: detik tidak pernah berarti di lembar pemantauan, dan
     * membiarkannya membuat dua catatan pada menit yang sama tidak saling
     * menimpa — padahal orangnya bermaksud mengoreksi.
     */
    public static function normaliseTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace('T', ' ', $raw);

        foreach ([self::TIME_FORMAT, 'Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($time !== false && $time->format($format) === $raw) {
                return $time->format(self::TIME_FORMAT);
            }
        }

        return null;
    }

    /**
     * Nilai yang boleh masuk dokumen — atau null.
     *
     * Angka disimpan sebagai angka. Pecahan dibulatkan dua angka di belakang
     * koma, dan yang bulat disimpan sebagai bilangan bulat: `4.0` dan `4`
     * menggambarkan pengukuran yang sama, dan dua bentuk untuk satu pengukuran
     * berarti dua hash untuk satu dokumen.
     *
     * @param mixed $raw
     * @return float|int|string|null
     */
    public static function normaliseValue($raw)
    {
        if (is_bool($raw) || $raw === null) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_float($raw) || (is_string($raw) && is_numeric(trim($raw)))) {
            $value = round((float) $raw, 2);
            if (!is_finite($value)) {
                return null;
            }

            return $value == (int) $value ? (int) $value : $value;
        }

        if (is_string($raw)) {
            $label = trim($raw);
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
                return null;
            }

            return $label;
        }

        return null;
    }
}
