<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use mysqli;

/**
 * Repository for managing OTP challenges in table `ezdoc_ttd_otp_challenges`.
 */
final class OtpChallengeRepository
{
    /**
     * Extra seconds a sign token stays usable past the challenge's own expiry,
     * covering the gap between entering the code and submitting the signature.
     */
    private const SIGN_GRACE_SECONDS = 600;

    /** @var Connection */
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
     * Create a new OTP challenge record.
     *
     * Schema comes from the migration runner. An earlier revision issued a
     * CREATE TABLE IF NOT EXISTS on every call; that ran DDL per request and,
     * when it won the race against the migration, produced a table missing
     * both indexes — leaving sign-token lookups on a full scan permanently.
     */
    public function create(array $data): void
    {
        $sql = "
            INSERT INTO `ezdoc_ttd_otp_challenges` (
                `challenge_id`, `doc_id`, `template_id`, `ttd_id`, `phone`,
                `otp_hash`, `attempts`, `max_attempts`, `expires_at`, `provider_meta`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
        ";

        $this->db->execute($sql, [
            $data['challenge_id'],
            $data['doc_id'] ?? null,
            $data['template_id'],
            $data['ttd_id'],
            $data['phone'],
            $data['otp_hash'],
            $data['max_attempts'] ?? 3,
            $data['expires_at'],
            isset($data['provider_meta']) ? json_encode($data['provider_meta'], JSON_UNESCAPED_UNICODE) : null,
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Find challenge by ID.
     */
    public function findById(string $challengeId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM `ezdoc_ttd_otp_challenges` WHERE `challenge_id` = ?",
            [$challengeId]
        );
        if ($row && !empty($row['provider_meta'])) {
            $row['provider_meta'] = json_decode($row['provider_meta'], true);
        }
        return $row ?: null;
    }

    /**
     * Unix timestamp of the most recent challenge for a signature slot, or
     * null if there is none. Backs the send cooldown.
     */
    public function lastChallengeCreatedAt(int $templateId, string $ttdId, ?int $docId = null): ?int
    {
        $sql = "SELECT `created_at` FROM `ezdoc_ttd_otp_challenges`
                 WHERE `template_id` = ? AND `ttd_id` = ?";
        $params = [$templateId, $ttdId];

        if ($docId === null) {
            $sql .= " AND `doc_id` IS NULL";
        } else {
            $sql .= " AND `doc_id` = ?";
            $params[] = $docId;
        }

        $sql .= " ORDER BY `created_at` DESC LIMIT 1";

        $row = $this->db->fetchOne($sql, $params);
        if (!$row || empty($row['created_at'])) {
            return null;
        }
        $ts = strtotime((string) $row['created_at']);
        return $ts === false ? null : $ts;
    }

    /**
     * Attach provider metadata once delivery is confirmed.
     */
    public function attachProviderMeta(string $challengeId, array $meta): void
    {
        $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges` SET `provider_meta` = ? WHERE `challenge_id` = ?",
            [json_encode($meta, JSON_UNESCAPED_UNICODE), $challengeId]
        );
    }

    /**
     * Force a challenge to expire — used when delivery fails, so a code that
     * never reached anyone cannot later be verified.
     */
    public function expire(string $challengeId): void
    {
        $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges` SET `expires_at` = ? WHERE `challenge_id` = ?",
            [date('Y-m-d H:i:s', time() - 1), $challengeId]
        );
    }

    /**
     * Spend one verification attempt, if any remain.
     *
     * The guard lives in the WHERE clause so the check and the increment are
     * one statement — read-then-write let parallel requests overshoot the cap.
     *
     * @return int|null New attempt count, or null when the budget was already spent.
     */
    public function chargeAttempt(string $challengeId, int $maxAttempts): ?int
    {
        $affected = $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges`
                SET `attempts` = `attempts` + 1
              WHERE `challenge_id` = ? AND `attempts` < ?",
            [$challengeId, $maxAttempts]
        );

        if ((int) $affected < 1) {
            return null;
        }

        $row = $this->db->fetchOne(
            "SELECT `attempts` FROM `ezdoc_ttd_otp_challenges` WHERE `challenge_id` = ?",
            [$challengeId]
        );
        return $row ? (int) $row['attempts'] : null;
    }

    /**
     * Mark a challenge verified and store its sign token.
     *
     * `verified_at IS NULL` makes this first-writer-wins: concurrent correct
     * submissions cannot each mint a token, where the later write would
     * silently invalidate a token the caller already holds.
     *
     * @return bool True if this call performed the transition.
     */
    public function markVerified(string $challengeId, string $signToken): bool
    {
        $affected = $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges`
                SET `verified_at` = ?, `sign_token` = ?
              WHERE `challenge_id` = ? AND `verified_at` IS NULL",
            [date('Y-m-d H:i:s'), $signToken, $challengeId]
        );

        return (int) $affected > 0;
    }

    /**
     * Consume a sign token bound to a specific signature slot.
     *
     * Validation and consumption are one conditional UPDATE. Splitting them
     * would let two concurrent saves both observe an unused token and both
     * proceed, defeating single use.
     *
     * The binding covers template, slot and document. `ttd_id` on its own is
     * not unique — slot ids are template-local, so `ttd1` exists on nearly
     * every template.
     */
    /**
     * Answer whether a sign token would be accepted, without spending it.
     *
     * Exists so a caller can validate early and commit late. Spending the token
     * during validation means any later refusal — a locked document, a failed
     * write — destroys it, and the operator is asked for a fresh code for a
     * signature that was never stored. The token is single-use, so there is no
     * second chance to get it right.
     *
     * The conditions are the same as {@see consumeSignToken()}; only the effect
     * differs. Pair them: check here, consume once the work has succeeded.
     */
    public function verifySignToken(string $signToken, string $ttdId, int $templateId, ?int $docId = null): bool
    {
        [$sql, $params] = $this->signTokenPredicate($signToken, $ttdId, $templateId, $docId);

        $row = $this->db->fetchOne(
            'SELECT `challenge_id` FROM `ezdoc_ttd_otp_challenges` WHERE ' . $sql . ' LIMIT 1',
            $params
        );

        return $row !== null;
    }

    /**
     * The conditions a usable sign token satisfies, shared by check and consume
     * so the two can never drift apart.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function signTokenPredicate(string $signToken, string $ttdId, int $templateId, ?int $docId): array
    {
        $sql = "`sign_token` = ?
                AND `ttd_id` = ?
                AND `template_id` = ?
                AND `verified_at` IS NOT NULL
                AND `sign_token_used_at` IS NULL
                AND `expires_at` > ?";

        $params = [
            $signToken,
            $ttdId,
            $templateId,
            // Grace window: the code is entered, then the operator still has
            // to draw and submit the signature.
            date('Y-m-d H:i:s', time() - self::SIGN_GRACE_SECONDS),
        ];

        if ($docId === null) {
            $sql .= " AND `doc_id` IS NULL";
        } else {
            $sql .= " AND `doc_id` = ?";
            $params[] = $docId;
        }

        return [$sql, $params];
    }

    public function consumeSignToken(string $signToken, string $ttdId, int $templateId, ?int $docId = null): bool
    {
        [$where, $params] = $this->signTokenPredicate($signToken, $ttdId, $templateId, $docId);

        // The used-at stamp goes in front of the predicate's own parameters.
        array_unshift($params, date('Y-m-d H:i:s'));

        $sql = "UPDATE `ezdoc_ttd_otp_challenges`
                   SET `sign_token_used_at` = ?
                 WHERE " . $where;

        return (int) $this->db->execute($sql, $params) > 0;
    }
}
