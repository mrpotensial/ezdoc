<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\UUID;
use mysqli;

/**
 * Ezdoc\Signature\SignatureRepository — persistence gateway untuk ezdoc_signatures.
 *
 * Menyimpan satu row per signer per document (multi-signer via
 * `signature_id_within_doc`). Support 3 assurance levels: L1 HMAC (envelope='hmac'),
 * L2 LocalPKI (envelope='raw'|'pkcs7'), L3 PSrE (envelope='pades' + TSA response).
 *
 * ## Design notes
 *
 * - Row shape adalah plain assoc array (bukan value object) — signature payload
 *   binary (envelope, tsa_response) mahal buat wrap ke immutable object.
 *   Consumer decide interpretation.
 * - INSERT auto-generate UUID v7 kalau caller tidak provide.
 * - Verify status update via `updateVerifyStatus()` — append-only otherwise
 *   (signature envelope tidak boleh berubah setelah initial insert).
 *
 * PHP 7.4+ compatible.
 */
final class SignatureRepository
{
    /** @var Connection */
    private $db;

    /** @var string SELECT column list — sinkron dgn migrations/blueprints/ezdoc_signatures.php */
    private static $selectCols = 'id, uuid, document_id, signature_id_within_doc, '
        . 'signer_id, signer_role, signer_user_id, '
        . 'provider, level, envelope_format, envelope, '
        . 'content_hash, content_hash_algo, '
        . 'certificate_pem, certificate_serial, certificate_subject, certificate_issuer, '
        . 'tsa_response, signed_at, verified_at, verify_status, verify_reason, '
        . 'metadata, created_at, updated_at, deleted_at';

