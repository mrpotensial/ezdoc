<?php

declare(strict_types=1);

namespace Ezdoc\Tests;

use Ezdoc\Signature\Otp\OtpService;
use Ezdoc\Signature\Otp\UserOtpProvider;
use Ezdoc\Signature\Otp\OtpSendResult;
use Ezdoc\Signature\Otp\OtpChallengeRepository;
use PHPUnit\Framework\TestCase;

class OtpServiceTest extends TestCase
{
    public function testPhoneNormalization(): void
    {
        $this->assertEquals('6289693814458', OtpService::normalizePhone('089693814458'));
        $this->assertEquals('6289693814458', OtpService::normalizePhone('+6289693814458'));
        $this->assertEquals('6289693814458', OtpService::normalizePhone('6289693814458'));
    }

    public function testPhoneMasking(): void
    {
        $masked = OtpService::maskPhone('089693814458');
        $this->assertStringStartsWith('0896', $masked);
        $this->assertStringEndsWith('4458', $masked);
        $this->assertStringContainsString('***', $masked);
    }
}
