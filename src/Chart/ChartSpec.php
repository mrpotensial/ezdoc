<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

use Ezdoc\Exceptions\ValidationException;

/**
 * Bentuk sebuah papan grafik: kisinya, sumbunya, panel-panelnya.
 *
 * Papan, bukan grafik tunggal. Satu papan memuat beberapa panel yang bertumpuk
 * dan **berbagi satu sumbu X**. Itu bukan kemewahan: lembar pemantauan hidup
 * dari kesejajaran vertikalnya — jam 14:00 di panel atas harus tepat di atas
 * jam 14:00 di panel bawah. Kalau tiap panel jadi elemen sendiri yang digeser
 * dengan mouse di designer, melesetnya cuma soal waktu.
 *
 * Grafik biasa adalah papan dengan satu panel. Kasus sederhana adalah kasus
 * khususnya, bukan tambahan.
 *
 * ## Spesifikasi tersimpan di template, angkanya di dokumen
 *
 * Yang dipegang kelas ini cuma bentuknya. Angkanya hidup di `field_values`
 * dokumen dan dibaca {@see SeriesValues}. Pemisahan itu yang membuat pustaka
 * ini tetap netral: tidak ada satu pun nama domain di sini. Lembar pemantauan
 * apa pun, sekhusus apa pun bentuknya, adalah sebuah berkas spesifikasi —
 * bukan kelas baru di dalam pustaka.
 *
 * ## Bentuknya
 *
 *   {
 *     "width": 760,
 *     "hours": 16,
 *     "column_width": 40,
 *     "start_field": "start_time",
 *     "panels": [
 *       {
 *         "type": "line",
 *         "title": "Pembukaan serviks (cm)",
 *         "height": 250,
 *         "y": {"min": 0, "max": 10, "step": 1},
 *         "references": [
 *           {"from": [0, 4], "to": [8, 10], "style": "solid", "label": "waspada"}
 *         ],
 *         "series": [
 *           {"field": "dilatation", "draw": "point+line", "marker": "circle"}
 *         ]
 *       }
 *     ]
 *   }
 *
 * Semuanya boleh dihilangkan kecuali satu nama field. Spesifikasi terkecil yang
 * sah:
 *
 *   {"panels": [{"series": [{"field": "value"}]}]}
 *
 * PHP 7.4+ compatible.
 */
final class ChartSpec
{
    /**
     * Jenis panel.
     *
     *   line   — titik dan garis di atas sumbu Y berangka
     *   bar    — batang per kolom, tingginya dari deret pertama
     *   symbol — satu baris sel; isinya huruf atau angka pendek per kolom
     *   text   — sama seperti symbol, untuk teks yang lebih panjang
     *
     * `symbol` dan `text` digambar jalur yang sama; yang membedakan cuma
     * ukuran hurufnya. Dua nama dipertahankan karena penulis spesifikasi
     * memikirkan keduanya sebagai hal berbeda — "U/J/M/K" bukan "Tidak ada".
     */
    public const TYPES = ['line', 'bar', 'symbol', 'text'];

    /** Jenis yang isinya satu baris sel, bukan bidang bersumbu Y. */
    public const CELL_TYPES = ['symbol', 'text'];

    public const DRAWS = ['point+line', 'point', 'line'];

    public const MARKERS = ['circle', 'cross', 'square', 'dot', 'triangle-up', 'triangle-down'];

    public const STYLES = ['solid', 'dashed'];

    /** Cara isian batang digambar. Lihat catatan `<pattern>` di perender. */
    public const FILLS = ['solid', 'hatch', 'dots', 'empty'];

    /** @var int */
    private $width;

    /** @var int */
    private $hours;

    /** @var float */
    private $columnWidth;

    /** @var string */
    private $startField;

    /** @var int */
    private $labelEvery;

    /** @var float Lebar kolom label di kiri kisi. */
    private $left;

    /** @var float Jarak bawaan antar panel bertumpuk. */
    private $panelGap;

    /** @var array<int,array<string,mixed>> */
    private $panels;

