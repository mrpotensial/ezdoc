<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Runs the designer's `_isFlagOn()` against the cases that broke it.
 *
 * The bug it exists for: the OTP checkbox could be ticked but never unticked. Its
 * handler stores the string `'0'`, and in JavaScript `!!'0'` is **true** — every
 * re-render put the tick straight back. Nothing errored; the control simply
 * refused to turn off.
 *
 * The awkward part is that the value legitimately arrives as two different types.
 * The checkbox handler sends `'1'`/`'0'`; `scanTtdPlaceholders()` produces a real
 * boolean from the editor attributes. Coercing one caller would have left the
 * other wrong, so the helper accepts both — and this pins that down.
 *
 * The function is extracted from the shipped file and executed, rather than
 * reimplemented here. A PHP port of it would pass while the JavaScript stayed
 * broken, which is worse than having no test.
 */
final class DesignerFlagHelperTest extends TestCase
{
    private const SOURCE = 'views/_partials/designer_scripts.php';

    private static function node(): ?string
    {
        $probe = @shell_exec('node --version 2>&1');

        return is_string($probe) && preg_match('/^v\d+\./', trim($probe)) === 1 ? 'node' : null;
    }

    /** The `_isFlagOn` declaration, lifted verbatim from the view. */
    private static function helperSource(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::SOURCE;
        self::assertFileExists($path);

        $src = (string) file_get_contents($path);
        $start = strpos($src, 'function _isFlagOn(');
        self::assertNotFalse($start, '_isFlagOn is gone from ' . self::SOURCE);

        // Walk to the matching closing brace rather than guessing at a line count.
        $open = strpos($src, '{', $start);
        self::assertNotFalse($open);

        $depth = 0;
        $end = null;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') { $depth++; }
            if ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        self::assertNotNull($end, '_isFlagOn has no matching closing brace');

        return substr($src, $start, ($end - $start) + 1);
    }

    /**
     * @param mixed $value
     * @dataProvider flagCases
     */
    public function testFlagIsReadCorrectly($value, bool $expected): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node is not available.');
        }

        $script = self::helperSource() . "\n"
            . 'process.stdout.write(String(_isFlagOn(' . json_encode($value) . ')));';

        $file = tempnam(sys_get_temp_dir(), 'ezdoc-flag-') . '.js';
        file_put_contents($file, $script);
        $out = trim((string) @shell_exec('node ' . escapeshellarg($file) . ' 2>&1'));
        @unlink($file);

        self::assertSame(
            $expected ? 'true' : 'false',
            $out,
            'value ' . json_encode($value) . ' read as ' . $out
        );
    }

    /** @return array<string, array{mixed, bool}> */
    public static function flagCases(): array
    {
        return [
            // The case that shipped broken. `!!'0'` is true in JavaScript.
            "string '0' is OFF"      => ['0', false],
            "string '1' is ON"       => ['1', true],

            'boolean false is OFF'   => [false, false],
            'boolean true is ON'     => [true, true],

            'empty string is OFF'    => ['', false],
            'null is OFF'            => [null, false],

            // scanTtdPlaceholders defaults a missing attribute to '0'; an absent
            // key arrives as undefined. Both mean the same thing.
            "'false' is OFF"         => ['false', false],
            "'true' is ON"           => ['true', true],
            "'off' is OFF"           => ['off', false],
            "'no' is OFF"            => ['no', false],

            // Whitespace and case come from hand-edited template attributes.
            "' 0 ' is OFF"           => [' 0 ', false],
            "'FALSE' is OFF"         => ['FALSE', false],
            "'  ' is OFF"            => ['  ', false],

            'number 0 is OFF'        => [0, false],
            'number 1 is ON'         => [1, true],

            // Anything else set deliberately counts as on — an unknown value is
            // more likely someone enabling the flag than disabling it.
            "'yes' is ON"            => ['yes', true],
        ];
    }

    /**
     * The helper must not be replaced by a bare `!!` again. That is the exact
     * shape of the original bug, and it reads as correct at a glance.
     */
    public function testTheFlagIsNotReadWithBareCoercion(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::SOURCE);

        self::assertStringNotContainsString(
            '!!(ttd.require_otp)',
            $src,
            'require_otp is being read with `!!`, which reports the string "0" as true'
            . ' — the checkbox will tick but never untick. Use _isFlagOn().'
        );
        self::assertStringContainsString('_isFlagOn(ttd.require_otp)', $src);
    }
}
