<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Kerangka HTML untuk PDF tidak boleh berubah tanpa ada yang menyadarinya.
 *
 * Bentuknya *golden master* (Michael Feathers, **Working Effectively with Legacy
 * Code**): keluaran yang dianggap benar disimpan apa adanya, dan tiap perubahan
 * dibandingkan dengannya. Sama seperti `toMatchSnapshot()` di Jest atau
 * ApprovalTests — yang membedakan cuma ukurannya, bukan gagasannya.
 *
 * ## Kenapa ini ada
 *
 * Pemindahan perakitan keluar dari view (2026-08-12) dibuktikan dengan menangkap
 * HTML sepuluh dokumen sungguhan di server, sebelum dan sesudah. Hasilnya identik
 * byte per byte — tapi pembuktian itu **sekali pakai**: berkasnya 2,4 MB berisi
 * isi dokumen pasien, jadi ia tidak bisa ikut ter-commit, dan tidak ada yang
 * menjaganya bulan depan.
 *
 * Berkas ini menggantinya dengan yang permanen. Datanya **sintetis** —
 * "Pasien Uji", tanggal tetap, gambar tanda tangan satu piksel — jadi ia boleh
 * hidup di repositori. Yang dijaga bukan datanya melainkan **bentuknya**: CSS,
 * `@page`, urutan blok, cara nilai disisipkan.
 *
 * Menyembunyikan data sensitif sebelum menyimpan snapshot adalah praktik baku;
 * pustaka Verify menyebutnya *scrubber*. Di sini caranya lebih sederhana: data
 * sensitifnya tidak pernah dipakai sejak awal.
 *
 * ## Kalau tes ini gagal
 *
 * Baca dulu bedanya. Ada dua kemungkinan, dan keduanya bukan "perbaiki
 * snapshot-nya":
 *
 *   1. **Perubahannya tidak disengaja** — perbaiki kodenya
 *   2. **Perubahannya disengaja** — perbarui snapshot, dan jelaskan bedanya di
 *      pesan commit supaya peninjau bisa memeriksanya
 *
 * Memperbarui snapshot:
 *
 *     EZDOC_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter PdfComposeSnapshot
 *
 * Snapshot yang diperbarui tanpa dibaca sama saja dengan tidak punya tes.
 */
final class PdfComposeSnapshotTest extends TestCase
{
    /** Gambar 1×1 piksel — cukup untuk membuktikan jalurnya, tanpa membawa data. */
    private const TTD_PIKSEL = 'data:image/png;base64,'
        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg==';

    /** @var mixed */
    private $penerjemahAsli;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/pdf_compose.php';
        require_once dirname(__DIR__, 2) . '/lib/signature_image.php';

