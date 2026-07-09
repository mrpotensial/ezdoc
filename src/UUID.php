<?php

declare(strict_types=1);

namespace Ezdoc;

/**
 * UUID generator — v7 (time-ordered, RFC 9562) + v4 (random, legacy).
 *
 * @see https://datatracker.ietf.org/doc/rfc9562/ RFC 9562 (May 2024)
 */
final class UUID
{
    /**
     * Generate UUID v7 — time-ordered, 36-char format.
     *
     * Layout (128 bits):
     *   48 bits: unix_ts_ms (milliseconds since epoch)
     *   4 bits:  version (0111 = 7)
     *   12 bits: rand_a
     *   2 bits:  variant (10 = RFC 4122)
     *   62 bits: rand_b
     *
     * Benefits vs v4:
     *   - Sortable by creation time (bulk INSERT friendly)
     *   - Better B-tree index locality (~30-50% faster inserts)
     *   - Chronological ordering visible in logs
     */
    public static function v7(): string
    {
        $unixTsMs = (int) (microtime(true) * 1000);
        $tsHex = str_pad(dechex($unixTsMs), 12, '0', STR_PAD_LEFT);

        $rand = random_bytes(10);
        $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70); // version 7
        $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80); // variant RFC 4122

        $randHex = bin2hex($rand);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($tsHex, 0, 8),
            substr($tsHex, 8, 4),
            substr($randHex, 0, 4),
            substr($randHex, 4, 4),
            substr($randHex, 8, 12)
        );
    }

    /**
     * Generate UUID v4 — random, legacy.
     * Prefer v7 for row keys.
     */
    public static function v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Extract timestamp (unix milliseconds) from UUID v7.
     * @return int|null null if not a valid UUID v7
     */
    public static function extractTimestampMs(string $uuid): ?int
    {
        if (!preg_match('/^([0-9a-f]{8})-([0-9a-f]{4})-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid, $m)) {
            return null;
        }

        return hexdec($m[1] . $m[2]);
    }

    /**
     * Check if a string is a valid UUID (any version).
     */
    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    /**
     * Extract version number from UUID.
     * @return int|null null if invalid
     */
    public static function version(string $uuid): ?int
    {
        if (!self::isValid($uuid)) return null;
        // Version is upper nibble of 7th byte (position 14 in "xxxxxxxx-xxxx-Vxxx-...")
        $versionChar = $uuid[14];
        return is_numeric($versionChar) ? (int) $versionChar : null;
    }
}
