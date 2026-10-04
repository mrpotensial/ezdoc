<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Exceptions\ValidationException;

/**
 * Resolves the L1 signing key, and derives a purpose-specific key from it.
 *
 * ## Why derive instead of using the secret directly
 *
 * A deployment has one secret, and more than one thing wants to use it — verify
 * URLs are already signed with it. Signing document content with the same key
 * means a weakness in one use bleeds into the other, and it makes rotating one
 * without the other impossible.
 *
 * So the configured secret is treated as key material, not as a key: HKDF
 * (RFC 5869) expands it into a distinct key per purpose. Compromising a derived
 * key yields neither the master nor its siblings. `hash_hkdf()` is the standard
 * primitive for this and has shipped since PHP 7.1.2 — no hand-rolled
 * construction.
 *
 * The `info` string carries a version. When the derivation needs to change, the
 * label changes with it and old signatures stay verifiable under the old label.
 *
 * ## Where the secret comes from
 *
 * In order: the `EZDOC_HMAC_SECRET` constant, then the environment variable of
 * the same name. Nothing else — the library does not go looking through the
 * host's files.
 *
 * A host that keeps its secret elsewhere defines the constant during bootstrap.
 * At least 32 bytes; anything shorter is refused rather than stretched, because
 * a short secret silently accepted is a weak key nobody knows about.
 *
 * ## When it is missing
 *
 * {@see resolve()} returns null. Callers skip recording and say so, rather than
 * writing signature rows an unconfigured deployment cannot verify. A record
 * signed with no real key is worse than no record: it looks like evidence.
 */
final class SigningKey
{
    /** Minimum secret length. 256 bits of entropy, expressed as raw bytes. */
    private const MIN_SECRET_BYTES = 32;

    /** HKDF label for L1 document-content signing. Versioned on purpose. */
    public const PURPOSE_L1_CONTENT = 'ezdoc/signature/l1-content/v1';

    /** HKDF label for OTP codes derived per challenge (HOTP-style, RFC 4226). */
    public const PURPOSE_DELIVERED_CODE = 'ezdoc/otp/delivered-code/v1';

    /** @var array<string,string> derived keys, per purpose */
    private static $derived = [];

    /** @var bool one complaint per process */
    private static $warned = false;

    /**
     * Derived key for a purpose, or null when no secret is configured.
     *
     * @param string $purpose HKDF info label; use a `PURPOSE_*` constant
     * @return string|null 32 raw bytes
     */
    public static function resolve(string $purpose = self::PURPOSE_L1_CONTENT): ?string
    {
        if (isset(self::$derived[$purpose])) {
            return self::$derived[$purpose];
        }

        $secret = self::readSecret();
        if ($secret === null) {
            self::warnOnce(
                'no signing key: define EZDOC_HMAC_SECRET (constant or environment, at least '
                . self::MIN_SECRET_BYTES . ' bytes). Signatures will not be recorded until it is set — '
                . 'a record signed with no real key would look like evidence without being any.'
            );
            return null;
        }

        // HKDF-Expand with the purpose as `info`. No salt: the master secret is
        // already high-entropy random, which is the case RFC 5869 §3.1 says a
        // salt is optional for.
        $key = hash_hkdf('sha256', $secret, 32, $purpose);

        return self::$derived[$purpose] = $key;
    }

    /**
     * Whether a signing key is available, without deriving one.
     */
    public static function isConfigured(): bool
    {
        return self::readSecret() !== null;
    }

    /**
     * The configured secret, or null when absent or too short.
     */
    private static function readSecret(): ?string
    {
        if (defined('EZDOC_HMAC_SECRET')) {
            $candidate = (string) constant('EZDOC_HMAC_SECRET');
            if (strlen($candidate) >= self::MIN_SECRET_BYTES) {
                return $candidate;
            }
            self::warnOnce(sprintf(
                'EZDOC_HMAC_SECRET is defined but only %d bytes; at least %d are required. '
                . 'Refusing to stretch it — a short secret quietly accepted is a weak key nobody knows about.',
                strlen($candidate),
                self::MIN_SECRET_BYTES
            ));
            return null;
        }

        $env = getenv('EZDOC_HMAC_SECRET');
        if (is_string($env) && strlen($env) >= self::MIN_SECRET_BYTES) {
            return $env;
        }

        return null;
    }

    private static function warnOnce(string $why): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        @error_log('[ezdoc:signing-key] ' . $why);
    }

    /**
     * Forget cached derivations and the once-per-process latch.
     *
     * For tests, which change the configured secret between cases.
     */
    public static function reset(): void
    {
        self::$derived = [];
        self::$warned = false;
    }

    /**
     * Guard against constructing this — it holds no state worth an instance.
     *
     * @throws ValidationException
     */
    private function __construct()
    {
        throw ValidationException::forField('SigningKey', 'static only');
    }
}
