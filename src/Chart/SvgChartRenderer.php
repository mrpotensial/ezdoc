<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

/**
 * Menggambar papan grafik jadi SVG, dari spesifikasi dan angka.
 *
 * PHP murni: tidak menyentuh basis data, tidak membaca permintaan yang sedang
 * berjalan, tidak membaca jam dinding. Itu syarat, bukan kebetulan — perender
 * yang sama dipanggil tiga pihak:
 *
 *   halaman dokumen  → SVG disisipkan langsung
 *   PDF cetak        → SVG dibungkus <img src="data:image/svg+xml;base64,…">
 *   PDF beku         → sama, dari proses yang tidak punya peramban
 *
 * Satu perender, bukan dua. Godaannya besar untuk menggambar lagi di
 * JavaScript supaya isiannya terasa lincah — dan dua perender selalu berpisah
 * diam-diam, lalu yang ditandatangani orang berbeda dari yang tercetak.
 *
 * ## Dua batasan Dompdf yang membentuk keluarannya
 *
 * Diuji langsung pada Dompdf 3.1.5 + php-svg-lib (lihat
 * `docs/260929-grafik-terisi.md` §2):
 *
 * 1. **`<pattern>` gagal diam-diam** — terisi hitam pekat. Arsiran apa pun
 *    harus disusun dari `<line>`. Gambarnya tetap muncul, cuma artinya hilang,
 *    jadi kegagalannya tidak terlihat sampai ada yang membaca hasil cetaknya.
 * 2. **Atribut tampilan, bukan CSS.** `class` dan `<style>` tidak diperlakukan
 *    seperti di peramban.
 *
 * Keduanya dijaga uji, bukan cuma ditulis di sini.
 *
 * ## Keluarannya deterministik
 *
 * Angka yang sama menghasilkan byte yang sama. Tanpa `uniqid()`, tanpa tanggal
 * hari ini, urutan atribut tetap, pecahan diformat satu cara. Tanpa itu,
 * "dokumen ini bisa dibangun ulang dari nilainya" tidak bisa dibuktikan — dan
 * itu satu-satunya alasan tanda tangannya berarti.
 *
 * PHP 7.4+ compatible.
 */
final class SvgChartRenderer
{
    private const GRID_COLOR = '#b8b8b8';

    private const AXIS_COLOR = '#333333';

    private const TEXT_COLOR = '#333333';

    private const FONT = 'Helvetica, Arial, sans-serif';

    /**
     * Skala papan ini untuk sekumpulan nilai.
     *
     * Dipisah dari {@see self::render()} karena halaman dokumen membutuhkannya
     * sendiri: lapisan pegangan di peramban memakai angka yang sama persis
     * dengan yang dipakai menggambar.
     *
     * @param array<string,mixed> $values nama field → nilai tersimpan
     */
    public function scale(ChartSpec $spec, array $values): Scale
    {
        $start = null;

        $field = $spec->startField();
        if ($field !== '' && isset($values[$field]) && is_scalar($values[$field])) {
            $start = SeriesValues::normaliseTime((string) $values[$field]);
        }

        if ($start === null) {
            // Tanpa jam mulai yang disebut, sumbu dimulai dari pengukuran
            // paling awal. Lembar yang sudah terisi tetap tergambar walau
            // isian jam mulainya belum disentuh.
            $all = [];
            foreach ($spec->panels() as $panel) {
                /** @var array<int,array<string,mixed>> $series */
                $series = $panel['series'];
                foreach ($series as $one) {
                    $name = (string) $one['field'];
                    $all[] = SeriesValues::parse($values[$name] ?? null);
                }
            }
            $start = SeriesValues::earliest($all);
        }

        return Scale::create($spec, $start);
    }

    /**
     * @param array<string,mixed> $values nama field → nilai tersimpan
     */
    public function render(ChartSpec $spec, array $values): string
    {
        $scale = $this->scale($spec, $values);
        $width = (float) $spec->width();
        $height = $spec->height();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . self::num($width)
            . '" height="' . self::num($height)
            . '" viewBox="0 0 ' . self::num($width) . ' ' . self::num($height) . '">';
        $svg .= '<rect x="0" y="0" width="' . self::num($width) . '" height="' . self::num($height)
            . '" fill="#ffffff"/>';

        foreach ($spec->panels() as $i => $panel) {
            $svg .= $this->panel($spec, $scale, $values, $i, $panel);
        }

        $svg .= $this->hourLabels($spec, $scale);
        $svg .= '</svg>';

        return $svg;
    }

