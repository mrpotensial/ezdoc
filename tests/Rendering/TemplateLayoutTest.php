<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Tahap 2b — keputusan tata letak diturunkan tanpa memuat view.
 *
 * Jalur pembekuan harus merakit PDF yang sama persis dengan yang dicetak, dari
 * `save_document.php`. Selama derivasi ini tinggal di dalam
 * `views/document/generate.php`, memanggilnya berarti menjalankan seluruh
 * halamannya — query-nya, gerbang aksesnya, keluarannya.
 *
 * Kesetaraan dengan perilaku lama dibuktikan di luar suite, dengan cara yang
 * sama seperti tahap sebelumnya: seluruh template sungguhan di basis data
 * pemasangan ini (19 template, 10.313.018 byte hasil derivasi) ditangkap sebelum
 * dan sesudah pemindahan, lalu dibandingkan byte per byte. Yang dijaga DI SINI
 * adalah sifat yang bisa hilang diam-diam nanti: ia berdiri sendiri, dan
 * bentuknya tetap.
 */
final class TemplateLayoutTest extends TestCase
{
    /** @return array<string,mixed> */
    private function template(array $ganti = []): array
    {
        return $ganti + [
            'template_html'     => '<p>Halo {{nama}}.</p>',
            'config_ttd'        => '[]',
            'config_header'     => '{}',
            'floating_elements' => null,
            'doc_scope'         => 'patient',
        ];
    }

    public function testAdaTanpaViewDimuat(): void
    {
        self::assertTrue(
            function_exists('ezdoc_template_layout'),
            'derivasi tata letak tidak tersedia di luar view — jalur pembekuan tidak bisa merakit apa pun'
        );
    }

    /** Semua kunci yang dipakai perakit ada, dan tidak berkurang diam-diam. */
    public function testBentukKeluarannya(): void
    {
        $hasil = ezdoc_template_layout($this->template());

        foreach ([
            'templateHtml', 'configTtdRaw', 'configHeader', 'templateDocScope',
            'isGeneralDoc', 'hasTtdPlaceholders', 'configTtd', 'logos', 'logoSizes',
            'paperSizes', 'paperSize', 'orientation', 'paperDim', 'padding',
            'padTop', 'padRight', 'padBottom', 'padLeft', 'layoutMode',
        ] as $kunci) {
            self::assertArrayHasKey($kunci, $hasil, "kunci '{$kunci}' hilang dari hasil derivasi");
        }
    }

    public function testBawaanKertasDanPadding(): void
    {
        $h = ezdoc_template_layout($this->template());

        self::assertSame(['width' => 210, 'height' => 297], $h['paperDim'], 'bawaannya harus A4 potret');
        self::assertSame('portrait', $h['orientation']);
        self::assertSame(20, $h['padTop']);
        self::assertSame(20, $h['padLeft']);
        self::assertSame('continuous', $h['layoutMode']);
    }

    /**
     * Lanskap menukar sisi, bukan sekadar menandai orientasinya.
     *
     * Kalau penukarannya hilang, dokumen lanskap dirender di atas kertas potret
     * dan isinya terpotong di sisi kanan — tanpa galat apa pun.
     */
    public function testLanskapMenukarSisiKertas(): void
    {
        $h = ezdoc_template_layout($this->template([
            'config_header' => json_encode(['paperSize' => 'A4', 'orientation' => 'landscape']),
        ]));

        self::assertSame(['width' => 297, 'height' => 210], $h['paperDim']);
    }

    public function testUkuranKertasTakDikenalJatuhKeA4(): void
    {
        $h = ezdoc_template_layout($this->template([
            'config_header' => json_encode(['paperSize' => 'Origami']),
        ]));

        self::assertSame('A4', $h['paperSize']);
        self::assertSame(['width' => 210, 'height' => 297], $h['paperDim']);
    }

    public function testKertasKustomMemakaiUkurannyaSendiri(): void
    {
        $h = ezdoc_template_layout($this->template([
            'config_header' => json_encode([
                'paperSize' => 'Custom', 'customWidth' => 100, 'customHeight' => 150,
            ]),
        ]));

        self::assertSame(['width' => 100, 'height' => 150], $h['paperDim']);
    }

    /**
     * Mode tata letak yang tidak dikenal jatuh ke `continuous`.
     *
     * Nilai asing pernah datang dari template yang disunting tangan. Tanpa
     * penjagaan ini, halamannya dirender dengan mode yang tidak ada aturannya.
     */
    public function testModeTataLetakAsingJatuhKeContinuous(): void
    {
        $h = ezdoc_template_layout($this->template([
            'config_header' => json_encode(['layoutMode' => 'zigzag']),
        ]));

        self::assertSame('continuous', $h['layoutMode']);
    }

    /** Konfigurasi ttd dibaca dari placeholder kalau templatenya memakainya. */
    public function testKonfigTtdDiuraiDariPlaceholder(): void
    {
        $h = ezdoc_template_layout($this->template([
            'template_html' => '<div class="ttd-placeholder" data-ttd="ttd_9"'
                . ' data-label="Dokter" data-nama-field="nama_dpjp"'
                . ' data-ttd-modes="image,qr"></div>',
        ]));

        self::assertTrue($h['hasTtdPlaceholders']);
        self::assertCount(1, $h['configTtd']);
        self::assertSame('ttd_9', $h['configTtd'][0]['id']);
        self::assertSame('nama_dpjp', $h['configTtd'][0]['nama_field']);
        self::assertSame('image,qr', $h['configTtd'][0]['ttd_modes']);
    }

    /** Tanpa placeholder, format lama dipakai apa adanya. */
    public function testTanpaPlaceholderMemakaiFormatLama(): void
    {
        $h = ezdoc_template_layout($this->template([
            'config_ttd' => json_encode([['id' => 'ttd_lama', 'label' => 'Pasien']]),
        ]));

        self::assertFalse($h['hasTtdPlaceholders']);
        self::assertSame('ttd_lama', $h['configTtd'][0]['id']);
    }

    /** JSON rusak tidak boleh menjatuhkan halamannya. */
    public function testKonfigurasiRusakTidakMelempar(): void
    {
        $h = ezdoc_template_layout($this->template([
            'config_ttd'    => '{rusak',
            'config_header' => 'bukan json',
        ]));

        self::assertSame([], $h['configTtdRaw']);
        self::assertSame([], $h['configHeader']);
        self::assertSame(['width' => 210, 'height' => 297], $h['paperDim']);
    }

    /** Ia tidak boleh menyentuh permintaan yang sedang berjalan. */
    public function testTidakMembacaPermintaan(): void
    {
        $sumber = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/pdf_compose.php');

        $awal = strpos($sumber, 'function ezdoc_template_layout(');
        self::assertNotFalse($awal, 'fungsinya hilang — premis tes ini perlu ditinjau');

        $kode = '';
        $dalam = 0;
        $mulai = false;
        foreach (token_get_all('<?php ' . substr($sumber, $awal)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $teks = is_array($t) ? $t[1] : $t;
            $kode .= $teks;
            if ($teks === '{') { $dalam++; $mulai = true; }
            elseif ($teks === '}') { $dalam--; if ($mulai && $dalam === 0) { break; } }
        }

        foreach (['$_POST', '$_GET', '$_REQUEST', '$_SESSION', '$_SERVER'] as $global) {
            self::assertSame(0, substr_count($kode, $global), "derivasi tata letak membaca {$global}");
        }
    }
}
