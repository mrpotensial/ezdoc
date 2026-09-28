<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Satu-satunya tindakan di alur contoh: menyimpannya jadi template.
 *
 * Diminta pemakai, sesudah usulan halaman "tempel HTML lalu impor" ditolak dua
 * kali: "aku ingin langsung bisa lihat hasil ai tanpa aksi, aksi ku hanya lihat
 * hasil aja, jika aku merasa cocok simpan ke template".
 *
 * Jadi rancangan hidup sebagai BERKAS lebih dulu — bisa dibuka di halaman
 * contoh, di halaman pembuatan dokumen, dan di designer tanpa menyentuh
 * database. Tindakannya satu tombol. Yang dijaga di sini adalah tombol itu:
 * bahwa ia benar-benar menyimpan, bahwa ia tidak pernah menimpa apa pun, dan
 * bahwa template hasilnya masih mencetak yang tadi dilihat orangnya.
 */
final class SimpanContohJadiTemplateTest extends TestCase
{
    private const AKSI = 'actions/template/save_contoh_as_template.php';

    private static function baca(string $berkas): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $berkas);
    }

    /**
     * Ambil satu baris JSON dari keluaran mentah.
     *
     * PHP bisa menyelipkan peringatannya di antara — dan peringatan yang tidak
     * ada hubungannya jangan sampai terbaca sebagai "tesnya gagal".
     *
     * @return array<mixed>|null
     */
    private static function json(string $blok): ?array
    {
        foreach (explode("\n", $blok) as $baris) {
            $baris = trim($baris);
            if ($baris === '' || $baris[0] !== '{') {
                continue;
            }
            $urai = json_decode($baris, true);
            if (is_array($urai)) {
                return $urai;
            }
        }

        return null;
    }

    /**
     * Jalankan permintaan simpannya di proses sendiri.
     *
     * Action-nya mengakhiri permintaan dengan `exit`, jadi tidak bisa dipanggil
     * dari dalam PHPUnit.
     *
     * @return array{jawaban: array<string, mixed>, templates: list<array<string, mixed>>, audit: list<array<string, mixed>>, render: array<string, mixed>}
     */
    private static function jalankan(string $skenario, string $slug = 'skor-bishop'): array
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_simpan_contoh.php'),
            escapeshellarg($skenario),
            escapeshellarg($slug)
        ), $baris);
        $keluaran = implode("\n", $baris);

        $bagian = explode("\n---\n", $keluaran, 2);
        self::assertCount(2, $bagian, 'fixture tidak mencetak isi tabelnya: ' . $keluaran);

        $render = [];
        $sisa = $bagian[1];
        if (strpos($sisa, "\n---render---\n") !== false) {
            [$sisa, $mentahRender] = explode("\n---render---\n", $sisa, 2);
            $render = (array) self::json($mentahRender);
        }

        $jawaban = self::json($bagian[0]);
        $isi = self::json($sisa);
        self::assertIsArray($jawaban, 'jawaban bukan JSON: ' . $bagian[0]);
        self::assertIsArray($isi, 'isi tabel bukan JSON: ' . $sisa);

        return [
            'jawaban'   => $jawaban,
            'templates' => $isi['templates'],
            'audit'     => $isi['audit'],
            'render'    => $render,
        ];
    }

    // ── Yang terjadi kalau tombolnya ditekan ─────────────────────────────

    /** Satu tekan, satu template baru — dengan isi contohnya, bukan isi kosong. */
    public function testMenyimpanContohJadiTemplateBaru(): void
    {
        $h = self::jalankan('baru');

        self::assertTrue($h['jawaban']['success'] ?? false, json_encode($h['jawaban']));
        self::assertSame(1, $h['jawaban']['id']);
        self::assertCount(1, $h['templates']);

        $t = $h['templates'][0];
        self::assertSame('Lembar Skor Bishop', $t['name'], 'kata "(contoh)" ikut tersimpan');
        self::assertSame('patient', $t['scope']);
        self::assertSame(7, (int) $t['owner_id'], 'yang menyimpan tidak tercatat sebagai pemilik');
        self::assertSame(1, (int) $t['is_active']);
        self::assertSame(0, (int) $t['is_locked'], 'template baru tidak boleh langsung terkunci');
        self::assertSame(1, (int) $t['version']);
        self::assertSame(1, (int) $t['is_current']);

        // Isinya benar-benar isi contohnya, lengkap dengan fiturnya.
        self::assertStringContainsString('{{total_bishop}}', (string) $t['content']);
        self::assertStringContainsString('data-formula=', (string) $t['content'], 'field hitung tidak ikut');
        self::assertStringContainsString('conditional-section', (string) $t['content'], 'blok bersyarat tidak ikut');
        self::assertStringContainsString('ttd-placeholder', (string) $t['content'], 'tanda tangan tidak ikut');
        self::assertNotEmpty($t['layout_config'], 'pengaturan kertas dan kop tidak ikut');
        self::assertNotEmpty($t['verify_config'], 'pengaturan halaman verifikasi tidak ikut');
    }

    /** Tercatat di log audit, dengan nama contohnya. */
    public function testTercatatDiLogAudit(): void
    {
        $h = self::jalankan('baru');

        self::assertCount(1, $h['audit']);
        self::assertSame('template.created_from_contoh', $h['audit'][0]['event_type']);
        self::assertSame(1, (int) $h['audit'][0]['template_id']);
        self::assertSame(7, (int) $h['audit'][0]['actor_id']);
    }

    /** Nama sendiri dipakai apa adanya. */
    public function testNamaDariYangMenekanDipakai(): void
    {
        $h = self::jalankan('nama-sendiri');

        self::assertTrue($h['jawaban']['success'] ?? false);
        self::assertSame('Lembar Skor Bishop VK', $h['templates'][0]['name']);
    }

    // ── Yang TIDAK boleh terjadi ─────────────────────────────────────────

    /**
     * Nama yang sudah dipakai ditolak, dan yang lama tidak tersentuh.
     *
     * Ini jaring pengaman terpentingnya. Menimpa berarti menghapus pekerjaan
     * orang lain dengan satu tekan tombol di halaman yang judulnya "contoh".
     */
    public function testNamaYangSudahDipakaiDitolakDanYangLamaUtuh(): void
    {
        $h = self::jalankan('nama-dipakai');

        self::assertFalse($h['jawaban']['success'] ?? true, 'nama kembar diterima');
        self::assertStringContainsString('Lembar Skor Bishop', (string) $h['jawaban']['message']);

        self::assertCount(1, $h['templates'], 'ada template tambahan padahal permintaannya ditolak');
        self::assertSame(
            '<p>punya orang lain</p>',
            $h['templates'][0]['content'],
            'isi template orang lain tertimpa'
        );
    }

    /** Nama yang templatenya sudah dihapus boleh dipakai lagi. */
    public function testNamaMilikTemplateTerhapusBolehDipakai(): void
    {
        $h = self::jalankan('nama-terhapus');

        self::assertTrue($h['jawaban']['success'] ?? false, json_encode($h['jawaban']));
        self::assertCount(2, $h['templates']);
    }

    /** Yang tidak berhak merancang template tidak bisa menyimpan. */
    public function testTanpaHakTidakAdaYangTersimpan(): void
    {
        $h = self::jalankan('tanpa-hak');

        self::assertFalse($h['jawaban']['success'] ?? true, 'tersimpan tanpa hak');
        self::assertSame([], $h['templates']);
        self::assertSame('authz.denied', $h['audit'][0]['event_type'] ?? null);
    }

    /** Nama folder contoh yang tidak ada tidak menghasilkan template kosong. */
    public function testContohYangTidakAdaTidakMenyimpanApaPun(): void
    {
        $h = self::jalankan('tak-ada', 'tidak-ada-folder-ini');

        self::assertFalse($h['jawaban']['success'] ?? true);
        self::assertSame([], $h['templates']);
    }

    /**
     * Berkas aksinya tidak punya cabang ubah atau hapus sama sekali.
     *
     * Bukan gaya penulisan: selama tidak ada UPDATE di dalamnya, tidak ada
     * urutan permintaan apa pun — termasuk yang mengirim `id` sendiri — yang
     * bisa mengubah template yang sudah ada lewat pintu ini.
     */
    public function testBerkasAksinyaHanyaMenyisipkan(): void
    {
        $isi = self::baca(self::AKSI);

        self::assertStringContainsString('INSERT INTO ezdoc_templates', $isi);

        // Yang dicari perintahnya, bukan katanya: docblock berkas itu sendiri
        // menyebut "tidak ada cabang UPDATE" dan itu bukan pelanggaran.
        foreach ([
            'UPDATE'      => '/\bUPDATE\s+[`"\']?ezdoc_/i',
            'DELETE'      => '/\bDELETE\s+FROM/i',
            'REPLACE'     => '/\bREPLACE\s+INTO/i',
            'TRUNCATE'    => '/\bTRUNCATE\b/i',
            'ON DUPLICATE'=> '/\bON\s+DUPLICATE\s+KEY/i',
        ] as $nama => $pola) {
            self::assertSame(
                0,
                preg_match($pola, $isi),
                'ada perintah ' . $nama . ' di jalur simpan contoh'
            );
        }
        self::assertStringContainsString('ezdoc_require_manage_templates(', $isi, 'tidak ada penjaga hak');
    }

    // ── Hasilnya masih dokumen yang tadi dilihat ─────────────────────────

    /**
     * Stempelnya ikut, tepat di tempatnya, dan cuma satu.
     *
     * Elemen yang diletakkan bebas disimpan di kolom terpisah dan penandanya
     * dibuang dari isi HTML — sama seperti simpan dari designer. Dua hal bisa
     * salah: kolomnya tidak diisi (stempelnya hilang dari template hasil
     * simpan), atau penandanya ditinggal di isi HTML sekaligus disalin ke
     * kolomnya (stempelnya tercetak dua kali).
     */
    public function testStempelIkutTersimpanDanTercetakSekali(): void
    {
        $h = self::jalankan('render', 'surat-keterangan-tindakan');

        self::assertTrue($h['jawaban']['success'] ?? false, json_encode($h['jawaban']));

        $t = $h['templates'][0];
        self::assertNotEmpty($t['floating_elements'], 'elemen mengapung tidak tersimpan di kolomnya');
        self::assertStringNotContainsString(
            'floating-only',
            (string) $t['content'],
            'penanda elemen mengapung ditinggal di isi HTML — akan tercetak dua kali'
        );

        $mengapung = $h['render']['mengapung'] ?? null;
        self::assertIsArray($mengapung, 'template hasil simpan gagal dirender: ' . json_encode($h['render']));
        self::assertCount(1, $mengapung, 'stempelnya tidak tepat satu: ' . json_encode($mengapung));
        self::assertStringContainsString('left:520px', $mengapung[0], 'posisi stempel berubah');
        self::assertStringContainsString('top:900px', $mengapung[0], 'posisi stempel berubah');
    }

    // ── Tombolnya ────────────────────────────────────────────────────────

    /** Dikenali dispatcher, kalau tidak permintaannya jatuh ke halaman biasa. */
    public function testDikenaliDispatcher(): void
    {
        self::assertStringContainsString(
            "'save_contoh'",
            self::baca('actions/_dispatcher.php'),
            'aksinya tidak terdaftar'
        );
    }

    /**
     * Tombolnya ada di ketiga tempat contohnya bisa dilihat.
     *
     * Kalau tombolnya cuma di halaman penjelasan, yang sedang melihat hasilnya
     * di halaman pembuatan dokumen harus pindah halaman dulu untuk menyimpan —
     * dan yang sudah diubahnya di designer hilang di jalan.
     */
    public function testTombolnyaAdaDiTiapTempatContohBisaDilihat(): void
    {
        $halaman = self::baca('views/contoh.php');
        self::assertStringContainsString('ezSimpanTemplate', $halaman);
        self::assertStringContainsString("'action', 'save_contoh'", $halaman);

        $buat = self::baca('views/document/generate.php');
        self::assertStringContainsString('ezContohJadiTemplate', $buat);
        self::assertStringContainsString("data.append('action', 'save_contoh')", $buat);

        $designer = self::baca('views/document/designer.php');
        self::assertStringContainsString('ezContohJadiTemplate', $designer);
    }

    /**
     * Tombolnya tidak ditawarkan kepada yang pasti ditolak sisi server.
     *
     * Halaman penjelasannya sendiri sudah tertutup untuk mereka; halaman
     * pembuatan dokumen terbuka untuk siapa saja yang membuat dokumen, jadi
     * di sana tombolnya yang harus hilang. Diperiksa di halaman yang benar-benar
     * dirender, bukan di kodenya.
     */
    public function testTombolnyaDisembunyikanDariYangTidakBerhak(): void
    {
        $dengan = self::halamanBuatDokumen('ya');
        $tanpa = self::halamanBuatDokumen('tidak');

        self::assertSame(
            1,
            substr_count($dengan, 'id="ezContohJadiTemplate"'),
            'yang berhak tidak diberi tombolnya'
        );
        self::assertSame(
            0,
            substr_count($tanpa, 'id="ezContohJadiTemplate"'),
            'tombolnya ditawarkan kepada yang tidak berhak'
        );

        // Dan di designer penjaganya ada di markup yang sama.
        $designer = self::baca('views/document/designer.php');
        $pos = strpos($designer, 'id="ezContohJadiTemplate"');
        self::assertNotFalse($pos);
        self::assertStringContainsString(
            'ezdoc_can_manage_templates',
            substr($designer, max(0, $pos - 400), 400),
            'designer: tombolnya tidak dijaga hak'
        );
    }

    /** Halaman pembuatan dokumen dalam mode contoh, dirender apa adanya. */
    private static function halamanBuatDokumen(string $boleh): string
    {
        $baris = [];
        exec(sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/jalankan_contoh_generate.php'),
            escapeshellarg('contoh:skor-bishop'),
            escapeshellarg($boleh)
        ), $baris);

        return implode("\n", $baris);
    }

    /**
     * Di designer yang tersimpan adalah yang di layar, bukan berkas contohnya.
     *
     * Kalau tombolnya di designer memanggil aksi simpan-contoh, editan orangnya
     * hilang tanpa ada yang memberitahu: yang tersimpan isi berkasnya. Jadi di
     * sana jalurnya simpan resmi designer dengan nomor template dikosongkan.
     */
    public function testDiDesignerYangTersimpanIsiLayarnya(): void
    {
        $isi = self::baca('views/document/designer.php');

        self::assertStringNotContainsString(
            'save_contoh',
            $isi,
            'designer memakai aksi simpan-contoh — editan di layar akan hilang'
        );
        self::assertSame(
            1,
            preg_match('/jadiTemplate[\s\S]{0,1200}?saveTemplate\(\)/', $isi),
            'tombolnya tidak memakai jalur simpan resmi designer'
        );
        // Dan kaitnya harus berhenti membelokkan simpan ke peramban selama
        // tombol itu bekerja, kalau tidak yang tersimpan cuma draf lokal.
        self::assertSame(
            1,
            preg_match("/if \(jadiTemplateBerjalan\) \{ return; \}\s*\n\s*e\.preventDefault\(\);/", $isi),
            'kait simpan-ke-peramban tidak dilewati saat menyimpan jadi template'
        );
    }

    /** Kalimat jawabannya ada di kedua bahasa. */
    public function testKalimatnyaAdaDiKeduaBahasa(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            $common = require dirname(__DIR__, 2) . '/lang/' . $bahasa . '/common.php';
            foreach (['contoh_not_found', 'template_name_taken', 'template_created_from_contoh'] as $kunci) {
                self::assertArrayHasKey($kunci, $common['response'], $bahasa . ': ' . $kunci);
            }
        }
    }
}