    /**
     * @param array<int,array<string,mixed>> $panels
     */
    private function __construct(
        int $width,
        int $hours,
        float $columnWidth,
        string $startField,
        int $labelEvery,
        float $left,
        float $panelGap,
        array $panels
    ) {
        $this->left = $left;
        $this->panelGap = $panelGap;
        $this->width = $width;
        $this->hours = $hours;
        $this->columnWidth = $columnWidth;
        $this->startField = $startField;
        $this->labelEvery = $labelEvery;
        $this->panels = $panels;
    }

    /**
     * @param array<string,mixed> $spec
     *
     * @throws ValidationException kalau bentuknya tidak masuk akal
     */
    public static function fromArray(array $spec): self
    {
        $hours = self::readInt($spec, 'hours', 16, 1, 48);
        $columnWidth = self::readFloat($spec, 'column_width', 40.0, 4.0, 400.0);
        $labelEvery = self::readInt($spec, 'label_every', 1, 1, 24);

        // Kolom label kiri. Bisa diatur karena lembar sungguhan memuat nama
        // baris yang panjang — "Obat dan Cairan IV" tidak muat di 64.
        $left = self::readFloat($spec, 'left', Geometry::LEFT, 24.0, 400.0);

        // Jarak antar panel. Lembar pemantauan sungguhan menempelkan barisnya
        // rapat — tiga belas panel berjarak 14 memakan seperempat halaman
        // untuk ruang kosong.
        $panelGap = self::readFloat($spec, 'panel_gap', Geometry::PANEL_GAP, 0.0, 80.0);

        $startField = isset($spec['start_field']) ? (string) $spec['start_field'] : '';
        if ($startField !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $startField) !== 1) {
            throw new ValidationException("Nama field mulai tidak sah: '{$startField}'.");
        }

        $rawPanels = isset($spec['panels']) && is_array($spec['panels']) ? $spec['panels'] : [];
        if ($rawPanels === []) {
            throw new ValidationException('Papan grafik tanpa satu panel pun tidak menggambarkan apa-apa.');
        }

        $panels = [];
        foreach (array_values($rawPanels) as $i => $panel) {
            if (!is_array($panel)) {
                throw new ValidationException('Panel ke-' . ($i + 1) . ' bukan objek.');
            }
            $panels[] = self::readPanel($panel, $i + 1);
        }

        // Lebar bawaan mengikuti isinya, supaya spesifikasi terkecil pun
        // menghasilkan papan yang pas — bukan papan lebar dengan kisi menggantung.
        $defaultWidth = (int) round($left + ($hours * $columnWidth) + Geometry::RIGHT);
        $width = self::readInt($spec, 'width', $defaultWidth, 120, 4000);

