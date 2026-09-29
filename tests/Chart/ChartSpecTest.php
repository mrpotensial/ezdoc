<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Chart;

use Ezdoc\Chart\ChartSpec;
use Ezdoc\Chart\Geometry;
use Ezdoc\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Bentuk papan grafik: yang diterima, yang ditolak, dan yang dihitung sendiri.
 *
 * Spesifikasi ini ditulis orang di designer lalu tersimpan di template. Salah
 * ketik di sini tidak boleh berakhir sebagai gambar kosong di dokumen yang
 * sudah ditandatangani — harus berhenti lebih awal, dengan kalimat yang
 * menyebut panel keberapa.
 */
final class ChartSpecTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function minimal(): array
    {
        return ['panels' => [['series' => [['field' => 'value']]]]];
    }

    /**
     * Spesifikasi terkecil yang sah cuma menyebut satu nama field.
     *
     * Sisanya bawaan. Orang yang menaruh grafik pertama kalinya tidak sedang
     * memilih lebar kolom.
     */
    public function testSmallestSpecWorks(): void
    {
        $spec = ChartSpec::fromArray(self::minimal());

        self::assertSame(16, $spec->hours());
        self::assertSame(40.0, $spec->columnWidth());
        self::assertSame(['value'], $spec->fieldNames());

        $panel = $spec->panels()[0];
        self::assertSame('line', $panel['type']);
        self::assertSame(0.0, $panel['y_min']);
        self::assertSame(10.0, $panel['y_max']);
        self::assertSame('point+line', $panel['series'][0]['draw']);
    }

    /**
     * Lebar bawaan mengikuti isinya.
     *
     * Papan lebar dengan kisi menggantung di tengah terbaca sebagai salah
     * gambar, dan orang akan menambalnya dengan menebak angka lebar.
     */
    public function testDefaultWidthFollowsContents(): void
    {
        $spec = ChartSpec::fromArray(['hours' => 10, 'column_width' => 30] + self::minimal());

        self::assertSame((int) round(Geometry::LEFT + (10 * 30) + Geometry::RIGHT), $spec->width());
    }

    /**
     * Tinggi papan dihitung, tidak disetel.
     *
     * Tinggi yang diketik sendiri dan tinggi panel yang dijumlahkan akan
     * berpisah pada perubahan berikutnya, dan yang menang selalu yang salah.
     */
    public function testHeightIsComputedFromPanels(): void
    {
        $spec = ChartSpec::fromArray([
            'panels' => [
                ['height' => 100, 'series' => [['field' => 'a']]],
                ['height' => 200, 'series' => [['field' => 'b']]],
            ],
        ]);

        self::assertSame(
            Geometry::TOP + 100 + Geometry::PANEL_GAP + 200 + Geometry::BOTTOM,
            $spec->height()
        );
    }

    /** Nama field dikumpulkan sekali, termasuk field jam mulai. */
    public function testFieldNamesAreCollectedOnce(): void
    {
        $spec = ChartSpec::fromArray([
            'start_field' => 'start_time',
            'panels' => [
                ['series' => [['field' => 'a'], ['field' => 'b']]],
                ['series' => [['field' => 'a']]],
            ],
        ]);

        self::assertSame(['start_time', 'a', 'b'], $spec->fieldNames());
    }

    /**
     * Bentuk yang tidak masuk akal ditolak, dengan menyebut letaknya.
     *
     * "Spesifikasi tidak sah" tidak menolong siapa pun pada papan tujuh panel.
     *
     * @param array<string,mixed> $spec
     * @dataProvider brokenSpecs
     */
    public function testBrokenSpecsAreRejected(array $spec, string $mentions): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($mentions, '/') . '/i');

        ChartSpec::fromArray($spec);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function brokenSpecs(): array
    {
        return [
            'tanpa panel' => [['panels' => []], 'tanpa satu panel'],
            'panel tanpa deret' => [['panels' => [[]]], 'ke-1'],
            'nama field ngawur' => [
                ['panels' => [['series' => [['field' => '2 nilai']]]]],
                'nama field tidak sah',
            ],
            'jenis panel belum ada' => [
                ['panels' => [['type' => 'bar', 'series' => [['field' => 'a']]]]],
                'berjenis',
            ],
            'sumbu y terbalik' => [
                ['panels' => [['y' => ['min' => 10, 'max' => 0], 'series' => [['field' => 'a']]]]],
                'max harus di atas min',
            ],
            'kisi terlalu rapat' => [
                ['panels' => [['y' => ['min' => 0, 'max' => 100, 'step' => 0.01], 'series' => [['field' => 'a']]]]],
                'terlalu rapat',
            ],
            'warna bukan heksa' => [
                ['panels' => [['series' => [['field' => 'a', 'color' => 'merah']]]]],
                'warna harus',
            ],
            'acuan bukan dua angka' => [
                ['panels' => [['references' => [['from' => [0], 'to' => [1, 2]]], 'series' => [['field' => 'a']]]]],
                'dua angka',
            ],
            'jam di luar jangkauan' => [
                ['hours' => 99] + ['panels' => [['series' => [['field' => 'a']]]]],
                'jangkauan',
            ],
            'jam bukan angka' => [
                ['hours' => 'enam belas'] + ['panels' => [['series' => [['field' => 'a']]]]],
                'harus angka',
            ],
            'field mulai ngawur' => [
                ['start_field' => '1 jam'] + ['panels' => [['series' => [['field' => 'a']]]]],
                'field mulai',
            ],
        ];
    }

    /** JSON yang bukan objek ditolak sebagai spesifikasi. */
    public function testNonObjectJsonIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        ChartSpec::fromJson('"cuma teks"');
    }

    /** JSON dan array menghasilkan papan yang sama. */
    public function testJsonMatchesArray(): void
    {
        $array = ChartSpec::fromArray(self::minimal());
        $json = ChartSpec::fromJson((string) json_encode(self::minimal()));

        self::assertEquals($array, $json);
    }
}
