<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Context;
use Ezdoc\Exceptions\EzdocException;

/**
 * Service orchestrating OTP challenges, verification, and provider dispatch.
 *
 * ## Trust model
 *
 * A verified challenge yields a single-use *sign token*. That token is bound
 * to the exact (template, signature slot, document) triple it was issued for
 * — `ttd_id` alone is not enough, since slot ids are template-local and
 * collide freely across templates.
 *
 * Consuming the token is a single conditional UPDATE, so two concurrent saves
 * cannot both succeed with the same token.
 */
class OtpService
{
    /** Attempts allowed per challenge before it is burned. */
    private const MAX_ATTEMPTS = 3;

    /** Seconds a caller must wait before requesting a new code for the same slot. */
    private const SEND_COOLDOWN_SECONDS = 60;

    /** Bounds for challenge lifetime, regardless of what the template asks for. */
    private const TTL_MIN_SECONDS = 60;
    private const TTL_MAX_SECONDS = 900;

    private OtpChallengeRepository $repo;
    private ?UserOtpProvider $provider;

    public function __construct(?OtpChallengeRepository $repo = null, ?UserOtpProvider $provider = null)
    {
        $ctx = Context::default();
        $this->repo = $repo ?? new OtpChallengeRepository($ctx->db);
        $this->provider = $provider ?? $ctx->otpProvider;
    }

    /**
     * Read a signature slot's OTP settings out of template HTML.
     *
     * Single source of truth for these attributes — they were previously
     * re-parsed in three places with divergent regexes and key names, so a
     * setting saved by the designer never reached the code that enforced it.
     *
     * @param string $templateHtml Raw template markup.
     * @param string $ttdId        Signature slot id to look for.
     * @return array{
     *     found: bool, label: string, require_otp: bool,
     *     phone_source: string, ttl_seconds: int,
     *     rbac: array{roles: array, users: array}
     * }
     */
    public static function findTtdConfig(string $templateHtml, string $ttdId): array
    {
        $blank = [
            'found'        => false,
            'label'        => $ttdId,
            'require_otp'  => false,
            'phone_source' => '',
            'ttl_seconds'  => self::TTL_MAX_SECONDS,
            'rbac'         => ['roles' => [], 'users' => []],
        ];

        if ($templateHtml === '' || $ttdId === '') {
            return $blank;
        }

        preg_match_all(
            '/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>/i',
            $templateHtml,
            $matches
        );

        foreach ($matches[1] as $attrs) {
            if (!preg_match('/data-ttd="([^"]+)"/', $attrs, $m) || $m[1] !== $ttdId) {
                continue;
            }

            preg_match('/data-label="([^"]*)"/', $attrs, $label);
            preg_match('/data-allowed-roles="([^"]*)"/', $attrs, $roles);
            preg_match('/data-allowed-users="([^"]*)"/', $attrs, $users);
            preg_match('/data-require-otp="([^"]*)"/', $attrs, $requireOtp);
            preg_match('/data-otp-phone-source="([^"]*)"/', $attrs, $phoneSource);
            preg_match('/data-otp-ttl="([^"]*)"/', $attrs, $ttl);

            $rawOtp = $requireOtp[1] ?? '';
            $rawTtl = $ttl[1] ?? '';

            return [
                'found'        => true,
                'label'        => ($label[1] ?? '') !== '' ? $label[1] : $ttdId,
                'require_otp'  => $rawOtp === '1' || strtolower($rawOtp) === 'true',
                'phone_source' => $phoneSource[1] ?? '',
                'ttl_seconds'  => self::clampTtl($rawTtl !== '' ? (int) $rawTtl : self::TTL_MAX_SECONDS),
                'rbac'         => function_exists('ezdoc_parse_ttd_config')
                    ? ezdoc_parse_ttd_config($roles[1] ?? '', $users[1] ?? '')
                    : ['roles' => [], 'users' => []],
            ];
        }

        return $blank;
    }

    /** Keep challenge lifetime inside sane bounds. */
    public static function clampTtl(int $seconds): int
    {
        return max(self::TTL_MIN_SECONDS, min(self::TTL_MAX_SECONDS, $seconds));
    }

