<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Rendering;

use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Rendering\QrRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * QR disediakan consumer lewat kontrak, bukan lewat nama fungsi global.
 *
 * Sebelumnya kode view memanggil `generateQrForDompdf()` langsung — fungsi milik
 * aplikasi pemakai, didefinisikan di `koneksi.php`. Di dua action ia dijaga
 * `function_exists()`; di tiga tempat di view **tidak**.
 *
 * Yang membuatnya berbahaya bukan QR-nya. Perender isi PDF juga dipanggil jalur
 * pembekuan dokumen, dan aturan rencananya berbunyi: pembekuan gagal berarti
 * tanda tangannya ikut gagal (docs/260811-beku-pdf-tiap-tanda-tangan.md §5).
 * Jadi satu fungsi host yang kebetulan belum termuat berubah dari "QR tidak
 * tampil" menjadi "tidak ada yang bisa menandatangani".
 *
 * Polanya menyalin `PdfRenderer`, yang sudah dipakai library ini sejak lama:
 * kontrak di `src/`, implementasi milik consumer, entri `suggest` di composer.
 */
final class QrRendererContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        require_once dirname(__DIR__, 2) . '/lib/view_helpers.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['EZDOC_QR_RENDERER']);
        Context::resetDefault();
    }

    /**
     * Context sendiri, bukan Context::default().
     *
     * Yang terakhir menuntut $GLOBALS['conn'] milik aplikasi pemakai, dan tes
     * library tidak boleh bergantung padanya. Cara yang sama dipakai
     * ActorNamesTest.
     */
    private function konteks(?QrRenderer $qr): Context
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $provider = new CallableRoleProvider(
            static function () { return false; },
            static function () { return 7; },
            static function () { return []; }
        );

        return new Context(new PdoConnection($pdo), $provider, null, null, null, $qr);
    }

    public function testKontraknyaAdaDanBentuknyaSepertiPdfRenderer(): void
    {
        self::assertTrue(interface_exists(QrRenderer::class));

        $m = new \ReflectionMethod(QrRenderer::class, 'render');
        self::assertSame('string', (string) $m->getReturnType());
        self::assertSame(3, $m->getNumberOfParameters());
        self::assertSame(1, $m->getNumberOfRequiredParameters(), 'ukuran dan margin harus punya bawaan');
    }

    public function testContextMenerimaDanMembawanya(): void
    {
        $qr = new QrPalsu('data:image/png;base64,ZZZ');

        $ctx = $this->konteks(null)->withQr($qr);
        self::assertSame($qr, $ctx->qr);

        // Salinan tak-berubah lain tidak boleh menjatuhkannya di tengah jalan.
        self::assertSame($qr, $ctx->withRoleProvider($ctx->roleProvider)->qr);
        self::assertSame($qr, $ctx->withPdf(null)->qr);
        self::assertSame($qr, $ctx->withActorDirectory(null)->qr);
    }

    public function testPembantuMemakaiKontraknyaLebihDulu(): void
    {
        $qr = new QrPalsu('data:image/png;base64,DARI-KONTRAK');
        Context::setDefault($this->konteks($qr));

        self::assertSame('data:image/png;base64,DARI-KONTRAK', ezdoc_qr_data_uri('apa saja'));
        self::assertSame(['apa saja', 200, 5], $qr->terakhir, 'argumennya harus diteruskan apa adanya');
    }

    /**
     * Tanpa generator, QR-nya hilang — bukan dokumennya.
     *
     * Ini sifat yang paling penting di sini. Kalau ia melempar, pembekuan
     * dokumen ikut gagal, dan bersamanya tanda tangan yang sedang dibuat orang.
     */
    public function testTanpaGeneratorMengembalikanKosongDanTidakMelempar(): void
    {
        Context::setDefault($this->konteks(null));

        // Jalur usang mungkin masih tersedia di lingkungan lain; yang dijaga di
        // sini adalah TIDAK MELEMPAR, dan mengembalikan string.
        $hasil = ezdoc_qr_data_uri('apa saja');
        self::assertIsString($hasil);
    }

    public function testDataKosongTidakPernahSampaiKeGenerator(): void
    {
        $qr = new QrPalsu('tidak boleh terpakai');
        Context::setDefault($this->konteks($qr));

        self::assertSame('', ezdoc_qr_data_uri(''));
        self::assertNull($qr->terakhir, 'generator dipanggil untuk data kosong');
    }

    /**
     * Generator yang melempar tetap tidak menjatuhkan pemanggilnya.
     */
    public function testGeneratorYangMelemparDitelan(): void
    {
        Context::setDefault($this->konteks(new QrMeledak()));

        self::assertIsString(ezdoc_qr_data_uri('apa saja'));
    }

    // ─── Penjaga: kode library berhenti memanggil nama fungsi host ───

    /**
     * @dataProvider berkasLibrary
     */
    public function testTidakAdaLagiPemanggilanLangsung(string $rel): void
    {
        $kode = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        self::assertStringNotContainsString(
            'generateQrForDompdf',
            $kode,
            "{$rel} masih memanggil fungsi milik aplikasi pemakai dengan namanya langsung"
        );
    }

    /** @return array<string,array{string}> */
    public static function berkasLibrary(): array
    {
        return [
            'generate view'    => ['views/document/generate.php'],
            'aksi generate_qr' => ['actions/document/generate_qr.php'],
            'aksi scan code'   => ['actions/document/request_scan_code.php'],
        ];
    }

    /** Jalur usangnya hanya boleh hidup di satu tempat, dan tempatnya dinamai. */
    public function testJalurUsangTinggalDiSatuTempat(): void
    {
        $helpers = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/view_helpers.php');

        self::assertStringContainsString('function_exists(\'generateQrForDompdf\')', $helpers);
        self::assertStringContainsString('USANG', $helpers, 'jalur lamanya harus ditandai usang');
    }
}

/** Generator uji yang mencatat apa yang diminta darinya. */
final class QrPalsu implements QrRenderer
{
    /** @var string */
    private $hasil;

    /** @var array{string,int,int}|null */
    public $terakhir;

    public function __construct(string $hasil)
    {
        $this->hasil = $hasil;
    }

    public function render(string $data, int $size = 200, int $margin = 5): string
    {
        $this->terakhir = [$data, $size, $margin];

        return $this->hasil;
    }
}

/** Generator yang rusak — dipakai membuktikan kegagalannya tidak menular. */
final class QrMeledak implements QrRenderer
{
    public function render(string $data, int $size = 200, int $margin = 5): string
    {
        throw new \RuntimeException('generator QR rusak');
    }
}
