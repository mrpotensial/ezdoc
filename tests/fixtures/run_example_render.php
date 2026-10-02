<?php
/**
 * Render satu contoh dengan perender yang mencetak dokumen, lalu cetak HTML-nya.
 *
 *   php run_example_render.php <nama-folder>
 *
 * Di proses sendiri karena perakit dokumen memuat separuh pustaka dan memasang
 * state global (locale, baris tabel siap pakai). Tanpa sambungan database:
 * contoh memang tidak boleh menyentuhnya.
 *
 * Dipakai tests/UI/AllExamplesTest.php.
 */

$slug = (string) ($argv[1] ?? '');
$akar = dirname(__DIR__, 2);

require $akar . '/bootstrap.php';

$contoh = \Ezdoc\Template\Example::load($slug);
if ($contoh === null) {
    fwrite(STDERR, 'contoh tidak ditemukan: ' . $slug . "\n");
    exit(1);
}

if (function_exists('ezdoc_set_document_locale')) {
    ezdoc_set_document_locale('id');
}

// Baris tabelnya dari berkas, bukan dari kueri: contoh yang tabelnya kosong
// tidak menunjukkan apa pun tentang fitur tabel.
$GLOBALS['ezdocTableRowsReady'] = $contoh['table_rows'];

echo ezdoc_document_pdf_html([
    'template'   => $contoh,
    'doc_id'     => 0,
    'fields'     => $contoh['sample_values'],
    'ttd_values' => [],
    'conn'       => null,
]);
