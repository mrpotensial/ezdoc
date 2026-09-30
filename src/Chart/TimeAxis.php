<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

use DateTimeImmutable;
use Ezdoc\Exceptions\ValidationException;

/**
 * Sumbu waktu: satu kolom sama dengan satu jam, satu hari, atau satu bulan.
 *
 * ## Bulan tidak boleh dihitung dari detik
 *
 * Ini jebakan yang paling mungkin lolos sampai produksi, karena salahnya
 * kecil dan tidak menghasilkan galat apa pun — cuma titik yang mendarat di
 * kolom sebelah.
 *
 *   jam   : selisih detik dibagi 3600. Benar.
 *   hari  : selisih detik dibagi 86400 **salah di zona ber-DST**. Indonesia
 *           tidak ber-DST, tapi library ini tidak boleh menganggapnya.
 *   bulan : selisih detik dibagi 2592000 **selalu salah** — Februari 28 hari,
 *           Januari 31. Antara 1 Feb dan 1 Mar ada 28 hari; antara 1 Jan dan
 *           1 Feb ada 31. Keduanya satu bulan.
 *
 * Karena itu hari dihitung dari selisih kalender ditambah pecahan hari, dan
 * bulan dari selisih tahun-bulan ditambah kedudukan di dalam bulannya.
 *
 * ## Awal sumbu bulan dibulatkan ke tanggal 1 — dan itu syarat
 *
 * `(new DateTimeImmutable('2026-01-31'))->modify('+1 month')` menghasilkan
 * **3 Maret**, bukan Februari: PHP menambah bulannya lalu meluapkan
 * tanggalnya. Papan yang dimulai 31 Januari lalu melewatkan Februari sama
 * sekali.
 *
 * Penangkalnya bukan aritmetika sendiri di tempat label, melainkan
 * membulatkan awal sumbunya sekali di {@see self::withOrigin()}. Sesudah itu
 * "tanggal 1 + n bulan" tidak pernah meluap, dan cuma ada satu tempat yang
 * perlu benar.
 *
 * ## Nama bulan tidak diterjemahkan
 *
 * Label bawaannya angka. Nama bulan menuntut locale, dan locale berarti dua
 * server menghasilkan PDF berbeda untuk dokumen yang sama — yang meruntuhkan
 * satu-satunya alasan tanda tangan pada dokumen bergrafik berarti. Yang mau
 * "Sep 2026" menuliskannya sendiri lewat `label_format`.
 *
 * PHP 7.4+ compatible.
 */
final class TimeAxis implements XAxis
{
    /** Satuan satu kolom. */
    public const UNITS = ['hour', 'day', 'month'];

    /** Satu-satunya bentuk waktu yang diterima dan ditulis. */
    public const FORMAT = 'Y-m-d H:i';

    /** Tulisan bawaan di bawah kolom, per satuan. */
    private const DEFAULT_LABELS = [
        'hour' => 'H:i',
        'day' => 'd/m',
        'month' => 'm/Y',
    ];

    /** @var string */
    private $unit;

    /** @var string */
    private $labelFormat;

    /** @var DateTimeImmutable|null */
    private $origin;

    private function __construct(string $unit, string $labelFormat, ?DateTimeImmutable $origin)
    {
        $this->unit = $unit;
        $this->labelFormat = $labelFormat;
        $this->origin = $origin;
    }

    /**
     * @throws ValidationException kalau satuannya tidak dikenal
     */
    public static function create(string $unit = 'hour', string $labelFormat = ''): self
    {
        if (!in_array($unit, self::UNITS, true)) {
            throw new ValidationException(
                "Satuan sumbu '{$unit}' tidak dikenal (pilihannya " . implode(', ', self::UNITS) . ').'
            );
        }

        return new self($unit, $labelFormat, null);
    }

    public function kind(): string
    {
        return 'time';
    }

