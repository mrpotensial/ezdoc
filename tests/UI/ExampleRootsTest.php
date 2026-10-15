<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use Ezdoc\Template\Example;
use PHPUnit\Framework\TestCase;

/**
 * Folder contoh milik aplikasi pemakai (`EZDOC_EXAMPLES_PATHS`).
 *
 * Template yang memanggil skema basis data pemakai bukan milik pustaka yang
 * netral, dan tidak boleh hilang saat pustakanya diganti versi baru.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ExampleRootsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ezdoc_contoh_host_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/contoh-host', 0777, true);
        file_put_contents($this->dir . '/contoh-host/template.html', '<p>{{a}}</p>');
        file_put_contents($this->dir . '/contoh-host/example.json', json_encode(['nama_template' => 'Contoh Host', 'doc_scope' => 'general']));

        // Slug yang sama dengan bawaan: bawaan harus menang.
        mkdir($this->dir . '/surat-keterangan-tindakan', 0777, true);
        file_put_contents($this->dir . '/surat-keterangan-tindakan/template.html', '<p>PALSU</p>');
        file_put_contents($this->dir . '/surat-keterangan-tindakan/example.json', json_encode(['nama_template' => 'PALSU']));
    }

    protected function tearDown(): void
    {
        foreach (['contoh-host', 'surat-keterangan-tindakan'] as $slug) {
            @unlink($this->dir . '/' . $slug . '/template.html');
            @unlink($this->dir . '/' . $slug . '/example.json');
            @rmdir($this->dir . '/' . $slug);
        }
        @rmdir($this->dir);
    }

    public function testTanpaKonstantaHanyaBawaan(): void
    {
        self::assertSame([Example::root()], Example::roots());
        self::assertNull(Example::load('contoh-host'));
    }

    public function testFolderPemakaiIkutDimuatDanDikatalog(): void
    {
        define('EZDOC_EXAMPLES_PATHS', [$this->dir, '/tidak/ada/sama/sekali']);

        self::assertSame([Example::root(), $this->dir], Example::roots(), 'folder yang tidak ada dilewati');
        $muat = Example::load('contoh-host');
        self::assertNotNull($muat);
        self::assertSame('Contoh Host', $muat['nama_template']);
        self::assertArrayHasKey('contoh-host', Example::catalogue());
    }

    public function testSlugBawaanTidakBisaDitimpa(): void
    {
        define('EZDOC_EXAMPLES_PATHS', json_encode([$this->dir]));

        $muat = Example::load('surat-keterangan-tindakan');
        self::assertNotNull($muat);
        self::assertNotSame('PALSU', $muat['nama_template']);
        self::assertNotSame('PALSU', Example::catalogue()['surat-keterangan-tindakan']['name']);
    }

    public function testJalanKeluarFolderTetapDitolak(): void
    {
        define('EZDOC_EXAMPLES_PATHS', [$this->dir]);

        self::assertNull(Example::load('../contoh-host'));
        self::assertNull(Example::load('contoh-host/../..'));
    }
}
