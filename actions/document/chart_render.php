<?php
/**
 * POST _doc_action=chart_render
 *   body: spec=<JSON spesifikasi papan>, values=<JSON nama field → nilai>
 *
 * Gambar sebuah papan grafik, lalu kembalikan SVG-nya beserta skalanya.
 *
 * Response: { success: true, svg, scale, fields }
 *           { success: false, message }  ← spesifikasi yang tidak masuk akal
 *
 * ## Kenapa lewat server, padahal gambarnya untuk layar
 *
 * Karena perender yang sama juga yang mencetak. Menggambar di JavaScript
 * supaya lincah, lalu menggambar lagi di PHP supaya bisa dicetak, berarti dua
 * perender — dan dua perender selalu berpisah diam-diam, lalu yang
 * ditandatangani orang berbeda dari yang tercetak.
 *
 * Yang di peramban cuma menaruh pegangan di atas gambar ini, memakai `scale`
 * yang ikut dikembalikan di sini. Satu sumber angka, dua arah pemakaian.
 *
 * ## Tidak menyentuh basis data
 *
 * Spesifikasi dan angkanya datang dari permintaan, digambar, dikembalikan.
 * Tidak ada dokumen yang dibaca, tidak ada yang ditulis. Karena itu jalur ini
 * tidak perlu tahu dokumen mana yang sedang dibuka — designer memakainya untuk
 * pratinjau template yang belum pernah disimpan.
 *
 * Balasannya JSON, bukan `image/svg+xml`. SVG yang dilayani langsung bisa
 * dibuka sebagai halaman tersendiri, dan halaman itu membawa skrip apa pun
 * yang sempat diselipkan ke dalamnya.
 */

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\SeriesValues;
use Ezdoc\Chart\SvgChartRenderer;
use Ezdoc\Exceptions\ValidationException;

/**
 * Batas ukuran, supaya satu permintaan tidak bisa menyuruh server menggambar
 * papan seukuran apa pun. Angkanya longgar: papan tujuh panel dengan seratus
 * titik per deret masih jauh di bawahnya.
 */
const EZDOC_CHART_MAX_SPEC = 65536;
const EZDOC_CHART_MAX_VALUES = 262144;

$specRaw = (string) ($_POST['spec'] ?? '');
$valuesRaw = (string) ($_POST['values'] ?? '');

if ($specRaw === '') {
    ezdoc_respond_error(t('response.incomplete_parameters', [], 'Incomplete parameters'));
}
if (strlen($specRaw) > EZDOC_CHART_MAX_SPEC || strlen($valuesRaw) > EZDOC_CHART_MAX_VALUES) {
    ezdoc_respond_error(t('response.chart_too_big', [], 'Chart specification or values are too large to draw.'));
}

try {
    $spec = ChartSpec::fromJson($specRaw);
} catch (ValidationException $e) {
    // Pesannya diteruskan apa adanya: ia menyebut panel keberapa yang salah,
    // dan itulah satu-satunya keterangan yang menolong orang di designer.
    ezdoc_respond_error($e->getMessage());
}

$values = [];
if ($valuesRaw !== '') {
    $decoded = json_decode($valuesRaw, true);
    if (is_array($decoded)) {
        $values = $decoded;
    }
}

$renderer = new SvgChartRenderer();

// Bentuk baku tiap deret ikut dikembalikan.
//
// Yang di peramban menuliskannya kembali ke isian tersembunyi, jadi yang
// tersimpan di dokumen selalu bentuk yang sama — urut, tanpa waktu kembar,
// angka satu bentuk. Tanpa ini, dua dokumen beridentik isi bisa menghasilkan
// dua hash berbeda hanya karena urutan mengetiknya berbeda.
$canonical = [];
foreach ($spec->panels() as $panel) {
    /** @var array<int,array<string,mixed>> $series */
    $series = $panel['series'];
    foreach ($series as $one) {
        $name = (string) $one['field'];
        $canonical[$name] = SeriesValues::toJson($values[$name] ?? null);
    }
}

ezdoc_respond_success([
    'svg' => $renderer->render($spec, $values),
    'scale' => $renderer->scale($spec, $values)->export(),
    'fields' => $spec->fieldNames(),
    'values' => $canonical,
]);
