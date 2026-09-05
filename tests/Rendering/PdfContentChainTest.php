<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Tahap 2a — perender isi PDF bisa dipanggil tanpa memuat view.
 *
 * Inilah sifat yang seluruh Tahap 2 bergantung padanya. `save_document.php`
 * harus merakit HTML yang sama persis dengan yang dicetak, dan selama rantai
 * perendernya tinggal di dalam `views/document/generate.php`, memanggilnya
 * berarti menjalankan seluruh halamannya — dengan `$_GET`-nya, RBAC-nya,
 * query-nya, dan keluarannya.
 *
 * Tes ini tidak me-require view sama sekali. Kalau ia hijau, rantainya memang
 * berdiri sendiri.
 *
 * Kesetaraan keluarannya dibuktikan terpisah, dengan cara yang sama seperti
 * Tahap 1: enam template sungguhan dari basis data, empat varian masing-masing,
 * ditangkap sebelum dan sesudah pemindahan — 24 berkas, 340.414 byte, identik
 * byte per byte. Yang dijaga DI SINI adalah kemandiriannya, karena itu yang bisa
 * hilang diam-diam nanti.
 *
 * Lihat docs/260811-beku-pdf-tiap-tanda-tangan.md §4.
 */
final class PdfContentChainTest extends TestCase
{
    /** PNG 1×1 yang sah — nilai karangan ditolak penyaring tanda tangan. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ'
        . 'AAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @return array<int,array<string,string>> */
    private function configTtd(): array
    {
        return [[
            'id'         => 'ttd_1',
            'mode'       => 'inline',
            'label'      => 'Dokter',
            'ttdModes'   => 'image',
            'nama_field' => 'nama_dokter',
            'posX'       => '50',
            'posY'       => '100',
        ]];
    }

    private function template(): string
    {
        return '<p>Halo {{nama}}.</p>'
            . '<div class="ttd-placeholder" data-ttd="ttd_1" data-label="Dokter"'
            . ' data-nama-field="nama_dokter" data-ttd-modes="image" contenteditable="false">'
            . '<div>satu</div><div>dua</div><div>tiga</div></div>';
    }

    /**
     * @param array<string,string> $ttdValues
     * @param array<string,mixed>  $permintaan
     */
    private function render(array $ttdValues, array $permintaan = []): string
    {
        return renderContentForPdf(
            $this->template(),
            [],
            [],
            $this->configTtd(),
            $ttdValues,
            ['nama' => 'Budi', 'nama_dokter' => 'dr. Sari'],
            [],
            null,
            '12345',
            '678',
            [],
            77,
            $permintaan
        );
    }

    public function testRantainyaAdaTanpaViewDimuat(): void
    {
        // Kalau ini merah, seluruh Tahap 2 tidak bisa dikerjakan: jalur simpan
        // tidak punya cara memanggil perender yang sama.
        foreach ([
            'renderContentForPdf', 'renderFieldForPdf', 'processTabledbRows',
            'executeDynQuery', 'safeDataImg', 'safeSignatureValue',
        ] as $f) {
            self::assertTrue(function_exists($f), "{$f} tidak tersedia di luar view");
        }
    }

    public function testFieldTerisiDariNilaiTersimpan(): void
    {
        $out = $this->render([]);

        // Nilainya dibungkus penanda cetak, bukan disisipkan telanjang — itu yang
        // membedakan keluaran PDF dari keluaran layar.
        self::assertStringContainsString('<span class="f-pdf">Budi</span>', $out);
        self::assertStringNotContainsString('{{nama}}', $out, 'placeholder tidak tergantikan');
    }

    public function testFieldBisaDatangDariNilaiPermintaan(): void
    {
        // Nama penanda tangan diambil lewat $pickPdf, yang jatuh ke nilai
        // permintaan kalau field tersimpannya kosong.
        $out = renderContentForPdf(
            $this->template(),
            [], [], $this->configTtd(), [], ['nama' => 'Budi'],
            [], null, '12345', '678', [], 77,
            ['values' => ['nama_dokter' => 'dr. Dari Permintaan']]
        );

        self::assertStringContainsString('dr. Dari Permintaan', $out);
    }

    /**
     * Slot yang ditandatangani menghasilkan gambarnya; yang kosong tidak.
     *
     * Dua arah sekaligus. Yang pertama saja akan tetap hijau kalau perendernya
     * memasang gambar pada setiap slot.
     */
    public function testSlotBertandaTanganBerbedaDariYangKosong(): void
    {
        $kosong = $this->render([]);
        $terisi = $this->render(['ttd_1' => self::PNG]);

        self::assertNotSame($kosong, $terisi, 'ttd tersimpan tidak mengubah keluaran');
        self::assertStringContainsString('data:image/png;base64,', $terisi);
        self::assertStringNotContainsString('data:image/png;base64,', $kosong);
    }

    /** Nilai tanda tangan yang tidak sah tidak pernah mendarat di keluaran. */
    public function testNilaiTandaTanganPalsuDitolak(): void
    {
        $out = $this->render(['ttd_1' => 'data:image/png;base64,BUKAN-BASE64-SAH']);

        self::assertStringNotContainsString('BUKAN-BASE64-SAH', $out);
    }

    /**
     * Tanpa generator QR, mode verifikasi tidak menjatuhkan perendernya.
     *
     * Pada jalur pembekuan ini bukan soal tampilan: §5 rencananya menetapkan
     * pembekuan yang gagal menggagalkan tanda tangannya juga.
     */
    public function testModeVerifyQrTidakMelemparTanpaGenerator(): void
    {
        $out = $this->render(
            ['ttd_1' => self::PNG],
            ['show_verify_qr' => true, 'verify_doc_id' => 77]
        );

        self::assertIsString($out);
        self::assertNotSame('', $out);
    }
}
