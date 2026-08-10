<?php

declare(strict_types=1);

namespace Ezdoc\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `vendor/` yang rusak tidak boleh mematikan ezdoc.
 *
 * Ini lahir dari kegagalan produksi nyata. `require` ezdoc tidak memuat satu
 * pun paket — hanya `php >= 7.4` dan dua ekstensi — jadi `vendor/` di sini
 * SELALU folder pengembangan, isinya PHPUnit dan PHPStan yang menuntut PHP 8.
 *
 * Satu folder itu ikut tersalin ke server ber-PHP 7, dan Composer me-`require`
 * berkas PHPUnit lewat daftar `files`-nya saat autoload dimuat. Hasilnya
 * ParseError pada sintaks PHP 8 — di berkas PHPUnit, bukan di kode ezdoc — dan
 * setiap halaman jadi 500 kosong.
 *
 * Dua sifat yang dijaga di sini:
 *
 *   1. `Ezdoc\*` tetap bisa dimuat walau Composer gagal total
 *   2. kegagalannya dicatat, tidak ditelan diam-diam
 *
 * Yang pertama mengubah padam total jadi kehilangan sebagian; yang kedua
 * memastikan yang hilang masih bisa dicari. Tanpa nomor dua, nomor satu cuma
 * memindahkan kebingungan ke tempat lain.
 *
 * Dijalankan di proses terpisah dengan `vendor/autoload.php` palsu yang memang
 * dibuat rusak — bukan dengan mencocokkan teks berkasnya. Ketahanan tidak bisa
 * dibuktikan tanpa ada yang benar-benar patah.
 */
final class AutoloadResilienceTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() dimatikan di lingkungan ini.');
        }

        $this->sandbox = sys_get_temp_dir() . '/ezdoc-autoload-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/vendor', 0770, true);
    }

    protected function tearDown(): void
    {
        if ($this->sandbox === '' || !is_dir($this->sandbox)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->sandbox);
    }

    /**
     * Salin autoload.php + lib/polyfill.php + src/ ke sandbox, lalu tanam
     * `vendor/autoload.php` yang isinya rusak seperti berkas PHP 8 di PHP 7.
     */
    private function buildSandbox(string $vendorBody): string
    {
        $root = dirname(__DIR__);

        mkdir($this->sandbox . '/lib', 0770, true);
        copy($root . '/autoload.php', $this->sandbox . '/autoload.php');
        copy($root . '/lib/polyfill.php', $this->sandbox . '/lib/polyfill.php');

        // Satu kelas nyata sudah cukup untuk membuktikan loadernya bekerja.
        mkdir($this->sandbox . '/src', 0770, true);
        file_put_contents(
            $this->sandbox . '/src/PenandaUji.php',
            "<?php\nnamespace Ezdoc;\nclass PenandaUji { public const OK = 'ketemu'; }\n"
        );

        file_put_contents($this->sandbox . '/vendor/autoload.php', $vendorBody);

        $probe = $this->sandbox . '/probe.php';
        file_put_contents($probe, <<<'PHP'
<?php
require __DIR__ . '/autoload.php';
echo class_exists('Ezdoc\PenandaUji') ? \Ezdoc\PenandaUji::OK : 'HILANG';
PHP);

        return $probe;
    }

    /** @return array{keluaran: string, kode: int} */
    private function jalankan(string $probe): array
    {
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1', $out, $code);

        return ['keluaran' => implode("\n", $out), 'kode' => $code];
    }

    /**
     * Kegagalan yang sebenarnya terjadi: berkas vendor memakai sintaks yang
     * tidak bisa diurai runtime-nya.
     */
    public function testEzdocStillLoadsWhenComposerCannotBeParsed(): void
    {
        // Dibuat rusak apa pun versi PHP-nya, supaya tesnya menguji hal yang
        // sama di mesin mana pun.
        $probe = $this->buildSandbox("<?php\nini bukan php yang sah (((\n");

        $hasil = $this->jalankan($probe);

        self::assertStringContainsString('ketemu', $hasil['keluaran'], 'Ezdoc\\* harus tetap termuat');
        self::assertStringNotContainsString('HILANG', $hasil['keluaran']);
    }

    public function testEzdocStillLoadsWhenComposerThrows(): void
    {
        $probe = $this->buildSandbox("<?php\nthrow new RuntimeException('vendor rusak');\n");

        $hasil = $this->jalankan($probe);

        self::assertStringContainsString('ketemu', $hasil['keluaran']);
    }

    /** Yang sehat tetap dimuat — ketahanan tidak boleh berarti Composer diabaikan. */
    public function testAHealthyComposerAutoloadIsStillUsed(): void
    {
        $probe = $this->buildSandbox("<?php\ndefine('VENDOR_TERMUAT', true);\n");

        $hasil = $this->jalankan($probe);

        self::assertStringContainsString('ketemu', $hasil['keluaran']);
        self::assertSame(0, $hasil['kode']);
    }

    /**
     * Yang ditelan diam-diam cuma memindahkan kebingungan. Sebabnya harus
     * tercatat, beserta petunjuk bahwa vendor/ memang tidak semestinya ada di
     * server.
     */
    public function testTheSkippedFailureIsLogged(): void
    {
        $probe = $this->buildSandbox("<?php\nthrow new RuntimeException('vendor rusak');\n");
        $log = $this->sandbox . '/error.log';

        $out = [];
        exec(
            escapeshellarg(PHP_BINARY)
            . ' -d error_log=' . escapeshellarg($log)
            . ' -d log_errors=1'
            . ' ' . escapeshellarg($probe) . ' 2>&1',
            $out
        );

        self::assertFileExists($log, 'kegagalan harus dicatat, bukan ditelan');

        $isi = (string) file_get_contents($log);
        self::assertStringContainsString('[ezdoc]', $isi);
        self::assertStringContainsString('vendor rusak', $isi);
        self::assertStringContainsString('pengembangan', $isi, 'catatannya harus menyebut sebab akarnya');
    }
}
