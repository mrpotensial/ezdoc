<?php

declare(strict_types=1);

namespace Ezdoc\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the action endpoints against being silently emptied or broken.
 *
 * Written after a bulk edit truncated 18 of them to zero bytes. Nothing caught
 * it: an empty file is valid PHP, so `php -l` passes; no test loaded them, so
 * the suite stayed green; and the dispatcher `require`s a file that defines
 * nothing, returns, and answers "200 OK" with an empty body. The caller does
 * `await resp.json()` and reports a parse error, which points nowhere near the
 * real cause.
 *
 * These endpoints are the library's entire HTTP surface. They cannot be
 * executed here — each one ends in exit() and expects a live request — but
 * their integrity can be asserted cheaply, and that is what was missing.
 */
final class ActionFilesTest extends TestCase
{
    private const ACTIONS_DIR = __DIR__ . '/../actions';

    /**
     * @return array<string, array{0: string}>
     */
    public static function actionFiles(): array
    {
        $out = [];
        foreach ((array) glob(self::ACTIONS_DIR . '/*/*.php') as $path) {
            $label = basename(dirname((string) $path)) . '/' . basename((string) $path);
            $out[$label] = [(string) $path];
        }
        return $out;
    }

    public function testTheActionDirectoryIsNotEmpty(): void
    {
        self::assertNotEmpty(
            self::actionFiles(),
            'no action files found — the glob or the directory moved'
        );
    }

    /**
     * The failure that prompted this file. Zero bytes is valid PHP, so nothing
     * downstream complains; the endpoint just stops existing.
     *
     * @dataProvider actionFiles
     */
    public function testAnActionFileIsNotEmpty(string $path): void
    {
        self::assertGreaterThan(
            0,
            (int) filesize($path),
            basename($path) . ' is empty — the endpoint would answer 200 with no body'
        );
    }

    /**
     * @dataProvider actionFiles
     */
    public function testAnActionFileParses(string $path): void
    {
        $output = [];
        $status = 0;
        @exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    /**
     * Every endpoint answers JSON and ends the request itself. One that returns
     * normally falls through to the dispatcher's "no match" reply, which is
     * misleading — the action did run.
     *
     * @dataProvider actionFiles
     */
    public function testAnActionFileTerminatesTheRequest(string $path): void
    {
        $source = (string) file_get_contents($path);

        $terminates = strpos($source, 'ezdoc_respond_') !== false
            || strpos($source, 'exit') !== false
            || strpos($source, 'die(') !== false;

        self::assertTrue(
            $terminates,
            basename($path) . ' never responds or exits — it would look like an unmatched action'
        );
    }

    /**
     * The Db handle comes from Context::connection(), which adapts whatever the
     * host supplied. Reaching for MysqliConnection directly pins the endpoint
     * to one engine and undoes the portability the Db layer provides.
     *
     * @dataProvider actionFiles
     */
    public function testAnActionFileDoesNotHardcodeADriver(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertStringNotContainsString(
            'new MysqliConnection(Context::default()->db)',
            $source,
            basename($path) . ' should use Context::default()->connection()'
        );
    }

    /**
     * An import left behind after its last use is dead weight, and it is also
     * the signature of an edit that removed usages without tidying up.
     *
     * @dataProvider actionFiles
     */
    public function testAnActionFileHasNoUnusedDriverImport(string $path): void
    {
        $source = (string) file_get_contents($path);

        if (strpos($source, 'use Ezdoc\Db\Mysqli\MysqliConnection;') === false) {
            self::assertTrue(true);
            return;
        }

        self::assertStringContainsString(
            'MysqliConnection(',
            $source,
            basename($path) . ' imports MysqliConnection without using it'
        );
    }
}
