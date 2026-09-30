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

    /**
     * Cara sebuah deret digambar.
     *
     *   point+line — titik dihubungkan garis lurus (bawaan)
     *   point      — titik saja
     *   line       — garis saja
     *   point+step — **tangga**: nilai bertahan sampai diubah
     *   step       — tangga tanpa titik
     *   point+area — garis dengan bidang terisi sampai dasar
     *   area       — bidang terisi tanpa titik
     *
     * Tangga bukan hiasan. Untuk tetes/menit, dosis, dan aliran oksigen,
     * garis lurus menggambarkan perubahan bertahap yang tidak pernah terjadi:
     * yang sebenarnya 20 sampai pukul 10, lalu 40. Dokumennya lalu
     * memperlihatkan nilai yang tidak pernah disetel siapa pun.
     */
    public const DRAWS = [
        'point+line', 'point', 'line',
        'point+step', 'step',
        'point+area', 'area',
    ];

    public const MARKERS = ['circle', 'cross', 'square', 'dot', 'diamond', 'triangle-up', 'triangle-down'];

    public const STYLES = ['solid', 'dashed'];

    /** Cara isian batang digambar. Lihat catatan `<pattern>` di perender. */
    public const FILLS = ['solid', 'hatch', 'dots', 'empty'];

    /**
     * Letak legenda.
     *
     *   above — sebaris di atas panel (pembukaan serviks)
     *   below — sebaris di bawah panel (nadi & tekanan darah)
     *   left  — bertumpuk di kolom label kiri (keterangan isian kontraksi)
     */
    public const LEGENDS = ['above', 'below', 'left'];

    /**
     * Letak baris jam di papan.
     *
     *   bottom — di dasar papan, di bawah panel terakhir
     *   none   — tidak ada di dasar; panel yang memintanya yang memuatnya
     *
     * Tiap panel punya saklarnya sendiri (`time_axis`). Keduanya berdiri
     * sendiri: lembar partograf lama menaruh jamnya tepat di bawah pembukaan
     * serviks DAN tidak mengulanginya di dasar, tapi lembar lain mengulang.
     */
    public const TIME_AXES = ['bottom', 'none'];

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

    /** @var string Salah satu dari self::TIME_AXES. */
    private $timeAxis;

    /** @var XAxis Sumbu mendatar, belum berawal. */
    private $axis;

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
        string $timeAxis,
        XAxis $axis,
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
        $this->timeAxis = $timeAxis;
        $this->axis = $axis;
        $this->panels = $panels;
    }

    /**
     * @param array<string,mixed> $spec
     *
     * @throws ValidationException kalau bentuknya tidak masuk akal
     */
    public static function fromArray(array $spec): self
    {
        $axis = self::readAxis($spec);

        // `hours` namanya menyesatkan begitu satuannya bukan jam, tapi ia ada
        // di tiap papan yang sudah tersimpan. `columns` dibaca duluan; `hours`
        // tetap jadi cadangan selamanya.
        $x = isset($spec['x']) && is_array($spec['x']) ? $spec['x'] : [];
        $kolomMentah = $x['columns'] ?? $spec['columns'] ?? $spec['hours'] ?? null;
        $hours = self::readInt(['columns' => $kolomMentah], 'columns', 16, 1, self::maxColumns($axis));
        $columnWidth = self::readFloat($spec, 'column_width', 40.0, 4.0, 400.0);
        $labelEvery = self::readInt($spec, 'label_every', 1, 1, 24);
        $timeAxis = self::readTimeAxis($spec);

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

        return new self(
            $width,
            $hours,
            $columnWidth,
            $startField,
            $labelEvery,
            $timeAxis,
            $axis,
            $left,
            $panelGap,
            $panels
        );
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

    /** Sumbu mendatar papan ini, belum berawal. */
    public function axis(): XAxis
    {
        return $this->axis;
    }

    /**
     * Salinan papan ini dengan sumbu mendatar yang lain.
     *
     * Satu-satunya jalan memakai sumbu selain waktu, dan sengaja lewat kode,
     * bukan lewat spesifikasi: bentuk sumbu berangka belum diputuskan, dan
     * spesifikasi tersimpan yang telanjur memakainya akan mengikat keputusan
     * itu. Dipakai {@see \Ezdoc\Tests\Chart\PapanSumbuNilaiTest} untuk
     * membuktikan {@see XAxis} benar-benar muat untuk yang bukan waktu.
     */
    public function withAxis(XAxis $axis): self
    {
        $salinan = clone $this;
        $salinan->axis = $axis;

        return $salinan;
    }

    /**
     * Jumlah kolom di sumbu mendatar.
     *
     * {@see self::hours()} nama lamanya, dari masa satu kolom selalu satu jam.
     */
    public function columns(): int
    {
        return $this->hours;
    }

    /** Letak baris jam di dasar papan: 'bottom' atau 'none'. */
    public function timeAxis(): string
    {
        return $this->timeAxis;
    }

    /**
     * Ruang di bawah panel terakhir, ikut ada-tidaknya baris jam di situ.
     *
     * Kalau panel terakhir memuat barisnya SENDIRI dan dasar papan juga, ada
     * DUA baris — dan tanpa ruang tambahan keduanya jatuh berdekatan lalu
     * saling menimpa. Ketahuannya waktu membaca hasil cetak papan rantai
     * dingin: tanggalnya terbaca kabur, seolah gambarnya buram, padahal dua
     * baris angka tertulis tujuh piksel berjauhan.
     */
    public function bottomSpace(): float
    {
        $last = $this->panels[count($this->panels) - 1] ?? null;
        $barisPanel = $last !== null && $last['time_axis'];
        $barisDasar = $this->timeAxis === 'bottom';

        if ($barisPanel && $barisDasar) {
            return Geometry::BOTTOM + Geometry::HOUR_ROW;
        }

        return ($barisPanel || $barisDasar) ? Geometry::BOTTOM : Geometry::BOTTOM_BARE;
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

        // Ruang lebih perlu di dua keadaan: legenda panel ini di ATAS, atau
        // legenda panel SEBELUMNYA di bawah — keduanya ditulis di ruang antar
        // panel, dan tanpa ruang itu mereka menimpa barisnya.
        $sebelumnya = $this->panels[$index - 1] ?? null;
        $perlu = $panel['legend'] === 'above'
            || ($sebelumnya !== null && $sebelumnya['legend'] === 'below');

        $gap = $perlu ? max($this->panelGap, 12.0) : $this->panelGap;

        // Panel sebelumnya yang memuat baris jam menulisnya di ruang ini.
        // Tanpa tambahan itu, angka jamnya jatuh di atas kisi panel ini.
        if ($sebelumnya !== null && $sebelumnya['time_axis']) {
            $gap += Geometry::HOUR_ROW;
        }

        return $gap;
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

        return $height + $this->bottomSpace();
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

        // Tiap tingkat boleh berupa nama isian saja, atau objek dengan namanya
        // sendiri. Yang kedua dipakai legenda kiri: "< 20", "20-40", "> 40".
        $levels = [];
        $rawLevels = isset($panel['levels']) && is_array($panel['levels']) ? $panel['levels'] : [];
        foreach ($rawLevels as $value => $level) {
            $fill = is_array($level) ? (string) ($level['fill'] ?? '') : (string) $level;
            if (!in_array($fill, self::FILLS, true)) {
                throw new ValidationException(
                    "Panel ke-{$position}: isian batang '{$fill}' tidak dikenal."
                );
            }
            $levels[(string) $value] = [
                'fill' => $fill,
                'label' => is_array($level) ? (string) ($level['label'] ?? '') : '',
            ];
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
            // Baris jam di bawah panel ini. Mati kecuali diminta: papan yang
            // mengulang jamnya di tiap panel terbaca sebagai kisi, bukan data.
            'time_axis' => isset($panel['time_axis']) && (bool) $panel['time_axis'],
            'grid' => !isset($panel['grid']) || (bool) $panel['grid'],
            'sub_columns' => self::readInt($panel, 'sub_columns', 1, 1, 12),
            'legend' => self::readLegend($panel, $position),
            'gap_before' => isset($panel['gap_before']) && $panel['gap_before'] !== ''
                ? self::readFloat($panel, 'gap_before', 0.0, 0.0, 200.0)
                : null,
            'levels' => $levels,
            'bands' => self::readBands($panel, $position),
            'verticals' => $verticals,
            'references' => $references,
            'series' => $series,
        ];
    }

    /**
     * Pita nilai: "nilai di rentang ini warnanya ini".
     *
     * Satu aturan, dua bacaan — yang membedakan cuma bentuk panelnya. Di
     * baris sel ia mewarnai latar tiap sel menurut nilai sel itu; di panel
     * bersumbu Y ia melukis pita mendatar di latar panel.
     *
     * Rancangan pertama menjadikannya jenis panel tersendiri (`heat`), lalu
     * dibatalkan: pita nilai berguna juga di panel garis — suhu 36–37,5 hijau,
     * di atasnya kuning — dan jenis panel yang cuma menutupi separuh
     * kebutuhannya bukan jenis, cuma kebetulan. Lihat
     * `docs/260930-grafik-jenis-baru.md` §6.3.
     *
     * @param array<string,mixed> $panel
     * @return array<int,array<string,mixed>>
     *
     * @throws ValidationException
     */
    private static function readBands(array $panel, int $position): array
    {
        $mentah = isset($panel['bands']) && is_array($panel['bands']) ? $panel['bands'] : [];
        $bands = [];

        foreach (array_values($mentah) as $i => $band) {
            $urutan = $i + 1;
            if (!is_array($band) || !isset($band['from']) || !is_numeric($band['from'])) {
                throw new ValidationException(
                    "Panel ke-{$position}, pita ke-{$urutan}: 'from' wajib berupa angka."
                );
            }

            $dari = (float) $band['from'];
            $ke = isset($band['to']) && $band['to'] !== '' && $band['to'] !== null
                ? (float) $band['to']
                : null;

            if ($ke !== null && $ke <= $dari) {
                throw new ValidationException(
                    "Panel ke-{$position}, pita ke-{$urutan}: 'to' harus lebih besar dari 'from'."
                );
            }

            // Sumbu Y kedua belum ada. Kuncinya sudah dibaca supaya bentuk
            // spesifikasinya tidak berubah lagi nanti, tapi 'right' ditolak:
            // pita yang diam-diam dilukis di skala yang salah menandai nilai
            // yang tidak pernah terjadi.
            $sumbu = isset($band['axis']) && $band['axis'] !== '' ? (string) $band['axis'] : 'left';
            if ($sumbu !== 'left') {
                throw new ValidationException(
                    "Panel ke-{$position}, pita ke-{$urutan}: sumbu Y kedua belum ada."
                );
            }

            $bands[] = [
                'from' => $dari,
                'to' => $ke,
                'color' => self::readColor($band, 'color', '#e5e7eb', $position, $urutan),
                'label' => isset($band['label']) ? (string) $band['label'] : '',
                'axis' => $sumbu,
            ];
        }

        usort($bands, static function (array $a, array $b): int {
            return $a['from'] <=> $b['from'];
        });

        // Bertumpang tindih ditolak: "nilai 4 warnanya apa" tidak boleh punya
        // dua jawaban. Celah di antaranya boleh — nilai yang tidak masuk pita
        // mana pun memang tidak diwarnai.
        for ($i = 0; $i < count($bands) - 1; $i++) {
            $ke = $bands[$i]['to'];
            if ($ke === null || $ke >= $bands[$i + 1]['from']) {
                throw new ValidationException(
                    "Panel ke-{$position}: pita nilai bertumpang tindih di sekitar "
                    . self::angka($bands[$i + 1]['from']) . '.'
                );
            }
        }

        return $bands;
    }

    /** Angka untuk pesan galat, tanpa nol berlebih di belakang koma. */
    private static function angka(float $value): string
    {
        $teks = number_format($value, 2, '.', '');

        return strpos($teks, '.') === false ? $teks : rtrim(rtrim($teks, '0'), '.');
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
     * Letak legenda — atau null kalau panel ini tidak berlegenda.
     *
     * `true` diterima dan berarti "di atas": itu satu-satunya letak yang ada
     * saat legenda pertama dibuat, dan spesifikasi yang sudah ditulis dengan
     * `true` tidak boleh mendadak kehilangan legendanya.
     *
     * @param array<string,mixed> $panel
     *
     * @throws ValidationException
     */
    /**
     * Batas atas jumlah kolom, ikut satuan sumbunya.
     *
     * Satu angka untuk semua satuan pasti salah di salah satu ujungnya: 48
     * masuk akal untuk jam dan menyesakkan untuk hari, sedangkan 400 masuk
     * akal untuk hari dan tidak berarti apa-apa untuk jam. Batas ini bukan
     * penjaga lebar halaman — itu tugas ringkasan "muat di halaman" — cuma
     * penangkal spesifikasi yang jelas salah ketik.
     */
    private static function maxColumns(XAxis $axis): int
    {
        if ($axis instanceof TimeAxis) {
            $batas = ['hour' => 168, 'day' => 400, 'month' => 240];

            return $batas[$axis->unit()] ?? 168;
        }

        return 400;
    }

    /**
     * Sumbu mendatar papan ini, belum berawal.
     *
     * Bentuk barunya `{"x": {"kind": "time", "unit": "day"}}`. Bentuk lama
     * tanpa kunci `x` sama saja dengan sumbu jam — dan tiap papan yang sudah
     * tersimpan berbentuk begitu.
     *
     * Hanya `time` yang terdaftar. {@see ValueAxis} ada dan diuji, tapi tidak
     * bisa diminta dari spesifikasi: bentuk sumbu berangka belum diputuskan,
     * dan spesifikasi yang telanjur memakainya akan mengikat keputusan itu.
     *
     * @param array<string,mixed> $spec
     *
     * @throws ValidationException
     */
    private static function readAxis(array $spec): XAxis
    {
        $x = isset($spec['x']) && is_array($spec['x']) ? $spec['x'] : [];

        $kind = isset($x['kind']) ? (string) $x['kind'] : 'time';
        if ($kind !== 'time') {
            throw new ValidationException(
                "Jenis sumbu X '{$kind}' belum didukung (yang ada: time)."
            );
        }

        $unit = (string) ($x['unit'] ?? $spec['unit'] ?? 'hour');
        $format = (string) ($x['label_format'] ?? $spec['label_format'] ?? '');

        return TimeAxis::create($unit, $format);
    }

    /**
     * @param array<string,mixed> $spec
     *
     * @throws ValidationException
     */
    private static function readTimeAxis(array $spec): string
    {
        if (!isset($spec['time_axis']) || $spec['time_axis'] === true || $spec['time_axis'] === '') {
            return 'bottom';
        }
        if ($spec['time_axis'] === false) {
            return 'none';
        }

        $axis = (string) $spec['time_axis'];
        if (!in_array($axis, self::TIME_AXES, true)) {
            throw new ValidationException(
                "Letak baris jam '{$axis}' tidak dikenal (pilihannya "
                . implode(', ', self::TIME_AXES) . ').'
            );
        }

        return $axis;
    }

    private static function readLegend(array $panel, int $position): ?string
    {
        if (!isset($panel['legend']) || $panel['legend'] === false || $panel['legend'] === '') {
            return null;
        }
        if ($panel['legend'] === true) {
            return 'above';
        }

        $legend = (string) $panel['legend'];
        if (!in_array($legend, self::LEGENDS, true)) {
            throw new ValidationException(
                "Panel ke-{$position}: letak legenda '{$legend}' tidak dikenal (pilihannya "
                . implode(', ', self::LEGENDS) . ').'
            );
        }

        return $legend;
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