    /**
     * @param Connection|mysqli $db
     */
    public function __construct($db)
    {
        if ($db instanceof Connection) {
            $this->db = $db;
        } elseif ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } else {
            throw new \InvalidArgumentException(
                'SignatureRepository requires Ezdoc\\Db\\Connection or mysqli, got: '
                . (is_object($db) ? get_class($db) : gettype($db))
            );
        }
    }

    // ─── Finders ─────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_signatures WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id]
        );
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        if ($uuid === '') return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_signatures WHERE uuid = ? AND deleted_at IS NULL LIMIT 1',
            [$uuid]
        );
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByCertSerial(string $serial): ?array
    {
        if ($serial === '') return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_signatures WHERE certificate_serial = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT 1',
            [$serial]
        );
        return $row ?: null;
    }

    // ─── Listers ─────────────────────────────────────────────────────────

    /**
     * All signatures untuk satu document, ordered by slot within doc.
     *
     * @return list<array<string,mixed>>
     */
    public function listByDocument(int $documentId): array
    {
        if ($documentId <= 0) return [];
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_signatures WHERE document_id = ? AND deleted_at IS NULL'
            . ' ORDER BY signature_id_within_doc ASC',
            [$documentId]
        );
    }

    /**
     * Signer history — semua doc yang di-sign oleh signer tertentu.
     *
     * @return list<array<string,mixed>>
     */
    public function listBySigner(string $signerId, int $limit = 100): array
    {
        if ($signerId === '') return [];
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_signatures WHERE signer_id = ? AND deleted_at IS NULL'
            . ' ORDER BY signed_at DESC LIMIT ?',
            [$signerId, $limit]
        );
    }

    /**
     * Pending verify — signatures yang belum di-verify (untuk background job).
     *
     * @return list<array<string,mixed>>
     */
    public function listPendingVerify(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_signatures'
            . ' WHERE verify_status = \'pending\' AND deleted_at IS NULL'
            . ' ORDER BY id ASC LIMIT ?',
            [$limit]
        );
    }

    // ─── Writers ─────────────────────────────────────────────────────────

    /**
     * INSERT signature row. Auto-generate UUID v7 kalau kosong.
     *
     * $data expected keys:
     *   - document_id (required, int)
     *   - signer_id (required, string)
     *   - provider (required, string)
     *   - level (required, int 1|2|3)
     *   - envelope_format (required, string)
     *   - envelope (required, binary string)
     *   - content_hash (required, hex string)
     *   - signed_at (required, 'Y-m-d H:i:s' string)
     *   - uuid, signer_role, signer_user_id, signature_id_within_doc,
     *     content_hash_algo, certificate_*, tsa_response, metadata (optional)
     *
     * @param array<string,mixed> $data
     * @return int New signature id.
     */
    public function insert(array $data): int
    {
        // Validate required keys
        foreach (['document_id', 'signer_id', 'provider', 'level', 'envelope_format', 'envelope', 'content_hash', 'signed_at'] as $req) {
            if (!isset($data[$req])) {
                throw new \InvalidArgumentException("SignatureRepository::insert missing required key '$req'");
            }
        }

        $uuid = !empty($data['uuid']) ? (string) $data['uuid'] : UUID::v7();
        $metadataJson = null;
        if (isset($data['metadata']) && is_array($data['metadata']) && $data['metadata'] !== []) {
            $encoded = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $metadataJson = $encoded === false ? null : $encoded;
        }

        $sql = 'INSERT INTO ezdoc_signatures
            (uuid, document_id, signature_id_within_doc,
             signer_id, signer_role, signer_user_id,
             provider, level, envelope_format, envelope,
             content_hash, content_hash_algo,
             certificate_pem, certificate_serial, certificate_subject, certificate_issuer,
             tsa_response, signed_at, verify_status, verify_reason,
             metadata)
            VALUES (?, ?, ?,  ?, ?, ?,  ?, ?, ?, ?,  ?, ?,  ?, ?, ?, ?,  ?, ?, ?, ?,  ?)';

        $this->db->execute($sql, [
            $uuid,
            (int) $data['document_id'],
            isset($data['signature_id_within_doc']) ? (int) $data['signature_id_within_doc'] : 0,
            (string) $data['signer_id'],
            isset($data['signer_role']) ? (string) $data['signer_role'] : null,
            isset($data['signer_user_id']) ? (int) $data['signer_user_id'] : null,
            (string) $data['provider'],
            (int) $data['level'],
            (string) $data['envelope_format'],
            $data['envelope'], // binary
            (string) $data['content_hash'],
            isset($data['content_hash_algo']) ? (string) $data['content_hash_algo'] : 'sha256',
            isset($data['certificate_pem']) ? (string) $data['certificate_pem'] : null,
            isset($data['certificate_serial']) ? (string) $data['certificate_serial'] : null,
            isset($data['certificate_subject']) ? (string) $data['certificate_subject'] : null,
            isset($data['certificate_issuer']) ? (string) $data['certificate_issuer'] : null,
            isset($data['tsa_response']) ? $data['tsa_response'] : null,
            (string) $data['signed_at'],
            isset($data['verify_status']) ? (string) $data['verify_status'] : 'pending',
            isset($data['verify_reason']) ? (string) $data['verify_reason'] : null,
            $metadataJson,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update verify status + timestamp. Signature envelope tidak boleh berubah;
     * hanya verify result yg update.
     */
    public function updateVerifyStatus(int $id, string $status, ?string $reason = null): bool
    {
        if ($id <= 0) return false;
        $allowed = ['valid', 'tampered', 'expired', 'revoked', 'untrusted', 'error', 'pending'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid verify_status '$status'");
        }
        $affected = $this->db->execute(
            'UPDATE ezdoc_signatures SET verify_status = ?, verify_reason = ?, verified_at = CURRENT_TIMESTAMP'
            . ' WHERE id = ? AND deleted_at IS NULL',
            [$status, $reason, $id]
        );
        return $affected > 0;
    }

    /**
     * Soft-delete signature — set deleted_at. Envelope + timestamp preserved
     * untuk audit.
     */
    public function softDelete(int $id): bool
    {
        if ($id <= 0) return false;
        $affected = $this->db->execute(
            'UPDATE ezdoc_signatures SET deleted_at = CURRENT_TIMESTAMP'
            . ' WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );
        return $affected > 0;
    }
}
