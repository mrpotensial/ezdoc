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
     * Ensure database table exists.
     */
    public function ensureTableExists(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `ezdoc_ttd_otp_challenges` (
                `challenge_id` VARCHAR(64) NOT NULL PRIMARY KEY,
                `doc_id` INT(11) NULL,
                `template_id` INT(11) NOT NULL,
                `ttd_id` VARCHAR(64) NOT NULL,
                `phone` VARCHAR(30) NOT NULL,
                `otp_hash` VARCHAR(255) NOT NULL,
                `attempts` INT(11) NOT NULL DEFAULT 0,
                `max_attempts` INT(11) NOT NULL DEFAULT 3,
                `verified_at` DATETIME NULL,
                `expires_at` DATETIME NOT NULL,
                `sign_token` VARCHAR(128) NULL,
                `sign_token_used_at` DATETIME NULL,
                `provider_meta` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $this->db->execute($sql);
    }

    /**
     * Create a new OTP challenge record.
     */
    public function create(array $data): void
    {
        $this->ensureTableExists();

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
        $this->ensureTableExists();
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
     * Increment failed attempt count.
     */
    public function incrementAttempts(string $challengeId): int
    {
        $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges` SET `attempts` = `attempts` + 1 WHERE `challenge_id` = ?",
            [$challengeId]
        );
        $row = $this->findById($challengeId);
        return $row ? (int)$row['attempts'] : 0;
    }

    /**
     * Mark challenge as verified and assign sign_token.
     */
    public function markVerified(string $challengeId, string $signToken): void
    {
        $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges` 
             SET `verified_at` = ?, `sign_token` = ? 
             WHERE `challenge_id` = ?",
            [date('Y-m-d H:i:s'), $signToken, $challengeId]
        );
    }

    /**
     * Validate sign_token for a given ttd_id and mark it used.
     */
    public function validateAndConsumeSignToken(string $signToken, string $ttdId): bool
    {
        $this->ensureTableExists();
        $row = $this->db->fetchOne(
            "SELECT * FROM `ezdoc_ttd_otp_challenges` 
             WHERE `sign_token` = ? AND `ttd_id` = ? AND `verified_at` IS NOT NULL AND `sign_token_used_at` IS NULL",
            [$signToken, $ttdId]
        );

        if (!$row) {
            return false;
        }

        // Check if token expired (valid for 15 minutes after creation)
        if (strtotime($row['expires_at']) + 600 < time()) {
            return false;
        }

        // Mark sign token as consumed
        $this->db->execute(
            "UPDATE `ezdoc_ttd_otp_challenges` SET `sign_token_used_at` = ? WHERE `challenge_id` = ?",
            [date('Y-m-d H:i:s'), $row['challenge_id']]
        );

        return true;
    }
}