    // ── Satu panel ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $panel
     */
    private function panel(ChartSpec $spec, Scale $scale, array $values, int $index, array $panel): string
    {
        $top = $scale->top($index);
        $bottom = $top + (float) $panel['height'];
        $left = Geometry::LEFT;
        $right = $scale->xFromHour((float) $spec->hours());

        $out = '';

        if ((bool) $panel['grid']) {
            $out .= '<g stroke="' . self::GRID_COLOR . '" stroke-width="0.5">';
            for ($column = 0; $column <= $spec->hours(); $column++) {
                $x = $scale->xFromHour((float) $column);
                $out .= '<line x1="' . self::num($x) . '" y1="' . self::num($top)
                    . '" x2="' . self::num($x) . '" y2="' . self::num($bottom) . '"/>';
            }
            foreach ($this->ticks($panel) as $value) {
                $y = $scale->y($index, $value);
                if ($y === null) {
                    continue;
                }
                $out .= '<line x1="' . self::num($left) . '" y1="' . self::num($y)
                    . '" x2="' . self::num($right) . '" y2="' . self::num($y) . '"/>';
            }
            $out .= '</g>';
        }

        // Bingkai panel digambar terpisah dari kisinya, dengan garis lebih
        // tegas: batas sumbu harus terbaca sebagai batas, bukan sebagai salah
        // satu garis kisi.
        $out .= '<rect x="' . self::num($left) . '" y="' . self::num($top)
            . '" width="' . self::num($right - $left) . '" height="' . self::num($bottom - $top)
            . '" fill="none" stroke="' . self::AXIS_COLOR . '" stroke-width="1"/>';

        foreach ($this->ticks($panel) as $value) {
            $y = $scale->y($index, $value);
            if ($y === null) {
                continue;
            }
            $out .= '<text x="' . self::num($left - 6) . '" y="' . self::num($y + 3)
                . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                . '" text-anchor="end">' . self::text(self::num($value)) . '</text>';
        }

        $title = (string) $panel['title'];
        if ($title !== '') {
            $middle = $top + (((float) $panel['height']) / 2);
            $out .= '<g transform="translate(' . self::num($left - 40) . ' ' . self::num($middle) . ') rotate(-90)">'
                . '<text x="0" y="0" font-family="' . self::FONT . '" font-size="9" fill="' . self::TEXT_COLOR
                . '" text-anchor="middle">' . self::text($title) . '</text></g>';
        }

        /** @var array<int,array<string,mixed>> $references */
        $references = $panel['references'];
        foreach ($references as $reference) {
            $out .= $this->reference($scale, $index, $reference);
        }

        /** @var array<int,array<string,mixed>> $series */
        $series = $panel['series'];
        foreach ($series as $one) {
            $name = (string) $one['field'];
            $out .= $this->series($scale, $index, $one, SeriesValues::parse($values[$name] ?? null));
        }

        return $out;
    }

    /**
     * Nilai-nilai yang diberi garis dan angka di sumbu Y.
     *
     * @param array<string,mixed> $panel
     * @return array<int,float>
     */
    private function ticks(array $panel): array
    {
        $min = (float) $panel['y_min'];
        $max = (float) $panel['y_max'];
        $step = (float) $panel['y_step'];

        $ticks = [];
        // Dihitung dari perkalian, bukan penjumlahan beruntun: menambah 0,1
        // dua ratus kali menghasilkan 19,999999999 dan garis terakhir hilang.
        $count = (int) floor((($max - $min) / $step) + 0.0000001);
        for ($i = 0; $i <= $count; $i++) {
            $ticks[] = round($min + ($i * $step), 4);
        }

        return $ticks;
    }

    /**
     * @param array<string,mixed> $reference
     */
    private function reference(Scale $scale, int $index, array $reference): string
    {
        $y1 = $scale->y($index, (float) $reference['y1']);
        $y2 = $scale->y($index, (float) $reference['y2']);
        if ($y1 === null || $y2 === null) {
            return '';
        }

        $dash = $reference['style'] === 'dashed' ? ' stroke-dasharray="5 3"' : '';

        return '<line x1="' . self::num($scale->xFromHour((float) $reference['x1']))
            . '" y1="' . self::num($y1)
            . '" x2="' . self::num($scale->xFromHour((float) $reference['x2']))
            . '" y2="' . self::num($y2)
            . '" stroke="' . self::attr((string) $reference['color']) . '" stroke-width="1.2"' . $dash . '/>';
    }