        // Penerjemah global dikendalikan, bukan diwarisi.
        //
        // Ini bukan kerapian. Versi pertama berkas ini lulus kalau dijalankan
        // sendirian dan GAGAL di suite penuh: tes lain memasang penerjemah, dan
        // label bawaan "Signature" berubah jadi "Tanda Tangan". Snapshot yang
        // hasilnya bergantung pada tes mana yang jalan lebih dulu tidak menjaga
        // apa pun — ia cuma bergiliran salah.
        $this->penerjemahAsli = $GLOBALS['translator'] ?? null;
        unset($GLOBALS['translator']);
    }

    protected function tearDown(): void
    {
        if ($this->penerjemahAsli === null) {
            unset($GLOBALS['translator']);

            return;
        }

        $GLOBALS['translator'] = $this->penerjemahAsli;
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__) . '/fixtures/pdf-compose';
    }

    /**
     * Bandingkan dengan snapshot, atau tulis ulang kalau diminta.
     */
    private function assertMatchesSnapshot(string $nama, string $hasil): void
    {
        $dir = $this->fixtureDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/' . $nama . '.html';
        $perbarui = getenv('EZDOC_UPDATE_SNAPSHOTS') === '1';

        if ($perbarui || !is_file($path)) {
            file_put_contents($path, $hasil);
            if (!$perbarui) {
                // Snapshot yang lahir sendiri diam-diam akan mengabadikan
                // keluaran yang mungkin sudah salah. Lulus, tapi katakan.
                self::markTestIncomplete(
                    'Snapshot ' . $nama . ' baru dibuat. BACA berkasnya sebelum di-commit — '
                    . 'ia menjadi definisi "benar" untuk seterusnya.'
                );
            }

            return;
        }

        self::assertSame(
            (string) file_get_contents($path),
            $hasil,
            "Kerangka PDF berubah untuk '{$nama}'.\n"
            . "Kalau disengaja: EZDOC_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter PdfComposeSnapshot\n"
            . 'Kalau tidak: itu perubahan yang tidak diminta siapa pun.'
        );
    }

    /**
     * Masukan tetap. Setiap nilai dipilih supaya bisa dikenali di keluarannya —
     * angka yang berbeda-beda untuk tiap margin, misalnya, supaya tertukarnya
     * padTop dan padBottom langsung terlihat.
     *
     * @param array<string,mixed> $ganti
     * @return array<string,mixed>
     */
    private function masukan(array $ganti = []): array
    {
        return $ganti + [
            'template'             => ['nama_template' => 'Surat Uji <Bentuk> & "Kutip"'],
            'doc_id'               => 4321,
            'paper'                => ['width' => 210, 'height' => 297],
            // Empat angka berbeda: tertukarnya salah satu langsung kelihatan.
            'pad_top'              => 21,
            'pad_right'            => 22,
            'pad_bottom'           => 23,
            'pad_left'             => 24,
            'content'              => '<p>Isi dokumen uji.</p>',
            'config_ttd'           => [],
            'ttd_values'           => [],
            'pdf_fields'           => [],
            'has_ttd_placeholders' => true,
            'conn'                 => null,
        ];
    }

    public function testKerangkaMinimal(): void
    {
        $this->assertMatchesSnapshot('minimal', ezdoc_compose_pdf_html($this->masukan()));
    }

    /**
     * Blok tanda tangan format lama — cabang yang hanya menyala kalau template
     * TIDAK memakai penanda TTD tapi punya konfigurasinya.
     *
     * Tiga slot sengaja: satu bergambar, satu tanpa gambar (harus jadi kotak
     * kosong setinggi 50px), satu tanpa nama (harus jadi garis titik-titik).
     */
    public function testKerangkaDenganTandaTanganFormatLama(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan([
            'has_ttd_placeholders' => false,
            'config_ttd'           => [
                ['id' => 'ttd_dokter', 'label' => 'Dokter Pemeriksa', 'nama_field' => 'nama_dokter'],
                ['id' => 'ttd_pasien', 'label' => 'Pasien', 'nama_field' => 'nama_pasien'],
                ['id' => 'ttd_saksi'],
            ],
            'ttd_values' => [
                'ttd_dokter' => self::TTD_PIKSEL,
                'ttd_pasien' => '',
            ],
            'pdf_fields' => [
                'nama_dokter' => 'dr. Uji Coba',
                'nama_pasien' => 'Pasien Uji',
            ],
        ]));

        $this->assertMatchesSnapshot('ttd-format-lama', $hasil);
    }

    /**
     * Ukuran kertas dan margin lain — yang dijaga di sini interpolasi angkanya
     * ke dalam CSS, termasuk hasil pengurangan untuk lebar `.page` dan
     * `.content`.
     */
    public function testKerangkaKertasDanMarginLain(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan([
            'paper'      => ['width' => 215, 'height' => 330],   // F4
            'pad_top'    => 10,
            'pad_right'  => 15,
            'pad_bottom' => 12.5,
            'pad_left'   => 30,
        ]));

        $this->assertMatchesSnapshot('kertas-f4', $hasil);
    }

    /**
     * Jalur terjemahan, dikunci tersendiri.
     *
     * Label bawaan `Signature` diganti terjemahannya begitu penerjemah dipasang.
     * Itu perilaku yang benar, dan justru karena benar ia perlu snapshot sendiri
     * — kalau tidak, satu-satunya cara ia terlihat adalah dengan mencemari tes
     * lain, persis yang terjadi saat berkas ini pertama ditulis.
     */
    public function testKerangkaDenganPenerjemahIndonesia(): void
    {
        $GLOBALS['translator'] = \Ezdoc\UI\Translator::forView('generate', 'id');

        $hasil = ezdoc_compose_pdf_html($this->masukan([
            'has_ttd_placeholders' => false,
            'config_ttd'           => [['id' => 'ttd_x']],   // tanpa label → pakai bawaan
            'ttd_values'           => [],
            'pdf_fields'           => [],
        ]));

        self::assertStringContainsString('Tanda Tangan', $hasil);
        $this->assertMatchesSnapshot('ttd-terjemahan-id', $hasil);
    }

    // ─── Sifat yang dijaga terpisah dari snapshot ───
    //
    // Snapshot menangkap SEMUA perubahan, termasuk yang tidak penting. Yang di
    // bawah ini menyatakan mana yang benar-benar tidak boleh berubah, supaya
    // kalau snapshot diperbarui sembarangan, ini yang menahan.

    public function testJudulDokumenDilolosiHtml(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan([
            'template' => ['nama_template' => '<script>alert(1)</script>'],
        ]));

        self::assertStringNotContainsString('<script>alert(1)</script>', $hasil);
        self::assertStringContainsString('&lt;script&gt;', $hasil);
    }

    /** Isi dokumen disisipkan APA ADANYA — ia sudah HTML, bukan teks. */
    public function testIsiDokumenTidakDilolosi(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan([
            'content' => '<table class="dyntable-rendered"><tr><td>sel</td></tr></table>',
        ]));

        self::assertStringContainsString('<table class="dyntable-rendered">', $hasil);
    }

    /**
     * Ukuran kertas dan margin harus benar-benar sampai ke `@page`. Tanpa ini,
     * kesalahan penyisipan cuma terlihat sebagai dokumen yang tata letaknya
     * meleset — gejala yang jauh dari sebabnya.
     */
    public function testUkuranKertasSampaiKeAturanPage(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan());

        self::assertStringContainsString('size: 210mm 297mm;', $hasil);
        self::assertStringContainsString('margin: 21mm 22mm 23mm 24mm;', $hasil);
        // .page = kertas dikurangi margin kiri-kanan dan atas-bawah.
        self::assertStringContainsString('width: 164mm;', $hasil);   // 210 - 24 - 22
        self::assertStringContainsString('min-height: 253mm;', $hasil); // 297 - 21 - 23
    }

    public function testDokumennyaUtuhDariAwalSampaiAkhir(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan());

        self::assertStringStartsWith('<!DOCTYPE html>', $hasil);
        self::assertStringEndsWith('</div></body></html>', $hasil);
        self::assertTrue(mb_check_encoding($hasil, 'UTF-8'));
    }

    /**
     * Penanda tangan tanpa nama harus muncul sebagai garis titik-titik, bukan
     * kurung kosong. Kurung kosong di dokumen cetak terbaca sebagai kelalaian;
     * garis titik-titik terbaca sebagai tempat tanda tangan.
     */
    public function testPenandaTanganTanpaNamaMendapatGarisTitik(): void
    {
        $hasil = ezdoc_compose_pdf_html($this->masukan([
            'has_ttd_placeholders' => false,
            'config_ttd'           => [['id' => 'ttd_x', 'label' => 'Saksi']],
            'ttd_values'           => [],
            'pdf_fields'           => [],
        ]));

        self::assertStringContainsString('(..................)', $hasil);
    }
}