        return new self($width, $hours, $columnWidth, $startField, $labelEvery, $left, $panelGap, $panels);
    }

    /**
     * @throws ValidationException
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ValidationException('Spesifikasi grafik bukan JSON objek.');
        }

        return self::fromArray($data);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function hours(): int
    {
        return $this->hours;
    }

    public function columnWidth(): float
    {
        return $this->columnWidth;
    }

    public function startField(): string
    {
        return $this->startField;
    }

    public function labelEvery(): int
    {
        return $this->labelEvery;
    }

    public function left(): float
    {
        return $this->left;
    }

    public function panelGap(): float
    {
        return $this->panelGap;
    }

    /**
     * Jarak sebelum panel ke-n.
     *
     * Panel berlegenda butuh ruang lebih: legendanya ditulis DI ATAS panel,
     * dan dengan jarak rapat ia akan menimpa panel di atasnya.
     */
    public function gapBefore(int $index): float
    {
        $panel = $this->panels[$index] ?? null;
        if ($panel === null) {
            return $this->panelGap;
        }
        if ($panel['gap_before'] !== null) {
            return (float) $panel['gap_before'];
        }

        return (bool) $panel['legend'] ? max($this->panelGap, 12.0) : $this->panelGap;
    }

    /** @return array<int,array<string,mixed>> */
    public function panels(): array
    {
        return $this->panels;
    }

    /**
     * Tinggi papan seluruhnya, dihitung dari panel-panelnya.
     *
     * Bukan disetel orang: tinggi yang diketik sendiri dan tinggi panel yang
     * dijumlahkan akan berpisah pada perubahan berikutnya, dan yang menang
     * selalu yang salah.
     */
    public function height(): float
    {
        $height = Geometry::TOP;
        foreach ($this->panels as $i => $panel) {
            $height += (float) $panel['height'] + ($i === 0 ? 0.0 : $this->gapBefore($i));
        }

        return $height + Geometry::BOTTOM;
    }

    /**
     * Semua nama field yang dirujuk papan ini.
     *
     * Dipakai halaman dokumen untuk tahu isian mana yang harus disediakan, dan
     * untuk tahu perubahan mana yang menuntut gambarnya digambar ulang.
     *
     * @return array<int,string>
     */
    public function fieldNames(): array
    {
        $names = [];
        if ($this->startField !== '') {
            $names[] = $this->startField;
        }
        foreach ($this->panels as $panel) {
            /** @var array<int,array<string,mixed>> $series */
            $series = $panel['series'];
            foreach ($series as $one) {
                $names[] = (string) $one['field'];
            }
            /** @var array<int,array<string,mixed>> $verticals */
            $verticals = $panel['verticals'];
            foreach ($verticals as $one) {
                $names[] = (string) $one['at_field'];
            }
        }

        return array_values(array_unique($names));
    }

    // ── Pembacaan satu panel ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $panel
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private static function readPanel(array $panel, int $position): array
    {
        $type = isset($panel['type']) ? (string) $panel['type'] : 'line';
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException(
                "Panel ke-{$position} berjenis '{$type}'; yang tersedia baru: " . implode(', ', self::TYPES) . '.'
            );
        }

        $isCell = in_array($type, self::CELL_TYPES, true);

        $y = isset($panel['y']) && is_array($panel['y']) ? $panel['y'] : [];
        $min = self::readFloat($y, 'min', 0.0, -100000.0, 100000.0);
        $max = self::readFloat($y, 'max', $isCell ? 1.0 : 10.0, -100000.0, 100000.0);
        if ($max <= $min) {
            throw new ValidationException("Panel ke-{$position}: sumbu Y max harus di atas min.");
        }
        $step = self::readFloat($y, 'step', 1.0, 0.0001, 100000.0);
        if (($max - $min) / $step > 200) {
            throw new ValidationException(
                "Panel ke-{$position}: langkah sumbu Y terlalu rapat, kisinya jadi blok hitam."
            );
        }

        $rawSeries = isset($panel['series']) && is_array($panel['series']) ? $panel['series'] : [];
        if ($rawSeries === []) {
            throw new ValidationException("Panel ke-{$position} tidak menyebut satu deret pun.");
        }

        $series = [];
        foreach (array_values($rawSeries) as $i => $one) {
            if (!is_array($one)) {
                throw new ValidationException("Panel ke-{$position}, deret ke-" . ($i + 1) . ' bukan objek.');
            }
            $series[] = self::readSeries($one, $position, $i + 1);
        }

        $references = [];
        $rawReferences = isset($panel['references']) && is_array($panel['references']) ? $panel['references'] : [];
        foreach (array_values($rawReferences) as $i => $one) {
            if (!is_array($one)) {
                throw new ValidationException("Panel ke-{$position}, acuan ke-" . ($i + 1) . ' bukan objek.');
            }
            $references[] = self::readReference($one, $position, $i + 1);
        }

        $verticals = [];
        $rawVerticals = isset($panel['verticals']) && is_array($panel['verticals']) ? $panel['verticals'] : [];
        foreach (array_values($rawVerticals) as $i => $one) {
            if (!is_array($one)) {
                throw new ValidationException("Panel ke-{$position}, garis tegak ke-" . ($i + 1) . ' bukan objek.');
            }
            $verticals[] = self::readVertical($one, $position, $i + 1);
        }

        $levels = [];
        $rawLevels = isset($panel['levels']) && is_array($panel['levels']) ? $panel['levels'] : [];
        foreach ($rawLevels as $value => $fill) {
            if (!in_array((string) $fill, self::FILLS, true)) {
                throw new ValidationException(
                    "Panel ke-{$position}: isian batang '" . (string) $fill . "' tidak dikenal."
                );
            }
            $levels[(string) $value] = (string) $fill;
        }

        // Baris sel setinggi 200 adalah salah ketik, bukan pilihan. Bawaannya
        // dibedakan supaya spesifikasi terkecil pun menghasilkan baris, bukan
        // bidang kosong setinggi sepuluh sentimeter.
        $defaultHeight = $isCell ? 22.0 : 200.0;

        return [
            'type' => $type,
            'title' => isset($panel['title']) ? (string) $panel['title'] : '',
            // Judul panel tipis ditulis mendatar di kolom kiri; panel tinggi
            // memakai tulisan berdiri karena mendatar tidak akan muat.
            'title_align' => self::readTitleAlign($panel, $isCell, $defaultHeight, $position),
            'height' => self::readFloat($panel, 'height', $defaultHeight, 12.0, 2000.0),
            'y_min' => $min,
            'y_max' => $max,
            'y_step' => $step,
            'y_axis' => !$isCell && (!isset($panel['y_axis']) || (bool) $panel['y_axis']),
            'grid' => !isset($panel['grid']) || (bool) $panel['grid'],
            'sub_columns' => self::readInt($panel, 'sub_columns', 1, 1, 12),
            'legend' => isset($panel['legend']) && (bool) $panel['legend'],
            'gap_before' => isset($panel['gap_before']) && $panel['gap_before'] !== ''
                ? self::readFloat($panel, 'gap_before', 0.0, 0.0, 200.0)
                : null,
            'levels' => $levels,
            'verticals' => $verticals,
            'references' => $references,
            'series' => $series,
        ];
    }

    /**
     * @param array<string,mixed> $series
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private static function readSeries(array $series, int $panelPosition, int $position): array
    {
        $field = isset($series['field']) ? (string) $series['field'] : '';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, deret ke-{$position}: nama field tidak sah ('{$field}')."
            );
        }

        $draw = isset($series['draw']) ? (string) $series['draw'] : 'point+line';
        if (!in_array($draw, self::DRAWS, true)) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, deret ke-{$position}: cara gambar '{$draw}' tidak dikenal."
            );
        }

        $marker = isset($series['marker']) ? (string) $series['marker'] : 'circle';
        if (!in_array($marker, self::MARKERS, true)) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, deret ke-{$position}: tanda '{$marker}' tidak dikenal."
            );
        }

        return [
            'field' => $field,
            'title' => isset($series['title']) ? (string) $series['title'] : '',
            'draw' => $draw,
            'marker' => $marker,
            // Angka di sebelah titiknya. Di lembar pemantauan sungguhan nilainya
            // ditulis, bukan cuma dititikkan — 140 dan 148 terlalu dekat untuk
            // dibedakan dari tingginya saja.
            'labels' => isset($series['labels']) && (bool) $series['labels'],
            'color' => self::readColor($series, 'color', '#cc0000', $panelPosition, $position),
        ];
    }

    /**
     * @param array<string,mixed> $reference
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private static function readReference(array $reference, int $panelPosition, int $position): array
    {
        $from = isset($reference['from']) && is_array($reference['from']) ? array_values($reference['from']) : [];
        $to = isset($reference['to']) && is_array($reference['to']) ? array_values($reference['to']) : [];
        if (count($from) !== 2 || count($to) !== 2) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, acuan ke-{$position}: 'from' dan 'to' harus dua angka [jam, nilai]."
            );
        }

        $style = isset($reference['style']) ? (string) $reference['style'] : 'solid';
        if (!in_array($style, self::STYLES, true)) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, acuan ke-{$position}: gaya '{$style}' tidak dikenal."
            );
        }

        return [
            'x1' => (float) $from[0],
            'y1' => (float) $from[1],
            'x2' => (float) $to[0],
            'y2' => (float) $to[1],
            'style' => $style,
            'label' => isset($reference['label']) ? (string) $reference['label'] : '',
            // Nama yang ditulis MIRING mengikuti garisnya, seperti "WASPADA"
            // di lembar aslinya. Tanpa ini namanya cuma ada di legenda, dan
            // garis mana yang mana harus ditebak.
            'label_on_line' => isset($reference['label_on_line']) && (bool) $reference['label_on_line'],
            'color' => self::readColor($reference, 'color', '#333333', $panelPosition, $position),
        ];
    }

    /**
     * Garis tegak pada satu waktu — "sekarang", atau saat apa pun yang ditandai.
     *
     * Waktunya datang dari sebuah field, bukan dari jam dinding: yang ditandai
     * harus ikut tersimpan bersama dokumennya, dan harus sama tiap kali
     * dokumen itu digambar ulang.
     *
     * @param array<string,mixed> $vertical
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private static function readVertical(array $vertical, int $panelPosition, int $position): array
    {
        $field = isset($vertical['at_field']) ? (string) $vertical['at_field'] : '';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, garis tegak ke-{$position}: nama field tidak sah ('{$field}')."
            );
        }

        return [
            'at_field' => $field,
            'label' => !isset($vertical['label']) || (bool) $vertical['label'],
            'color' => self::readColor($vertical, 'color', '#cc0000', $panelPosition, $position),
        ];
    }

    /**
     * @param array<string,mixed> $panel
     *
     * @throws ValidationException
     */
    private static function readTitleAlign(array $panel, bool $isCell, float $defaultHeight, int $position): string
    {
        $height = self::readFloat($panel, 'height', $defaultHeight, 12.0, 2000.0);
        $default = ($isCell || $height < 60) ? 'left' : 'rotated';

        if (!isset($panel['title_align']) || $panel['title_align'] === '') {
            return $default;
        }

        $align = (string) $panel['title_align'];
        if (!in_array($align, ['left', 'rotated'], true)) {
            throw new ValidationException(
                "Panel ke-{$position}: letak judul '{$align}' tidak dikenal (pilihannya left atau rotated)."
            );
        }

        return $align;
    }

    // ── Pembaca nilai yang memberi tahu, bukan diam ───────────────────────

    /**
     * @param array<string,mixed> $source
     *
     * @throws ValidationException
     */
    private static function readInt(array $source, string $key, int $default, int $min, int $max): int
    {
        if (!isset($source[$key]) || $source[$key] === '') {
            return $default;
        }
        if (!is_numeric($source[$key])) {
            throw new ValidationException("Nilai '{$key}' harus angka.");
        }
        $value = (int) round((float) $source[$key]);
        if ($value < $min || $value > $max) {
            throw new ValidationException("Nilai '{$key}' di luar jangkauan {$min}–{$max}.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     *
     * @throws ValidationException
     */
    private static function readFloat(array $source, string $key, float $default, float $min, float $max): float
    {
        if (!isset($source[$key]) || $source[$key] === '') {
            return $default;
        }
        if (!is_numeric($source[$key])) {
            throw new ValidationException("Nilai '{$key}' harus angka.");
        }
        $value = (float) $source[$key];
        if ($value < $min || $value > $max) {
            throw new ValidationException("Nilai '{$key}' di luar jangkauan {$min}–{$max}.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     *
     * @throws ValidationException
     */
    private static function readColor(
        array $source,
        string $key,
        string $default,
        int $panelPosition,
        int $position
    ): string {
        if (!isset($source[$key]) || $source[$key] === '') {
            return $default;
        }
        $color = strtolower((string) $source[$key]);
        if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw new ValidationException(
                "Panel ke-{$panelPosition}, bagian ke-{$position}: warna harus #rrggbb, bukan '{$color}'."
            );
        }

        return $color;
    }
}
