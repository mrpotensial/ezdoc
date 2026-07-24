<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Table;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Table\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Test Ezdoc\Table\FormulaEvaluator — safe expression parser.
 *
 * Coverage priority:
 * 1. Correctness (arithmetic, precedence, parens, field refs)
 * 2. Null semantics (missing/empty/non-numeric fields, division by zero)
 * 3. Safety (reject function calls, property access, injection attempts)
 * 4. Parse errors (malformed input)
 */
final class FormulaEvaluatorTest extends TestCase
{
    // ─── Correctness ───

    public function testSimpleAddition(): void
    {
        $this->assertSame(3.0, FormulaEvaluator::evaluate('1 + 2', []));
    }

    public function testSimpleFieldReference(): void
    {
        $this->assertSame(42.0, FormulaEvaluator::evaluate('{qty}', ['qty' => 42]));
    }

    public function testFieldMultiplication(): void
    {
        $this->assertSame(50.0, FormulaEvaluator::evaluate('{qty} * {harga}', ['qty' => 5, 'harga' => 10]));
    }

    public function testPrecedenceMultBeforeAdd(): void
    {
        // 2 + 3 * 4 = 14 (not 20)
        $this->assertSame(14.0, FormulaEvaluator::evaluate('2 + 3 * 4', []));
    }

    public function testParensOverridePrecedence(): void
    {
        // (2 + 3) * 4 = 20
        $this->assertSame(20.0, FormulaEvaluator::evaluate('(2 + 3) * 4', []));
    }

    public function testDivision(): void
    {
        $this->assertSame(2.5, FormulaEvaluator::evaluate('10 / 4', []));
    }

    public function testNestedParens(): void
    {
        // ((1 + 2) * (3 + 4)) = 3 * 7 = 21
        $this->assertSame(21.0, FormulaEvaluator::evaluate('((1 + 2) * (3 + 4))', []));
    }

    public function testDecimalLiterals(): void
    {
        $this->assertSame(3.5, FormulaEvaluator::evaluate('1.5 + 2.0', []));
    }

    public function testFieldValueAsString(): void
    {
        // SQL often returns numeric columns as strings; must coerce
        $this->assertSame(150.0, FormulaEvaluator::evaluate('{qty} * {harga}', ['qty' => '15', 'harga' => '10']));
    }

    public function testWhitespaceTolerance(): void
    {
        $this->assertSame(6.0, FormulaEvaluator::evaluate('  1  +  2  +  3  ', []));
        $this->assertSame(6.0, FormulaEvaluator::evaluate('1+2+3', []));
    }

    public function testMultipleSubtractions(): void
    {
        // Left-assoc: 10 - 3 - 2 = (10 - 3) - 2 = 5, not 10 - (3 - 2) = 9
        $this->assertSame(5.0, FormulaEvaluator::evaluate('10 - 3 - 2', []));
    }

    public function testMultipleDivisions(): void
    {
        // Left-assoc: 100 / 5 / 2 = (100/5)/2 = 10, not 100/(5/2) = 40
        $this->assertSame(10.0, FormulaEvaluator::evaluate('100 / 5 / 2', []));
    }

    // ─── Null semantics ───

    public function testMissingFieldReturnsNull(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{missing_field}', ['qty' => 5]));
    }

    public function testMissingFieldPropagatesInExpression(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{qty} * {missing}', ['qty' => 5]));
    }

    public function testNullFieldValueReturnsNull(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{qty}', ['qty' => null]));
    }

    public function testEmptyStringFieldValueReturnsNull(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{qty}', ['qty' => '']));
    }

    public function testNonNumericFieldValueReturnsNull(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{qty}', ['qty' => 'abc']));
    }

    public function testDivisionByZeroReturnsNull(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('10 / 0', []));
    }

    public function testDivisionByZeroViaField(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{a} / {b}', ['a' => 10, 'b' => 0]));
    }

    public function testZeroDividedReturnsZero(): void
    {
        // 0 / n where n != 0 is valid, returns 0
        $this->assertSame(0.0, FormulaEvaluator::evaluate('0 / 5', []));
    }

    // ─── Safety — injection guards ───

    public function testRejectsFunctionCall(): void
    {
        // "sum" is not a valid token; `s` triggers invalid char
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('sum(1, 2)', []);
    }

    public function testRejectsPhpFunctionInFieldRef(): void
    {
        // {system(...)} — parens inside field name aren't allowed
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/invalid field name/');
        FormulaEvaluator::evaluate("{system('rm -rf /')}", []);
    }

    public function testRejectsPropertyAccess(): void
    {
        // dot outside a number is invalid
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('{obj}.prop', []);
    }

    public function testRejectsStringLiteral(): void
    {
        // Single quote is invalid character
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate("'malicious'", []);
    }

    public function testRejectsSemicolon(): void
    {
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('1 + 2; 3 + 4', []);
    }

    public function testRejectsBackticks(): void
    {
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('`whoami`', []);
    }

    public function testRejectsFieldNameWithSpecialChars(): void
    {
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('{qty-with-dash}', []);
    }

    public function testAllowsFieldNameWithUnderscoreAndDigits(): void
    {
        // Valid PHP-identifier-style names
        $this->assertSame(15.0, FormulaEvaluator::evaluate('{qty_1} + {harga_2}', [
            'qty_1' => 5,
            'harga_2' => 10,
        ]));
    }

    // ─── Parse errors ───

    public function testEmptyFormulaThrows(): void
    {
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('', []);
    }

    public function testWhitespaceOnlyFormulaThrows(): void
    {
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('   ', []);
    }

    public function testUnclosedFieldRefThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/unclosed field reference/');
        FormulaEvaluator::evaluate('{qty', []);
    }

    public function testMismatchedClosingParenThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/mismatched.*parenthesis/');
        FormulaEvaluator::evaluate('1 + 2)', []);
    }

    public function testMismatchedOpeningParenThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/mismatched parenthesis/');
        FormulaEvaluator::evaluate('(1 + 2', []);
    }

    public function testTrailingOperatorThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/insufficient operands/');
        FormulaEvaluator::evaluate('1 +', []);
    }

    public function testBackToBackOperatorsThrows(): void
    {
        $this->expectException(ValidationException::class);
        FormulaEvaluator::evaluate('1 + + 2', []);
    }

    public function testDoubleDotNumberThrows(): void
    {
        $this->expectException(ValidationException::class);
        // 1.2.3 — second dot is invalid character (not part of number)
        FormulaEvaluator::evaluate('1.2.3', []);
    }

    // ─── Real-world formulas ───

    public function testDiscountCalculation(): void
    {
        // total = (harga * qty) * (1 - diskon/100)
        $result = FormulaEvaluator::evaluate(
            '({harga} * {qty}) * (1 - {diskon} / 100)',
            ['harga' => 100000, 'qty' => 3, 'diskon' => 10]
        );
        $this->assertSame(270000.0, $result);
    }

    public function testPpnCalculation(): void
    {
        // ppn = subtotal * 11%
        $result = FormulaEvaluator::evaluate('{subtotal} * 11 / 100', ['subtotal' => 1000000]);
        $this->assertSame(110000.0, $result);
    }

    public function testProfitMarginCalculation(): void
    {
        // margin = (harga_jual - harga_beli) / harga_beli
        $result = FormulaEvaluator::evaluate(
            '({harga_jual} - {harga_beli}) / {harga_beli}',
            ['harga_jual' => 150000, 'harga_beli' => 100000]
        );
        $this->assertSame(0.5, $result);
    }
}
