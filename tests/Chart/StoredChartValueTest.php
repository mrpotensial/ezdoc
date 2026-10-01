<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use PHPUnit\Framework\TestCase;

/**
 * Angka yang diletakkan di kisi benar-benar ikut tersimpan.
 *
 * Jalur penyimpanan dokumen menyusun daftar field yang akan disimpan dari tiga
 * sumber: penanda `{{...}}` di HTML template, nama field tanda tangan, dan
 * `data-qr`. **Deret grafik bukan satu pun dari ketiganya** — namanya hidup di
 * dalam spesifikasi papan.
 *
 * Tanpa penjagaan ini, seluruh fitur tampak bekerja sampai titik terakhir:
 * titiknya muncul, gambarnya terbarui, dokumennya bilang "tersimpan" — lalu
 * angkanya hilang, dan hilangnya baru ketahuan saat dokumennya dibuka lagi.
 * Ditemukan saat memeriksa jalur simpan, bukan saat memakai.
 */
final class StoredChartValueTest extends TestCase
{
    /** @return array{jawaban:array<string,mixed>,tersimpan:array<string,mixed>} */
    private static function simpan(): array
    {
        $keluaran = (string) shell_exec(sprintf(
            'php %s grafik 2>&1',
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_save.php')
        ));

        $bagian = explode("\n---\n", $keluaran);
        self::assertCount(2, $bagian, 'keluaran fixture tidak seperti yang diharapkan: ' . $keluaran);

        $jawaban = json_decode(trim($bagian[0]), true);
        $tersimpan = json_decode(trim($bagian[1]), true);
        self::assertIsArray($jawaban, $keluaran);
        self::assertIsArray($tersimpan, $keluaran);

        return ['jawaban' => $jawaban, 'tersimpan' => $tersimpan];
    }

    /** Deret grafik masuk ke `field_values`, sama seperti isian lain. */
    public function testSeriesValuesReachTheDocument(): void
    {
        ['jawaban' => $jawaban, 'tersimpan' => $tersimpan] = self::simpan();

        self::assertTrue($jawaban['success'], 'penyimpanannya gagal: ' . ($jawaban['message'] ?? ''));
        self::assertSame(
            '[{"x":"2026-09-29 09:00","y":144}]',
            $tersimpan['pantau_djj'] ?? null,
            'angka yang diletakkan di kisi tidak ikut tersimpan'
        );
    }

    /** Field jam mulai papan ikut, walau ia juga bukan penanda `{{...}}`. */
    public function testStartFieldIsSavedToo(): void
    {
        ['tersimpan' => $tersimpan] = self::simpan();

        self::assertSame('2026-09-29 08:00', $tersimpan['pantau_mulai'] ?? null);
    }

    /** Isian biasa di dokumen yang sama tidak terganggu. */
    public function testOrdinaryFieldsStillSaved(): void
    {
        ['tersimpan' => $tersimpan] = self::simpan();

        self::assertSame('Ani', $tersimpan['nama'] ?? null);
    }

    /**
     * Penolongnya mengumpulkan nama dari spesifikasi, bukan dari `{{...}}`.
     *
     * Diperiksa terpisah supaya kalau yang gagal jalur simpannya, letaknya
     * langsung ketahuan.
     */
    public function testHelperCollectsNamesFromTheSpec(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/chart_helpers.php';

        $spec = json_encode([
            'start_field' => 'mulai',
            'panels' => [
                ['series' => [['field' => 'a'], ['field' => 'b']]],
                ['series' => [['field' => 'a'], ['field' => 'c']]],
            ],
        ]);
        $html = '<p>{{lain}}</p><div class="chart-placeholder" data-chart="c" data-chart-spec="'
            . htmlspecialchars((string) $spec, ENT_QUOTES, 'UTF-8') . '"><span>x</span></div>';

        self::assertSame(['mulai', 'a', 'b', 'c'], ezdoc_chart_field_names($html));
        self::assertSame([], ezdoc_chart_field_names('<p>{{lain}}</p>'));
    }
}
