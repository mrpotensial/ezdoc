<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

use DateTimeImmutable;

/**
 * Pengubah antara angka dan tempatnya di gambar.
 *
 * Satu-satunya tempat perhitungan itu hidup. Perenderlah yang memakainya untuk
 * menaruh titik, dan lapisan pegangan di peramban memakai {@see self::export()}
 * untuk mengubah klik kembali menjadi angka. Dua perhitungan yang terpisah akan
 * berbeda beberapa piksel, dan selisih itu tidak pernah terbaca sebagai bug —
 * cuma sebagai "grafiknya agak meleset".
 *
 * ## Titik di luar sumbu tidak digambar
 *
 * Bukan digeser ke tepi. Nilai di luar jangkauan biasanya salah ketik — DJJ
 * 1440, bukan 144. Kalau digeser ke tepi, ia mendarat di garis 180 dan terbaca
 * sebagai pengukuran yang sah; dokumen yang ditandatangani lalu memuat angka
 * yang tidak pernah diukur siapa pun. Kalau tidak digambar, yang mengisi
 * melihat titiknya tidak muncul dan membetulkannya.
 *
 * Garis penghubungnya ikut terputus di situ, bukan melompati titik yang hilang.
 *
 * PHP 7.4+ compatible.
 */
final class Scale
{
    /** @var ChartSpec */
    private $spec;

    /** @var DateTimeImmutable|null */
    private $start;

    /** @var array<int,float> Puncak tiap panel, searah urutannya di spesifikasi. */
    private $tops = [];

    private function __construct(ChartSpec $spec, ?DateTimeImmutable $start)
    {
        $this->spec = $spec;
        $this->start = $start;

        $top = Geometry::TOP;
        foreach ($spec->panels() as $i => $panel) {
            $this->tops[$i] = $top;
            $top += (float) $panel['height'] + Geometry::PANEL_GAP;
        }
    }

    /**
     * @param string|null $start waktu awal sumbu, bentuk {@see SeriesValues::TIME_FORMAT}
     */
    public static function create(ChartSpec $spec, ?string $start): self
    {
        $time = null;
        if ($start !== null) {
            $normalised = SeriesValues::normaliseTime($start);
            if ($normalised !== null) {
                $parsed = DateTimeImmutable::createFromFormat('!' . SeriesValues::TIME_FORMAT, $normalised);
                $time = $parsed === false ? null : $parsed;
            }
        }

        return new self($spec, $time);
    }

    public function hasStart(): bool
    {
        return $this->start !== null;
    }

    public function top(int $panelIndex): float
    {
        return $this->tops[$panelIndex] ?? Geometry::TOP;
    }

    /** Tempat mendatar sebuah jam ke-n sejak awal sumbu. */
    public function xFromHour(float $hour): float
    {
        return Geometry::LEFT + ($hour * $this->spec->columnWidth());
    }

    /**
     * Tempat mendatar sebuah waktu — null kalau di luar rentang sumbu.
     */
    public function x(string $time): ?float
    {
        $hour = $this->hour($time);
        if ($hour === null || $hour < 0.0 || $hour > (float) $this->spec->hours()) {
            return null;
        }

        return $this->xFromHour($hour);
    }

    /**
     * Jarak sebuah waktu dari awal sumbu, dalam jam — null kalau tak terbaca.
     */
    public function hour(string $time): ?float
    {
        if ($this->start === null) {
            return null;
        }
        $normalised = SeriesValues::normaliseTime($time);
        if ($normalised === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . SeriesValues::TIME_FORMAT, $normalised);
        if ($parsed === false) {
            return null;
        }

        return ($parsed->getTimestamp() - $this->start->getTimestamp()) / 3600.0;
    }

    /**
     * Tempat tegak sebuah nilai di sebuah panel — null kalau di luar sumbu Y.
     *
     * @param float|int|string $value
     */
    public function y(int $panelIndex, $value): ?float
    {
        $panels = $this->spec->panels();
        if (!isset($panels[$panelIndex]) || !is_numeric($value)) {
            return null;
        }

        $panel = $panels[$panelIndex];
        $min = (float) $panel['y_min'];
        $max = (float) $panel['y_max'];
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            return null;
        }

        $height = (float) $panel['height'];

        return $this->tops[$panelIndex] + ((($max - $number) / ($max - $min)) * $height);
    }

    /**
     * Label jam pada kolom ke-n. Kosong kalau awal sumbunya belum diketahui.
     *
     * Kosong, bukan jam hari ini. Perender harus menghasilkan gambar yang sama
     * untuk isi yang sama, kapan pun ia dipanggil; membaca jam dinding membuat
     * dokumen yang sama menghasilkan dua gambar berbeda, dan tanda tangannya
     * tidak lagi bisa dibuktikan ulang.
     */
    public function hourLabel(int $column): string
    {
        if ($this->start === null) {
            return '';
        }

        return $this->start->modify('+' . $column . ' hour')->format('H:i');
    }

    /**
     * Angka yang dibutuhkan lapisan pegangan di peramban.
     *
     * Sengaja mentah — kiri, lebar per jam, puncak dan tinggi tiap panel —
     * bukan daftar koordinat jadi. Yang di peramban perlu mengubah ke DUA arah:
     * angka ke tempat saat menggambar pegangan, dan tempat ke angka saat orang
     * mengklik. Daftar koordinat jadi cuma melayani arah pertama.
     *
     * @return array<string,mixed>
     */
    public function export(): array
    {
        $panels = [];
        foreach ($this->spec->panels() as $i => $panel) {
            $panels[] = [
                'top' => $this->tops[$i],
                'height' => (float) $panel['height'],
                'y_min' => (float) $panel['y_min'],
                'y_max' => (float) $panel['y_max'],
                'y_step' => (float) $panel['y_step'],
            ];
        }

        return [
            'left' => Geometry::LEFT,
            'column_width' => $this->spec->columnWidth(),
            'hours' => $this->spec->hours(),
            'start' => $this->start === null ? null : $this->start->format(SeriesValues::TIME_FORMAT),
            'width' => $this->spec->width(),
            'height' => $this->spec->height(),
            'panels' => $panels,
        ];
    }
}
