<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use PHPUnit\Framework\TestCase;

/**
 * Form BARU di slot dokumen bertanda tangan ditolak sebagai bentrok, bukan suntingan.
 *
 * Tanpa `_doc_id`, simpan dimaksudkan membuat dokumen baru. Pencarian slot
 * menemukan dokumen yang sudah ada dan mengubahnya jadi edit — lalu, karena
 * dokumen itu sudah ditandatangani, perbandingan field menolaknya dengan
 * "Changed: nik, ttl, nama…". Orangnya tidak mengubah apa pun: ia mengisi form
 * baru dan tidak tahu dokumen itu ada. Jawabannya kini menyebut dokumen yang
 * ditabrak dan slotnya, supaya peramban bisa menawarkan membuka atau membuat
 * versi barunya.
 *
 * Dijalankan ujung ke ujung lewat dispatcher di proses terpisah —
 * tests/fixtures/run_save.php — karena action-nya berakhir dengan `exit`.
 */
final class SlotTakenSignedTest extends TestCase
{
    /** @return array<string,mixed> */
    private function simpan(string $skenario): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite tidak aktif.');
        }

        $keluaran = (string) shell_exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_save.php'),
            escapeshellarg($skenario)
        ));

        $json = json_decode($keluaran, true);
        self::assertIsArray($json, "jawaban action bukan JSON:\n" . substr($keluaran, 0, 800));

        return $json;
    }

    public function testFormBaruMenyebutDokumenYangDitabrak(): void
    {
        $j = $this->simpan('baru-bertanda');

        self::assertFalse($j['success']);
        self::assertSame('slot_taken', $j['remedy'] ?? null);
        self::assertSame(5, $j['existing_doc_id'] ?? null);
        self::assertSame(2, $j['existing_version'] ?? null);
        self::assertSame(['norm' => '1', 'nopen' => '11', 'label' => '-'], $j['matched_slot'] ?? null);
    }

    /**
     * Tidak ada daftar "field yang berubah".
     *
     * Field form baru dibandingkan dengan dokumen yang tidak pernah dibukanya —
     * daftarnya cuma menyesatkan, seperti laporan aslinya.
     */
    public function testFormBaruTidakDilaporkanSebagaiSuntingan(): void
    {
        $j = $this->simpan('baru-bertanda');

        self::assertArrayNotHasKey('changed_fields', $j);
        self::assertStringContainsString('#5', (string) $j['message']);
    }

    /** Dokumen yang memang dibuka tetap dijaga persis seperti sebelumnya. */
    public function testDokumenYangDibukaTetapDitolakSebagaiSuntinganBeku(): void
    {
        $j = $this->simpan('buka-bertanda');

        self::assertFalse($j['success']);
        self::assertSame('new_version', $j['remedy'] ?? null);
        self::assertSame(['nama'], $j['changed_fields'] ?? null);
    }

    /**
     * Slot yang dokumennya belum ditandatangani tidak tersentuh perbaikan ini.
     *
     * Perilakunya — form baru memperbarui dokumen di slot itu — sengaja tidak
     * diubah di sini; ini mengikatnya supaya perubahan berikutnya disengaja.
     */
    public function testSlotBelumBertandaTanganTetapDisimpanSepertiSebelumnya(): void
    {
        $j = $this->simpan('baru-kosong');

        self::assertTrue($j['success'] ?? false, 'simpan ditolak: ' . ($j['message'] ?? ''));
        self::assertSame(5, $j['doc_id'] ?? null);
    }
}
