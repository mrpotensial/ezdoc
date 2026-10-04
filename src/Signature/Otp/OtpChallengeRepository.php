<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Support\Clock;
use Ezdoc\Support\SystemClock;
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

    private Clock $clock;

    /**
     * @param Connection|mysqli $db
     * @param Clock|null        $clock Source of "now" for expiry predicates and
     *        written timestamps. Optional, so existing callers are unaffected;
     *        supply one when the caller has its own, since a flow that reads two
     *        different clocks will reject rows it wrote itself moments earlier.
     */
    public function __construct($db, ?Clock $clock = null)
    {
        if ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } elseif ($db instanceof Connection) {
            $this->db = $db;
        } else {
            throw new \InvalidArgumentException('Expected Connection or mysqli instance');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    /** Now, in storage format, optionally offset by whole seconds. */
    private function stamp(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', $this->clock->now()->getTimestamp() + $offsetSeconds);
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
            $this->stamp(),
        ]);
    }

    /**
     * Create a challenge that has no code yet, waiting for the signer to scan.
     *
     * This is the patient-initiated route (V6). The row is bound to its document
     * and slot from the moment it exists — which is the whole point, and the
     * reason a separate voucher table is no longer needed.
     *
     * `phone` and `otp_hash` stay NULL: neither is known until somebody scans.
     * The code is only minted once a scan arrives, by {@see fulfilScan()}.
     *
     * @param array{
     *     challenge_id: string, doc_id: int|null, template_id: int, ttd_id: string,
     *     scan_token: string, expires_at: string, created_at: string,
     *     max_attempts?: int, provider_meta?: array<string,mixed>|null
     * } $data
     */
    public function createAwaitingScan(array $data): void
    {
        $this->db->execute(
            'INSERT INTO `ezdoc_ttd_otp_challenges`
                (`challenge_id`, `doc_id`, `template_id`, `ttd_id`, `scan_token`,
                 `attempts`, `max_attempts`, `expires_at`, `provider_meta`, `created_at`)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)',
            [
                $data['challenge_id'],
                $data['doc_id'],
                $data['template_id'],
                $data['ttd_id'],
                $data['scan_token'],
                $data['max_attempts'] ?? 3,
                $data['expires_at'],
                isset($data['provider_meta']) && $data['provider_meta'] !== null
                    ? json_encode($data['provider_meta'], JSON_UNESCAPED_UNICODE)
                    : null,
                $data['created_at'],
            ]
        );
    }

    /**
     * The challenge a scan token points at, whatever state it is in.
     *
     * Returns the row even when expired or already scanned — the caller needs to
     * tell those apart to say something useful, and a bare null would make every
     * failure look the same.
     *
     * @return array<string,mixed>|null
     */
    public function findByScanToken(string $scanToken): ?array
    {
        if ($scanToken === '') {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM `ezdoc_ttd_otp_challenges` WHERE `scan_token` = ? LIMIT 1',
            [$scanToken]
        );
    }

    /**
     * Turn a scan into a live code: record who scanned, store the code's hash,
     * and keep a copy of exactly what the signer was told.
     *
     * `scanned_at IS NULL` makes this first-scan-wins. Two people scanning the
     * same on-screen QR at the same moment must not both receive a code — the
     * second would silently overwrite the first's, and the first would be told a
     * code that no longer verifies.
     *
     * The expiry is rewritten here on purpose. Until the scan, the deadline
     * belongs to the QR (short: it sits on an unattended screen). Afterwards it
     * belongs to the code (longer: the signer has to read it out).
     *
     * @return bool True when this call performed the transition.
     */
    public function fulfilScan(
        string $scanToken,
        string $phone,
        string $otpHash,
        string $expiresAt,
        string $scannedAt,
        string $consentMessage
    ): bool {
        $affected = $this->db->execute(
            'UPDATE `ezdoc_ttd_otp_challenges`
                SET `phone` = ?, `otp_hash` = ?, `expires_at` = ?,
                    `scanned_at` = ?, `consent_message` = ?
              WHERE `scan_token` = ? AND `scanned_at` IS NULL',
            [$phone, $otpHash, $expiresAt, $scannedAt, $consentMessage, $scanToken]
        );

        return $affected > 0;
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
            [$this->stamp(-1), $challengeId]
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
            [$this->stamp(), $signToken, $challengeId]
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
            $this->stamp(-self::SIGN_GRACE_SECONDS),
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
        array_unshift($params, $this->stamp());

        $sql = "UPDATE `ezdoc_ttd_otp_challenges`
                   SET `sign_token_used_at` = ?
                 WHERE " . $where;

        return (int) $this->db->execute($sql, $params) > 0;
    }

    // ── Sisi penanda tangan: metadata, riwayat, permintaan tertunda ──────

    /**
     * Gabungkan metadata ke `provider_meta` sebuah tantangan, lewat token pindaiannya.
     *
     * Kunci yang SUDAH ada menang. `route` ditulis saat token terbit dan
     * menjawab "lewat jalan apa permintaan ini lahir" — pemindai yang datang
     * belakangan tidak boleh menulis ulang sejarah itu.
     *
     * Dua pernyataan, bukan satu: penggabungan JSON di SQL berbeda di tiap mesin
     * basis data. Tidak ada balapan, karena hanya pemenang {@see fulfilScan()}
     * yang memanggilnya.
     *
     * @param array<string,mixed> $meta
     */
    public function mergeProviderMetaByScanToken(string $scanToken, array $meta): void
    {
        if ($scanToken === '' || $meta === []) {
            return;
        }

        $row = $this->findByScanToken($scanToken);
        if ($row === null) {
            return;
        }

        $current = json_decode((string) ($row['provider_meta'] ?? ''), true);
        $merged = (is_array($current) ? $current : []) + $meta;

        $this->db->execute(
            'UPDATE `ezdoc_ttd_otp_challenges` SET `provider_meta` = ? WHERE `scan_token` = ?',
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $scanToken]
        );
    }

    /**
     * Tantangan yang diselesaikan satu penanda tangan, terbaru dulu.
     *
     * `$signerRef` adalah pengenal yang dipilih aplikasi pemakai dan disimpan di
     * `provider_meta.signer_ref` saat pindaian — misalnya akun portalnya.
     * Pustaka tidak menafsirkannya.
     *
     * Disaring LIKE dulu, lalu dicocokkan persis di PHP. Fungsi JSON di SQL
     * berbeda di tiap mesin basis data, dan LIKE saja bisa cocok sebagian:
     * "akun:12" ikut menemukan "akun:123".
     *
     * @return list<array<string,mixed>> `provider_meta` sudah berupa larik.
     */
    public function findBySignerRef(string $signerRef, int $limit = 30): array
    {
        if ($signerRef === '' || $limit <= 0) {
            return [];
        }

        $needle = '"signer_ref":' . json_encode($signerRef, JSON_UNESCAPED_UNICODE);
        $rows = $this->db->fetchAll(
            "SELECT * FROM `ezdoc_ttd_otp_challenges`
              WHERE `provider_meta` LIKE ? ESCAPE '!'
              ORDER BY `scanned_at` DESC, `created_at` DESC",
            ['%' . self::likeEscape($needle) . '%']
        );

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['provider_meta'] ?? ''), true);
            if (!is_array($meta) || ($meta['signer_ref'] ?? null) !== $signerRef) {
                continue;
            }

            $row['provider_meta'] = $meta;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Permintaan yang belum dipindai untuk dokumen milik satu subjek.
     *
     * Hanya yang lahir lewat `$route`. QR di layar petugas juga menghasilkan
     * token tertunda, tapi token itu untuk orang yang sedang berdiri di depan
     * layar — menampilkannya di aplikasi akan membuat siapa pun yang tertaut ke
     * subjek itu bisa memakai QR milik orang lain.
     *
     * @return list<array<string,mixed>> `provider_meta` sudah berupa larik.
     */
    public function findPendingForSubject(string $subjectType, string $subjectId, string $route): array
    {
        if ($subjectType === '' || $subjectId === '' || $route === '') {
            return [];
        }

        $rows = $this->db->fetchAll(
            "SELECT c.* FROM `ezdoc_ttd_otp_challenges` c
               JOIN `ezdoc_documents` d ON d.`id` = c.`doc_id`
              WHERE d.`subject_type` = ? AND d.`subject_id` = ? AND d.`deleted_at` IS NULL
                AND c.`scanned_at` IS NULL AND c.`expires_at` > ?
                AND c.`provider_meta` LIKE ? ESCAPE '!'
              ORDER BY c.`created_at` DESC",
            [
                $subjectType,
                $subjectId,
                $this->stamp(),
                '%' . self::likeEscape('"route":' . json_encode($route)) . '%',
            ]
        );

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['provider_meta'] ?? ''), true);
            if (!is_array($meta) || ($meta['route'] ?? null) !== $route) {
                continue;
            }

            $row['provider_meta'] = $meta;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Matikan permintaan tertunda lewat `$route` untuk satu slot tanda tangan.
     *
     * Petugas yang menekan "kirim" dua kali tidak boleh meninggalkan dua
     * permintaan hidup di aplikasi untuk tanda tangan yang sama — yang satu
     * akan terbaca sebagai permintaan lain.
     *
     * @return int Jumlah permintaan yang dimatikan.
     */
    public function expirePendingForSlot(int $docId, string $ttdId, string $route): int
    {
        $rows = $this->db->fetchAll(
            "SELECT `challenge_id`, `provider_meta` FROM `ezdoc_ttd_otp_challenges`
              WHERE `doc_id` = ? AND `ttd_id` = ? AND `scanned_at` IS NULL AND `expires_at` > ?",
            [$docId, $ttdId, $this->stamp()]
        );

        $n = 0;
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['provider_meta'] ?? ''), true);
            if (!is_array($meta) || ($meta['route'] ?? null) !== $route) {
                continue;
            }

            $this->expire((string) $row['challenge_id']);
            $n++;
        }

        return $n;
    }

    /** Escape LIKE dengan `!`, karena backslash ditafsirkan berbeda di tiap mesin. */
    private static function likeEscape(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