    /**
     * Create and send an OTP challenge.
     *
     * @param int         $templateId Template ID
     * @param string      $ttdId      Signature placeholder ID
     * @param string      $phone      Patient phone number
     * @param string      $name       Patient name
     * @param int|null    $docId      Document ID (optional)
     * @param int         $ttlSeconds Time to live in seconds (default 300 = 5 minutes)
     * @param array       $context    Extra context for provider
     * @return array Array containing ['challenge_id' => string, 'masked_phone' => string, 'expires_at' => string]
     * @throws EzdocException
     */
    public function createChallenge(
        int $templateId,
        string $ttdId,
        string $phone,
        string $name = 'Pasien',
        ?int $docId = null,
        int $ttlSeconds = 300,
        array $context = []
    ): array {
        if ($this->provider === null) {
            throw new EzdocException('No UserOtpProvider is configured in the Ezdoc context.');
        }

        $cleanPhone = self::normalizePhone($phone);
        if (strlen($cleanPhone) < 8) {
            throw new EzdocException('Invalid recipient phone number.');
        }

        // Cooldown: without it, each request mints a fresh challenge with its
        // own attempt budget, so the 3-attempt cap caps nothing — and every
        // request costs a real message.
        $lastSentAt = $this->repo->lastChallengeCreatedAt($templateId, $ttdId, $docId);
        if ($lastSentAt !== null) {
            $elapsed = time() - $lastSentAt;
            if ($elapsed < self::SEND_COOLDOWN_SECONDS) {
                throw new EzdocException(sprintf(
                    'Please wait %d more second(s) before requesting another code.',
                    self::SEND_COOLDOWN_SECONDS - $elapsed
                ));
            }
        }

        $ttlSeconds = self::clampTtl($ttlSeconds);

        $otpCode = (string) random_int(100000, 999999);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);

        $challengeId = 'otp_' . bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $sendContext = array_merge($context, [
            'challenge_id' => $challengeId,
            'template_id'  => $templateId,
            'ttd_id'       => $ttdId,
            'doc_id'       => $docId,
        ]);

        // Record the attempt before dispatching, so a provider that is slow or
        // failing still counts against the cooldown. Otherwise a broken
        // provider becomes an unmetered send loop.
        $this->repo->create([
            'challenge_id'  => $challengeId,
            'doc_id'        => $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'phone'         => $cleanPhone,
            'otp_hash'      => $otpHash,
            'max_attempts'  => self::MAX_ATTEMPTS,
            'expires_at'    => $expiresAt,
            'provider_meta' => [],
        ]);

        $sendResult = $this->provider->send($cleanPhone, $name, $otpCode, $sendContext);

        if (!$sendResult->isSuccess()) {
            // Burn the challenge so an undelivered code can never be verified.
            $this->repo->expire($challengeId);
            throw new EzdocException('Failed to send OTP: ' . $sendResult->getMessage());
        }

        $this->repo->attachProviderMeta($challengeId, $sendResult->getProviderMeta());

