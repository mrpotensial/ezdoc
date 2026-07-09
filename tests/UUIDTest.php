<?php

declare(strict_types=1);

namespace Ezdoc\Tests;

use Ezdoc\UUID;
use PHPUnit\Framework\TestCase;

/**
 * Test Ezdoc\UUID — v7 sortability, v4 randomness, validation.
 */
final class UUIDTest extends TestCase
{
    public function testV7FormatValid(): void
    {
        $uuid = UUID::v7();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'UUID v7 harus match format spec'
        );
    }

    public function testV7IsSortableChronologically(): void
    {
        $a = UUID::v7();
        usleep(2000); // 2ms
        $b = UUID::v7();
        usleep(2000);
        $c = UUID::v7();

        $this->assertLessThan(0, strcmp($a, $b), 'UUID v7 lebih awal harus < UUID v7 lebih baru');
        $this->assertLessThan(0, strcmp($b, $c));
    }

    public function testV7ExtractTimestamp(): void
    {
        $before = (int) (microtime(true) * 1000);
        $uuid = UUID::v7();
        $after = (int) (microtime(true) * 1000);

        $ts = UUID::extractTimestampMs($uuid);
        $this->assertNotNull($ts, 'Timestamp harus dapat di-extract dari UUID v7');
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testV4FormatValid(): void
    {
        $uuid = UUID::v4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'UUID v4 harus match format spec'
        );
    }

    public function testV4IsRandom(): void
    {
        // 100 UUIDs harus semuanya unique
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = UUID::v4();
        }
        $this->assertCount(100, array_unique($uuids), 'UUID v4 harus unique');
    }

    public function testExtractTimestampReturnsNullForV4(): void
    {
        $v4 = UUID::v4();
        $this->assertNull(UUID::extractTimestampMs($v4), 'UUID v4 tidak ada timestamp');
    }

    public function testExtractTimestampReturnsNullForInvalid(): void
    {
        $this->assertNull(UUID::extractTimestampMs('not-a-uuid'));
        $this->assertNull(UUID::extractTimestampMs(''));
        $this->assertNull(UUID::extractTimestampMs('00000000-0000-0000-0000-000000000000'));
    }

    public function testIsValidRecognizesValidUuids(): void
    {
        $this->assertTrue(UUID::isValid(UUID::v7()));
        $this->assertTrue(UUID::isValid(UUID::v4()));
        $this->assertTrue(UUID::isValid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testIsValidRejectsInvalid(): void
    {
        $this->assertFalse(UUID::isValid('not-a-uuid'));
        $this->assertFalse(UUID::isValid(''));
        $this->assertFalse(UUID::isValid('550e8400-e29b-41d4-a716'));
    }

    public function testVersionExtractedCorrectly(): void
    {
        $this->assertSame(7, UUID::version(UUID::v7()));
        $this->assertSame(4, UUID::version(UUID::v4()));
        $this->assertNull(UUID::version('not-a-uuid'));
    }
}
