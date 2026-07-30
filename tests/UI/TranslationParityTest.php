<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Setiap kunci terjemahan harus ada di semua bahasa, dengan bentuk yang sama.
 *
 * Kunci ditambahkan dengan tangan ke dua file terpisah. Yang terlewat tidak
 * memunculkan error apa pun saat halaman dirender — `t()` mengembalikan kunci
 * mentahnya, jadi yang muncul di layar adalah `ttd.sign_with_qr` dan baru
 * ketahuan kalau ada yang membuka halaman itu dalam bahasa yang tertinggal.
 *
 * Tes ini juga memeriksa placeholder `{param}`: pesan yang di satu bahasa
 * memakai `{label}` tapi di bahasa lain `{nama}` akan mencetak kurung kurawal
 * apa adanya ke pengguna.
 */
final class TranslationParityTest extends TestCase
{
    private const BASE_LOCALE = 'en';

    /** @return array<string, array{string}> */
    public static function translationFiles(): array
    {
        $dir = dirname(__DIR__, 2) . '/lang/' . self::BASE_LOCALE;
        $cases = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $name = basename($path, '.php');
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /** @return list<string> */
    private static function locales(): array
    {
        $dirs = glob(dirname(__DIR__, 2) . '/lang/*', GLOB_ONLYDIR) ?: [];

        return array_map('basename', $dirs);
    }

    /**
     * @return array<string, string|array<mixed>>
     */
    private static function load(string $locale, string $file): array
    {
        $path = dirname(__DIR__, 2) . '/lang/' . $locale . '/' . $file . '.php';
        self::assertFileExists($path, "File terjemahan hilang: {$locale}/{$file}.php");

        /** @var array<string, string|array<mixed>> $data */
        $data = require $path;
        self::assertIsArray($data, "{$locale}/{$file}.php harus mengembalikan array");

        return $data;
    }

    /**
     * Daftarkan tiap kunci sebagai path bertitik, beserta tipe nilainya.
     *
     * @param array<mixed> $data
     * @return array<string, string> path => 'string'|'array'
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out[$path] = 'array';
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = 'string';
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = array_unique($m[1]);
        sort($names);

        return array_values($names);
    }

    /**
     * @param array<mixed> $data
     */
    private static function valueAt(array $data, string $path): ?string
    {
        $ref = $data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                return null;
            }
            $ref = $ref[$seg];
        }

        return is_string($ref) ? $ref : null;
    }

    public function testEveryLocaleHasTheSameFiles(): void
    {
        $locales = self::locales();
        self::assertGreaterThan(1, count($locales), 'Paritas tidak ada artinya dengan satu bahasa');

        $expected = array_keys(self::translationFiles());
        foreach ($locales as $locale) {
            $files = array_map(
                static fn (string $p): string => basename($p, '.php'),
                glob(dirname(__DIR__, 2) . '/lang/' . $locale . '/*.php') ?: []
            );
            sort($files);
            $want = $expected;
            sort($want);
            self::assertSame($want, $files, "Daftar file bahasa {$locale} berbeda dari " . self::BASE_LOCALE);
        }
    }

    /**
     * @dataProvider translationFiles
     */
    public function testKeysMatchAcrossLocales(string $file): void
    {
        $base = self::flatten(self::load(self::BASE_LOCALE, $file));

        foreach (self::locales() as $locale) {
            if ($locale === self::BASE_LOCALE) {
                continue;
            }

            $other = self::flatten(self::load($locale, $file));

            $missing = array_diff(array_keys($base), array_keys($other));
            self::assertSame([], array_values($missing),
                "Kunci ada di " . self::BASE_LOCALE . " tapi hilang di {$locale} ({$file}.php)");

            $extra = array_diff(array_keys($other), array_keys($base));
            self::assertSame([], array_values($extra),
                "Kunci ada di {$locale} tapi hilang di " . self::BASE_LOCALE . " ({$file}.php)");

            // Satu bahasa memakai string di tempat bahasa lain memakai grup akan
            // membuat `t()` mengembalikan kunci mentah untuk seluruh cabangnya.
            foreach ($base as $path => $kind) {
                self::assertSame($kind, $other[$path] ?? null,
                    "Bentuk berbeda pada '{$path}': {$kind} di " . self::BASE_LOCALE . ", {$other[$path]} di {$locale} ({$file}.php)");
            }
        }
    }

    /**
     * @dataProvider translationFiles
     */
    public function testPlaceholdersMatchAcrossLocales(string $file): void
    {
        $baseData = self::load(self::BASE_LOCALE, $file);
        $base = self::flatten($baseData);

        foreach (self::locales() as $locale) {
            if ($locale === self::BASE_LOCALE) {
                continue;
            }

            $otherData = self::load($locale, $file);

            foreach ($base as $path => $kind) {
                if ($kind !== 'string') {
                    continue;
                }

                $baseText = self::valueAt($baseData, $path);
                $otherText = self::valueAt($otherData, $path);
                if ($baseText === null || $otherText === null) {
                    continue; // ketiadaan kunci sudah dilaporkan tes di atas
                }

                self::assertSame(
                    self::placeholders($baseText),
                    self::placeholders($otherText),
                    "Placeholder berbeda pada '{$path}' ({$file}.php) antara " . self::BASE_LOCALE . " dan {$locale}"
                );
            }
        }
    }

    /**
     * Nilai kosong lolos semua pemeriksaan di atas tapi tetap tidak menampilkan
     * apa pun ke pengguna.
     *
     * @dataProvider translationFiles
     */
    public function testNoEmptyTranslations(string $file): void
    {
        foreach (self::locales() as $locale) {
            $data = self::load($locale, $file);
            foreach (self::flatten($data) as $path => $kind) {
                if ($kind !== 'string') {
                    continue;
                }
                $text = self::valueAt($data, $path);
                self::assertNotSame('', (string) $text,
                    "Terjemahan kosong pada '{$path}' ({$locale}/{$file}.php)");
            }
        }
    }
}
