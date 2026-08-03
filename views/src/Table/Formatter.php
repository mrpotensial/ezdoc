<?php

declare(strict_types=1);

namespace Ezdoc\Table;

use Ezdoc\Format\DateFormatter;

/**
 * Ezdoc\Table\Formatter — locale-aware value formatter untuk enhanced
 * tabledb markers (`{{tabledb.ns.col|currency}}` etc.). Semua method
 * static, pure, deterministic.
 *
 * ## Currency formatting
 *
 * - IDR: `Rp 1.500.000` (dot thousands, no decimals default)
 * - USD: `$1,500,000.00` (comma thousands, dot decimals)
 * - EUR: `€ 1.500.000,00` (dot thousands, comma decimals)
 *
 * Currency-specific separators mirror official ISO 4217 + regional
 * convention (BI untuk IDR, ECB untuk EUR).
 *
 * ## Number formatting
 *
 * Delegates to PHP `number_format()` dgn locale-appropriate separators.
 * Default locale 'id' → dot thousands, comma decimals. Locale 'en' →
 * comma thousands, dot decimals.
 *
 * ## Date formatting
 *
 * Delegates to PHP `date()` untuk format spec, then
 * {@see DateFormatter::localize()} untuk translate weekday/month names.
 *
 * Accepts input as:
 * - Unix timestamp (int)
 * - ISO 8601 string (`Y-m-d`, `Y-m-d\TH:i:s\Z`, dst)
 * - Any strtotime-parseable string
 *
 * Invalid input → empty string (no throw, no default-to-now).
 *
 * PHP 7.4+ compatible.
 */
final class Formatter
{
    /**
     * Currency configuration table. Key = ISO 4217 code.
     *
     * @var array<string,array{symbol:string,thousands:string,decimal:string,decimals:int,symbolBefore:bool,spaceAfterSymbol:bool}>
     */
    private const CURRENCIES = [
        'IDR' => [
            'symbol' => 'Rp',
            'thousands' => '.',
            'decimal' => ',',
            'decimals' => 0,
            'symbolBefore' => true,
            'spaceAfterSymbol' => true,
        ],
        'USD' => [
            'symbol' => '$',
            'thousands' => ',',
            'decimal' => '.',
            'decimals' => 2,
            'symbolBefore' => true,
            'spaceAfterSymbol' => false,
        ],
        'EUR' => [
            'symbol' => '€',
            'thousands' => '.',
            'decimal' => ',',
            'decimals' => 2,
            'symbolBefore' => true,
            'spaceAfterSymbol' => true,
        ],
        'SGD' => [
            'symbol' => 'S$',
            'thousands' => ',',
            'decimal' => '.',
            'decimals' => 2,
            'symbolBefore' => true,
            'spaceAfterSymbol' => false,
        ],
        'MYR' => [
            'symbol' => 'RM',
            'thousands' => ',',
            'decimal' => '.',
            'decimals' => 2,
            'symbolBefore' => true,
            'spaceAfterSymbol' => true,
        ],
    ];

    /**
     * Format value sebagai currency. Null/non-numeric → empty string.
     *
     * @param mixed  $val         float|int|numeric-string
     * @param string $currency    ISO 4217 code (default 'IDR')
     * @param int|null $decimals  override decimal places (null = use currency default)
     */
    public static function formatCurrency($val, string $currency = 'IDR', ?int $decimals = null): string
    {
        if ($val === null || $val === '' || !is_numeric($val)) return '';
        $currency = strtoupper($currency);
        $conf = self::CURRENCIES[$currency] ?? self::CURRENCIES['IDR'];
        $d = $decimals !== null ? max(0, $decimals) : $conf['decimals'];
        $num = number_format((float) $val, $d, $conf['decimal'], $conf['thousands']);
        $space = $conf['spaceAfterSymbol'] ? ' ' : '';
        return $conf['symbolBefore']
            ? $conf['symbol'] . $space . $num
            : $num . $space . $conf['symbol'];
    }

