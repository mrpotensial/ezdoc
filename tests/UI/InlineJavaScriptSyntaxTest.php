<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Parses the JavaScript embedded in the views.
 *
 * `php -l` validates the PHP and stops there. A syntax error inside a `<script>`
 * block passes every check this project had, deploys cleanly, and then takes the
 * whole designer down in the browser — no editor, no panels, no toolbar. The PHP
 * renders fine, so nothing server-side has any idea.
 *
 * These files are large (5,700 lines of JS in the designer alone), built from
 * template literals and PHP interpolation, and edited often. That combination is
 * exactly where a stray brace hides.
 *
 * Skipped when Node is unavailable rather than failing: the check is worth having
 * where it can run, and a machine without Node should not be blocked from running
 * the rest of the suite.
 */
final class InlineJavaScriptSyntaxTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function viewsWithInlineJs(): array
    {
        return [
            'designer_scripts' => ['views/_partials/designer_scripts.php'],
            'generate_scripts' => ['views/_partials/generate_scripts.php'],
            'dialog_helper'    => ['views/_partials/dialog_helper.php'],
            'generate'         => ['views/document/generate.php'],
            'designer'         => ['views/document/designer.php'],
            'template_list'    => ['views/document/template_list.php'],
        ];
    }

    private static function node(): ?string
    {
        foreach (['node', 'nodejs'] as $bin) {
            $probe = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
            if (is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * @return list<array{line: int, code: string}>
     */
    private static function scriptBlocks(string $source): array
    {
        // PHP blocks first: their docblocks mention `<script>` as prose, and
        // without this the documentation gets parsed as code. Replaced with the
        // same number of newlines so reported line numbers stay true.
        $source = (string) preg_replace_callback(
            '/<\?php[\s\S]*?\?>/',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source
        );

        preg_match_all(
            '/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/i',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $blocks = [];
        foreach ($matches[1] as $i => $capture) {
            $code = (string) $capture[0];

            // PHP interpolation becomes a neutral literal — what is under test is
            // the structure, not the rendered output. `0` is valid in both
            // expression and attribute position.
            $code = (string) preg_replace('/<\?=[\s\S]*?\?>/', '0', $code);

            $blocks[] = [
                'line' => substr_count(substr($source, 0, (int) $matches[0][$i][1]), "\n") + 1,
                'code' => $code,
            ];
        }

        return $blocks;
    }

    /**
     * @dataProvider viewsWithInlineJs
     */
    public function testInlineJavaScriptParses(string $relative): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node is not available; cannot parse the inline JavaScript.');
        }

        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        $blocks = self::scriptBlocks((string) file_get_contents($path));
        self::assertNotEmpty($blocks, "no <script> block found in {$relative} — has the view been restructured?");

        foreach ($blocks as $i => $block) {
            $tmp = tempnam(sys_get_temp_dir(), 'ezdoc-js-') . '.js';
            file_put_contents($tmp, $block['code']);

            // --check parses without executing. Nothing in these files should run
            // here, and several of them would throw immediately if it did.
            $output = (string) @shell_exec(
                escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1'
            );
            @unlink($tmp);

            self::assertSame(
                '',
                trim($output),
                sprintf(
                    "JavaScript in %s (block %d, starting near line %d) does not parse:\n%s",
                    $relative,
                    $i + 1,
                    $block['line'],
                    $output
                )
            );
        }
    }

    /**
     * The designer is the file this is really here for. If its block ever gets
     * much smaller, the extraction regex has stopped matching and the test above
     * is quietly checking nothing.
     */
    public function testTheDesignerBlockIsActuallyBeingRead(): void
    {
        $path = dirname(__DIR__, 2) . '/views/_partials/designer_scripts.php';
        $blocks = self::scriptBlocks((string) file_get_contents($path));

        self::assertCount(1, $blocks);
        self::assertGreaterThan(
            3000,
            substr_count($blocks[0]['code'], "\n"),
            'the designer script block came out far smaller than it is — the extraction is broken, not the file'
        );
    }
}
