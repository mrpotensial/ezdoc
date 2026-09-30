<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

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

    /** @var XAxis Sumbu mendatar, sudah berawal kalau awalnya diketahui. */
    private $axis;

    /** @var array<int,float> Puncak tiap panel, searah urutannya di spesifikasi. */
    private $tops = [];

    private function __construct(ChartSpec $spec, XAxis $axis)
    {
        $this->spec = $spec;
        $this->axis = $axis;

        $top = Geometry::TOP;
        foreach ($spec->panels() as $i => $panel) {
            if ($i > 0) {
                $top += $spec->gapBefore($i);
            }
            $this->tops[$i] = $top;
            $top += (float) $panel['height'];
        }
    }

    /**
     * @param string|null $start awal sumbu, dalam bentuk yang dimengerti sumbunya
     */
    public static function create(ChartSpec $spec, ?string $start): self
    {
        return new self($spec, $spec->axis()->withOrigin($start));
    }

    /** Sumbu mendatar papan ini, beserta awalnya. */
    public function axis(): XAxis
    {
        return $this->axis;
    }

    public function hasStart(): bool
    {
        return $this->axis->hasOrigin();
    }

    public function top(int $panelIndex): float
    {
        return $this->tops[$panelIndex] ?? Geometry::TOP;
    }

    /**
     * Tempat mendatar kolom ke-n sejak awal sumbu.
     *
     * Perkalian piksel tetap di sini, bukan di sumbu: satu kolom selalu
     * selebar `column_width`, apa pun artinya kolom itu. Sumbu cuma menjawab
     * "nilai ini ada di kolom keberapa".
     */
    public function xFromColumn(float $column): float
    {
        return $this->spec->left() + ($column * $this->spec->columnWidth());
    }

    /** @deprecated Pakai {@see self::xFromColumn()} — namanya dari masa satu kolom selalu satu jam. */
    public function xFromHour(float $hour): float
    {
        return $this->xFromColumn($hour);
    }

    /**
     * Tempat mendatar sebuah waktu — null kalau di luar rentang sumbu.
     */
    public function x(string $time): ?float
    {
        $column = $this->column($time);
        if ($column === null || $column < 0.0 || $column > (float) $this->spec->columns()) {
            return null;
        }

        return $this->xFromColumn($column);
    }

    /**
     * Jarak sebuah nilai X dari awal sumbu, dalam kolom — null kalau tak terbaca.
     */
    public function column(string $x): ?float
    {
        return $this->axis->column($x);
    }

    /** @deprecated Pakai {@see self::column()} — namanya dari masa satu kolom selalu satu jam. */
    public function hour(string $time): ?float
    {
        return $this->column($time);
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
     * Tempat tegak dasar bidang terisi di sebuah panel.
     *
     * Nol kalau nol ada di dalam jangkauan sumbunya; kalau tidak, tepi yang
     * paling dekat dengan nol. Bidang cairan masuk terisi dari 0 ke atas;
     * bidang suhu 35–40 terisi dari 35, bukan dari nol yang letaknya jauh di
     * luar panel.
     */
    public function baselineY(int $panelIndex): ?float
    {
        $panels = $this->spec->panels();
        if (!isset($panels[$panelIndex])) {
            return null;
        }

        $min = (float) $panels[$panelIndex]['y_min'];
        $max = (float) $panels[$panelIndex]['y_max'];

        return $this->y($panelIndex, max($min, min($max, 0.0)));
    }

    /**
     * Label jam pada kolom ke-n. Kosong kalau awal sumbunya belum diketahui.
     *
     * Kosong, bukan jam hari ini. Perender harus menghasilkan gambar yang sama
     * untuk isi yang sama, kapan pun ia dipanggil; membaca jam dinding membuat
     * dokumen yang sama menghasilkan dua gambar berbeda, dan tanda tangannya
     * tidak lagi bisa dibuktikan ulang.
     */
    public function columnLabel(int $column): string
    {
        return $this->axis->label($column);
    }

    /** @deprecated Pakai {@see self::columnLabel()}. */
    public function hourLabel(int $column): string
    {
        return $this->columnLabel($column);
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
                'type' => (string) $panel['type'],
                'stacked' => (bool) $panel['stacked'],
                'top' => $this->tops[$i],
                'height' => (float) $panel['height'],
                'y_min' => (float) $panel['y_min'],
                'y_max' => (float) $panel['y_max'],
                'y_step' => (float) $panel['y_step'],
            ];
        }

        return [
            'left' => $this->spec->left(),
            'column_width' => $this->spec->columnWidth(),
            'hours' => $this->spec->columns(),
            'columns' => $this->spec->columns(),
            'axis' => $this->axis->export(),
            'start' => $this->axis->origin(),
            'width' => $this->spec->width(),
            'height' => $this->spec->height(),
            'panels' => $panels,
        ];
    }
}