    /**
     * Format value sebagai number dgn locale separators.
     *
     * @param mixed  $val
     * @param int    $decimals   decimal places (default 0)
     * @param string $locale     'id' (default) atau 'en'
     */
    public static function formatNumber($val, int $decimals = 0, string $locale = 'id'): string
    {
        if ($val === null || $val === '' || !is_numeric($val)) return '';
        $d = max(0, $decimals);
        if ($locale === 'en') {
            return number_format((float) $val, $d, '.', ',');
        }
        // Default 'id' locale
        return number_format((float) $val, $d, ',', '.');
    }

    /**
     * Format value sebagai percentage. Input treated as ratio (0.5 = 50%)
     * kecuali `$asRatio = false` (value = raw percent, 50 = 50%).
     *
     * @param mixed  $val
     * @param int    $decimals   default 2
     * @param bool   $asRatio    true (default) = 0.5 → "50%"; false = 50 → "50%"
     * @param string $locale
     */
    public static function formatPercent($val, int $decimals = 2, bool $asRatio = true, string $locale = 'id'): string
    {
        if ($val === null || $val === '' || !is_numeric($val)) return '';
        $num = (float) $val;
        if ($asRatio) $num *= 100;
        return self::formatNumber($num, $decimals, $locale) . '%';
    }

    /**
     * Format value sebagai date/datetime. Locale-aware weekday/month names.
     *
     * @param mixed  $val     unix ts (int) atau strtotime-parseable string
     * @param string $format  PHP date format spec (default 'd/m/Y')
     * @param string $locale  default 'id'
     * @return string empty kalau invalid input
     */
    public static function formatDate($val, string $format = 'd/m/Y', string $locale = 'id'): string
    {
        if ($val === null || $val === '') return '';
        $ts = null;
        if (is_int($val)) {
            $ts = $val;
        } elseif (is_string($val) && $val !== '') {
            if (ctype_digit($val)) {
                $ts = (int) $val;
            } else {
                $parsed = strtotime($val);
                if ($parsed !== false) $ts = $parsed;
            }
        } elseif (is_object($val) && $val instanceof \DateTimeInterface) {
            $ts = $val->getTimestamp();
        }
        if ($ts === null) return '';
        $formatted = date($format, $ts);
        // Localize weekday/month names if format contains them (l, D, F, M)
        if ($locale !== 'en' && preg_match('/[lDFM]/', $format)) {
            return DateFormatter::localize($formatted, $locale);
        }
        return $formatted;
    }

    /**
     * Escape HTML entities for safe output. Table cells always go through
     * this — user-provided data must not break table markup.
     */
    public static function escapeHtml($val): string
    {
        if ($val === null) return '';
        return htmlspecialchars((string) $val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Format by column type shortcut. Convenient dispatch untuk enhanced
     * tabledb rendering (`_tabledbFormatValue()`).
     *
     * @param mixed              $val
     * @param string             $type    'text'|'number'|'currency'|'percent'|'date'|'datetime'
     * @param array<string,mixed> $opts   locale, currency, format, decimals, precision
     */
    public static function format($val, string $type, array $opts = []): string
    {
        switch (strtolower($type)) {
            case 'currency':
                return self::formatCurrency(
                    $val,
                    $opts['currency'] ?? 'IDR',
                    isset($opts['decimals']) ? (int) $opts['decimals'] : null
                );
            case 'number':
                return self::formatNumber(
                    $val,
                    isset($opts['decimals']) ? (int) $opts['decimals'] : 0,
                    $opts['locale'] ?? 'id'
                );
            case 'percent':
                return self::formatPercent(
                    $val,
                    isset($opts['decimals']) ? (int) $opts['decimals'] : 2,
                    $opts['asRatio'] ?? true,
                    $opts['locale'] ?? 'id'
                );
            case 'date':
                return self::formatDate($val, $opts['format'] ?? 'd/m/Y', $opts['locale'] ?? 'id');
            case 'datetime':
                return self::formatDate($val, $opts['format'] ?? 'd/m/Y H:i', $opts['locale'] ?? 'id');
            case 'text':
            default:
                return self::escapeHtml($val);
        }
    }
}
