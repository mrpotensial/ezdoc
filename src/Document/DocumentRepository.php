<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\UUID;
use mysqli;

/**
 * Ezdoc\Document\DocumentRepository — mysqli-backed persistence for documents.
 *
 * All queries use mysqli_prepare + bind_param. Optimistic locking on UPDATE
 * via WHERE revision = expectedRevision (throws ValidationException on mismatch).
 * Content hash is SHA-256 of canonical JSON of field_values (keys sorted).
 *
 * PHP 7.4+ compatible.
 */
final class DocumentRepository
{
    /** @var mysqli */
    private $db;

    /**
     * SELECT column list — kept in sync with schema in 2026_01_01_000002 migration.
     * Includes legacy hospital columns (norm, nopen, label) so Document::fromRow()
     * can back-fill field_values when those legacy columns are populated.
     *
     * @var string
     */
    private static $SELECT_COLS = 'id, uuid, template_id, template_uuid, template_version, '
        . 'title, norm, nopen, label, field_values, signature_values, metadata, '
        . 'status, is_locked, content_hash, content_hash_at, content_hash_version, '
        . 'public_slug, public_slug_active, revision, '
        . 'created_by, updated_by, created_at, updated_at';

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?Document
    {
        if ($id <= 0) return null;
        $sql = 'SELECT ' . self::$SELECT_COLS
            . ' FROM ezdoc_documents WHERE id = ? AND deleted_at IS NULL LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;
        mysqli_stmt_bind_param($stmt, 'i', $id);
        return $this->fetchSingle($stmt);
    }

    public function findByUuid(string $uuid): ?Document
    {
        if ($uuid === '') return null;
        $sql = 'SELECT ' . self::$SELECT_COLS
            . ' FROM ezdoc_documents WHERE uuid = ? AND deleted_at IS NULL LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;
        mysqli_stmt_bind_param($stmt, 's', $uuid);
        return $this->fetchSingle($stmt);
    }

    public function findByPublicSlug(string $slug): ?Document
    {
        if ($slug === '') return null;
        $sql = 'SELECT ' . self::$SELECT_COLS
            . ' FROM ezdoc_documents WHERE public_slug = ? AND public_slug_active = 1'
            . ' AND deleted_at IS NULL LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;
        mysqli_stmt_bind_param($stmt, 's', $slug);
        return $this->fetchSingle($stmt);
    }

    /**
     * @return array<int,Document>
     */
    public function listByTemplate(int $templateId, int $limit = 100, int $offset = 0): array
    {
        if ($templateId <= 0) return [];
        if ($limit < 1) $limit = 1;
        if ($limit > 1000) $limit = 1000;
        if ($offset < 0) $offset = 0;

        $sql = 'SELECT ' . self::$SELECT_COLS
            . ' FROM ezdoc_documents WHERE template_id = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 'iii', $templateId, $limit, $offset);
        return $this->fetchMany($stmt);
    }

