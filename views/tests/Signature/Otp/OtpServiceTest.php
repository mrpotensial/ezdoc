<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Signature\Otp\OtpService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, dependency-free surface of {@see OtpService}:
 * phone normalisation, display masking, TTL clamping, and template config
 * parsing.
 *
 * Challenge lifecycle (attempt cap, single-use tokens, cooldown, binding)
 * needs a database and lives in the integration suite.
 */
final class OtpServiceTest extends TestCase
{
    // ─── normalizePhone ───

    public function testNormalizeConvertsNationalTrunkPrefix(): void
    {
        self::assertSame('6289693814458', OtpService::normalizePhone('089693814458'));
    }

    public function testNormalizeKeepsExistingCountryCode(): void
    {
        self::assertSame('6289693814458', OtpService::normalizePhone('6289693814458'));
    }

    public function testNormalizeStripsFormatting(): void
    {
        self::assertSame('6289693814458', OtpService::normalizePhone('0896-9381-4458'));
        self::assertSame('6289693814458', OtpService::normalizePhone(' (0896) 9381 4458 '));
    }

    public function testNormalizeTrustsExplicitInternationalForm(): void
    {
        // A leading "+" means the caller already gave E.164 — do not re-prefix.
        self::assertSame('14155552671', OtpService::normalizePhone('+1 415 555 2671'));
    }

    public function testNormalizeAddsCountryCodeToBareSubscriberNumber(): void
    {
        self::assertSame('6289693814458', OtpService::normalizePhone('89693814458'));
    }

    public function testNormalizeReturnsEmptyForNonNumeric(): void
    {
        self::assertSame('', OtpService::normalizePhone(''));
        self::assertSame('', OtpService::normalizePhone('not a phone'));
    }

    // ─── maskPhone ───

    public function testMaskHidesMiddleDigits(): void
    {
        $masked = OtpService::maskPhone('6289693814458');

        self::assertStringContainsString('*', $masked);
        self::assertStringStartsWith('0896', $masked);
        self::assertStringEndsWith('4458', $masked);
    }

    /**
     * Regression: the previous rule took 4 leading + 4 trailing digits, which
     * for an 8-digit number covered the whole thing — the "mask" printed every
     * digit with stars merely inserted between them.
     */
    public function testMaskNeverRevealsEveryDigit(): void
    {
        foreach (['12345678', '1234567', '123456', '12345'] as $number) {
            $masked = OtpService::maskPhone($number);
            $visible = preg_replace('/\D/', '', $masked);

            self::assertNotSame(
                $number,
                $visible,
                "maskPhone('{$number}') exposed the full number as '{$masked}'"
            );
            self::assertGreaterThanOrEqual(
                4,
                substr_count($masked, '*'),
                "maskPhone('{$number}') hid fewer than 4 digits: '{$masked}'"
            );
        }
    }

    public function testMaskHandlesEmptyInput(): void
    {
        self::assertSame('', OtpService::maskPhone(''));
    }

    // ─── clampTtl ───

    public function testClampRejectsUnboundedLifetime(): void
    {
        // A client-supplied TTL previously had a floor but no ceiling, so
        // ttl_seconds=999999999 produced a code that never expired.
        self::assertSame(900, OtpService::clampTtl(999999999));
    }

    public function testClampRaisesTooShortLifetime(): void
    {
        self::assertSame(60, OtpService::clampTtl(1));
        self::assertSame(60, OtpService::clampTtl(0));
        self::assertSame(60, OtpService::clampTtl(-30));
    }

    public function testClampPassesReasonableLifetimeThrough(): void
    {
        self::assertSame(300, OtpService::clampTtl(300));
    }

    // ─── findTtdConfig ───

    public function testFindTtdConfigReadsSlotSettings(): void
    {
        $html = '<div class="ttd-placeholder" data-ttd="ttd1" data-label="Pasien"'
              . ' data-require-otp="1" data-otp-phone-source="nomor_hp"'
              . ' data-otp-ttl="300"></div>';

        $config = OtpService::findTtdConfig($html, 'ttd1');

        self::assertTrue($config['found']);
        self::assertTrue($config['require_otp']);
        self::assertSame('Pasien', $config['label']);
        self::assertSame('nomor_hp', $config['phone_source']);
        self::assertSame(300, $config['ttl_seconds']);
    }

    public function testFindTtdConfigClampsSlotTtl(): void
    {
        $html = '<div class="ttd-placeholder" data-ttd="ttd1" data-otp-ttl="99999"></div>';

        self::assertSame(900, OtpService::findTtdConfig($html, 'ttd1')['ttl_seconds']);
    }

    public function testFindTtdConfigPicksTheRequestedSlot(): void
    {
        $html = '<div class="ttd-placeholder" data-ttd="ttd1" data-require-otp="1"></div>'
              . '<div class="ttd-placeholder" data-ttd="ttd2" data-require-otp="0"></div>';

        self::assertTrue(OtpService::findTtdConfig($html, 'ttd1')['require_otp']);
        self::assertFalse(OtpService::findTtdConfig($html, 'ttd2')['require_otp']);
    }

    public function testFindTtdConfigReportsMissingSlot(): void
    {
        $config = OtpService::findTtdConfig('<p>no signatures here</p>', 'ttd1');

        self::assertFalse($config['found']);
        self::assertFalse($config['require_otp']);
    }

    /**
     * A slot with no `data-require-otp` must not be treated as requiring OTP,
     * and a missing slot must never report `require_otp => true` — that would
     * let a caller demand codes for slots that do not exist.
     */
    public function testFindTtdConfigDefaultsToNoOtp(): void
    {
        $html = '<div class="ttd-placeholder" data-ttd="ttd1" data-label="Dokter"></div>';

        self::assertFalse(OtpService::findTtdConfig($html, 'ttd1')['require_otp']);
        self::assertFalse(OtpService::findTtdConfig($html, 'unknown')['require_otp']);
    }

    public function testFindTtdConfigAcceptsTrueLiteral(): void
    {
        $html = '<div class="ttd-placeholder" data-ttd="ttd1" data-require-otp="true"></div>';

        self::assertTrue(OtpService::findTtdConfig($html, 'ttd1')['require_otp']);
    }

    public function testFindTtdConfigHandlesEmptyInput(): void
    {
        self::assertFalse(OtpService::findTtdConfig('', 'ttd1')['found']);
        self::assertFalse(OtpService::findTtdConfig('<div></div>', '')['found']);
    }
}
