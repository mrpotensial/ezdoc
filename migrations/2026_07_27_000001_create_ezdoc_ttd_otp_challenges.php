<?php
/**
 * Create `ezdoc_ttd_otp_challenges` table for patient signature OTP challenges.
 *
 * Stores OTP challenges, secure hashes, verification status, attempt tracking,
 * and issued sign_tokens.
 *
 * Automatically discovered & executed by `ezdoc_migrate()` / `?ezdoc_page=admin_migrate`.
 */

return [
    'name' => '2026_07_27_000001_create_ezdoc_ttd_otp_challenges',
    'up' => function ($conn): void {
        // The runner records a migration as applied unless it throws, so an
        // unchecked query() would register a table that was never created.
        $ok = $conn->query("
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
                `created_at` DATETIME NOT NULL,
                -- Token consumption looks up by sign_token alone.
                INDEX `idx_sign_token` (`sign_token`),
                -- Send cooldown looks up the newest challenge per slot.
                INDEX `idx_slot_recent` (`template_id`, `ttd_id`, `created_at`),
                INDEX `idx_ttd_phone` (`ttd_id`, `phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        if ($ok === false) {
            throw new \RuntimeException(
                'Failed to create ezdoc_ttd_otp_challenges: ' . $conn->error
            );
        }
    },
];
