<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Setiap kunci yang dipanggil `t()` di view harus benar-benar ada.
 *
 * Kunci yang tidak ada tidak melempar apa pun. `t()` mengembalikan kunci
 * mentahnya, jadi yang muncul di layar adalah `materai.warning_title` sebagai
 * judul dialog. Satu-satunya cara menangkapnya sekarang adalah ada orang yang
 * membuka halaman itu dan memperhatikan.
 *
 * Pelengkap TranslationParityTest: yang itu menjaga dua bahasa tetap sejajar,
 * yang ini menjaga keduanya tetap sejajar dengan kode yang memakainya.
 */
final class TranslationUsageTest extends TestCase
{
    /**
     * View => file bahasa yang dimuat halaman itu.
     *
     * Pemetaannya eksplisit karena tiap halaman memuat kamusnya sendiri: kunci
     * designer tidak tersedia di halaman generate, dan sebaliknya. Memuat semua
     * file sekaligus akan membuat tes ini lolos untuk kunci yang saat runtime
     * justru tidak ada.
     *
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function pages(): array
    {
        return [
            'generate' => [
                [
                    'views/document/generate.php',
                    'views/_partials/generate_scripts.php',
                    'views/_partials/generate_styles.php',
                ],
                ['common', 'generate'],
            ],
            'designer' => [
                [
                    'views/template/designer.php',
                    'views/_partials/designer_scripts.php',
                    'views/_partials/designer_styles.php',
                ],
                ['common', 'designer'],
            ],
        ];
    }

    /**
     * @param list<string> $files
     * @return array<string, mixed>
     */
    private static function dictionary(string $locale, array $files): array
    {
        $dict = [];
        foreach ($files as $file) {
            $path = dirname(__DIR__, 2) . '/lang/' . $locale . '/' . $file . '.php';
            self::assertFileExists($path);
            /** @var array<string, mixed> $part */
            $part = require $path;
            $dict = array_merge($dict, $part);
        }

        return $dict;
    }

