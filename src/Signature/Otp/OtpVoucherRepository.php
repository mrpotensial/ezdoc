<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use mysqli;

/**
 * Storage for `ezdoc_otp_vouchers` — patient-initiated one-time codes.
 *
 * ## Time comes from the caller
 *
 * Every method that needs "now" is handed it as a formatted string rather than
 * reading the clock itself. One place decides what time it is — the service,
 * which holds an injectable {@see \Ezdoc\Support\Clock} — so a test that moves
 * the clock moves it for the query predicates too. A repository calling
 * `date()` on its own would quietly ignore the frozen clock and every expiry
 * test would be testing the real one.
 *
 * ## Concurrency
 *
 * Claiming and spending an attempt are single conditional UPDATEs, checked by
 * affected-row count. Read-then-write would let two simultaneous requests both
 * observe an unclaimed voucher and both proceed. Same shape as
 * {@see OtpChallengeRepository::chargeAttempt()} and, in the host application,
 * the queue-token claim it was modelled on.
 */
final class OtpVoucherRepository
{
    private Connection $db;

    /**
     * @param Connection|mysqli $db
     */
    public function __construct($db)
    {
        if ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } elseif ($db instanceof Connection) {
            $this->db = $db;
        } else {
            throw new \InvalidArgumentException('Expected Connection or mysqli instance');
        }
    }

    /**
     * Insert a voucher. Returns its id.
     *
     * @param array{
     *     phone: string, code_hash: string, issued_at: string, expires_at: string,
     *     max_attempts?: int, source?: string, provider_meta?: array<string,mixed>|null
     * } $data
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO `ezdoc_otp_vouchers`
                (`phone`, `code_hash`, `source`, `issued_at`, `expires_at`,
                 `attempts`, `max_attempts`, `provider_meta`, `created_at`)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
            [
                $data['phone'],
                $data['code_hash'],
                $data['source'] ?? 'whatsapp_inbound',
                $data['issued_at'],
                $data['expires_at'],
                $data['max_attempts'] ?? 3,
                isset($data['provider_meta']) && $data['provider_meta'] !== null
                    ? json_encode($data['provider_meta'], JSON_UNESCAPED_UNICODE)
                    : null,
                $data['issued_at'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * The most recent voucher for a phone that has not been claimed.
     *
     * Expired rows are returned too — the caller needs to tell "there was a
     * code and it lapsed" apart from "no code was ever asked for", and those
     * deserve different words on screen.
     *
     * @return array<string,mixed>|null
     */
    public function findLatestUnclaimed(string $phone): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM `ezdoc_otp_vouchers`
              WHERE `phone` = ? AND `claimed_at` IS NULL
              ORDER BY `id` DESC LIMIT 1',
            [$phone]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `ezdoc_otp_vouchers` WHERE `id` = ?', [$id]);
    }

    /**
     * When this phone last had a voucher issued, as a Unix timestamp.
     *
     * Backs the issue cooldown. Counts every voucher, including ones already
     * superseded or claimed — otherwise superseding would reset the cooldown
     * and the cooldown would gate nothing.
     */
    public function lastIssuedAt(string $phone): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT `issued_at` FROM `ezdoc_otp_vouchers`
              WHERE `phone` = ? ORDER BY `id` DESC LIMIT 1',
            [$phone]
        );

        if ($row === null || empty($row['issued_at'])) {
            return null;
        }

        $ts = strtotime((string) $row['issued_at']);

        return $ts === false ? null : $ts;
    }

    /**
     * Retire every unclaimed live voucher for a phone.
     *
     * Called just before issuing a new one, so a phone never holds two usable
     * codes. Expiry is moved rather than the row deleted: the record that a
     * code was issued is part of the audit trail.
     *
     * @param string $now      Formatted 'Y-m-d H:i:s'.
     * @param string $expireTo Formatted 'Y-m-d H:i:s', strictly before `$now`.
     * @return int Rows retired.
     */
    public function supersedeActive(string $phone, string $now, string $expireTo): int
    {
        return $this->db->execute(
            'UPDATE `ezdoc_otp_vouchers`
                SET `expires_at` = ?
              WHERE `phone` = ? AND `claimed_at` IS NULL AND `expires_at` > ?',
            [$expireTo, $phone, $now]
        );
    }

    /**
     * Spend one verification attempt, if the budget allows.
     *
     * The guard is in the WHERE clause so checking and incrementing are one
     * statement — read-then-write lets parallel requests overshoot the cap.
     *
     * @return int|null New attempt count, or null when the budget was gone.
     */
    public function chargeAttempt(int $id, int $maxAttempts): ?int
    {
        $affected = $this->db->execute(
            'UPDATE `ezdoc_otp_vouchers`
                SET `attempts` = `attempts` + 1
              WHERE `id` = ? AND `attempts` < ?',
            [$id, $maxAttempts]
        );

        if ($affected < 1) {
            return null;
        }

        $row = $this->db->fetchOne('SELECT `attempts` FROM `ezdoc_otp_vouchers` WHERE `id` = ?', [$id]);

        return $row === null ? null : (int) $row['attempts'];
    }

    /**
     * Bind a voucher to the signature it was spent on. First writer wins.
     *
     * `claimed_at IS NULL` in the predicate is what makes the code single-use.
     * The expiry check is here as well as in the service so a voucher that
     * lapses between the two cannot still be claimed.
     *
     * @param array{
     *     doc_id?: int|null, ttd_id?: string|null, template_id?: int|null,
     *     challenge_id?: string|null, phone_origin?: string|null
     * } $binding
     * @param string $now Formatted 'Y-m-d H:i:s'.
     * @return bool True when this call performed the transition.
     */
    public function claim(int $id, array $binding, string $now): bool
    {
        $affected = $this->db->execute(
            'UPDATE `ezdoc_otp_vouchers`
                SET `claimed_at` = ?, `claimed_doc_id` = ?, `claimed_ttd_id` = ?,
                    `claimed_template_id` = ?, `claimed_challenge_id` = ?,
                    `claimed_phone_origin` = ?
              WHERE `id` = ? AND `claimed_at` IS NULL AND `expires_at` > ?',
            [
                $now,
                $binding['doc_id'] ?? null,
                $binding['ttd_id'] ?? null,
                $binding['template_id'] ?? null,
                $binding['challenge_id'] ?? null,
                $binding['phone_origin'] ?? null,
                $id,
                $now,
            ]
        );

        return $affected > 0;
    }

    /**
     * Drop vouchers that expired before `$before` and were never claimed.
     *
     * Claimed rows are kept: they are the link between a signature and the code
     * that authorised it, which is exactly what an audit asks for.
     *
     * @param string $before Formatted 'Y-m-d H:i:s'.
     * @return int Rows removed.
     */
    public function purgeExpired(string $before): int
    {
        return $this->db->execute(
            'DELETE FROM `ezdoc_otp_vouchers` WHERE `claimed_at` IS NULL AND `expires_at` < ?',
            [$before]
        );
    }
}
