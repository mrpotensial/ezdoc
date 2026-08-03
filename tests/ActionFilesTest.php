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

    /**
     * An action file must not read a variable it never declared global.
     *
     * These files are `require`d from two places, and the two differ in exactly
     * the way that makes this bug invisible. A consumer that requires the
     * dispatcher at global scope leaks its own `$conn`/`$author_id` into the
     * included file, so an undeclared variable happens to be populated. Under
     * `App::run()` the dispatcher is required from inside a method, so the same
     * variable is null — and the request dies with "Call to a member function
     * query() on null", which names neither the file nor the real cause.
     *
     * `$GLOBALS['conn']` being set is not enough: without `global $conn` the
     * local name does not alias it.
     *
     * @dataProvider actionFiles
     */
    public function testAnActionFileDeclaresTheGlobalsItReads(string $path): void
    {
        $source = (string) file_get_contents($path);

        // Comments and docblocks mention these names constantly. Strip them
        // first, or every file that documents its history fails.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $hostGlobals = ['conn', 'author_id', 'author_role_array'];

        preg_match_all('/global\s+([^;]+);/', $code, $dm);
        $declared = [];
        foreach ($dm[1] as $list) {
            preg_match_all('/\$(\w+)/', $list, $vm);
            foreach ($vm[1] as $name) {
                $declared[$name] = true;
            }
        }

        $offenders = [];
        foreach ($hostGlobals as $name) {
            if (isset($declared[$name])) {
                continue;
            }
            // Skip the declaration itself, then look for any remaining read.
            $withoutDecls = preg_replace('/global\s+[^;]+;/', '', $code);
            if (preg_match('/\$' . $name . '\b/', (string) $withoutDecls) === 1) {
                $offenders[] = '$' . $name;
            }
        }

        self::assertSame([], $offenders,
            basename($path) . ' reads ' . implode(', ', $offenders)
            . ' without declaring it global — works when required at global scope, null under App::run().'
            . ' Prefer Context::default()->db, or declare the global.');
    }

    /**
     * Creating a new version must not carry the previous version's signatures.
     *
     * A signature binds one document state, named by that version's
     * `content_hash`. Carrying it over claims somebody signed something they
     * never saw — and the record does not come with it, since the row in
     * `ezdoc_signatures` points at the old `document_id`. The new version arrives
     * looking signed with nothing behind it.
     *
     * The practical result is the opposite of what a new version is for: the §7b
     * guard sees filled slots, freezes the content, and the version created to
     * revise the document is born locked.
     *
     * Every system draws this line — DocuSign's "copy envelope" carries the
     * documents and recipients but not the signatures; Google Docs' "make a copy"
     * carries neither history nor approvals.
     *
     * Asserted structurally because the alternative needs a database and a full
     * request. Weak, but it catches the one edit that would bring it back: adding
     * the column to the source SELECT.
     */
    public function testNewVersionDoesNotCopySignatures(): void
    {
        $path = dirname(__DIR__) . '/actions/document/new_version.php';
        self::assertFileExists($path);

        $source = (string) file_get_contents($path);

        // Strip comments — this file explains at length why it does not do it.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        self::assertSame(
            1,
            preg_match_all('/signature_values/', $code),
            'new_version.php should mention signature_values exactly once — in the INSERT,'
            . ' where it writes the empty default. A second mention means it is reading the'
            . " previous version's signatures and copying them into the new one."
        );

        self::assertStringNotContainsString(
            'SELECT field_values, signature_values',
            $code,
            'the source SELECT must not fetch signature_values'
        );
    }
}
