<?php

/**
 * Mengganti penanda papan grafik di HTML template dengan gambarnya.
 *
 * Dua tempat memanggilnya dan keduanya harus menghasilkan gambar yang sama:
 * halaman dokumen (`views/document/generate.php`) dan perakit PDF
 * (`lib/pdf_compose.php`, termasuk saat membekukan dokumen yang ditandatangani).
 *
 * Bedanya hanya bungkusnya:
 *
 *   layar → `<svg>` disisipkan langsung, ditambah isian tersembunyi per deret
 *           dan lapisan pegangan supaya bisa diisi di atas gambarnya sendiri
 *   cetak → `<img src="data:image/svg+xml;base64,…">`
 *
 * Bungkusnya berbeda karena Dompdf **mengabaikan `<svg>` yang ditulis langsung
 * di HTML** — diuji pada Dompdf 3.1.5 + php-svg-lib, lihat
 * `docs/260929-grafik-terisi.md` §2. Lewat `<img>` dengan data URI, gambarnya
 * utuh dan tetap vektor.
 *
 * ## Angkanya field biasa
 *
 * Tiap deret adalah satu field dokumen. Karena itu grafik tidak butuh
 * mekanisme sendiri untuk apa pun: pembekuan sesudah tanda tangan, penyalinan
 * ke versi baru, dan cakupan tanda tangan sudah berlaku untuknya.
 *
 * PHP 7.4+ compatible.
 */

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SeriesValues;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;

/**
 * Penanda satu papan di HTML template.
 *
 * Isinya sengaja tidak boleh mengandung `<div>` — pencocokan berhenti di
 * `</div>` pertama, dan penanda yang membungkus div lain akan terpotong di
 * tengah. Designer menuliskannya dengan `<span>` di dalamnya.
 */
const EZDOC_CHART_PATTERN = '/<div([^>]*class="[^"]*chart-placeholder[^"]*"[^>]*)>.*?<\/div>/s';

if (!function_exists('ezdoc_chart_attr')) {
    /**
     * Ambil satu atribut dari potongan tag pembuka.
     */
    function ezdoc_chart_attr(string $tag, string $name, string $default = ''): string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m) !== 1) {
            return $default;
        }

        // Nilainya sudah ter-escape sebagai HTML saat ditulis designer. JSON
        // penuh tanda kutip, jadi tanpa pembalikan ini spesifikasinya tidak
        // pernah bisa di-decode.
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ezdoc_chart_esc')) {
    /**
     * Teks yang masuk ke nilai atribut HTML.
     *
     * Tidak memakai `h()` milik view: berkas ini juga dipanggil dari perakit
     * PDF, yang bisa berjalan tanpa satu pun view pernah dimuat.
     */
    function ezdoc_chart_esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ezdoc_chart_spec_from_tag')) {
    /**
     * Baca spesifikasi dari tag penanda — null kalau tidak masuk akal.
     */
    function ezdoc_chart_spec_from_tag(string $tag): ?ChartSpec
    {
        $raw = ezdoc_chart_attr($tag, 'data-chart-spec');
        if ($raw === '') {
            return null;
        }

        try {
            return ChartSpec::fromJson($raw);
        } catch (ValidationException $e) {
            return null;
        }
    }
}

if (!function_exists('ezdoc_chart_values')) {
    /**
     * Kumpulkan nilai field yang dipakai sebuah papan.
     *
     * @param callable(string):string $reader pembaca nilai field mentah
     * @return array<string,mixed>
     */
    function ezdoc_chart_values(ChartSpec $spec, callable $reader): array
    {
        $values = [];
        foreach ($spec->fieldNames() as $name) {
            $values[$name] = $reader($name);
        }

        return $values;
    }
}

