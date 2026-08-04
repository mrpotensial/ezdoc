<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use Ezdoc\Db\Pdo\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `ezdoc_issue_otp_voucher()` — the function the host's inbound message handler
 * calls when a patient asks for a code.
 *
 * Tested through the real Context rather than by poking the service directly,
 * because the point of this function is everything around the service: it must
 * never throw at a webhook, it must turn each outcome into something the bot
 * can put in a sentence, and it must leave an audit trail that does not double
 * as a phone directory.
 *
 * Requires pdo_sqlite.
 */
final class IssueVoucherHelperTest extends TestCase
{
    private const PHONE = '6281234567890';

    private PdoConnection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        require_once dirname(__DIR__, 3) . '/lib/audit.php';
        require_once dirname(__DIR__, 3) . '/lib/otp_voucher.php';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $migrations = dirname(__DIR__, 3) . '/migrations/sqlite/';
        foreach ([
            '2026_08_03_000001_create_ezdoc_otp_vouchers.sql',
            '2026_07_27_000001_create_ezdoc_ttd_otp_challenges.sql',
            '2026_01_01_000004_create_ezdoc_audit_log.sql',
        ] as $file) {
            $pdo->exec((string) file_get_contents($migrations . $file));
        }

        $this->db = new PdoConnection($pdo);

        // The bot has no logged-in user. Zero is what the audit trail should
        // record, and the entry must still be written.
        $provider = new CallableRoleProvider(
            static function () { return false; },
            static function () { return 0; },
            static function () { return []; }
        );

        Context::setDefault(new Context($this->db, $provider));
    }

    protected function tearDown(): void
    {
        Context::resetDefault();
    }

    /** @return array<string,mixed>|null */
    private function lastAudit(): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ezdoc_audit_log ORDER BY id DESC LIMIT 1');
    }

    public function testAPatientAskingGetsACode(): void
    {
        $result = ezdoc_issue_otp_voucher(self::PHONE, ['message_id' => 'abc']);

        self::assertTrue($result['ok']);
        self::assertSame('issued', $result['reason']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $result['code']);
        self::assertSame(10, $result['ttl_minutes']);
    }

    /**
     * The bot has to put a number in its reply. Parsing one back out of an
     * exception message would break the moment that message is translated.
     */
    public function testAskingAgainTooSoonSaysHowLongToWait(): void
    {
        ezdoc_issue_otp_voucher(self::PHONE);
        $again = ezdoc_issue_otp_voucher(self::PHONE);

        self::assertFalse($again['ok']);
        self::assertSame('cooldown', $again['reason']);
        self::assertGreaterThan(0, $again['retry_after']);
        self::assertLessThanOrEqual(60, $again['retry_after']);
    }

    public function testAnUnusableNumberIsReportedNotThrown(): void
    {
        $result = ezdoc_issue_otp_voucher('12');

        self::assertFalse($result['ok']);
        self::assertSame('bad_phone', $result['reason']);
    }

    /**
     * A webhook that dies leaves the sender with silence and usually gets
     * retried, which would mint codes nobody reads. No input may throw.
     */
    public function testNoInputEscapesAsAnException(): void
    {
        foreach (['', '   ', 'not a phone', '+', '0', str_repeat('9', 40)] as $input) {
            $result = ezdoc_issue_otp_voucher($input);
            self::assertIsBool($result['ok'], "input '{$input}' did not produce a usable result");
        }
    }

    public function testIssuingIsRecorded(): void
    {
        ezdoc_issue_otp_voucher(self::PHONE, [], 'whatsapp_inbound');

        $row = $this->lastAudit();

        self::assertNotNull($row);
        self::assertSame('otp.voucher_issued', $row['event_type']);
        self::assertSame(0, (int) $row['actor_id']);
    }

    /**
     * An audit row naming every number that ever messaged the service would be
     * a directory of patients. The masked form still identifies the row for a
     * dispute without publishing the list.
     */
    public function testTheAuditTrailDoesNotStoreTheWholeNumber(): void
    {
        ezdoc_issue_otp_voucher(self::PHONE);

        $metadata = (string) ($this->lastAudit()['metadata'] ?? '');

        self::assertStringNotContainsString(self::PHONE, $metadata);
        self::assertStringNotContainsString('081234567890', $metadata);
        self::assertStringContainsString('*', $metadata);
    }

    /** The code exists in the reply and nowhere else. */
    public function testTheCodeNeverReachesTheAuditTrail(): void
    {
        $result = ezdoc_issue_otp_voucher(self::PHONE);

        self::assertStringNotContainsString(
            $result['code'],
            (string) ($this->lastAudit()['metadata'] ?? '')
        );
    }
}
