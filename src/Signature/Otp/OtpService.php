<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Context;
use Ezdoc\Exceptions\EzdocException;

/**
 * Service orchestrating OTP challenges, verification, and provider dispatch.
 */
class OtpService
{
    private OtpChallengeRepository $repo;
    private ?UserOtpProvider $provider;

    public function __construct(?OtpChallengeRepository $repo = null, ?UserOtpProvider $provider = null)
    {
        $ctx = Context::default();
        $this->repo = $repo ?? new OtpChallengeRepository($ctx->db);
        $this->provider = $provider ?? $ctx->otpProvider;
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
            throw new EzdocException('UserOtpProvider is not configured in Ezdoc Context.');
        }

        $cleanPhone = self::normalizePhone($phone);
        if (strlen($cleanPhone) < 8) {
            throw new EzdocException('Nomor telepon pasien tidak valid.');
        }

        // Generate 6-digit OTP
        $otpCode = (string) random_int(100000, 999999);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);

        // Challenge ID
        $challengeId = 'otp_' . bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));

        // Call provider
        $sendContext = array_merge($context, [
            'challenge_id' => $challengeId,
            'template_id'  => $templateId,
            'ttd_id'       => $ttdId,
            'doc_id'       => $docId,
        ]);

        $sendResult = $this->provider->send($cleanPhone, $name, $otpCode, $sendContext);

        if (!$sendResult->isSuccess()) {
            throw new EzdocException('Gagal mengirim OTP: ' . $sendResult->getMessage());
        }

        // Save challenge record
        $this->repo->create([
            'challenge_id'  => $challengeId,
            'doc_id'        => $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'phone'         => $cleanPhone,
            'otp_hash'      => $otpHash,
            'max_attempts'  => 3,
            'expires_at'    => $expiresAt,
            'provider_meta' => $sendResult->getProviderMeta(),
        ]);

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
        $challenge = $this->repo->findById($challengeId);
        if (!$challenge) {
            return OtpVerifyResult::failure('Sesi OTP tidak ditemukan atau sudah kadaluarsa.');
        }

        if (!empty($challenge['verified_at'])) {
            return OtpVerifyResult::failure('OTP ini sudah pernah diverifikasi sebelumnya.');
        }

        if (strtotime($challenge['expires_at']) < time()) {
            return OtpVerifyResult::failure('Kode OTP telah kadaluarsa. Silakan kirim ulang OTP.');
        }

        $attempts = (int) $challenge['attempts'];
        $maxAttempts = (int) ($challenge['max_attempts'] ?: 3);

        if ($attempts >= $maxAttempts) {
            return OtpVerifyResult::failure('Batas percobaan masukan OTP telah habis. Silakan kirim ulang OTP.', 0);
        }

        $otpInput = trim($otpInput);
        if (!password_verify($otpInput, $challenge['otp_hash'])) {
            $newAttempts = $this->repo->incrementAttempts($challengeId);
            $left = max(0, $maxAttempts - $newAttempts);

            if ($left <= 0) {
                return OtpVerifyResult::failure('Kode OTP salah. Batas percobaan telah habis.', 0);
            }

            return OtpVerifyResult::failure("Kode OTP tidak sesuai. Sisa percobaan: {$left} kali.", $left);
        }

        // OTP is valid! Issue single-use sign token
        $signToken = 'stkn_' . bin2hex(random_bytes(32));
        $this->repo->markVerified($challengeId, $signToken);

        return OtpVerifyResult::success($signToken, 'Verifikasi OTP berhasil.');
    }

    /**
     * Validate sign_token before saving signature.
     */
    public function validateSignToken(string $signToken, string $ttdId): bool
    {
        if (!$signToken) {
            return false;
        }
        return $this->repo->validateAndConsumeSignToken($signToken, $ttdId);
    }

    /**
     * Helper: normalize phone number to E.164 standard without leading "+".
     */
    public static function normalizePhone(string $phone): string
    {
        $s = trim($phone);
        $hasPlus = ($s !== '' && $s[0] === '+');
        $d = preg_replace('/\D+/', '', $s);
        if ($d === '') return '';

        if ($hasPlus) return $d;
        if (strpos($d, '62') === 0 && strlen($d) >= 10) return $d;
        if ($d[0] === '0') return '62' . substr($d, 1);
        if ($d[0] === '8' && strlen($d) >= 9 && strlen($d) <= 13) return '62' . $d;

        return $d;
    }

    /**
     * Helper: mask phone number for UI display (e.g. 6289693814458 -> 0896***4458).
     */
    public static function maskPhone(string $phone): string
    {
        $d = self::normalizePhone($phone);
        if (strpos($d, '62') === 0) {
            $local = '0' . substr($d, 2);
        } else {
            $local = $d;
        }

        $len = strlen($local);
        if ($len <= 7) return $local;

        $prefix = substr($local, 0, 4);
        $suffix = substr($local, -4);
        $stars = str_repeat('*', max(3, $len - 8));

        return $prefix . $stars . $suffix;
    }
}