        return [
            'challenge_id' => $challengeId,
            'masked_phone' => self::maskPhone($cleanPhone),
            'expires_at'   => $expiresAt,
        ];
    }

    /**
     * Verify an OTP challenge code.
     *
     * @param string $challengeId
     * @param string $otpInput 6-digit code provided by patient/staff
     * @return OtpVerifyResult
     */
    public function verify(string $challengeId, string $otpInput): OtpVerifyResult
    {
        // One generic message for every rejection. Distinct messages ("not
        // found" vs "expired" vs "wrong code") let a caller probe which
        // challenge ids exist and what state they are in.
        $generic = 'Incorrect or expired code.';

        $challenge = $this->repo->findById($challengeId);
        if (!$challenge) {
            return OtpVerifyResult::failure($generic);
        }

        if (!empty($challenge['verified_at'])) {
            return OtpVerifyResult::failure($generic);
        }

        if (strtotime($challenge['expires_at']) < time()) {
            return OtpVerifyResult::failure($generic);
        }

        $maxAttempts = (int) ($challenge['max_attempts'] ?: self::MAX_ATTEMPTS);

        // Charge the attempt first, conditionally. If the row was already at
        // the cap this returns null and nothing is compared — so parallel
        // requests cannot spend more attempts than the budget allows.
        $newAttempts = $this->repo->chargeAttempt($challengeId, $maxAttempts);
        if ($newAttempts === null) {
            return OtpVerifyResult::failure($generic, 0);
        }

        $left = max(0, $maxAttempts - $newAttempts);

        if (!password_verify(trim($otpInput), (string) $challenge['otp_hash'])) {
            return OtpVerifyResult::failure($generic, $left);
        }

        // Correct code. markVerified only succeeds if this is the first time,
        // so concurrent correct submissions cannot mint two tokens.
        $signToken = 'stkn_' . bin2hex(random_bytes(32));
        if (!$this->repo->markVerified($challengeId, $signToken)) {
            return OtpVerifyResult::failure($generic, $left);
        }

        return OtpVerifyResult::success($signToken, 'Code verified.');
    }

    /**
     * Consume a sign token, if it is valid for exactly this signature slot.
     *
     * Single-use: the first caller wins and the token is spent. Binding covers
     * template, slot and document together — a token minted against a slot
     * called `ttd1` on one template must not satisfy a slot that happens to
     * share that id on another.
     *
     * @param string   $signToken  Token returned by {@see verify()}.
     * @param string   $ttdId      Signature slot the token must belong to.
     * @param int      $templateId Template the token must belong to.
     * @param int|null $docId      Document, when the challenge was raised for one.
     */
    public function validateSignToken(string $signToken, string $ttdId, int $templateId, ?int $docId = null): bool
    {
        if ($signToken === '' || $ttdId === '' || $templateId <= 0) {
            return false;
        }
        return $this->repo->consumeSignToken($signToken, $ttdId, $templateId, $docId);
    }

    /**
     * Whether a sign token would be accepted right now, without spending it.
     *
     * For callers that validate before doing work that can still fail. Spending
     * the token up front means a later refusal destroys it, and the operator is
     * asked for a fresh code for a signature that was never stored — the token
     * is single-use, so nothing recovers it.
     *
     * Pair with {@see validateSignToken()}: check here, consume once the work
     * has succeeded. Pass the same `$docId` to both, or the consume will not
     * match what was checked.
     */
    public function checkSignToken(string $signToken, string $ttdId, int $templateId, ?int $docId = null): bool
    {
        if ($signToken === '' || $ttdId === '' || $templateId <= 0) {
            return false;
        }
        return $this->repo->verifySignToken($signToken, $ttdId, $templateId, $docId);
    }

    /**
     * Normalize a phone number to E.164 digits, without the leading "+".
     *
     * A number already in international form is left alone. A national-format
     * number is prefixed with the configured country code — set
     * `EZDOC_OTP_COUNTRY_CODE` to match the deployment (defaults to `62`,
     * Indonesia). Set it to an empty string to disable national-form handling
     * and require callers to pass E.164 themselves.
     */
    public static function normalizePhone(string $phone): string
    {
        $s = trim($phone);
        $hasPlus = ($s !== '' && $s[0] === '+');
        $d = preg_replace('/\D+/', '', $s);
        if ($d === '') return '';

        // Explicit international form — trust it.
        if ($hasPlus) return $d;

        $cc = defined('EZDOC_OTP_COUNTRY_CODE') ? (string) EZDOC_OTP_COUNTRY_CODE : '62';
        if ($cc === '') return $d;

        // Already carries the country code.
        if (strpos($d, $cc) === 0 && strlen($d) >= strlen($cc) + 8) return $d;

        // National trunk prefix "0" — replace it with the country code.
        if ($d[0] === '0') return $cc . substr($d, 1);

        // Bare national subscriber number.
        if (strlen($d) >= 8 && strlen($d) <= 13) return $cc . $d;

        return $d;
    }

    /**
     * Mask a phone number for display, e.g. `6289693814458` → `0896****4458`.
     *
     * Always hides at least four digits. Short numbers are masked more
     * aggressively rather than less: the previous rule exposed an 8-digit
     * number in full (4 leading + 4 trailing covers all of it) and returned
     * anything 7 digits or shorter completely unmasked.
     */
    public static function maskPhone(string $phone): string
    {
        $d = self::normalizePhone($phone);
        if ($d === '') return '';

        $cc = defined('EZDOC_OTP_COUNTRY_CODE') ? (string) EZDOC_OTP_COUNTRY_CODE : '62';
        $local = ($cc !== '' && strpos($d, $cc) === 0) ? '0' . substr($d, strlen($cc)) : $d;

        $len = strlen($local);
        $minHidden = 4;

        // Too short to reveal anything on both ends.
        if ($len <= $minHidden + 2) {
            return str_repeat('*', $len);
        }

        // Split the visible budget between head and tail, never letting the
        // two halves cover the whole number.
        $visible = $len - $minHidden;
        $head = (int) min(4, ceil($visible / 2));
        $tail = (int) min(4, $visible - $head);

        return substr($local, 0, $head)
            . str_repeat('*', $len - $head - $tail)
            . ($tail > 0 ? substr($local, -$tail) : '');
    }
}
