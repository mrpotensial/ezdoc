<?php

declare(strict_types=1);

namespace Ezdoc\Format;

/**
 * Locale-aware date/time formatter.
 *
 * Industry-standard OO wrapper for translating English date component names
 * (day-of-week, month) to target locale. Modeled after Symfony/Intl
 * DateFormatter, Carbon's translatedFormat(), and Laravel's Str::of()->title().
 *
 * ## Design
 * - **Locale-driven table**: static translation table per locale, keyed by
 *   canonical English weekday/month names. Fast (O(1) per translation) +
 *   deterministic (no ext-intl dependency required).
 * - **Additive**: takes formatted date string (from PHP's `date()`) and
 *   REPLACES English tokens dgn locale equivalents. Preserves numerical
 *   parts, punctuation, arbitrary format specifiers.
 * - **Fallback-safe**: unknown locales return input unchanged.
 *
 * ## Locales supported (built-in)
 * - `en` — passthrough (identity, no translation)
 * - `id` — Bahasa Indonesia (default dogfood locale)
 *
 * ## Usage
 *
 * ```php
 * $formatted = date('l, d F Y');                   // "Wednesday, 15 January 2025"
 * $indo = DateFormatter::localize($formatted, 'id'); // "Rabu, 15 Januari 2025"
 * ```
 *
 * ## Extending
 * Register additional locale table via {@see registerLocale()}:
 * ```php
 * DateFormatter::registerLocale('ms', [
 *     'Monday' => 'Isnin', 'Tuesday' => 'Selasa', ...
 * ]);
 * ```
 *
 * spec: docs/LOCALIZATION.md
 */
final class DateFormatter
{
    /**
     * Registered locale translation tables.
     *
     * Structure: `[locale => [english_name => localized_name, ...]]`
     * Keys are canonical English names as output by PHP `date('l')`, `date('F')`.
     *
     * @var array<string,array<string,string>>
     */
    private static $locales = [
        'en' => [], // identity (no translation)
        'id' => [
            // Days
            'Sunday'    => 'Minggu',
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu',
            // Months
            'January'   => 'Januari',
            'February'  => 'Februari',
            'March'     => 'Maret',
            'April'     => 'April',
            'May'       => 'Mei',
            'June'      => 'Juni',
            'July'      => 'Juli',
            'August'    => 'Agustus',
            'September' => 'September',
            'October'   => 'Oktober',
            'November'  => 'November',
            'December'  => 'Desember',
        ],
    ];

    /**
     * Translate English weekday/month names di formatted date string ke target locale.
     *
     * @param string $formattedDate  Output dari PHP `date()` yg mengandung English names
     *                                (mis. "Wednesday, 15 January 2025")
     * @param string $locale          Target locale code (mis. "id", "en", "ms")
     * @return string                 Localized string, atau input unchanged kalau locale unknown
     */
    public static function localize(string $formattedDate, string $locale = 'id'): string
    {
        if (!isset(self::$locales[$locale]) || empty(self::$locales[$locale])) {
            return $formattedDate;
        }

        return strtr($formattedDate, self::$locales[$locale]);
    }

    /**
     * Register or override locale translation table.
     *
     * @param string                 $locale       Locale code
     * @param array<string,string>   $translations `[english_name => localized_name]` map
     */
    public static function registerLocale(string $locale, array $translations): void
    {
        self::$locales[$locale] = $translations;
    }

    /**
     * Get list of registered locale codes.
     *
     * @return string[]
     */
    public static function locales(): array
    {
        return array_keys(self::$locales);
    }
}
