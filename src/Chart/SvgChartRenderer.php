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

    /**
     * Nama font TANPA spasi sesudah koma. Itu syarat, bukan gaya penulisan.
     *
     * php-svg-lib tidak membaca daftar berkoma yang berspasi, lalu jatuh ke
     * huruf berkait — diuji enam ejaan, 30 Sep 2026, lihat
     * `docs/260930-grafik-jenis-baru.md` §3. Akibatnya label grafik tercetak
     * berkait sementara di layar tak berkait: satu perender, dua rupa.
     *
     * Rantai cadangannya dipertahankan untuk peramban; cuma spasinya dibuang.
     */
    private const FONT = 'Helvetica,Arial,sans-serif';

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
        $axis = $spec->axis();
        $start = null;

        $field = $spec->startField();
        if ($field !== '' && isset($values[$field]) && is_scalar($values[$field])) {
            $start = $axis->normalise((string) $values[$field]);
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
                    $all[] = SeriesValues::parse($values[$name] ?? null, $axis);
                }
            }
            $start = SeriesValues::earliest($all, $axis);
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

        if ($spec->timeAxis() === 'bottom') {
            $svg .= $this->columnRow($spec, $scale, $spec->height() - Geometry::BOTTOM + 12);
        }
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
        $left = $spec->left();
        $right = $scale->xFromColumn((float) $spec->columns());
        $type = (string) $panel['type'];

        $out = $this->grid($spec, $scale, $index, $panel, $top, $bottom, $left, $right);

        // Bingkai panel digambar terpisah dari kisinya, dengan garis lebih
        // tegas: batas sumbu harus terbaca sebagai batas, bukan sebagai salah
        // satu garis kisi.
        $out .= '<rect x="' . self::num($left) . '" y="' . self::num($top)
            . '" width="' . self::num($right - $left) . '" height="' . self::num($bottom - $top)
            . '" fill="none" stroke="' . self::AXIS_COLOR . '" stroke-width="1"/>';

        if ((bool) $panel['y_axis']) {
            foreach ($this->ticks($panel) as $value) {
                $y = $scale->y($index, $value);
                if ($y === null) {
                    continue;
                }
                $out .= '<text x="' . self::num($left - 6) . '" y="' . self::num($y + 3)
                    . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                    . '" text-anchor="end">' . self::text(self::num($value)) . '</text>';
            }
        }

        $out .= $this->title($panel, $left, $top, $bottom);

        if ($panel['legend'] !== null) {
            $out .= $this->legend($panel, $left, $top, $bottom, (string) $panel['legend']);
        }

        /** @var array<int,array<string,mixed>> $references */
        $references = $panel['references'];
        foreach ($references as $reference) {
            $out .= $this->reference($scale, $index, $reference);
        }

        if ($type === 'bar') {
            $out .= $this->bars($spec, $scale, $values, $index, $panel, $bottom);
        } elseif (in_array($type, ChartSpec::CELL_TYPES, true)) {
            $out .= $this->cells($spec, $scale, $values, $panel, $top, $bottom, $type);
        } else {
            /** @var array<int,array<string,mixed>> $series */
            $series = $panel['series'];
            foreach ($series as $one) {
                $name = (string) $one['field'];
                $out .= $this->series(
                    $scale,
                    $index,
                    $one,
                    SeriesValues::parse($values[$name] ?? null, $scale->axis())
                );
            }
        }

        // Garis tegak digambar paling akhir supaya terlihat di atas isinya —
        // ia penanda waktu, bukan salah satu pengukuran.
        /** @var array<int,array<string,mixed>> $verticals */
        $verticals = $panel['verticals'];
        foreach ($verticals as $vertical) {
            $out .= $this->vertical($scale, $values, $vertical, $top, $bottom);
        }

        // Baris jam milik panel ini ditulis di ruang antar panel, yang sudah
        // dilebarkan {@see ChartSpec::gapBefore()} sebanyak satu baris.
        if ((bool) $panel['time_axis']) {
            $out .= $this->columnRow($spec, $scale, $bottom + 11);
        }

        return $out;
    }

    /**
     * Kisi panel, termasuk garis halus di antara jam kalau diminta.
     *
     * @param array<string,mixed> $panel
     */
    private function grid(
        ChartSpec $spec,
        Scale $scale,
        int $index,
        array $panel,
        float $top,
        float $bottom,
        float $left,
        float $right
    ): string {
        if (!(bool) $panel['grid']) {
            return '';
        }

        $out = '<g stroke="' . self::GRID_COLOR . '" stroke-width="0.5">';

        $sub = (int) $panel['sub_columns'];
        $total = $spec->columns() * $sub;
        for ($i = 0; $i <= $total; $i++) {
            $x = $scale->xFromColumn($i / $sub);
            $out .= '<line x1="' . self::num($x) . '" y1="' . self::num($top)
                . '" x2="' . self::num($x) . '" y2="' . self::num($bottom) . '"/>';
        }

        if ((bool) $panel['y_axis']) {
            foreach ($this->ticks($panel) as $value) {
                $y = $scale->y($index, $value);
                if ($y === null) {
                    continue;
                }
                $out .= '<line x1="' . self::num($left) . '" y1="' . self::num($y)
                    . '" x2="' . self::num($right) . '" y2="' . self::num($y) . '"/>';
            }
        }

        return $out . '</g>';
    }

    /**
     * Judul panel — mendatar di kolom kiri, atau berdiri.
     *
     * Baris setinggi 22 tidak bisa memuat tulisan berdiri, dan panel setinggi
     * 200 tidak bisa memuat "Obat dan Cairan IV" mendatar di kolom selebar itu.
     * Karena itu letaknya mengikuti tingginya, bukan satu aturan untuk semua.
     *
     * @param array<string,mixed> $panel
     */
    private function title(array $panel, float $left, float $top, float $bottom): string
    {
        $title = (string) $panel['title'];
        if ($title === '') {
            return '';
        }

        // Judul biasanya di tengah tinggi panel. Kecuali kalau legendanya juga
        // menempati kolom kiri — keduanya di tengah berarti keduanya bertumpuk.
        $middle = $panel['legend'] === 'left'
            ? $top + 6
            : $top + (($bottom - $top) / 2);

        if ((string) $panel['title_align'] === 'left') {
            return '<text x="' . self::num($left - 6) . '" y="' . self::num($middle + 3)
                . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                . '" text-anchor="end">' . self::text($title) . '</text>';
        }

        return '<g transform="translate(' . self::num($left - 40) . ' ' . self::num($middle) . ') rotate(-90)">'
            . '<text x="0" y="0" font-family="' . self::FONT . '" font-size="9" fill="' . self::TEXT_COLOR
            . '" text-anchor="middle">' . self::text($title) . '</text></g>';
    }

    /**
     * Legenda satu baris di atas panel: tanda tiap deret, lalu garis acuannya.
     *
     * @param array<string,mixed> $panel
     */
    private function legend(array $panel, float $left, float $top, float $bottom, string $where): string
    {
        if ($where === 'left') {
            return $this->legendLeft($panel, $left, $top);
        }

        $x = $left + 6;
        $y = $where === 'below' ? $bottom + 9 : $top - 4;
        $out = '';

        /** @var array<int,array<string,mixed>> $series */
        $series = $panel['series'];
        foreach ($series as $one) {
            $label = (string) ($one['title'] !== '' ? $one['title'] : $one['field']);
            $out .= $this->marker((string) $one['marker'], $x + 4, $y - 3, self::attr((string) $one['color']));
            $out .= '<text x="' . self::num($x + 11) . '" y="' . self::num($y)
                . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                . '">' . self::text($label) . '</text>';
            $x += 18 + (strlen($label) * 4.6);
        }

        /** @var array<int,array<string,mixed>> $references */
        $references = $panel['references'];
        foreach ($references as $reference) {
            $label = (string) $reference['label'];
            if ($label === '') {
                continue;
            }
            $dash = $reference['style'] === 'dashed' ? ' stroke-dasharray="4 2"' : '';
            $out .= '<line x1="' . self::num($x) . '" y1="' . self::num($y - 3)
                . '" x2="' . self::num($x + 14) . '" y2="' . self::num($y - 3)
                . '" stroke="' . self::attr((string) $reference['color']) . '" stroke-width="1.4"' . $dash . '/>';
            $out .= '<text x="' . self::num($x + 18) . '" y="' . self::num($y)
                . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                . '">' . self::text($label) . '</text>';
            $x += 24 + (strlen($label) * 4.6);
        }

        return $out;
    }

    /**
     * Legenda di kolom kiri: contoh isian batang beserta namanya.
     *
     * Bentuknya meniru keterangan di lembar aslinya — kotak kecil berisi
     * arsiran yang sama persis dengan yang dipakai batangnya, bukan sekadar
     * warna. Kotak berwarna tidak menjelaskan apa pun ketika ketiganya hitam.
     *
     * @param array<string,mixed> $panel
     */
    private function legendLeft(array $panel, float $left, float $top): string
    {
        /** @var array<string,array<string,string>> $levels */
        $levels = $panel['levels'];
        if ($levels === []) {
            return '';
        }

        /** @var array<int,array<string,mixed>> $series */
        $series = $panel['series'];
        $color = $series === [] ? '#111111' : self::attr((string) $series[0]['color']);

        $x = $left - 46;
        $y = $top + 14;
        $out = '';

        foreach ($levels as $level) {
            $out .= $this->barShape($x, $y, 12.0, 9.0, (string) $level['fill'], $color);
            $label = (string) $level['label'];
            if ($label !== '') {
                $out .= '<text x="' . self::num($x + 16) . '" y="' . self::num($y + 7)
                    . '" font-family="' . self::FONT . '" font-size="6" fill="' . self::TEXT_COLOR
                    . '">' . self::text($label) . '</text>';
            }
            $y += 12;
        }

        return $out;
    }

    /**
     * Batang per kolom — tingginya dari deret pertama, isiannya dari kedua.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $panel
     */
    private function bars(
        ChartSpec $spec,
        Scale $scale,
        array $values,
        int $index,
        array $panel,
        float $bottom
    ): string {
        /** @var array<int,array<string,mixed>> $series */
        $series = $panel['series'];
        if ($series === []) {
            return '';
        }

        $heightField = (string) $series[0]['field'];
        $points = SeriesValues::parse($values[$heightField] ?? null, $scale->axis());

        $styleByTime = [];
        if (isset($series[1])) {
            foreach (SeriesValues::parse($values[(string) $series[1]['field']] ?? null, $scale->axis()) as $point) {
                $styleByTime[$point['x']] = (string) $point['y'];
            }
        }

        /** @var array<string,array<string,string>> $levels */
        $levels = $panel['levels'];
        $color = self::attr((string) $series[0]['color']);
        $width = $spec->columnWidth() * 0.6;
        $out = '';

        foreach ($points as $point) {
            $x = $scale->x($point['x']);
            $y = $scale->y($index, $point['y']);
            if ($x === null || $y === null) {
                continue;
            }

            // Nilainya milik KOLOM yang dimulai pada jam itu, bukan titik di
            // garisnya — batang yang berdiri di atas garis jam terbaca sebagai
            // milik dua kolom sekaligus.
            $barX = $x + (($spec->columnWidth() - $width) / 2);
            $level = $levels[$styleByTime[$point['x']] ?? ''] ?? null;
            $fill = $level === null ? 'solid' : (string) $level['fill'];
            $out .= $this->barShape($barX, $y, $width, $bottom - $y, $fill, $color);
        }

        return $out;
    }

    /**
     * Satu batang, dengan isian yang diminta.
     *
     * Arsiran digambar sebagai kumpulan `<line>`, bukan `<pattern>`: pattern
     * tergambar hitam pekat di Dompdf, dan gagalnya tidak terlihat — kotaknya
     * tetap muncul, cuma ketiga tingkat jadi sama.
     */
    private function barShape(float $x, float $y, float $width, float $height, string $fill, string $color): string
    {
        if ($height <= 0) {
            return '';
        }

        $rect = '<rect x="' . self::num($x) . '" y="' . self::num($y)
            . '" width="' . self::num($width) . '" height="' . self::num($height)
            . '" fill="' . ($fill === 'solid' ? $color : 'none')
            . '" stroke="' . $color . '" stroke-width="0.8"/>';

        if ($fill === 'hatch') {
            $isi = '<g stroke="' . $color . '" stroke-width="0.8">';

            // Garis miring 45°, dipotong sendiri di dalam kotaknya supaya tidak
            // butuh clip-path — dukungannya tidak dijamin php-svg-lib.
            //
            // Tiap garis memenuhi u + v = c dalam koordinat lokal kotak (u ke
            // kanan, v ke bawah). Ujungnya didapat dengan memotong c ke
            // [0,lebar] dan [0,tinggi], bukan dengan menebak — versi pertama
            // menebak, dan arsirnya menyebar seperti kipas dari satu sudut.
            for ($c = 4.0; $c < $width + $height; $c += 4.0) {
                $u1 = max(0.0, $c - $height);
                $u2 = min($width, $c);
                if ($u2 <= $u1) {
                    continue;
                }
                $isi .= '<line x1="' . self::num($x + $u1) . '" y1="' . self::num($y + ($c - $u1))
                    . '" x2="' . self::num($x + $u2) . '" y2="' . self::num($y + ($c - $u2)) . '"/>';
            }

            return $rect . $isi . '</g>';
        }

        if ($fill === 'dots') {
            $isi = '<g fill="' . $color . '">';
            for ($dy = 3.0; $dy < $height; $dy += 4.0) {
                for ($dx = 3.0; $dx < $width; $dx += 4.0) {
                    $isi .= '<circle cx="' . self::num($x + $dx) . '" cy="' . self::num($y + $dy) . '" r="0.7"/>';
                }
            }

            return $rect . $isi . '</g>';
        }

        return $rect;
    }

    /**
     * Baris sel berisi huruf atau teks pendek, satu per kolom.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $panel
     */
    private function cells(
        ChartSpec $spec,
        Scale $scale,
        array $values,
        array $panel,
        float $top,
        float $bottom,
        string $type
    ): string {
        /** @var array<int,array<string,mixed>> $series */
        $series = $panel['series'];
        $middle = $top + (($bottom - $top) / 2) + 3;
        $size = $type === 'symbol' ? 9 : 7;
        $out = '';

        foreach ($series as $one) {
            $color = self::attr((string) $one['color']);
            foreach (SeriesValues::parse($values[(string) $one['field']] ?? null, $scale->axis()) as $point) {
                $x = $scale->x($point['x']);
                if ($x === null) {
                    continue;
                }
                $out .= '<text x="' . self::num($x + ($spec->columnWidth() / 2)) . '" y="' . self::num($middle)
                    . '" font-family="' . self::FONT . '" font-size="' . $size . '" fill="' . $color
                    . '" text-anchor="middle">' . self::text((string) $point['y']) . '</text>';
            }
        }

        return $out;
    }

    /**
     * Garis tegak penanda waktu, dengan kotak jamnya di bawah.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $vertical
     */
    private function vertical(Scale $scale, array $values, array $vertical, float $top, float $bottom): string
    {
        $raw = $values[(string) $vertical['at_field']] ?? null;
        if (!is_scalar($raw)) {
            return '';
        }
        $time = $scale->axis()->normalise((string) $raw);
        if ($time === null) {
            return '';
        }
        $x = $scale->x($time);
        if ($x === null) {
            return '';
        }

        $color = self::attr((string) $vertical['color']);
        $out = '<line x1="' . self::num($x) . '" y1="' . self::num($top)
            . '" x2="' . self::num($x) . '" y2="' . self::num($bottom)
            . '" stroke="' . $color . '" stroke-width="1"/>';

        if (!(bool) $vertical['label']) {
            return $out;
        }

        $jam = substr($time, 11, 5);

        return $out . '<rect x="' . self::num($x - 15) . '" y="' . self::num($bottom - 9)
            . '" width="30" height="10" fill="' . $color . '"/>'
            . '<text x="' . self::num($x) . '" y="' . self::num($bottom - 1.5)
            . '" font-family="' . self::FONT . '" font-size="7" fill="#ffffff" text-anchor="middle">'
            . self::text($jam) . '</text>';
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
        $x1 = $scale->xFromColumn((float) $reference['x1']);
        $x2 = $scale->xFromColumn((float) $reference['x2']);

        $out = '<line x1="' . self::num($x1) . '" y1="' . self::num($y1)
            . '" x2="' . self::num($x2) . '" y2="' . self::num($y2)
            . '" stroke="' . self::attr((string) $reference['color']) . '" stroke-width="1.2"' . $dash . '/>';

        $label = (string) $reference['label'];
        if (!(bool) $reference['label_on_line'] || $label === '') {
            return $out;
        }

        // Ditulis MIRING mengikuti garisnya, seperti "WASPADA" di lembar
        // aslinya. Sudutnya dihitung dari garis itu sendiri, bukan ditebak:
        // garis acuan boleh selandai atau securam apa pun.
        $sudut = rad2deg(atan2($y2 - $y1, $x2 - $x1));

        return $out . '<g transform="translate(' . self::num(($x1 + $x2) / 2) . ' '
            . self::num((($y1 + $y2) / 2) - 3) . ') rotate(' . self::num($sudut) . ')">'
            . '<text x="0" y="0" font-family="' . self::FONT . '" font-size="9" font-weight="bold" fill="'
            . self::attr((string) $reference['color']) . '" text-anchor="middle" letter-spacing="2">'
            . self::text($label) . '</text></g>';
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
        /** @var array<int,array{0:float,1:float}> $labelled Tempat titik, menurut urutan aslinya. */
        $labelled = [];
        /** @var array<int,array<int,array{0:float,1:float}>> $segments */
        $segments = [];
        $current = [];

        foreach ($points as $i => $point) {
            $x = $scale->x($point['x']);
            $y = $scale->y($index, $point['y']);
            if ($x !== null && $y !== null) {
                $labelled[$i] = [$x, $y];
            }
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

        // Angka di sebelah titiknya, kalau diminta. Di lembar pemantauan
        // sungguhan nilainya ditulis: 140 dan 148 terlalu dekat untuk
        // dibedakan dari tingginya saja.
        if ((bool) $series['labels']) {
            foreach ($points as $i => $point) {
                if (!isset($labelled[$i])) {
                    continue;
                }
                $out .= '<text x="' . self::num($labelled[$i][0]) . '" y="' . self::num($labelled[$i][1] - 6)
                    . '" font-family="' . self::FONT . '" font-size="7" fill="' . $color
                    . '" text-anchor="middle">' . self::text((string) $point['y']) . '</text>';
            }
        }

        return $out;
    }

    private function marker(string $marker, float $x, float $y, string $color): string
    {
        if ($marker === 'dot') {
            return '<circle cx="' . self::num($x) . '" cy="' . self::num($y) . '" r="2" fill="' . $color . '"/>';
        }

        if ($marker === 'diamond') {
            $titik = self::num($x) . ' ' . self::num($y - 4) . ' '
                . self::num($x + 4) . ' ' . self::num($y) . ' '
                . self::num($x) . ' ' . self::num($y + 4) . ' '
                . self::num($x - 4) . ' ' . self::num($y);

            return '<polygon points="' . $titik . '" fill="' . $color . '"/>';
        }

        if ($marker === 'square') {
            return '<rect x="' . self::num($x - 3) . '" y="' . self::num($y - 3)
                . '" width="6" height="6" fill="none" stroke="' . $color . '" stroke-width="1.4"/>';
        }

        if ($marker === 'triangle-up' || $marker === 'triangle-down') {
            $arah = $marker === 'triangle-up' ? -1 : 1;
            $titik = self::num($x) . ' ' . self::num($y + (4 * $arah)) . ' '
                . self::num($x - 3.5) . ' ' . self::num($y - (3 * $arah)) . ' '
                . self::num($x + 3.5) . ' ' . self::num($y - (3 * $arah));

            return '<polygon points="' . $titik . '" fill="' . $color . '"/>';
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

    /**
     * Satu baris angka jam pada ketinggian tertentu.
     *
     * Ketinggiannya diminta pemanggil, bukan dihitung di sini: barisnya bisa
     * berdiri di dasar papan atau tepat di bawah satu panel, dan lembar
     * partograf lama memakai yang kedua — jam ditulis di bawah pembukaan
     * serviks, tempat orang benar-benar membacanya.
     */
    private function columnRow(ChartSpec $spec, Scale $scale, float $y): string
    {
        if (!$scale->hasStart()) {
            return '';
        }

        $out = '';
        for ($column = 0; $column <= $spec->columns(); $column += $spec->labelEvery()) {
            $out .= '<text x="' . self::num($scale->xFromColumn((float) $column)) . '" y="' . self::num($y)
                . '" font-family="' . self::FONT . '" font-size="8" fill="' . self::TEXT_COLOR
                . '" text-anchor="middle">' . self::text($scale->columnLabel($column)) . '</text>';
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