    /**
     * @param array<string,mixed> $series
     * @param array<int,array{x:string,y:float|int|string}> $points
     */
    private function series(Scale $scale, int $index, array $series, array $points): string
    {
        $color = self::attr((string) $series['color']);
        $draw = (string) $series['draw'];

        /** @var array<int,array{0:float,1:float}> $placed */
        $placed = [];
        /** @var array<int,array<int,array{0:float,1:float}>> $segments */
        $segments = [];
        $current = [];

        foreach ($points as $point) {
            $x = $scale->x($point['x']);
            $y = $scale->y($index, $point['y']);
            if ($x === null || $y === null) {
                // Titik yang tidak tergambar memutus garisnya, bukan
                // dilompati: garis lurus yang melewati pengukuran yang hilang
                // menggambarkan perkembangan yang tidak pernah diamati.
                if (count($current) > 1) {
                    $segments[] = $current;
                }
                $current = [];
                continue;
            }
            $placed[] = [$x, $y];
            $current[] = [$x, $y];
        }
        if (count($current) > 1) {
            $segments[] = $current;
        }

        $out = '';

        if ($draw === 'point+line' || $draw === 'line') {
            foreach ($segments as $segment) {
                $d = '';
                foreach ($segment as $i => $xy) {
                    $d .= ($i === 0 ? 'M' : 'L') . self::num($xy[0]) . ' ' . self::num($xy[1]) . ' ';
                }
                $out .= '<path d="' . rtrim($d) . '" fill="none" stroke="' . $color . '" stroke-width="1.4"/>';
            }
        }

        if ($draw === 'point+line' || $draw === 'point') {
            foreach ($placed as $xy) {
                $out .= $this->marker((string) $series['marker'], $xy[0], $xy[1], $color);
            }
        }

        return $out;
    }

    private function marker(string $marker, float $x, float $y, string $color): string
    {
        if ($marker === 'dot') {
            return '<circle cx="' . self::num($x) . '" cy="' . self::num($y) . '" r="2" fill="' . $color . '"/>';
        }

        if ($marker === 'square') {
            return '<rect x="' . self::num($x - 3) . '" y="' . self::num($y - 3)
                . '" width="6" height="6" fill="none" stroke="' . $color . '" stroke-width="1.4"/>';
        }

        if ($marker === 'cross') {
            return '<line x1="' . self::num($x - 3.5) . '" y1="' . self::num($y - 3.5)
                . '" x2="' . self::num($x + 3.5) . '" y2="' . self::num($y + 3.5)
                . '" stroke="' . $color . '" stroke-width="1.4"/>'
                . '<line x1="' . self::num($x - 3.5) . '" y1="' . self::num($y + 3.5)
                . '" x2="' . self::num($x + 3.5) . '" y2="' . self::num($y - 3.5)
                . '" stroke="' . $color . '" stroke-width="1.4"/>';
        }

        return '<circle cx="' . self::num($x) . '" cy="' . self::num($y)
            . '" r="3.2" fill="#ffffff" stroke="' . $color . '" stroke-width="1.4"/>';
    }

    private function hourLabels(ChartSpec $spec, Scale $scale): string
    {
        if (!$scale->hasStart()) {
            return '';
        }

        $y = $spec->height() - Geometry::BOTTOM + 12;
        $out = '';
        for ($column = 0; $column <= $spec->hours(); $column += $spec->labelEvery()) {
            $out .= '<text x="' . self::num($scale->xFromHour((float) $column)) . '" y="' . self::num($y)
                . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                . '" text-anchor="middle">' . self::text($scale->hourLabel($column)) . '</text>';
        }

        return $out;
    }

    // ── Penulis nilai ────────────────────────────────────────────────────

    /**
     * Angka jadi teks, satu cara saja.
     *
     * Lewat `number_format`, bukan penggabungan langsung: mengubah float jadi
     * teks di PHP mengikuti setelan `precision` di php.ini, jadi server yang
     * berbeda bisa menulis "12.1" dan "12.099999999999999" untuk nilai yang
     * sama. Keluaran perender ini ikut masuk PDF yang ditandatangani; dua
     * bentuk untuk satu angka berarti dua dokumen untuk satu isi.
     */
    private static function num(float $value): string
    {
        $text = number_format($value, 2, '.', '');
        if (strpos($text, '.') !== false) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }

    /** Teks yang masuk ke isi elemen. */
    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Teks yang masuk ke nilai atribut. */
    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