if (!function_exists('ezdoc_chart_field_names')) {
    /**
     * Semua nama field yang dipakai papan-papan grafik di sebuah HTML template.
     *
     * Jalur penyimpanan dokumen menyusun daftar field yang akan disimpan dari
     * penanda `{{...}}`, nama field tanda tangan, dan `data-qr`. Deret grafik
     * bukan satu pun dari ketiganya — tanpa fungsi ini, angka yang sudah
     * diletakkan orang di kisi tidak ikut tersimpan, dan hilangnya tidak
     * terlihat sampai dokumennya dibuka lagi.
     *
     * @return array<int,string>
     */
    function ezdoc_chart_field_names(string $html): array
    {
        if (strpos($html, 'chart-placeholder') === false) {
            return [];
        }

        $names = [];
        if (preg_match_all(EZDOC_CHART_PATTERN, $html, $m) !== false) {
            foreach ($m[1] as $tag) {
                $spec = ezdoc_chart_spec_from_tag($tag);
                if ($spec !== null) {
                    foreach ($spec->fieldNames() as $name) {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }
}

if (!function_exists('ezdoc_chart_render_screen')) {
    /**
     * Ganti semua penanda papan dengan gambar yang bisa diisi.
     *
     * Isian tersembunyi per deret ikut ditulis: itulah yang membuat angkanya
     * tersimpan lewat jalur penyimpanan dokumen yang sudah ada, tanpa satu pun
     * perlakuan khusus.
     *
     * @param callable(string):string $reader pembaca nilai field mentah
     */
    function ezdoc_chart_render_screen(string $html, callable $reader): string
    {
        if (strpos($html, 'chart-placeholder') === false) {
            return $html;
        }

        $renderer = new SvgChartRenderer();

        $out = preg_replace_callback(EZDOC_CHART_PATTERN, static function (array $m) use ($renderer, $reader): string {
            $tag = $m[1];
            $spec = ezdoc_chart_spec_from_tag($tag);
            if ($spec === null) {
                // Kotak yang menyebut dirinya rusak, bukan ruang kosong.
                // Grafik yang hilang tanpa jejak terbaca sebagai template yang
                // memang tidak punya grafik.
                return '<div class="ez-chart-broken" style="border:1px dashed #b91c1c;color:#b91c1c;'
                    . 'padding:8px;font-size:11px">Spesifikasi grafik tidak terbaca</div>';
            }

            $id = ezdoc_chart_attr($tag, 'data-chart', 'chart');
            $values = ezdoc_chart_values($spec, $reader);

            // Satu isian tersembunyi per deret. Namanya nama field, jadi
            // penyimpanan dokumen memperlakukannya persis seperti isian lain —
            // termasuk pembekuan sesudah tanda tangan.
            $inputs = '';
            foreach ($spec->panels() as $panel) {
                /** @var array<int,array<string,mixed>> $series */
                $series = $panel['series'];
                foreach ($series as $one) {
                    $name = (string) $one['field'];
                    $inputs .= '<input type="hidden" name="' . ezdoc_chart_esc($name)
                        . '" data-chart-series="' . ezdoc_chart_esc($name)
                        . '" value="' . ezdoc_chart_esc(SeriesValues::toJson($values[$name] ?? null, $spec->axis())) . '">';
                }
            }

            // Spesifikasi dan skalanya ikut dibawa ke peramban: lapisan
            // pegangan memakai skala yang sama persis dengan yang dipakai
            // menggambar, dan meminta gambar baru dengan spesifikasi yang sama.
            return '<div class="ez-chart" data-chart="' . ezdoc_chart_esc($id)
                . '" data-chart-spec="' . ezdoc_chart_esc(ezdoc_chart_attr($tag, 'data-chart-spec'))
                . '" data-chart-scale="' . ezdoc_chart_esc(
                    (string) json_encode($renderer->scale($spec, $values)->export())
                )
                . '" style="position:relative;display:inline-block;line-height:0">'
                . $renderer->render($spec, $values)
                . $inputs
                . '</div>';
        }, $html);

        return $out === null ? $html : $out;
    }
}

if (!function_exists('ezdoc_chart_render_pdf')) {
    /**
     * Ganti semua penanda papan dengan gambar untuk dicetak.
     *
     * @param callable(string):string $reader pembaca nilai field mentah
     */
    function ezdoc_chart_render_pdf(string $html, callable $reader): string
    {
        if (strpos($html, 'chart-placeholder') === false) {
            return $html;
        }

        $renderer = new SvgChartRenderer();

        $out = preg_replace_callback(EZDOC_CHART_PATTERN, static function (array $m) use ($renderer, $reader): string {
            $spec = ezdoc_chart_spec_from_tag($m[1]);
            if ($spec === null) {
                return '';
            }

            $svg = $renderer->render($spec, ezdoc_chart_values($spec, $reader));

            return '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
                . '" width="' . (int) $spec->width() . '" height="' . (int) round($spec->height()) . '" alt="">';
        }, $html);

        return $out === null ? $html : $out;
    }
}