    public function unit(): string
    {
        return $this->unit;
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
    public function normalise(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace('T', ' ', $raw);

        foreach ([self::FORMAT, 'Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($time !== false && $time->format($format) === $raw) {
                return $time->format(self::FORMAT);
            }
        }

        return null;
    }

    public function compare(string $a, string $b): int
    {
        // Bentuk bakunya "Y-m-d H:i": urutan abjadnya sama dengan urutan
        // waktunya, jadi tidak perlu diurai dulu.
        return strcmp($a, $b);
    }

    public function column(string $x): ?float
    {
        if ($this->origin === null) {
            return null;
        }
        $time = self::toTime($this->normalise($x));
        if ($time === null) {
            return null;
        }

        if ($this->unit === 'hour') {
            return ($time->getTimestamp() - $this->origin->getTimestamp()) / 3600.0;
        }

        if ($this->unit === 'day') {
            $hari = (int) $this->origin->setTime(0, 0)->diff($time->setTime(0, 0))->format('%r%a');

            return $hari + ((self::secondOfDay($time) - self::secondOfDay($this->origin)) / 86400.0);
        }

        $bulan = ((int) $time->format('Y') - (int) $this->origin->format('Y')) * 12
            + ((int) $time->format('n') - (int) $this->origin->format('n'));

        return $bulan + (self::placeInMonth($time) - self::placeInMonth($this->origin));
    }

    public function hasOrigin(): bool
    {
        return $this->origin !== null;
    }

    /**
     * Awal sumbu hari dan bulan dibulatkan ke awal hari / awal bulannya.
     *
     * Tanpa itu, papan bulanan yang dimulai 31 Januari membuat "satu bulan
     * berikutnya" tidak punya jawaban: 31 Februari tidak ada. Kolom ke-1 lalu
     * berisi sesuatu antara Februari dan Maret, sementara labelnya tetap
     * berbunyi "02/2026" — angka dan tulisan menunjuk ke bulan yang berbeda.
     *
     * Sumbu bulan berbicara tentang bulan, bukan tentang "sebulan sesudah
     * tanggal 31". Begitu pula sumbu hari berbicara tentang hari.
     *
     * Sumbu **jam** tidak dibulatkan: papan yang dimulai 08.30 sudah dipakai
     * dan kolomnya memang 09.30, 10.30, dan seterusnya.
     */
    public function withOrigin(?string $origin): XAxis
    {
        $time = $origin === null ? null : self::toTime($this->normalise($origin));

        if ($time !== null && $this->unit === 'day') {
            $time = $time->setTime(0, 0);
        }
        if ($time !== null && $this->unit === 'month') {
            $time = $time->setDate((int) $time->format('Y'), (int) $time->format('n'), 1)->setTime(0, 0);
        }

        return new self($this->unit, $this->labelFormat, $time);
    }

    public function origin(): ?string
    {
        return $this->origin === null ? null : $this->origin->format(self::FORMAT);
    }

    public function label(int $column): string
    {
        if ($this->origin === null) {
            return '';
        }

        $format = $this->labelFormat !== '' ? $this->labelFormat : self::DEFAULT_LABELS[$this->unit];

        // `modify('+n month')` aman DI SINI, dan cuma di sini: awal sumbu
        // bulan selalu sudah dibulatkan ke tanggal 1 oleh {@see
        // self::withOrigin()}, dan 1 + n bulan tidak pernah meluap. Pada
        // tanggal 31 ia akan meluap ke bulan berikutnya — itu sebabnya
        // pembulatan awalnya bukan kerapian melainkan syarat.
        return $this->origin->modify('+' . $column . ' ' . $this->unit)->format($format);
    }

    /**
     * @return array<string,mixed>
     */
    public function export(): array
    {
        return [
            'kind' => 'time',
            'unit' => $this->unit,
            'origin' => $this->origin(),
        ];
    }

    // ── Hitungan kalender ────────────────────────────────────────────────

    private static function toTime(?string $baku): ?DateTimeImmutable
    {
        if ($baku === null) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $baku);

        return $time === false ? null : $time;
    }

    /** Detik sejak tengah malam — bukan dari timestamp, supaya DST tidak ikut. */
    private static function secondOfDay(DateTimeImmutable $t): int
    {
        return ((int) $t->format('G') * 3600) + ((int) $t->format('i') * 60) + (int) $t->format('s');
    }

    /**
     * Kedudukan sebuah saat di dalam bulannya, 0 sampai hampir 1.
     *
     * Dibagi jumlah hari bulan itu sendiri, bukan 30: tanggal 15 Februari ada
     * di tengah bulannya, tanggal 15 Januari belum.
     */
    private static function placeInMonth(DateTimeImmutable $t): float
    {
        $hariDalamBulan = (int) $t->format('t');

        return (((int) $t->format('j') - 1) + (self::secondOfDay($t) / 86400.0)) / $hariDalamBulan;
    }
}
