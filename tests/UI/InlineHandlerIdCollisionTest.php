<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * `id` sebuah elemen tidak boleh sama dengan nama fungsi di `onclick`-nya.
 *
 * Ini lahir dari bug yang dilaporkan pengguna: tombol "Show hidden" muncul,
 * ditekan, lalu peramban menjawab **"toggleHiddenVersions is not a function"**
 * — padahal fungsinya ada, global, dan di blok skrip yang sama.
 *
 * Sebabnya aturan lama HTML. Penangan sebaris tidak dijalankan di lingkup
 * global; ia dibungkus rantai lingkup:
 *
 *     elemen  ->  form induknya  ->  document  ->  window
 *
 * `<form>` mengekspos kontrolnya sebagai properti bernama, dengan kunci `name`
 * **maupun `id`**. Jadi kalau sebuah tombol di dalam form ber-id sama dengan
 * nama fungsinya, nama itu ketemu TOMBOLNYA lebih dulu — window tidak pernah
 * tercapai — dan tombol dipanggil sebagai fungsi.
 *
 * Yang membuatnya mahal: tidak ada yang merah. PHP-nya benar, JS-nya lolos
 * `node --check`, fungsinya benar-benar ada di global, dan tes cakupan fungsi
 * pun lulus. Satu-satunya yang tahu adalah peramban, saat tombolnya ditekan.
 *
 * Seluruh markup di proyek ini sudah memakai awalan `btn*` justru karena itu
 * (`btnSendOtp`/`sendTtdOtp`, `toolbarToggleBtn`/`toggleToolbar`). Yang satu
 * itu menyimpang, dan menyimpangnya tidak kelihatan sampai ada yang menekan.
 */
final class InlineHandlerIdCollisionTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function viewsWithMarkup(): array
    {
        return [
            'generate'      => ['views/document/generate.php'],
            'designer'      => ['views/document/designer.php'],
            'template_list' => ['views/document/template_list.php'],
        ];
    }

    /**
     * Tag pembuka yang punya `id` sekaligus penangan sebaris.
     *
     * @return list<array{tag: string, id: string, event: string, fn: string}>
     */
    private static function handlerTags(string $source): array
    {
        // Interpolasi PHP dibuang lebih dulu: tag penutupnya mengandung `>`
        // dan akan memotong tag HTML di tengah jalan.
        //
        // (Tag penutup itu tidak ditulis apa adanya di komentar ini: PHP
        // menutup mode-nya walau sedang di dalam komentar `//`.)
        $source = (string) preg_replace('/<\?php[\s\S]*?\?>/', '', $source);
        $source = (string) preg_replace('/<\?=[\s\S]*?\?>/', '0', $source);

        preg_match_all('/<[a-zA-Z][a-zA-Z0-9]*\b[^>]*>/', $source, $tags);

        $hasil = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bid="([^"]+)"/', $tag, $mId) !== 1) {
                continue;
            }
            if (preg_match('/\b(on[a-z]+)="\s*([A-Za-z_$][\w$]*)\s*\(/', $tag, $mOn) !== 1) {
                continue;
            }

            $hasil[] = [
                'tag'   => $tag,
                'id'    => $mId[1],
                'event' => $mOn[1],
                'fn'    => $mOn[2],
            ];
        }

        return $hasil;
    }

    /**
     * @dataProvider viewsWithMarkup
     */
    public function testIdTidakMenimpaFungsiPenangannya(string $rel): void
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, "{$rel} hilang — premis tes ini perlu ditinjau");

        $tabrakan = [];
        foreach (self::handlerTags((string) file_get_contents($path)) as $t) {
            if ($t['id'] === $t['fn']) {
                $tabrakan[] = sprintf(
                    'id="%s" %s="%s()" — nama itu akan ketemu elemennya sendiri, '
                    . 'bukan fungsinya; beri awalan btn* pada id-nya',
                    $t['id'],
                    $t['event'],
                    $t['fn']
                );
            }
        }

        self::assertSame([], $tabrakan, "{$rel}:\n  " . implode("\n  ", $tabrakan));
    }

    /**
     * Premisnya masih berlaku: markup-nya memang penuh penangan sebaris.
     *
     * Kalau suatu saat semuanya pindah ke addEventListener, tes di atas akan
     * lulus tanpa memeriksa apa pun — dan lebih baik ketahuan saat itu juga.
     */
    public function testMasihAdaPenangganSebarisUntukDijaga(): void
    {
        $n = count(self::handlerTags(
            (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php')
        ));

        self::assertGreaterThan(
            5,
            $n,
            'penangan sebaris nyaris habis — tinjau apakah tes ini masih relevan'
        );
    }

    /** Tombol yang memicu bug ini tetap terjaga, dengan nama barunya. */
    public function testTombolVersiTersembunyiTerhubungKeFungsinya(): void
    {
        $v = (string) file_get_contents(dirname(__DIR__, 2) . '/views/document/generate.php');
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/views/_partials/generate_scripts.php');

        self::assertStringContainsString('id="btnToggleHidden"', $v);
        self::assertStringContainsString('onclick="toggleHiddenVersions()"', $v);

        self::assertStringContainsString(
            "getElementById('btnToggleHidden')",
            $js,
            'yang memunculkan tombol harus mencari id yang sama dengan yang digambar'
        );
    }
}
