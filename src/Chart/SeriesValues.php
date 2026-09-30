<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

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
 * Nilai `y` boleh angka (panel garis) atau teks pendek (panel simbol).
 *
 * ## Rentang: kunci `x2` yang boleh tidak ada
 *
 * Jadwal obat dan waktu infus bukan titik. Bentuk penyimpanan kedua khusus
 * untuk itu ditolak — dua bentuk berarti dua pembaca, dua penulis, dan dua
 * jalur pembekuan yang bisa berpisah diam-diam. Yang dipilih satu kunci
 * tambahan:
 *
 *   tidak ada  → titik, persis seperti sebelumnya
 *   `false`    → rentang TERBUKA: sudah mulai, belum selesai
 *   waktu sah  → rentang tertutup
 *   selain itu → seluruh rentangnya dibuang
 *
 * Yang terakhir dibuang, bukan diturunkan jadi titik: rentang yang berakhir
 * sebelum mulai adalah salah isi, dan menggambarkannya sebagai titik
 * menyembunyikan kesalahan itu.
 *
 * `false` dipilih, bukan "`x2` dikosongkan pada panel timeline". Kalau artinya
 * bergantung jenis panel, nilai yang sama berubah arti begitu spesifikasinya
 * diubah — dan spesifikasi bisa diubah sesudah dokumennya ditandatangani.
 * Yang tersimpan harus menjelaskan dirinya sendiri.
 *
 * Infus yang masih jalan **tidak punya** jam selesai. Tanpa keadaan terbuka,
 * yang mengisi cuma punya dua pilihan: tidak menulisnya, atau mengarang jam
 * selesai — dan yang kedua persis kegagalan yang dijaga seluruh berkas ini.
 *
 * ## `x` tidak lagi selalu waktu
 *
 * Sampai 30 Sep 2026 kelas ini memanggil `normaliseTime()` langsung, dan
 * dengan itu memutuskan sendiri bahwa tiap nilai `x` adalah waktu. Keputusan
 * itu sekarang milik {@see XAxis}, yang diserahkan pemanggil. Alasannya bukan
 * kerapian: selama anggapan itu tertanam **di sini**, grafik sebar menuntut
 * pembongkaran penyimpanan nilai — dan penyimpanan nilai inilah yang ikut
 * di-hash tanda tangan. Lihat `docs/260930-grafik-jenis-baru.md` §5.
 *
 * PHP 7.4+ compatible.
 */
final class SeriesValues
{
    /** Satu-satunya bentuk waktu yang diterima dan ditulis. */
    public const TIME_FORMAT = 'Y-m-d H:i';

    /** Teks `y` lebih panjang dari ini bukan simbol, melainkan salah isi. */
    public const MAX_LABEL = 16;

