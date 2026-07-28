<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Audit;

use Ezdoc\Audit\Logger;
use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use Ezdoc\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Covers what actually lands in `ezdoc_audit_log`.
 *
 * The logger is deliberately silent — every failure path returns without
 * throwing, so business flows never break because auditing hiccuped. That makes
 * it easy for a write to go wrong unnoticed, which is why these assert on the
 * stored row rather than on the call returning.
 */
final class LoggerTest extends TestCase
{
    /** @var \mysqli */
    private $db;

    protected function setUp(): void
    {
        $db = TestDatabase::connect();
        if ($db === null) {
            self::markTestSkipped('No MySQL server reachable for tests.');
        }

        TestDatabase::reset();
        $this->db = $db;

        $migration = require dirname(__DIR__, 2) . '/migrations/2026_01_01_000004_create_ezdoc_audit_log.php';
        ($migration['up'])($db);
    }

    /**
     * @param array<string> $roles
     */
    private function logger(int $userId = 7, array $roles = ['dokter']): Logger
    {
        $provider = new CallableRoleProvider(
            static function ($role) use ($roles) {
                foreach ((array) $role as $r) {
                    if (in_array($r, $roles, true)) return true;
                }
                return false;
            },
            static function () use ($userId) { return $userId; },
            static function () use ($roles) { return $roles; }
        );

        return new Logger(new Context($this->db, $provider));
    }

    /** @return array<string,mixed>|null */
    private function lastRow(): ?array
    {
        $rs = $this->db->query('SELECT * FROM ezdoc_audit_log ORDER BY id DESC LIMIT 1');
        return $rs ? ($rs->fetch_assoc() ?: null) : null;
    }

    private function rowCount(): int
    {
        $rs = $this->db->query('SELECT COUNT(*) c FROM ezdoc_audit_log');
        return (int) $rs->fetch_assoc()['c'];
    }

    // ─── Happy path ───

    public function testEventIsRecordedWithActorAndTarget(): void
    {
        $this->logger()->log('doc.saved', [
            'target_type' => 'document',
            'target_id'   => '42',
            'doc_id'      => 42,
            'template_id' => 3,
        ]);

        $row = $this->lastRow();

        self::assertNotNull($row, 'the event should have been written');
        self::assertSame('doc.saved', $row['event_type']);
        self::assertSame(7, (int) $row['actor_id']);
        self::assertSame('dokter', $row['actor_roles']);
        self::assertSame('document', $row['target_type']);
        self::assertSame('42', $row['target_id']);
        self::assertSame(42, (int) $row['doc_id']);
        self::assertSame(3, (int) $row['template_id']);
    }

    public function testEventUuidIsPresentAndUnique(): void
    {
        $this->logger()->log('a.first');
        $first = $this->lastRow()['event_uuid'];

        $this->logger()->log('a.second');
        $second = $this->lastRow()['event_uuid'];

        self::assertNotEmpty($first);
        self::assertNotSame($first, $second);
    }

    public function testMetadataRoundTripsAsJson(): void
    {
        $this->logger()->log('otp.sent', [
            'metadata' => ['masked_phone' => '0896****4458', 'ttd_id' => 'ttd1'],
        ]);

        $decoded = json_decode($this->lastRow()['metadata'], true);

        self::assertSame('0896****4458', $decoded['masked_phone']);
        self::assertSame('ttd1', $decoded['ttd_id']);
    }

    /**
     * The IP address is bound in the same call as two integer columns. Getting
     * the type string out of step by one would coerce a dotted-quad to its
     * leading octet, so the stored value is checked verbatim.
     */
    public function testIpAddressIsStoredVerbatim(): void
    {
        $original = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.190';

        try {
            $this->logger()->log('doc.viewed');
            self::assertSame('192.168.1.190', $this->lastRow()['ip_address']);
        } finally {
            if ($original === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $original;
            }
        }
    }

    public function testUserAgentIsStored(): void
    {
        $original = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (probe)';

        try {
            $this->logger()->log('doc.viewed');
            self::assertSame('Mozilla/5.0 (probe)', $this->lastRow()['user_agent']);
        } finally {
            if ($original === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $original;
            }
        }
    }

    // ─── Normalisation ───

    public function testUnknownResultFallsBackToSuccess(): void
    {
        $this->logger()->log('x.y', ['result' => 'banana']);

        self::assertSame('success', $this->lastRow()['result']);
    }

    public function testKnownResultIsPreserved(): void
    {
        $this->logger()->log('x.y', ['result' => 'denied']);

        self::assertSame('denied', $this->lastRow()['result']);
    }

    public function testUnknownActorTypeFallsBackToUser(): void
    {
        $this->logger()->log('x.y', ['actor_type' => 'robot']);

        self::assertSame('user', $this->lastRow()['actor_type']);
    }

    public function testActorCanBeOverridden(): void
    {
        $this->logger()->log('x.y', ['actor_id' => 99, 'actor_type' => 'system']);

        $row = $this->lastRow();
        self::assertSame(99, (int) $row['actor_id']);
        self::assertSame('system', $row['actor_type']);
    }

    public function testAnonymousActorIsStoredAsNull(): void
    {
        $this->logger(0, [])->log('public.verify');

        self::assertNull($this->lastRow()['actor_id']);
    }

    public function testOverlongMessageIsTruncatedNotRejected(): void
    {
        $this->logger()->log('x.y', ['message' => str_repeat('a', 5000)]);

        $row = $this->lastRow();
        self::assertNotNull($row, 'an overlong message must not drop the event');
        self::assertLessThanOrEqual(2000, strlen($row['message']));
    }

    // ─── Rejection ───

    public function testEmptyEventTypeIsIgnored(): void
    {
        $this->logger()->log('');

        self::assertSame(0, $this->rowCount());
    }

    public function testOverlongEventTypeIsIgnored(): void
    {
        // Longer than the column, so writing it would fail anyway — the guard
        // makes that a no-op rather than a silent error.
        $this->logger()->log(str_repeat('e', 65));

        self::assertSame(0, $this->rowCount());
    }

    // ─── Silence ───

    /**
     * Auditing must never take down the operation it is recording. With the
     * table gone the write cannot succeed, and the caller must still not see an
     * exception.
     */
    public function testMissingTableDoesNotThrow(): void
    {
        $this->db->query('DROP TABLE ezdoc_audit_log');

        $this->logger()->log('doc.saved', ['doc_id' => 1]);

        self::assertTrue(true); // reaching here is the assertion
    }

    // ─── denied() helper ───

    public function testDeniedHelperRecordsADenial(): void
    {
        $this->logger()->denied('template.delete', 'not the owner', ['template_id' => 5]);

        $row = $this->lastRow();
        self::assertNotNull($row);
        self::assertSame('denied', $row['result']);
        self::assertStringContainsString('not the owner', $row['message'] ?? '');
    }
}
