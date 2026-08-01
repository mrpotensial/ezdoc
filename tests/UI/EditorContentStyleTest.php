<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * The designer's editor CSS lives inside a JavaScript template literal, which
 * means every backslash in it is read twice — once by JavaScript, once by CSS.
 *
 * A Font Awesome glyph written `\f0ce` instead of `\\f0ce` is a valid JS escape:
 * form feed. CSS counts form feed as a newline, and a CSS string may not contain
 * one, so the parser produces a bad-string; the rest of the glyph then opens a
 * *new* string that swallows `; color: …; }` to the end of the line — closing
 * brace included. The block never closes, and the next rule is consumed with it.
 *
 * Four such lines took 33 of 52 rules with them, including `.ttd-placeholder`
 * and `.logo-placeholder`. The signature placeholder in the editor lost
 * `display: inline-block` and `text-align: center`, so its label and name went
 * flush left and the box stretched across the page.
 *
 * Nothing is logged. The console is clean. The only symptom is a layout that
 * looks wrong — which is exactly why this belongs in a test rather than in
 * anybody's memory.
 */
final class EditorContentStyleTest extends TestCase
{
    private const SOURCE = 'views/_partials/designer_scripts.php';

    /** The CSS as the browser receives it, after JS template-literal escapes. */
    private static function emittedCss(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::SOURCE;
        self::assertFileExists($path);

        $lines = file($path);
        self::assertIsArray($lines);

        $start = 0;
        $end = 0;
        foreach ($lines as $i => $line) {
            if ($start === 0 && strpos($line, 'content_style: `') !== false) {
                $start = $i + 1;
                continue;
            }
            if ($start !== 0 && preg_match('/^\s*`,\s*$/', $line) === 1) {
                $end = $i;
                break;
            }
        }
        self::assertGreaterThan(0, $start, 'content_style block not found');
        self::assertGreaterThan($start, $end, 'end of the content_style literal not found');

        $raw = implode('', array_slice($lines, $start, $end - $start));

        // PHP comments never reach the browser; they are allowed to talk about
        // backslashes without being held to the rule they describe.
        $raw = (string) preg_replace('/<\?php.*?\?>/s', '', $raw);

        // JS template-literal semantics. `\\` first, so an already-escaped
        // backslash is not mistaken for the start of an escape.
        $guard = "\x01";
        $out = str_replace('\\\\', $guard, $raw);
        $out = str_replace(
            ['\\f', '\\n', '\\r', '\\t', '\\v', '\\b', '\\0'],
            [chr(12), "\n", "\r", "\t", chr(11), chr(8), chr(0)],
            $out
        );

        return str_replace($guard, '\\', $out);
    }

    /**
     * Walk the emitted CSS the way a tokenizer would and collect the selectors
     * that actually open a rule.
     *
     * Counting rules rather than grepping for text is the point: a swallowed rule
     * is still present in the source, so a grep would pass while the browser sees
     * nothing.
     *
     * @return list<string>
     */
    private static function parsedSelectors(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $rules = [];
        $sel = '';
        $depth = 0;
        $i = 0;
        $n = strlen($css);

        while ($i < $n) {
            $c = $css[$i];

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $i++;
                while ($i < $n) {
                    $d = $css[$i];
                    if ($d === '\\') { $i += 2; continue; }
                    if ($d === $quote) { $i++; break; }
                    // Newline inside a string — the parse error this test exists for.
                    if ($d === "\n" || $d === "\r" || $d === chr(12)) { break; }
                    $i++;
                }
                continue;
            }

            if ($c === '{') {
                if ($depth === 0) {
                    $rules[] = trim((string) preg_replace('/\s+/', ' ', $sel));
                }
                $depth++;
                $sel = '';
                $i++;
                continue;
            }

            if ($c === '}') {
                $depth = max(0, $depth - 1);
                $i++;
                continue;
            }

            if ($depth === 0) {
                $sel .= $c;
            }
            $i++;
        }

        return $rules;
    }

    /**
     * No character that ends a CSS string may survive into one. Form feed is the
     * one that actually happened; the others are the same mistake with a
     * different letter.
     */
    public function testTheEmittedCssContainsNoStringTerminatingControlCharacters(): void
    {
        $css = self::emittedCss();

        foreach ([chr(12) => 'form feed (\\f)', chr(11) => 'vertical tab (\\v)', chr(8) => 'backspace (\\b)', chr(0) => 'NUL (\\0)'] as $char => $name) {
            self::assertSame(
                0,
                substr_count($css, $char),
                "The editor CSS contains a {$name}. In a JS template literal a single"
                . ' backslash before that letter produces this character, and CSS treats it'
                . ' as ending the string — taking the rest of the rule, and the next rule,'
                . ' with it. Write the glyph with two backslashes.'
            );
        }
    }

    /**
     * Rules the editor cannot render without. Each one styles something an author
     * inserts and then looks at, so losing one is immediately visible — and
     * completely unexplained.
     *
     * @dataProvider requiredSelectors
     */
    public function testARequiredRuleSurvivesParsing(string $selector): void
    {
        $found = false;
        foreach (self::parsedSelectors(self::emittedCss()) as $rule) {
            if (strpos($rule, $selector) !== false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found,
            "`{$selector}` never reaches the browser — an earlier rule's malformed string"
            . ' swallowed it. Check the content_style block for single-backslash escapes.');
    }

    /** @return array<string, array{string}> */
    public static function requiredSelectors(): array
    {
        return [
            'placeholder tanda tangan' => ['.ttd-placeholder'],
            'placeholder logo'         => ['.logo-placeholder'],
            'placeholder field'        => ['.field-placeholder'],
            'placeholder materai'      => ['.materai-placeholder'],
            'placeholder QR'           => ['.qr-placeholder'],
            'marker tabledb'           => ['.tabledb-marker'],
        ];
    }

    /**
     * A blunt backstop for the same failure. The exact count will drift as rules
     * are added, so this asserts a floor rather than an equality — but a
     * swallowed block drops it by dozens, which is what it is here to notice.
     */
    public function testMostOfTheStylesheetSurvivesParsing(): void
    {
        $selectors = self::parsedSelectors(self::emittedCss());

        self::assertGreaterThanOrEqual(45, count($selectors),
            'Far fewer CSS rules parse than the source contains — something is eating them.'
            . ' When this broke, 19 of 52 rules survived.');
    }

    /**
     * Braces must balance. An unclosed block is the shape every one of these
     * failures takes, whatever caused it.
     */
    public function testBracesBalance(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::emittedCss());

        $depth = 0;
        $i = 0;
        $n = strlen($css);
        while ($i < $n) {
            $c = $css[$i];
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $i++;
                while ($i < $n) {
                    $d = $css[$i];
                    if ($d === '\\') { $i += 2; continue; }
                    if ($d === $quote) { $i++; break; }
                    if ($d === "\n" || $d === "\r" || $d === chr(12)) { break; }
                    $i++;
                }
                continue;
            }
            if ($c === '{') { $depth++; }
            if ($c === '}') { $depth--; }
            self::assertGreaterThanOrEqual(0, $depth, 'a closing brace with nothing open');
            $i++;
        }

        self::assertSame(0, $depth, 'the editor CSS has an unclosed rule block');
    }
}
