<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Db\Schema\ColumnIntrospector;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\UUID;
use mysqli;

/**
 * Ezdoc\Document\DocumentRepository — driver-agnostic persistence for documents.
 *
 * ## v0.9.9 refactor — Connection interface
 *
 * Sebelumnya hard-coupled `mysqli`. Sekarang accepts `Ezdoc\Db\Connection`
 * (interface) — semua query goes lewat `fetchOne`/`fetchAll`/`execute`.
 * Constructor tetap backward-compat: kalau consumer lempar `mysqli` global
 * (consumer bootstrap pattern), otomatis di-wrap ke `MysqliConnection`.
 *
 * ## Behavior
 *
 * All queries use prepared statements via Connection. Optimistic locking on
 * UPDATE via WHERE revision = expectedRevision (throws ValidationException
 * on mismatch). Content hash is SHA-256 of canonical JSON of field_values
 * (keys sorted recursively).
 *
 * PHP 7.4+ compatible.
 */
final class DocumentRepository
{
    /** @var Connection */
    private $db;

    /** @var ColumnIntrospector Lazy schema introspection untuk adaptive SELECT */
    private $introspector;

    /**
     * Desired SELECT columns (Blueprint-canonical). Actual SELECT list =
     * intersection dgn columns yg exist di consumer DB (via ColumnIntrospector).
     * Handle schema drift kalau consumer DB pakai older migration subset.
     *
     * @var list<string>
     */
    private static $desiredCols = [
        'id', 'uuid', 'template_id', 'template_uuid', 'template_version',
        'title', 'norm', 'nopen', 'label', 'version',
        'field_values', 'signature_values', 'metadata',
        'status', 'is_locked',
        'content_hash', 'content_hash_at', 'content_hash_version',
        'public_slug', 'public_slug_active',
        'revision',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ];