    /**
     * @return array<int,Document>
     */
    public function listByStatus(string $status, int $limit = 100): array
    {
        if ($status === '') return [];
        if ($limit < 1) $limit = 1;
        if ($limit > 1000) $limit = 1000;

        $sql = 'SELECT ' . self::$SELECT_COLS
            . ' FROM ezdoc_documents WHERE status = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT ?';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 'si', $status, $limit);
        return $this->fetchMany($stmt);
    }

    /**
     * INSERT if $req->documentId is null; UPDATE otherwise.
     *
     * @throws NotFoundException   when updating a nonexistent id
     * @throws ValidationException on revision mismatch (optimistic lock fail)
     */
    public function save(SaveDocumentRequest $req, int $actorId): SaveDocumentResult
    {
        $fieldValues = $req->getFieldValues();
        $signatureValues = $req->getSignatureValues();
        $metadata = $req->getMetadata();

        $fieldValuesJson = self::encodeJson($fieldValues);
        $signatureValuesJson = self::encodeJson($signatureValues);
        $metadataJson = self::encodeJson($metadata);

        $contentHash = self::computeContentHash($fieldValues);

        if ($req->isUpdate()) {
            return $this->doUpdate($req, $actorId, $fieldValuesJson, $signatureValuesJson, $metadataJson, $contentHash);
        }
        return $this->doInsert($req, $actorId, $fieldValuesJson, $signatureValuesJson, $metadataJson, $contentHash);
    }

    /**
     * Load template row for INSERT — needs template_uuid + template.version to
     * denormalize into the document. Repository-local read so callers don't
     * have to plumb this through the request.
     *
     * @return array{template_uuid:string, template_version:int}|null
     */
    private function loadTemplateSnapshot(int $templateId): ?array
    {
        $sql = 'SELECT uuid, version FROM ezdoc_templates WHERE id = ? LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;
        mysqli_stmt_bind_param($stmt, 'i', $templateId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return null;
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if (!$row) return null;
        return [
            'template_uuid' => (string) $row['uuid'],
            'template_version' => (int) $row['version'],
        ];
    }

    private function doInsert(
        SaveDocumentRequest $req,
        int $actorId,
        string $fieldValuesJson,
        string $signatureValuesJson,
        string $metadataJson,
        string $contentHash
    ): SaveDocumentResult {
        $snap = $this->loadTemplateSnapshot($req->getTemplateId());
        if ($snap === null) {
            throw NotFoundException::forResource('template', $req->getTemplateId());
        }

        $uuid = UUID::v7();
        $templateId = $req->getTemplateId();
        $templateUuid = $snap['template_uuid'];
        $templateVersion = $snap['template_version'];
        $title = $req->getTitle();
        $status = $req->getStatus();
        $isLocked = ($status === 'locked') ? 1 : 0;
        $publishedAt = ($status === 'published' || $status === 'locked')
            ? date('Y-m-d H:i:s')
            : null;

        // Map generic subject_type/subject_id → legacy norm/nopen columns when
        // subject_type is 'patient' (backward-compat during v0.6.5 migration).
        // Also honor explicit field_values['norm']/['nopen'] as legacy fallback.
        $norm = null;
        $nopen = null;
        $fieldValues = $req->getFieldValues();
        if ($req->getSubjectType() === 'patient' && $req->getSubjectId() !== null) {
            $norm = $req->getSubjectId();
        } elseif (isset($fieldValues['norm']) && is_scalar($fieldValues['norm'])) {
            $norm = (string) $fieldValues['norm'];
        }
        if (isset($fieldValues['nopen']) && is_scalar($fieldValues['nopen'])) {
            $nopen = (string) $fieldValues['nopen'];
        }

        $label = '-';
        if (isset($fieldValues['label']) && is_scalar($fieldValues['label']) && (string) $fieldValues['label'] !== '') {
            $label = substr((string) $fieldValues['label'], 0, 100);
        }

        $now = date('Y-m-d H:i:s');
        $contentHashVersion = 1;

        $sql = 'INSERT INTO ezdoc_documents '
            . '(uuid, template_id, template_uuid, template_version, title, norm, nopen, label, '
            . 'field_values, signature_values, metadata, status, is_locked, '
            . 'content_hash, content_hash_at, content_hash_version, '
            . 'published_at, revision, created_by, updated_by, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare INSERT: ' . mysqli_error($this->db));
        }

        $actorIdForInsert = $actorId > 0 ? $actorId : null;

        // Types (21 params): s i s i  s s s s  s s s s  i s s i  s i i s s
        mysqli_stmt_bind_param(
            $stmt,
            'sisissssssssissisiiss',
            $uuid,
            $templateId,
            $templateUuid,
            $templateVersion,
            $title,
            $norm,
            $nopen,
            $label,
            $fieldValuesJson,
            $signatureValuesJson,
            $metadataJson,
            $status,
            $isLocked,
            $contentHash,
            $now,
            $contentHashVersion,
            $publishedAt,
            $actorIdForInsert,
            $actorIdForInsert,
            $now,
            $now
        );

        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new \RuntimeException('Failed to INSERT document: ' . $err);
        }

        $newId = (int) mysqli_insert_id($this->db);
        mysqli_stmt_close($stmt);

        return new SaveDocumentResult($newId, $uuid, 1, true, $contentHash);
    }

    private function doUpdate(
        SaveDocumentRequest $req,
        int $actorId,
        string $fieldValuesJson,
        string $signatureValuesJson,
        string $metadataJson,
        string $contentHash
    ): SaveDocumentResult {
        $docId = (int) $req->getDocumentId();

        // Load existing row for revision + uuid + owner
        $existing = $this->findById($docId);
        if ($existing === null) {
            throw NotFoundException::forResource('document', $docId);
        }

        $currentRevision = $existing->getRevision();
        $expected = $req->getExpectedRevision();
        if ($expected !== null && $expected !== $currentRevision) {
            throw ValidationException::forField(
                'expected_revision',
                "revision mismatch (expected {$expected}, current {$currentRevision})"
            );
        }

        $newRevision = $currentRevision + 1;
        $status = $req->getStatus();
        $isLocked = ($status === 'locked') ? 1 : 0;
        $title = $req->getTitle();
        $now = date('Y-m-d H:i:s');
        $contentHashVersion = 1;
        $actorIdForUpdate = $actorId > 0 ? $actorId : null;

        // Map generic subject → legacy norm/nopen for backward compat.
        $fieldValues = $req->getFieldValues();
        $norm = null;
        $nopen = null;
        if ($req->getSubjectType() === 'patient' && $req->getSubjectId() !== null) {
            $norm = $req->getSubjectId();
        } elseif (isset($fieldValues['norm']) && is_scalar($fieldValues['norm'])) {
            $norm = (string) $fieldValues['norm'];
        }
        if (isset($fieldValues['nopen']) && is_scalar($fieldValues['nopen'])) {
            $nopen = (string) $fieldValues['nopen'];
        }

        $label = '-';
        if (isset($fieldValues['label']) && is_scalar($fieldValues['label']) && (string) $fieldValues['label'] !== '') {
            $label = substr((string) $fieldValues['label'], 0, 100);
        }

        $sql = 'UPDATE ezdoc_documents SET '
            . 'title = ?, norm = ?, nopen = ?, label = ?, '
            . 'field_values = ?, signature_values = ?, metadata = ?, '
            . 'status = ?, is_locked = ?, '
            . 'content_hash = ?, content_hash_at = ?, content_hash_version = ?, '
            . 'revision = ?, updated_by = ?, updated_at = ? '
            . 'WHERE id = ? AND revision = ? AND deleted_at IS NULL';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare UPDATE: ' . mysqli_error($this->db));
        }

        // Types (17 params): s s s s  s s s s  i s s i  i i s i i
        mysqli_stmt_bind_param(
            $stmt,
            'ssssssssissiiisii',
            $title,
            $norm,
            $nopen,
            $label,
            $fieldValuesJson,
            $signatureValuesJson,
            $metadataJson,
            $status,
            $isLocked,
            $contentHash,
            $now,
            $contentHashVersion,
            $newRevision,
            $actorIdForUpdate,
            $now,
            $docId,
            $currentRevision
        );

        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new \RuntimeException('Failed to UPDATE document: ' . $err);
        }

        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected < 1) {
            // Row exists but revision changed between load & write → concurrent update.
            throw ValidationException::forField(
                'expected_revision',
                'concurrent update detected (revision changed between load and write)'
            );
        }

        return new SaveDocumentResult($docId, $existing->getUuid(), $newRevision, false, $contentHash);
    }

    /**
     * Soft-delete: set deleted_at + deleted_by + deleted_reason.
     * Returns true if a row was affected.
     */
    public function softDelete(int $id, int $actorId, string $reason = ''): bool
    {
        if ($id <= 0) return false;
        $now = date('Y-m-d H:i:s');
        $actorStr = $actorId > 0 ? (string) $actorId : null;

        $sql = 'UPDATE ezdoc_documents SET deleted_at = ?, deleted_by = ?, deleted_reason = ? '
            . 'WHERE id = ? AND deleted_at IS NULL';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'sssi', $now, $actorStr, $reason, $id);
        $ok = mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        return $ok && $affected > 0;
    }

    /**
     * @param \mysqli_stmt $stmt
     */
    private function fetchSingle($stmt): ?Document
    {
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return null;
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        return $row ? Document::fromRow($row) : null;
    }

    /**
     * @param \mysqli_stmt $stmt
     * @return array<int,Document>
     */
    private function fetchMany($stmt): array
    {
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return [];
        }
        $result = mysqli_stmt_get_result($stmt);
        $out = [];
        if ($result) {
            while (($row = mysqli_fetch_assoc($result)) !== null) {
                $out[] = Document::fromRow($row);
            }
        }
        mysqli_stmt_close($stmt);
        return $out;
    }

    /**
     * SHA-256 hex of canonical JSON of $fieldValues. Keys are sorted recursively
     * so semantically equal maps hash the same regardless of insertion order.
     *
     * @param array<string,mixed> $fieldValues
     */
    private static function computeContentHash(array $fieldValues): string
    {
        $canonical = self::canonicalize($fieldValues);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) $json = '';
        return hash('sha256', $json);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (!is_array($value)) return $value;
        // Detect list vs map. Lists preserve order, maps sort by key.
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $v) $out[] = self::canonicalize($v);
            return $out;
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::canonicalize($v);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }
}
