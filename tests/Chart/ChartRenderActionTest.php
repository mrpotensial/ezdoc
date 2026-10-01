<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use PHPUnit\Framework\TestCase;

/**
 * Jalur yang menggambar untuk peramban.
 *
 * Dipakai dua tempat: designer, untuk melihat bentuk papan yang sedang
 * disusun; dan halaman dokumen, tiap kali orang menaruh atau menggeser titik.
 * Keduanya menerima gambar YANG SAMA dengan yang nanti tercetak, karena
 * perendernya satu.
 *
 * Yang diuji di sini perilakunya lewat dispatcher, bukan sumbernya.
 */
final class ChartRenderActionTest extends TestCase
{
    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function jalankan(array $spec, array $values = []): array
    {
        $keluaran = (string) shell_exec(sprintf(
            'php %s %s %s 2>&1',
            escapeshellarg(dirname(__DIR__) . '/fixtures/run_chart_render.php'),
            escapeshellarg('b64:' . base64_encode((string) json_encode($spec))),
            escapeshellarg('b64:' . base64_encode((string) json_encode($values)))
        ));

        $data = json_decode(trim($keluaran), true);
        self::assertIsArray($data, 'jawabannya bukan JSON: ' . $keluaran);

        return $data;
    }

    /** @return array<string,mixed> */
    private static function spec(): array
    {
        return [
            'hours' => 16,
            'column_width' => 40,
            'start_field' => 'start_time',
            'panels' => [[
                'height' => 200,
                'y' => ['min' => 0, 'max' => 10, 'step' => 1],
                'series' => [['field' => 'dilatation']],
            ]],
        ];
    }

    /** Menggambar, dan menyerahkan skala yang dipakainya menggambar. */
    public function testReturnsDrawingAndTheScaleItUsed(): void
    {
        $data = self::jalankan(self::spec(), [
            'start_time' => '2026-09-29 08:00',
            'dilatation' => '[{"x":"2026-09-29 10:00","y":5}]',
        ]);

        self::assertTrue($data['success']);
        self::assertStringContainsString('cx="144" cy="116"', $data['svg']);
        // JSON tidak membedakan 64 dari 64.0, jadi yang dibandingkan angkanya.
        self::assertSame(64.0, (float) $data['scale']['left']);
        self::assertSame(40.0, (float) $data['scale']['column_width']);
        self::assertSame('2026-09-29 08:00', $data['scale']['start']);
        self::assertSame(['start_time', 'dilatation'], $data['fields']);
    }

    /**
     * Bentuk baku tiap deret ikut dikembalikan.
     *
     * Yang di peramban menuliskannya kembali ke isian tersembunyi, jadi yang
     * tersimpan di dokumen selalu bentuk yang sama — apa pun urutan orang
     * mengetiknya. Tanpa ini, dua dokumen beridentik isi bisa menghasilkan dua
     * hash berbeda.
     */
    public function testReturnsTheCanonicalFormOfEverySeries(): void
    {
        $data = self::jalankan(self::spec(), [
            'start_time' => '2026-09-29 08:00',
            'dilatation' => '[{"x":"2026-09-29 12:00","y":6},{"x":"2026-09-29 10:00","y":5},{"x":"2026-09-29 12:00","y":7}]',
        ]);

        self::assertSame(
            '[{"x":"2026-09-29 10:00","y":5},{"x":"2026-09-29 12:00","y":7}]',
            $data['values']['dilatation']
        );
    }

    /**
     * Spesifikasi yang salah dijawab dengan kalimat yang menyebut letaknya.
     *
     * "Spesifikasi tidak sah" tidak menolong siapa pun pada papan tujuh panel.
     */
    public function testBadSpecIsRefusedWithAUsefulSentence(): void
    {
        $data = self::jalankan([
            'panels' => [
                ['y' => ['min' => 0, 'max' => 10], 'series' => [['field' => 'a']]],
                ['y' => ['min' => 10, 'max' => 0], 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertFalse($data['success']);
        self::assertStringContainsString('ke-2', $data['message'], 'pesannya tidak menyebut panel keberapa');
    }

    /** Papan tanpa angka tetap digambar — kisinya yang dipakai orang mengisi. */
    public function testEmptyBoardStillDraws(): void
    {
        $data = self::jalankan(self::spec());

        self::assertTrue($data['success']);
        self::assertStringContainsString('<svg', $data['svg']);
        self::assertNull($data['scale']['start'], 'awal sumbunya ditebak dari jam dinding');
    }
}
