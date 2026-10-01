<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

/**
 * Sumbu X berangka — untuk grafik sebar dan sejenisnya.
 *
 * ## Belum dipakai, dan itu disengaja
 *
 * Kelas ini **tidak terdaftar** di {@see ChartSpec}: tidak ada spesifikasi
 * yang bisa memintanya, dan penyunting designer tidak menawarkannya. Ia ada
 * untuk satu alasan saja — membuktikan bahwa {@see XAxis} benar-benar muat
 * untuk sesuatu yang bukan waktu.
 *
 * Antarmuka yang dirancang dari satu contoh biasanya tidak muat untuk contoh
 * kedua: bentuknya ditebak dari yang ada, lalu yang berikutnya tidak masuk.
 * Tempelan yang sekadar memanggil metodenya juga tidak membuktikan apa-apa,
 * karena tempelan bisa dibentuk mengikuti antarmuka apa pun. Karena itu
 * `ValueAxisBoardTest` menggambar papan dua panel sungguhan lewat kelas ini,
 * ujung ke ujung. Kalau uji itu memaksa antarmukanya berubah, ketahuannya
 * sekarang — bukan sesudah delapan fase menumpuk di atasnya.
 *
 * Ia berbeda dari waktu justru di tempat yang penting: tidak punya field jam
 * mulai, tidak menyentuh kalender, labelnya angka, dan awalnya angka pula.
 *
 * Lihat `docs/260930-grafik-jenis-baru.md` §5 dan §10 butir 4.
 *
 * PHP 7.4+ compatible.
 */
final class ValueAxis implements XAxis
{
    /** @var float Lebar satu kolom dalam satuan nilai. */
    private $step;

    /** @var int Angka di belakang koma yang disimpan. */
    private $decimals;

    /** @var float|null */
    private $origin;

    private function __construct(float $step, int $decimals, ?float $origin)
    {
        $this->step = $step;
        $this->decimals = $decimals;
        $this->origin = $origin;
    }

    public static function create(float $step = 1.0, int $decimals = 2): self
    {
        return new self($step > 0.0 ? $step : 1.0, max(0, min(6, $decimals)), null);
    }

    public function kind(): string
    {
        return 'value';
    }

    /**
     * Angka yang bisa dibaca, ditulis satu cara — atau null.
     *
     * Lewat `number_format`, bukan penggabungan langsung: mengubah float jadi
     * teks di PHP mengikuti setelan `precision` di php.ini, jadi dua server
     * bisa menulis "12.1" dan "12.099999999999999" untuk nilai yang sama.
     */
    public function normalise(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $teks = number_format((float) $raw, $this->decimals, '.', '');
        if (strpos($teks, '.') !== false) {
            $teks = rtrim(rtrim($teks, '0'), '.');
        }

        return $teks === '-0' ? '0' : $teks;
    }

    public function compare(string $a, string $b): int
    {
        return (float) $a <=> (float) $b;
    }

    public function column(string $x): ?float
    {
        if ($this->origin === null) {
            return null;
        }
        $baku = $this->normalise($x);
        if ($baku === null) {
            return null;
        }

        return ((float) $baku - $this->origin) / $this->step;
    }

    public function hasOrigin(): bool
    {
        return $this->origin !== null;
    }

    public function withOrigin(?string $origin): XAxis
    {
        $baku = $origin === null ? null : $this->normalise($origin);

        return new self($this->step, $this->decimals, $baku === null ? null : (float) $baku);
    }

    public function origin(): ?string
    {
        return $this->origin === null ? null : $this->normalise((string) $this->origin);
    }

    public function label(int $column): string
    {
        if ($this->origin === null) {
            return '';
        }

        return (string) $this->normalise((string) ($this->origin + ($column * $this->step)));
    }

    /**
     * @return array<string,mixed>
     */
    public function export(): array
    {
        return [
            'kind' => 'value',
            'step' => $this->step,
            'origin' => $this->origin,
        ];
    }
}
