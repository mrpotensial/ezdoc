<?php

/**
 * ezdoc polyfill — PHP 8.0+ functions untuk PHP 7.4 runtime.
 *
 * Semua polyfill di-guard dengan `function_exists()` supaya di PHP 8+
 * (yang sudah punya native) tidak di-override. Idempotent — safe include
 * multiple times.
 *
 * Untuk polyfill lebih lengkap & battle-tested, install:
 *   composer require symfony/polyfill-php80
 *
 * Referensi implementasi: symfony/polyfill-php80 (MIT license).
 *
 * NOTE: syntax-level features (readonly, property promotion, union types,
 * match, enum, never return) TIDAK bisa di-polyfill — harus refactor manual
 * karena parser reject sebelum runtime.
 */

// -----------------------------------------------------------------------------
// PHP 8.0
// -----------------------------------------------------------------------------

if (!function_exists('str_starts_with')) {
    /**
     * Check apakah $haystack diawali $needle.
     * @see https://www.php.net/manual/en/function.str-starts-with.php
     */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return 0 === strncmp($haystack, $needle, \strlen($needle));
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Check apakah $haystack diakhiri $needle.
     * @see https://www.php.net/manual/en/function.str-ends-with.php
     */
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ('' === $needle) {
            return true;
        }
        $needleLen = \strlen($needle);
        return $needleLen <= \strlen($haystack)
            && 0 === substr_compare($haystack, $needle, -$needleLen);
    }
}

if (!function_exists('str_contains')) {
    /**
     * Check apakah $haystack mengandung $needle.
     * @see https://www.php.net/manual/en/function.str-contains.php
     */
    function str_contains(string $haystack, string $needle): bool
    {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('get_debug_type')) {
    /**
     * Get short type name of a variable (untuk error message).
     * @see https://www.php.net/manual/en/function.get-debug-type.php
     */
    function get_debug_type($value): string
    {
        switch (true) {
            case null === $value: return 'null';
            case \is_bool($value): return 'bool';
            case \is_string($value): return 'string';
            case \is_array($value): return 'array';
            case \is_int($value): return 'int';
            case \is_float($value): return 'float';
            case \is_object($value):
                $class = \get_class($value);
                if (strpos($class, '@') !== false) return 'class@anonymous';
                return $class;
            case $value instanceof \__PHP_Incomplete_Class: return '__PHP_Incomplete_Class';
        }
        if (null === $type = @get_resource_type($value)) return 'unknown';
        if ('Unknown' === $type) $type = 'closed';
        return "resource ($type)";
    }
}

// -----------------------------------------------------------------------------
// PHP 8.1
// -----------------------------------------------------------------------------

if (!function_exists('array_is_list')) {
    /**
     * Check apakah array adalah list (integer keys 0..N-1 sequential).
     * @see https://www.php.net/manual/en/function.array-is-list.php
     */
    function array_is_list(array $array): bool
    {
        if ([] === $array) return true;
        if (!isset($array[0]) || !isset($array[count($array) - 1])) return false;
        $i = -1;
        foreach ($array as $k => $v) {
            if ($k !== ++$i) return false;
        }
        return true;
    }
}

// -----------------------------------------------------------------------------
// PHP 8.2
// -----------------------------------------------------------------------------

if (!function_exists('mb_str_pad')) {
    /**
     * Multibyte-safe str_pad.
     * @see https://www.php.net/manual/en/function.mb-str-pad.php
     */
    function mb_str_pad(string $string, int $length, string $pad_string = ' ', int $pad_type = STR_PAD_RIGHT, ?string $encoding = null): string
    {
        if (!\in_array($pad_type, [STR_PAD_LEFT, STR_PAD_RIGHT, STR_PAD_BOTH], true)) {
            throw new \ValueError('mb_str_pad(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH');
        }
        $encoding = $encoding ?? mb_internal_encoding();
        $strLen = mb_strlen($string, $encoding);
        $padLen = mb_strlen($pad_string, $encoding);
        if ($padLen === 0 || $length <= $strLen) return $string;
        $need = $length - $strLen;

        if ($pad_type === STR_PAD_LEFT) {
            $pad = str_repeat($pad_string, (int) ceil($need / $padLen));
            return mb_substr($pad, 0, $need, $encoding) . $string;
        }
        if ($pad_type === STR_PAD_RIGHT) {
            $pad = str_repeat($pad_string, (int) ceil($need / $padLen));
            return $string . mb_substr($pad, 0, $need, $encoding);
        }
        // STR_PAD_BOTH
        $leftNeed = (int) floor($need / 2);
        $rightNeed = $need - $leftNeed;
        $left = mb_substr(str_repeat($pad_string, (int) ceil($leftNeed / $padLen)), 0, $leftNeed, $encoding);
        $right = mb_substr(str_repeat($pad_string, (int) ceil($rightNeed / $padLen)), 0, $rightNeed, $encoding);
        return $left . $string . $right;
    }
}
