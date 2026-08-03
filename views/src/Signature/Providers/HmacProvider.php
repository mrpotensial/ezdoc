<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Providers;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\ProviderCapabilities;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\SignResult;
use Ezdoc\Signature\SignatureProvider;
use Ezdoc\Signature\Verdict;
use Ezdoc\Signature\VerifyContext;

/**
 * Ezdoc\Signature\Providers\HmacProvider — level-1 integrity-only signer.
 *
 * Deterministic HMAC (default SHA-256). Cocok untuk QR verify slug atau
 * embedded integrity check di URL. TIDAK memberikan non-repudiation.
 *
 * ## Envelope
 *
 * `sign()` menghasilkan envelope berupa **hex-encoded** digest — konsisten
 * dengan `doc_verify_sign_slug()` legacy helper. Consumer boleh truncate
 * di layer atas kalau butuh signature pendek (mis. 16 hex = 64-bit).
 *
 * ## Compatibility
 *
 * PHP 7.4+. Constant-time compare via `hash_equals`.
 */
final class HmacProvider implements SignatureProvider
{
    /** @var string */
    private $secret;

    /** @var string */
    private $algo;

    /** @var int */
    const MIN_SECRET_LEN = 32;

    /** @var string */
    const PROVIDER_NAME = 'hmac';

    /**
     * @param string $secret raw shared secret; >= 32 bytes
     * @param string $algo   hash algo (mis. 'sha256', 'sha384', 'sha512')
     * @throws ValidationException
     */
    public function __construct(string $secret, string $algo = 'sha256')
    {
        if (strlen($secret) < self::MIN_SECRET_LEN) {
            throw ValidationException::forField(
                'secret',
                'HMAC secret must be at least ' . self::MIN_SECRET_LEN . ' bytes'
            );
        }
        $algo = strtolower($algo);
        if (!in_array($algo, hash_hmac_algos(), true)) {
            throw ValidationException::forField('algo', 'unsupported hash algorithm: ' . $algo);
        }
        $this->secret = $secret;
        $this->algo = $algo;
    }

    /**
     * Factory: baca secret dari env var EZDOC_HMAC_SECRET.
     *
     * @param string $algo
     * @return self
     * @throws EzdocException kalau env var kosong / < 32 char
     */
    public static function fromEnv(string $algo = 'sha256'): self
    {
        $raw = getenv('EZDOC_HMAC_SECRET');
        if (!is_string($raw) || $raw === '') {
            throw new EzdocException(
                'HmacProvider::fromEnv(): EZDOC_HMAC_SECRET env var not set. '
                . 'Set via server env atau injeksi secret manual ke constructor.'
            );
        }
        if (strlen($raw) < self::MIN_SECRET_LEN) {
            throw new EzdocException(
                'HmacProvider::fromEnv(): EZDOC_HMAC_SECRET too short (need >= '
                . self::MIN_SECRET_LEN . ' bytes).'
            );
        }
        return new self($raw, $algo);
    }

    /**
     * {@inheritdoc}
     *
     * Envelope adalah hex-encoded HMAC digest atas `contentHash`.
     * Level = 1. Format = 'hmac'.
     */
    public function sign(SignRequest $req): SignResult
    {
        // HMAC atas contentHash (bukan contentBytes) — 64-char hex input
        // memberi domain separation & panjang tetap. Consistent dgn pola
        // doc_verify_sign_slug() yang sign atas string slug.
        $binary = hash_hmac($this->algo, $req->getContentHash(), $this->secret, true);
        $envelope = bin2hex($binary);

        return new SignResult([
            'envelope' => $envelope,
            'envelopeFormat' => 'hmac',
            'signerId' => $req->getSignerId(),
            'certificatePem' => null,
            'providerName' => self::PROVIDER_NAME,
            'level' => 1,
            'signedAt' => self::nowIso8601Ms(),
            'metadata' => array_merge(
                $req->getMetadata(),
                [
                    'algo' => $this->algo,
                    'content_hash' => $req->getContentHash(),
                ]
            ),
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * Re-hash `$ctx->contentBytes`, recompute HMAC, `hash_equals` compare.
     * Envelope diharapkan hex-encoded — accept case-insensitive.
     */
    public function verify(string $envelope, VerifyContext $ctx): Verdict
    {
        if ($envelope === '') {
            return Verdict::error('empty envelope');
        }
        // Envelope should be lowercase hex — normalize sebelum bandingin.
        $envNorm = strtolower($envelope);
        if (!preg_match('/^[0-9a-f]+$/', $envNorm)) {
            return Verdict::error('envelope is not hex-encoded');
        }

        $contentHash = hash('sha256', $ctx->getContentBytes());
        $expectedBin = hash_hmac($this->algo, $contentHash, $this->secret, true);
        $expectedHex = bin2hex($expectedBin);

        // Length may differ — mismatch definitively tampered/different algo.
        if (strlen($envNorm) !== strlen($expectedHex)) {
            return Verdict::tampered('envelope length mismatch', [
                'algo' => $this->algo,
                'expected_len' => strlen($expectedHex),
                'actual_len' => strlen($envNorm),
            ]);
        }

        if (!hash_equals($expectedHex, $envNorm)) {
            return Verdict::tampered('hmac digest mismatch', [
                'algo' => $this->algo,
            ]);
        }

        $signerId = $ctx->getExpectedSignerId();
        return Verdict::valid($signerId, null, [
            'algo' => $this->algo,
            'content_hash' => $contentHash,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            self::PROVIDER_NAME,
            1,
            ['hmac', 'raw'],
            false,
            null,
            'Level 1 integrity-only HMAC-' . strtoupper($this->algo)
            . '. Deterministic; envelope = hex digest. No non-repudiation.'
        );
    }

    /**
     * ISO 8601 UTC dengan millisecond presisi.
     */
    private static function nowIso8601Ms(): string
    {
        // microtime(true) → 1720579200.123
        $mt = microtime(true);
        $sec = (int) floor($mt);
        $ms = (int) round(($mt - $sec) * 1000);
        if ($ms >= 1000) {
            // Rounding edge (0.9995 → 1000ms). Push ke detik berikutnya.
            $sec += 1;
            $ms = 0;
        }
        $dt = gmdate('Y-m-d\TH:i:s', $sec);
        return $dt . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT) . 'Z';
    }
}