    /**
     * @param array<string, mixed> $dict
     */
    private static function resolves(array $dict, string $key): bool
    {
        $ref = $dict;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($ref) || !array_key_exists($seg, $ref)) {
                return false;
            }
            $ref = $ref[$seg];
        }

        return is_string($ref);
    }

    /**
     * @param list<string> $viewFiles
     * @return list<string>
     */
    private static function usedKeys(array $viewFiles): array
    {
        $source = '';
        foreach ($viewFiles as $rel) {
            $path = dirname(__DIR__, 2) . '/' . $rel;
            if (!is_file($path)) {
                continue; // view opsional — daftar di pages() sengaja longgar
            }
            $source .= "\n" . (string) file_get_contents($path);
        }

        // Cocok untuk pemanggilan PHP `t('key', ...)` maupun JS `t('key', ...)`.
        // Keduanya memakai bentuk yang sama, jadi satu pola cukup.
        preg_match_all('/\bt\(\s*[\'"]([a-z0-9_.]+)[\'"]/i', $source, $m);

        $keys = array_values(array_unique($m[1]));
        sort($keys);

        return $keys;
    }

    /**
     * @param list<string> $viewFiles
     * @param list<string> $langFiles
     *
     * @dataProvider pages
     */
    public function testEveryUsedKeyExists(array $viewFiles, array $langFiles): void
    {
        $used = self::usedKeys($viewFiles);
        self::assertNotEmpty($used, 'Tidak ada pemanggilan t() yang terdeteksi — polanya mungkin sudah tidak cocok');

        foreach (['id', 'en'] as $locale) {
            $dict = self::dictionary($locale, $langFiles);
            $missing = [];
            foreach ($used as $key) {
                if (!self::resolves($dict, $key)) {
                    $missing[] = $key;
                }
            }
            self::assertSame([], $missing,
                "Kunci dipakai di view tapi tidak ada di lang/{$locale}: " . implode(', ', $missing));
        }
    }

    /**
     * Placeholder yang dipakai pemanggil harus ada di terjemahannya.
     *
     * Kalau tidak, parameter yang dikirim hilang tanpa jejak — pesan "Gagal:
     * {reason}" yang kehilangan placeholder-nya jadi cuma "Gagal", dan alasannya
     * tidak pernah sampai ke pengguna.
     *
     * @param list<string> $viewFiles
     * @param list<string> $langFiles
     *
     * @dataProvider pages
     */
    public function testCallerParamsExistInTranslation(array $viewFiles, array $langFiles): void
    {
        $source = '';
        foreach ($viewFiles as $rel) {
            $path = dirname(__DIR__, 2) . '/' . $rel;
            if (is_file($path)) {
                $source .= "\n" . (string) file_get_contents($path);
            }
        }

        // t('key', {a: ..., b: ...}) di JS, atau t('key', ['a' => ...]) di PHP.
        // Hanya nama parameternya yang diambil; nilainya tidak relevan.
        //
        // Heuristik, dan sengaja condong ke arah melewatkan pemeriksaan daripada
        // melaporkan yang bukan masalah: badan argumennya berhenti di `}` atau
        // `]` pertama, jadi pemanggilan dengan objek bersarang hanya diperiksa
        // sebagian. Yang tidak boleh terjadi adalah kebalikannya — kegagalan
        // palsu membuat tes ini diabaikan, dan tes yang diabaikan tidak menjaga
        // apa pun.
        preg_match_all('/\bt\(\s*[\'"]([a-z0-9_.]+)[\'"]\s*,\s*([\{\[][^\}\]]*)/i', $source, $calls, PREG_SET_ORDER);

        $dicts = [
            'id' => self::dictionary('id', $langFiles),
            'en' => self::dictionary('en', $langFiles),
        ];

        $problems = [];
        foreach ($calls as $call) {
            $key = $call[1];
            $argsRaw = $call[2];

            // Nama parameter hanya dikenali kalau berada tepat setelah pembuka
            // atau koma. Tanpa jangkar itu, `matched.map(f => f.key)` membuat `f`
            // terbaca sebagai nama parameter, dan `a ? b : c` membuat `b`
            // terbaca begitu juga — dua-duanya kegagalan palsu.
            //
            // Bentuk PHP mewajibkan kunci berkutip (`'a' =>`); bentuk JS
            // menerima `a:` maupun `'a':`. Pembedaan itulah yang menyingkirkan
            // arrow function, karena parameternya tidak berkutip.
            preg_match_all('/[\{\[,]\s*(?:[\'"](\w+)[\'"]\s*(?:=>|:)|(\w+)\s*:)/', $argsRaw, $pm, PREG_SET_ORDER);
            $params = [];
            foreach ($pm as $hit) {
                $name = $hit[1] !== '' ? $hit[1] : ($hit[2] ?? '');
                if ($name !== '') {
                    $params[$name] = true;
                }
            }
            $params = array_keys($params);
            if ($params === []) {
                continue; // t('key', {}) / t('key', []) — tidak menjanjikan apa pun
            }

            foreach ($dicts as $locale => $dict) {
                $ref = $dict;
                $ok = true;
                foreach (explode('.', $key) as $seg) {
                    if (!is_array($ref) || !array_key_exists($seg, $ref)) { $ok = false; break; }
                    $ref = $ref[$seg];
                }
                if (!$ok || !is_string($ref)) {
                    continue; // ketiadaan kunci sudah dilaporkan tes di atas
                }

                foreach ($params as $p) {
                    if (strpos($ref, '{' . $p . '}') === false) {
                        $problems[] = "{$locale}: '{$key}' dikirim {{$p}} tapi terjemahannya tidak memakainya";
                    }
                }
            }
        }

        $problems = array_values(array_unique($problems));
        self::assertSame([], $problems, implode("\n", $problems));
    }
}
