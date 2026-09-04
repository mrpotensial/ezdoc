<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use PHPUnit\Framework\TestCase;

/**
 * Penyimpanan yang tidak mengubah apa pun tidak ditulis dan tidak dicatat.
 *
 * Dilaporkan dari pemakaian: orang menekan Simpan berulang kali, dan tiap
 * tekanan menulis baris audit untuk perubahan yang tidak ada.
 *
 * Yang diuji di sini keputusannya sendiri, bukan sumbernya. Aturan ini gampang
 * salah dengan cara yang tidak kelihatan dari membaca kode: dua penyandian JSON
 * yang isinya sama bisa berbeda urutan kuncinya, dan perbandingan teks akan
 * menyebutnya perubahan — melewatkan justru kasus yang paling sering terjadi.
 *
 * Arah sebaliknya jauh lebih berbahaya: satu perubahan yang tidak terdeteksi
 * berarti simpanan yang hilang diam-diam. Karena itu tiap kolom yang ditulis
 * UPDATE punya kasusnya sendiri di bawah.
 */
final class SaveUnchangedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Berkasnya sebuah action; gerbang ini memuat fungsinya saja.
        if (!defined('EZDOC_SAVE_SELFTEST')) {
            define('EZDOC_SAVE_SELFTEST', true);
        }
        require_once dirname(__DIR__, 2) . '/actions/document/save_document.php';
    }

    /** @return array<string,mixed> */
    private function baris(): array
    {
        return [
            'title'             => 'Discharge Planning — 12345/678',
            'norm'              => '12345',
            'nopen'             => '678',
            'label'             => '-',
            'floating_elements' => null,
            'field_values'      => '{"nama":"Budi","umur":"40"}',
            'signature_values'  => '{"ttd1":"data:image/png;base64,AAA"}',
        ];
    }

    public function testSamaPersisBerartiTidakAdaPerubahan(): void
    {
        self::assertTrue(ezdoc_save_is_unchanged($this->baris(), $this->baris()));
    }

    /**
     * Kasus yang melahirkan perbandingan sebagai data, bukan teks.
     *
     * json_encode() menulis kunci sesuai urutan array-nya, dan urutan itu datang
     * dari urutan field di POST. Formulir yang sama bisa mengirimnya berbeda.
     */
    public function testUrutanKunciJsonBukanPerubahan(): void
    {
        $sesudah = $this->baris();
        $sesudah['field_values'] = '{"umur":"40","nama":"Budi"}';

        self::assertTrue(ezdoc_save_is_unchanged($this->baris(), $sesudah));
    }

    public function testNilaiFieldYangBerubahTerdeteksi(): void
    {
        $sesudah = $this->baris();
        $sesudah['field_values'] = '{"nama":"Budi","umur":"41"}';

        self::assertFalse(ezdoc_save_is_unchanged($this->baris(), $sesudah));
    }

    public function testFieldBaruTerdeteksi(): void
    {
        $sesudah = $this->baris();
        $sesudah['field_values'] = '{"nama":"Budi","umur":"40","alamat":"Jl. Mawar"}';

        self::assertFalse(ezdoc_save_is_unchanged($this->baris(), $sesudah));
    }

    /**
     * Menandatangani SELALU terlihat sebagai perubahan.
     *
     * Kalau ini merah, tanda tangan bisa ditelan diam-diam oleh jalur lewat-tulis
     * — kegagalan paling mahal yang bisa dihasilkan berkas ini.
     */
    public function testTandaTanganBaruTerdeteksi(): void
    {
        $sesudah = $this->baris();
        $sesudah['signature_values'] = '{"ttd1":"data:image/png;base64,AAA","ttd2":"data:image/png;base64,BBB"}';

        self::assertFalse(ezdoc_save_is_unchanged($this->baris(), $sesudah));
    }

    public function testTandaTanganYangDitukarTerdeteksi(): void
    {
        $sesudah = $this->baris();
        $sesudah['signature_values'] = '{"ttd1":"data:image/png;base64,ZZZ"}';

        self::assertFalse(ezdoc_save_is_unchanged($this->baris(), $sesudah));
    }

    /**
     * @dataProvider kolomTeks
     */
    public function testKolomTeksYangBerubahTerdeteksi(string $kolom, string $baru): void
    {
        $sesudah = $this->baris();
        $sesudah[$kolom] = $baru;

        self::assertFalse(
            ezdoc_save_is_unchanged($this->baris(), $sesudah),
            "perubahan pada {$kolom} tidak terdeteksi — simpanan akan hilang diam-diam"
        );
    }

    /** @return array<string,array{string,string}> */
    public static function kolomTeks(): array
    {
        return [
            'title'             => ['title', 'Judul lain'],
            'norm'              => ['norm', '99999'],
            'nopen'             => ['nopen', '111'],
            'label'             => ['label', 'revisi'],
            'floating_elements' => ['floating_elements', '[{"id":"logo"}]'],
        ];
    }

    /** null dan string kosong menggambarkan keadaan yang sama: tidak ada override. */
    public function testNullDanKosongBukanPerubahan(): void
    {
        $sebelum = $this->baris();
        $sebelum['floating_elements'] = null;

        $sesudah = $this->baris();
        $sesudah['floating_elements'] = '';

        self::assertTrue(ezdoc_save_is_unchanged($sebelum, $sesudah));
    }

    /**
     * JSON rusak dibandingkan sebagai teks, bukan dianggap sama.
     *
     * Dua nilai yang sama-sama gagal diurai bukan berarti isinya sama, dan
     * menganggapnya sama akan melewatkan tulisan yang memperbaiki barisnya.
     */
    public function testJsonRusakDibandingkanSebagaiTeks(): void
    {
        $sebelum = $this->baris();
        $sebelum['field_values'] = '{rusak';

        $sesudah = $this->baris();
        $sesudah['field_values'] = '{rusak juga';

        self::assertFalse(ezdoc_save_is_unchanged($sebelum, $sesudah));

        $sesudah['field_values'] = '{rusak';
        self::assertTrue(ezdoc_save_is_unchanged($sebelum, $sesudah));
    }

    /**
     * Keputusannya benar-benar tersambung ke tiga tulisan yang menghasilkan derau.
     *
     * Fungsi murni yang benar tapi tidak dipakai adalah kegagalan yang paling
     * mudah lolos: tesnya hijau, perilakunya tidak berubah sedikit pun.
     */
    public function testKeputusannyaTersambungKeKetigaTulisannya(): void
    {
        $kode = '';
        foreach (token_get_all((string) file_get_contents(
            dirname(__DIR__, 2) . '/actions/document/save_document.php'
        )) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        self::assertStringContainsString(
            'ezdoc_save_is_unchanged($beforeRow,',
            $kode,
            'aturannya tidak dipanggil — fungsi murni yang benar tapi menganggur'
        );

        // 1. barisnya tidak ditulis ulang
        self::assertStringContainsString(
            'if ($isEdit && $isiSama) {',
            $kode,
            'UPDATE tetap berjalan walau tidak ada yang berubah'
        );

        // 2. tidak ada baris audit untuk perubahan yang tidak ada
        $posAudit = strpos($kode, 'ezdoc_audit_log($isEdit');
        self::assertNotFalse($posAudit, 'pencatatan audit hilang — premis tes ini perlu ditinjau');
        self::assertStringContainsString(
            'if (!$isiSama) {',
            substr($kode, max(0, $posAudit - 200), min($posAudit, 200)),
            'audit masih ditulis untuk penyimpanan yang tidak mengubah apa pun'
        );

        // 3. hash tidak dihitung ulang — hook-nya menulis tiap kali dipanggil
        $posHash = strpos($kode, 'doc_verify_compute_and_store_hash($rawDb');
        self::assertNotFalse($posHash, 'hook hash hilang — premis tes ini perlu ditinjau');
        self::assertStringContainsString(
            'if ($isiSama) {',
            substr($kode, max(0, $posHash - 400), min($posHash, 400)),
            'hash tetap dihitung ulang — derainya cuma pindah ke baris dokumennya'
        );
    }
}
