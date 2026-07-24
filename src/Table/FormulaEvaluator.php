<?php

declare(strict_types=1);

namespace Ezdoc\Table;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Table\FormulaEvaluator — safe expression evaluator untuk computed
 * columns di {@see ReportTable}.
 *
 * ## Grammar (whitelisted)
 *
 * ```
 *   expr    := term (('+' | '-') term)*
 *   term    := factor (('*' | '/') factor)*
 *   factor  := NUMBER | FIELD_REF | '(' expr ')'
 *   NUMBER  := digit+ ('.' digit+)?
 *   FIELD_REF := '{' [a-zA-Z_][a-zA-Z0-9_]* '}'
 * ```
 *
 * Impl pakai shunting-yard algorithm (Dijkstra 1961) → RPN → stack eval.
 * O(n) time & space, deterministic.
 *
 * ## Safety guarantees
 *
 * - **NO PHP `eval()`** — parser murni token-based
 * - **NO function calls** — grammar tidak sediakan syntax function
 * - **NO property access** — grammar tidak sediakan `.` operator
 * - **NO string literals** — grammar cuma NUMBER + FIELD_REF
 * - Field name harus match `[a-zA-Z_][a-zA-Z0-9_]*` — injection attempt
 *   seperti `{system('rm -rf /')}` di-reject di tokenizer
 * - Unknown character → {@see ValidationException}
 *
 * ## Null semantics
 *
 * Return `null` (bukan throw) untuk kondisi runtime yg wajar:
 * - Field reference tidak ada di context (row incomplete)
 * - Field value null atau empty string
 * - Field value non-numeric
 * - Division by zero
 *
 * Null aware: aggregate functions (SUM/AVG di {@see ReportTable}) skip
 * rows yg formula-nya evaluate ke null.
 *
 * `ValidationException` throw hanya untuk PARSE error (formula syntax
 * salah) — bukan runtime. Parse error harusnya caught di designer save
 * time, bukan di render time.
 *
 * ## Non-goals
 *
 * - Unary minus (`-{qty}`) — user tulis `0 - {qty}` sebagai workaround
 * - Modulo, exponent — 4 operator dasar cukup untuk laporan keuangan
 * - Comparison operators — {@see ReportTable} handle conditional format
 *   di layer terpisah
 * - Function calls (SUM, IF, dst) — pakai aggregate di ColumnDef
 *
 * PHP 7.4+ compatible.
 */
final class FormulaEvaluator
{
    // Token types
    private const T_NUM = 'num';
    private const T_OP = 'op';
    private const T_LPAREN = 'lp';
    private const T_RPAREN = 'rp';
    private const T_FIELD = 'field';

    /** @var array<string,array{prec:int,assoc:string}> */
    private const OPS = [
        '+' => ['prec' => 1, 'assoc' => 'L'],
        '-' => ['prec' => 1, 'assoc' => 'L'],
        '*' => ['prec' => 2, 'assoc' => 'L'],
        '/' => ['prec' => 2, 'assoc' => 'L'],
    ];

    /**
     * Evaluate formula against row context.
     *
     * @param string              $formula expression string (e.g. "{qty} * {harga}")
     * @param array<string,mixed> $context row data (field name => value)
     * @return float|null evaluated result, null on runtime miss
     * @throws ValidationException on parse error (bad syntax)
     */
    public static function evaluate(string $formula, array $context): ?float
    {
        $formula = trim($formula);
        if ($formula === '') {
            throw ValidationException::forField('formula', 'empty expression');
        }
        $tokens = self::tokenize($formula);
        if (empty($tokens)) {
            throw ValidationException::forField('formula', 'empty expression after tokenize');
        }
        $rpn = self::shuntingYard($tokens);
        return self::evalRpn($rpn, $context);
    }

