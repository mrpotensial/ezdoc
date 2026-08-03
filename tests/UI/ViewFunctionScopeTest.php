<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Checks that the view's shared JavaScript helpers are reachable from outside the
 * function they happen to be written next to.
 *
 * The bug this exists for: `fmtDate` was declared with `const` *inside*
 * `showDocInfo()`. A later addition called it from `renderHistoryEntry()`, which
 * sits at the top level. Nothing complains at load time — JavaScript only raises
 * `ReferenceError` when the line actually runs, so the failure appeared the first
 * time somebody opened the history panel, and only there.
 *
 * Syntax checking cannot see this: the file parses perfectly. The only way to
 * know is to execute the script and ask whether the name exists.
 *
 * Deliberately narrow. It does **not** stub a browser and run the page — a
 * make-believe DOM would need maintaining, and a test that fails for stub reasons
 * gets ignored, which is worse than not having it. Function declarations are
 * hoisted, so they are all registered even when top-level code stops early on a
 * missing DOM. That is exactly the property being used here.
 */
final class ViewFunctionScopeTest extends TestCase
{
    private const SOURCE = 'views/_partials/generate_scripts.php';

    /**
     * Helpers that more than one part of the page calls.
     *
     * @return array<string, array{string}>
     */
    public static function sharedHelpers(): array
    {
        return [
            // Dipakai modal info DAN panel riwayat. Ini yang rusak.
            'fmtDate'            => ['fmtDate'],
            'escapeHtmlSimple'   => ['escapeHtmlSimple'],
            'renderHistoryEntry' => ['renderHistoryEntry'],
            'loadDocHistory'     => ['loadDocHistory'],
            'showDocInfo'        => ['showDocInfo'],
            'submitForm'         => ['submitForm'],
            'saveSign'           => ['saveSign'],
            'openSign'           => ['openSign'],
            'generateTtdQr'      => ['generateTtdQr'],
            'showNewVersionModal'=> ['showNewVersionModal'],
        ];
    }

    private static function node(): ?string
    {
        $probe = @shell_exec('node --version 2>&1');

        return is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1 ? 'node' : null;
    }

    /**
     * Names that exist at global scope after the script is evaluated.
     *
     * @return list<string>
     */
    private static function globalFunctions(): array
    {
        /** @var list<string>|null $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $path = dirname(__DIR__, 2) . '/' . self::SOURCE;
        self::assertFileExists($path);

        $raw = (string) file_get_contents($path);
        if (preg_match('/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/i', $raw, $m) !== 1) {
            self::fail('no <script> block found in ' . self::SOURCE);
        }

        $code = (string) preg_replace('/<\?php[\s\S]*?\?>/', '', $m[1]);
        $code = (string) preg_replace('/<\?=[\s\S]*?\?>/', '0', $code);

        $codeFile = tempnam(sys_get_temp_dir(), 'ezdoc-scope-') . '.js';
        file_put_contents($codeFile, $code);

        // The runner evaluates the script in a bare context and reports which
        // names ended up callable. Top-level code will throw on the missing DOM —
        // that is caught and ignored, because hoisting has already done its work.
        $runner = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const sandbox = { console: { log(){}, warn(){}, error(){} } };
sandbox.globalThis = sandbox;
sandbox.window = sandbox;
vm.createContext(sandbox);
try {
    vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox, { filename: 'view.js' });
} catch (e) { /* DOM tiruan tidak ada — deklarasi fungsi sudah terangkat */ }
const names = Object.getOwnPropertyNames(sandbox)
    .filter(n => typeof sandbox[n] === 'function');
process.stdout.write(JSON.stringify(names));
JS;

        $runnerFile = tempnam(sys_get_temp_dir(), 'ezdoc-runner-') . '.js';
        file_put_contents($runnerFile, $runner);

        $out = (string) @shell_exec(
            'node ' . escapeshellarg($runnerFile) . ' ' . escapeshellarg($codeFile) . ' 2>&1'
        );

        @unlink($codeFile);
        @unlink($runnerFile);

        $names = json_decode(trim($out), true);
        self::assertIsArray($names, "the scope probe did not return a list — node said:\n" . $out);

        /** @var list<string> $names */
        return $cache = $names;
    }

    /**
     * @dataProvider sharedHelpers
     */
    public function testHelperIsReachableFromAnywhere(string $fn): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node is not available.');
        }

        self::assertContains(
            $fn,
            self::globalFunctions(),
            "`{$fn}()` is not defined at the top level — it is nested inside another function."
            . ' Anything calling it from elsewhere throws ReferenceError, and only when that'
            . ' line actually runs. The file parses either way, so nothing else will catch it.'
        );
    }

    /**
     * If the probe suddenly finds almost nothing, it stopped working rather than
     * the file becoming empty — and every case above would pass by vacuum.
     */
    public function testTheProbeActuallySeesTheScript(): void
    {
        if (self::node() === null) {
            self::markTestSkipped('Node is not available.');
        }

        self::assertGreaterThan(
            40,
            count(self::globalFunctions()),
            'far fewer global functions than this file defines — the probe is broken, not the file'
        );
    }
}