    /**
     * Batas yang sama untuk label RENTANG, dan sengaja jauh lebih longgar.
     *
     * Enam belas huruf masuk akal untuk baris sel — "U", "J", "+", "20/30".
     * Untuk rentang, labelnya nama obat: "Ceftriaxone 1 gram" sudah delapan
     * belas. Batas yang ketat di situ membuang catatan yang benar, dan
     * membuangnya diam-diam.
     */
    public const MAX_RANGE_LABEL = 48;

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
     * @param XAxis|null $axis sumbu yang membakukan `x`; null berarti sumbu jam
     * @return array<int,array{x:string,y:float|int|string}>
     */
    public static function parse($raw, ?XAxis $axis = null): array
    {
        $axis = $axis ?? TimeAxis::create();

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $byKey = [];
        foreach ($raw as $point) {
            if (!is_array($point) || !isset($point['x'])) {
                continue;
            }

            $x = $axis->normalise((string) $point['x']);
            if ($x === null) {
                continue;
            }

            $punyaX2 = array_key_exists('x2', $point);
            $x2 = null;
            if ($punyaX2) {
                if ($point['x2'] === false) {
                    $x2 = false;
                } else {
                    $x2 = is_scalar($point['x2']) ? $axis->normalise((string) $point['x2']) : null;
                    if ($x2 === null || $axis->compare($x2, $x) <= 0) {
                        // Rentang yang berakhir sebelum mulai dibuang utuh,
                        // bukan diturunkan jadi titik.
                        continue;
                    }
                }
            }

            $value = self::normaliseValue(
                $point['y'] ?? null,
                $punyaX2 ? self::MAX_RANGE_LABEL : self::MAX_LABEL
            );
            if ($value === null) {
                // Rentang boleh tanpa label; titik tanpa nilai tidak
                // menggambarkan pengukuran apa pun.
                if (!$punyaX2) {
                    continue;
                }
                $value = '';
            }

            // Yang terakhir menang — lihat keputusan (3) di docblock kelas.
            // Kuncinya `x` dan `x2` sekaligus: dua rentang yang mulai bersamaan
            // tapi berakhir berbeda adalah dua rentang.
            $kunci = $punyaX2 ? ($x . '|' . ($x2 === false ? '~' : $x2)) : $x;
            $byKey[$kunci] = $punyaX2
                ? ['x' => $x, 'x2' => $x2, 'y' => $value]
                : ['x' => $x, 'y' => $value];
        }

        // Urutannya ditentukan sumbu, bukan abjad: pada sumbu berangka,
        // "10" datang sesudah "9", sedangkan abjad menaruhnya sebelum.
        // Rentang terbuka diurutkan paling belakang di antara yang sama
        // awalnya — ia yang paling panjang.
        usort($byKey, static function (array $a, array $b) use ($axis): int {
            $urut = $axis->compare($a['x'], $b['x']);
            if ($urut !== 0) {
                return $urut;
            }

            return self::rankEnd($a) <=> self::rankEnd($b);
        });

        return array_values($byKey);
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
    public static function toJson($raw, ?XAxis $axis = null): string
    {
        $json = json_encode(self::parse($raw, $axis), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '[]' : $json;
    }

    /**
     * Titik paling awal di antara beberapa deret, untuk menebak awal sumbu.
     *
     * @param array<int,array<int,array{x:string,y:float|int|string}>> $seriesList
     */
    public static function earliest(array $seriesList, ?XAxis $axis = null): ?string
    {
        $axis = $axis ?? TimeAxis::create();

        $earliest = null;
        foreach ($seriesList as $points) {
            if ($points === []) {
                continue;
            }
            $first = $points[0]['x'];
            if ($earliest === null || $axis->compare($first, $earliest) < 0) {
                $earliest = $first;
            }
        }

        return $earliest;
    }

    /** Apakah sebuah titik hasil {@see self::parse()} adalah rentang. */
    public static function isRange(array $point): bool
    {
        return array_key_exists('x2', $point);
    }

    /**
     * Cuma titik — rentang disaring keluar.
     *
     * Dipakai panel yang menggambar pengukuran. Rentang bukan pengukuran, dan
     * menggambarnya sebagai titik di jam mulainya mengarang bacaan yang tidak
     * pernah diambil.
     *
     * @param array<int,array<string,mixed>> $points
     * @return array<int,array<string,mixed>>
     */
    public static function onlyPoints(array $points): array
    {
        return array_values(array_filter($points, static function (array $p): bool {
            return !self::isRange($p);
        }));
    }

    /**
     * Cuma rentang.
     *
     * @param array<int,array<string,mixed>> $points
     * @return array<int,array<string,mixed>>
     */
    public static function onlyRanges(array $points): array
    {
        return array_values(array_filter($points, static function (array $p): bool {
            return self::isRange($p);
        }));
    }

    /** Urutan ujung sebuah titik: titik dulu, lalu rentang, lalu yang terbuka. */
    private static function rankEnd(array $point): int
    {
        if (!self::isRange($point)) {
            return 0;
        }

        return $point['x2'] === false ? 2 : 1;
    }

    /**
     * Waktu yang bisa dibaca, dalam satu bentuk baku — atau null.
     *
     * @deprecated Pakai {@see TimeAxis::normalise()}. Dipertahankan sebagai
     *             cangkang karena berkas diunggah satu per satu: kalau yang
     *             memanggilnya mendarat lebih dulu daripada yang memuatnya,
     *             permintaan yang lewat di sela itu mati.
     */
    public static function normaliseTime(string $raw): ?string
    {
        return TimeAxis::create()->normalise($raw);
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
    public static function normaliseValue($raw, ?int $maxLabel = null)
    {
        $maxLabel = $maxLabel ?? self::MAX_LABEL;

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
            if ($label === '' || mb_strlen($label) > $maxLabel) {
                return null;
            }

            return $label;
        }

        return null;
    }
}