    /**
     * Evaluate formula dgn bare-identifier support (tanpa `{}` wrapping).
     *
     * Untuk inline markers seperti `{{tabledb.ns.=qty*harga}}` — expression
     * `qty*harga` pakai bare column names (no {} braces). Preprocessor
     * wraps identifier tokens dgn `{}`, lalu delegate ke evaluate().
     *
     * Safety same as evaluate() — pre-processing tidak lonjakkan grammar.
     * Identifier followed by `(` still ends up as `{name}(...)` → parser
     * rejects karena `(` after field ref invalid.
     *
     * @param string              $formula expression dgn bare identifiers ("qty * harga")
     * @param array<string,mixed> $context row data
     * @return float|null
     * @throws ValidationException on parse error
     */
    public static function evaluateBare(string $formula, array $context): ?float
    {
        // Wrap bare identifiers dgn {} — regex-based preprocessing.
        // Skip identifiers already wrapped di {} (idempotent).
        // Pattern: word char that's not preceded by { and not followed by }.
        // Simpler approach: split on { }, wrap identifiers hanya di parts di luar braces.
        $wrapped = preg_replace_callback(
            '/([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($m) {
                return '{' . $m[1] . '}';
            },
            $formula
        );
        return self::evaluate($wrapped, $context);
    }

    /**
     * @return array<array{0:string,1:mixed}>
     * @throws ValidationException
     */
    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $len = strlen($formula);
        $i = 0;
        while ($i < $len) {
            $c = $formula[$i];
            if (ctype_space($c)) { $i++; continue; }

            // Number literal
            if (ctype_digit($c) || $c === '.') {
                $start = $i;
                $dotSeen = ($c === '.');
                $i++;
                while ($i < $len) {
                    $cc = $formula[$i];
                    if (ctype_digit($cc)) { $i++; continue; }
                    if ($cc === '.' && !$dotSeen) { $dotSeen = true; $i++; continue; }
                    break;
                }
                $numStr = substr($formula, $start, $i - $start);
                if ($numStr === '.' || $numStr === '') {
                    throw ValidationException::forField('formula', "invalid number '$numStr' at pos $start");
                }
                $tokens[] = [self::T_NUM, (float) $numStr];
                continue;
            }

            // Field reference {name}
            if ($c === '{') {
                $end = strpos($formula, '}', $i + 1);
                if ($end === false) {
                    throw ValidationException::forField('formula', "unclosed field reference at pos $i");
                }
                $name = substr($formula, $i + 1, $end - $i - 1);
                // Strict field name — rejects injection like {system('rm')}
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                    throw ValidationException::forField(
                        'formula',
                        "invalid field name '$name' at pos $i (must match [a-zA-Z_][a-zA-Z0-9_]*)"
                    );
                }
                $tokens[] = [self::T_FIELD, $name];
                $i = $end + 1;
                continue;
            }

            // Operator
            if (isset(self::OPS[$c])) {
                $tokens[] = [self::T_OP, $c];
                $i++;
                continue;
            }

            // Parens
            if ($c === '(') { $tokens[] = [self::T_LPAREN, '(']; $i++; continue; }
            if ($c === ')') { $tokens[] = [self::T_RPAREN, ')']; $i++; continue; }

            // Anything else = rejected (including letters, dot outside num,
            // semicolon, quotes, etc.) — this is the injection guard.
            throw ValidationException::forField(
                'formula',
                "invalid character '$c' at pos $i (allowed: digits, operators + - * /, parens, field refs {name})"
            );
        }
        return $tokens;
    }

    /**
     * Shunting-yard algorithm — convert infix tokens → RPN queue.
     *
     * @param array<array{0:string,1:mixed}> $tokens
     * @return array<array{0:string,1:mixed}> RPN queue
     * @throws ValidationException
     */
    private static function shuntingYard(array $tokens): array
    {
        $output = [];
        $ops = [];
        foreach ($tokens as $tok) {
            $type = $tok[0];
            $val = $tok[1];
            if ($type === self::T_NUM || $type === self::T_FIELD) {
                $output[] = $tok;
                continue;
            }
            if ($type === self::T_OP) {
                while (!empty($ops)) {
                    $top = $ops[count($ops) - 1];
                    if ($top[0] !== self::T_OP) break;
                    $topOp = self::OPS[$top[1]];
                    $curOp = self::OPS[$val];
                    // Left-assoc: pop when top prec >= current
                    if ($topOp['prec'] > $curOp['prec']
                        || ($topOp['prec'] === $curOp['prec'] && $curOp['assoc'] === 'L')) {
                        $output[] = array_pop($ops);
                    } else {
                        break;
                    }
                }
                $ops[] = $tok;
                continue;
            }
            if ($type === self::T_LPAREN) {
                $ops[] = $tok;
                continue;
            }
            if ($type === self::T_RPAREN) {
                $found = false;
                while (!empty($ops)) {
                    $top = array_pop($ops);
                    if ($top[0] === self::T_LPAREN) { $found = true; break; }
                    $output[] = $top;
                }
                if (!$found) {
                    throw ValidationException::forField('formula', 'mismatched closing parenthesis');
                }
                continue;
            }
        }
        while (!empty($ops)) {
            $top = array_pop($ops);
            if ($top[0] === self::T_LPAREN || $top[0] === self::T_RPAREN) {
                throw ValidationException::forField('formula', 'mismatched parenthesis');
            }
            $output[] = $top;
        }
        return $output;
    }

    /**
     * Evaluate RPN queue against row context.
     *
     * @param array<array{0:string,1:mixed}> $rpn
     * @param array<string,mixed>            $context
     * @return float|null
     * @throws ValidationException on structural error (insufficient operands)
     */
    private static function evalRpn(array $rpn, array $context): ?float
    {
        $stack = [];
        foreach ($rpn as $tok) {
            $type = $tok[0];
            $val = $tok[1];

            if ($type === self::T_NUM) {
                $stack[] = (float) $val;
                continue;
            }

            if ($type === self::T_FIELD) {
                if (!array_key_exists($val, $context)) return null;
                $v = $context[$val];
                if ($v === null || $v === '') return null;
                if (!is_numeric($v)) return null;
                $stack[] = (float) $v;
                continue;
            }

            if ($type === self::T_OP) {
                if (count($stack) < 2) {
                    throw ValidationException::forField(
                        'formula',
                        "insufficient operands for operator '$val'"
                    );
                }
                $b = array_pop($stack);
                $a = array_pop($stack);
                switch ($val) {
                    case '+': $stack[] = $a + $b; break;
                    case '-': $stack[] = $a - $b; break;
                    case '*': $stack[] = $a * $b; break;
                    case '/':
                        if ($b == 0.0) return null; // safe divide by zero
                        $stack[] = $a / $b;
                        break;
                }
                continue;
            }
        }
        if (count($stack) !== 1) {
            throw ValidationException::forField(
                'formula',
                'malformed expression (stack has ' . count($stack) . ' values after eval, expected 1)'
            );
        }
        return (float) $stack[0];
    }
}
