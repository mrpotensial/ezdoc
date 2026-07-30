<?php

declare(strict_types=1);

namespace Ezdoc\Support;

use Ezdoc\Exceptions\ValidationException;

/**
 * Canonical JSON — one byte sequence per value, so a hash over it means
 * something.
 *
 * A hash is only useful if the same data always produces the same bytes.
 * `json_encode()` does not promise that: object key order follows insertion
 * order, so two documents with identical content hash differently depending on
 * the order fields happened to be written. Two people signing in a different
 * sequence is enough to produce a different fingerprint for the same document.
 *
 * ## What this implements
 *
 * RFC 8785 (JSON Canonicalization Scheme), the same role C14N plays for XML:
 *
 *  1. Object keys sorted by their UTF-16 code units (§3.2.3)
 *  2. Array order preserved — arrays are ordered in JSON, only objects are not
 *  3. No insignificant whitespace
 *  4. Minimal escaping, non-ASCII left literal (§3.2.2.2)
 *
 * ## Where it deliberately differs
 *
 * **Floating point is rejected**, where RFC 8785 §3.2.2.3 would serialise it
 * per ECMAScript `Number::toString`. PHP's float output depends on the
 * `serialize_precision` ini setting — the same value can encode as `0.1` on one
 * host and `0.10000000000000001` on another. A signature hash that changes with
 * php.ini is worse than one that refuses to be computed, so a float raises
 * instead of quietly producing a host-specific digest.
 *
 * In practice nothing hits it: values reaching this class come from HTTP form
 * input and are strings. A float means someone put a different kind of data in,
 * and hearing about it immediately is the point.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8785
 */
final class CanonicalJson
{
    /**
     * Canonical JSON text for a value.
     *
     * @param mixed $value Scalar, list, or map. Nested freely.
     * @throws ValidationException on float, NAN/INF, or a value JSON cannot hold
     */
    public static function encode($value): string
    {
        $normalised = self::normalise($value, '$');

        $json = json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw ValidationException::forField(
                'value',
                'canonical encode failed: ' . json_last_error_msg()
            );
        }

        return $json;
    }

    /**
     * Digest of the canonical form.
     *
     * @param mixed $value
     * @param string $algo Any algorithm `hash()` knows; sha256 unless told otherwise
     * @throws ValidationException on an unknown algorithm, or per {@see encode()}
     */
    public static function hash($value, string $algo = 'sha256'): string
    {
        if (!in_array($algo, hash_algos(), true)) {
            throw ValidationException::forField('algo', "unknown hash algorithm '{$algo}'");
        }

        return hash($algo, self::encode($value));
    }

    /**
     * Decode JSON text and re-encode it canonically.
     *
     * The usual entry point when the data is already stored as JSON: the stored
     * bytes carry whatever key order they were written with, and that is
     * precisely what must not reach the hash.
     *
     * @throws ValidationException on invalid JSON, or per {@see encode()}
     */
    public static function fromJson(string $json): string
    {
        if (trim($json) === '') {
            return '{}';
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::forField(
                'json',
                'not valid JSON: ' . json_last_error_msg()
            );
        }

        return self::encode($decoded);
    }

    /**
     * Recursively sort map keys and reject what cannot be canonicalised.
     *
     * @param mixed $value
     * @param string $path Where we are, so an error names the offending key
     * @return mixed
     * @throws ValidationException
     */
    private static function normalise($value, string $path)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            throw ValidationException::forField(
                'value',
                "float at {$path} cannot be canonicalised: PHP float output depends on "
                . 'serialize_precision, so the digest would differ between hosts. Pass it as a string.'
            );
        }

        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            throw ValidationException::forField(
                'value',
                'cannot canonicalise ' . gettype($value) . " at {$path}"
            );
        }

        // Arrays are ordered in JSON; only objects have unordered members. So a
        // list keeps its order and a map gets sorted.
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $i => $item) {
                $out[] = self::normalise($item, $path . '[' . $i . ']');
            }
            return $out;
        }

        $keys = array_map('strval', array_keys($value));
        usort($keys, [self::class, 'compareKeys']);

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::normalise($value[$key], $path . '.' . $key);
        }

        // json_encode turns an int-keyed array into a list, which would lose the
        // keys entirely. Forcing an object keeps `{"0":…}` as a member name.
        return (object) $out;
    }

    /**
     * Order two keys by their UTF-16 code units, per RFC 8785 §3.2.3.
     *
     * Comparing UTF-8 bytes gives code-point order, which agrees with UTF-16
     * for everything below U+10000 — but astral characters encode as surrogates
     * in the 0xD800..0xDFFF range and therefore sort *before* U+E000..U+FFFF.
     * Converting first is what makes emoji and rarer CJK sort the way another
     * RFC 8785 implementation would.
     */
    private static function compareKeys(string $a, string $b): int
    {
        return strcmp(self::toUtf16($a), self::toUtf16($b));
    }

    private static function toUtf16(string $s): string
    {
        $converted = @mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');

        // Invalid UTF-8 has no UTF-16 form. Fall back to the raw bytes so a
        // malformed key still sorts deterministically rather than throwing —
        // stability matters more here than which side of the order it lands on.
        return is_string($converted) && $converted !== '' ? $converted : $s;
    }
}