    /**
     * @param Connection|mysqli $db Ezdoc\Db\Connection instance (preferred) atau
     *   raw mysqli (backward-compat, auto-wrap ke MysqliConnection).
     */
    public function __construct($db)
    {
        if ($db instanceof Connection) {
            $this->db = $db;
        } elseif ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } else {
            throw new \InvalidArgumentException(
                'DocumentRepository requires Ezdoc\\Db\\Connection or mysqli, got: '
                . (is_object($db) ? get_class($db) : gettype($db))
            );
        }
        $this->introspector = new ColumnIntrospector($this->db);
    }

    /**
     * Build SELECT column clause adaptif — intersection of $desiredCols dgn
     * columns yg actually exist di consumer DB. Cached via ColumnIntrospector.
     */
    private function selectCols(): string
    {
        $existing = $this->introspector->intersect('ezdoc_documents', self::$desiredCols);
        return implode(', ', $existing);
    }

    public function findById(int $id): ?Document
    {
        if ($id <= 0) return null;
        $row = $this->db->fetchOne(
            'SELECT ' . $this->selectCols()
            . ' FROM ezdoc_documents WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id]
        );
        return $row ? Document::fromRow($row) : null;
    }

    public function findByUuid(string $uuid): ?Document
    {
        if ($uuid === '') return null;
        $row = $this->db->fetchOne(
            'SELECT ' . $this->selectCols()
            . ' FROM ezdoc_documents WHERE uuid = ? AND deleted_at IS NULL LIMIT 1',
            [$uuid]
        );
        return $row ? Document::fromRow($row) : null;
    }

    public function findByPublicSlug(string $slug): ?Document
    {
        if ($slug === '') return null;
        $row = $this->db->fetchOne(
            'SELECT ' . $this->selectCols()
            . ' FROM ezdoc_documents WHERE public_slug = ? AND public_slug_active = 1'
            . ' AND deleted_at IS NULL LIMIT 1',
            [$slug]
        );
        return $row ? Document::fromRow($row) : null;
    }

    /**
     * @return array<int,Document>
     */
    public function listByTemplate(int $templateId, int $limit = 100, int $offset = 0): array
    {
        if ($templateId <= 0) return [];
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            'SELECT ' . $this->selectCols()
            . ' FROM ezdoc_documents WHERE template_id = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            [$templateId, $limit, $offset]
        );
        return array_map([Document::class, 'fromRow'], $rows);
    }

    /**
     * @return array<int,Document>
     */
    public function listByStatus(string $status, int $limit = 100): array
    {
        if ($status === '') return [];
        $limit = max(1, min($limit, 1000));

        $rows = $this->db->fetchAll(
            'SELECT ' . $this->selectCols()
            . ' FROM ezdoc_documents WHERE status = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT ?',
            [$status, $limit]
        );
        return array_map([Document::class, 'fromRow'], $rows);
    }

    /**
     * List recent documents across all statuses (for main list view).
     * Supports filter by status ('' = all) and title/norm/nopen search.
     *
     * @return array<int,Document>
     */
    public function findRecent(string $statusFilter = '', string $searchQ = '', int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));

        $where  = ['deleted_at IS NULL'];
        $params = [];

        if ($statusFilter !== '' && in_array($statusFilter, ['draft', 'published', 'locked', 'archived'], true)) {
            $where[]  = 'status = ?';
            $params[] = $statusFilter;
        }
        if ($searchQ !== '') {
            $where[]  = '(title LIKE ? OR norm LIKE ? OR nopen LIKE ?)';
            $needle   = '%' . $searchQ . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }
        $params[] = $limit;

        $rows = $this->db->fetchAll(
            'SELECT ' . $this->selectCols()
            . ' FROM ezdoc_documents WHERE ' . implode(' AND ', $where)
            . ' ORDER BY id DESC LIMIT ?',
            $params
        );
        return array_map([Document::class, 'fromRow'], $rows);
    }

    /**
     * INSERT if `$req->documentId` is null; UPDATE otherwise.
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
     * Load template row for INSERT — needs template_uuid + version to denormalize
     * into the document.
     *
     * @return array{template_uuid:string, template_version:int}|null
     */
    private function loadTemplateSnapshot(int $templateId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT uuid, version FROM ezdoc_templates WHERE id = ? LIMIT 1',
            [$templateId]
        );
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
        $status = $req->getStatus();
        $publishedAt = ($status === 'published' || $status === 'locked')
            ? date('Y-m-d H:i:s')
            : null;

        [$norm, $nopen, $label] = $this->extractLegacyIdentity($req);

        $now = date('Y-m-d H:i:s');
        $actorForInsert = $actorId > 0 ? $actorId : null;

        $sql = 'INSERT INTO ezdoc_documents '
            . '(uuid, template_id, template_uuid, template_version, title, norm, nopen, label, '
            . 'field_values, signature_values, metadata, status, is_locked, '
            . 'content_hash, content_hash_at, content_hash_version, '
            . 'published_at, revision, created_by, updated_by, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)';

        $this->db->execute($sql, [
            $uuid,
            $req->getTemplateId(),
            $snap['template_uuid'],
            $snap['template_version'],
            $req->getTitle(),
            $norm,
            $nopen,
            $label,
            $fieldValuesJson,
            $signatureValuesJson,
            $metadataJson,
            $status,
            ($status === 'locked') ? 1 : 0,
            $contentHash,
            $now,
            1, // content_hash_version
            $publishedAt,
            $actorForInsert,
            $actorForInsert,
            $now,
            $now,
        ]);

        $newId = (int) $this->db->lastInsertId();
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
        $now = date('Y-m-d H:i:s');
        $actorForUpdate = $actorId > 0 ? $actorId : null;

        [$norm, $nopen, $label] = $this->extractLegacyIdentity($req);

        $sql = 'UPDATE ezdoc_documents SET '
            . 'title = ?, norm = ?, nopen = ?, label = ?, '
            . 'field_values = ?, signature_values = ?, metadata = ?, '
            . 'status = ?, is_locked = ?, '
            . 'content_hash = ?, content_hash_at = ?, content_hash_version = ?, '
            . 'revision = ?, updated_by = ?, updated_at = ? '
            . 'WHERE id = ? AND revision = ? AND deleted_at IS NULL';

        $affected = $this->db->execute($sql, [
            $req->getTitle(),
            $norm,
            $nopen,
            $label,
            $fieldValuesJson,
            $signatureValuesJson,
            $metadataJson,
            $status,
            ($status === 'locked') ? 1 : 0,
            $contentHash,
            $now,
            1, // content_hash_version
            $newRevision,
            $actorForUpdate,
            $now,
            $docId,
            $currentRevision,
        ]);

        if ($affected < 1) {
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

        $affected = $this->db->execute(
            'UPDATE ezdoc_documents SET deleted_at = ?, deleted_by = ?, deleted_reason = ? '
            . 'WHERE id = ? AND deleted_at IS NULL',
            [$now, $actorStr, $reason, $id]
        );
        return $affected > 0;
    }

    /**
     * Extract norm/nopen/label dari SaveDocumentRequest untuk denormalize ke
     * legacy columns. Support subject_type=patient shortcut + field_values fallback.
     *
     * @return array{0:?string, 1:?string, 2:string}  [norm, nopen, label]
     */
    private function extractLegacyIdentity(SaveDocumentRequest $req): array
    {
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

        return [$norm, $nopen, $label];
    }

    /**
     * SHA-256 hex of canonical JSON of $fieldValues. Keys sorted recursively.
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
