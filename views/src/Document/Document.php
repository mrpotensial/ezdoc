<?php

declare(strict_types=1);

namespace Ezdoc\Document;

/**
 * Ezdoc\Document\Document — value object representing a document row.
 *
 * Immutable by convention (private props + getters). Domain-agnostic:
 * exposes generic subject_type/subject_id + field_values JSON. Legacy
 * hospital-specific columns (norm, nopen) are surfaced only through
 * field_values entries when present, never as top-level getters.
 *
 * PHP 7.4+ compatible — no readonly, no constructor promotion, no union types.
 *
 * @example
 *   $doc = Document::fromRow($row);
 *   $vals = $doc->getFieldValues();
 *   $norm = $vals['norm'] ?? null;
 */
final class Document
{
    /** @var int */
    private $id;

    /** @var string */
    private $uuid;

    /** @var int */
    private $templateId;

    /** @var string */
    private $templateUuid;

    /** @var int */
    private $templateVersion;

    /** @var string|null */
    private $title;

    /** @var string|null */
    private $referenceNumber;

    /** @var string|null */
    private $subjectType;

    /** @var string|null */
    private $subjectId;

    /** @var array<string,mixed> */
    private $fieldValues;

    /** @var array<string,mixed> */
    private $signatureValues;

    /** @var array<string,mixed> */
    private $metadata;

    /** @var string */
    private $status;

    /** @var string|null */
    private $contentHash;

    /** @var string|null */
    private $publicSlug;

    /** @var int */
    private $revision;

    /** @var int|null */
    private $ownerId;

    /** @var string|null */
    private $createdAt;

    /** @var string|null */
    private $updatedAt;

    /**
     * @param array<string,mixed> $fieldValues
     * @param array<string,mixed> $signatureValues
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        int $id,
        string $uuid,
        int $templateId,
        string $templateUuid,
        int $templateVersion,
        ?string $title,
        ?string $referenceNumber,
        ?string $subjectType,
        ?string $subjectId,
        array $fieldValues,
        array $signatureValues,
        array $metadata,
        string $status,
        ?string $contentHash,
        ?string $publicSlug,
        int $revision,
        ?int $ownerId,
        ?string $createdAt,
        ?string $updatedAt
    ) {
        $this->id = $id;
        $this->uuid = $uuid;
        $this->templateId = $templateId;
        $this->templateUuid = $templateUuid;
        $this->templateVersion = $templateVersion;
        $this->title = $title;
        $this->referenceNumber = $referenceNumber;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->fieldValues = $fieldValues;
        $this->signatureValues = $signatureValues;
        $this->metadata = $metadata;
        $this->status = $status;
        $this->contentHash = $contentHash;
        $this->publicSlug = $publicSlug;
        $this->revision = $revision;
        $this->ownerId = $ownerId;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Hydrate from mysqli fetch_assoc row.
     *
     * Handles schema mapping:
     *   - subject_type/subject_id: prefer explicit columns; fall back to
     *     legacy norm/nopen (subject_type='patient', subject_id=norm).
     *   - field_values / signature_values / metadata: decoded from JSON
     *     strings; also promotes legacy norm/nopen into field_values keys.
     *   - reference_number: reads from column of same name if present,
     *     else null.
     *   - owner_id: prefers explicit column, then created_by.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $id = isset($row['id']) ? (int) $row['id'] : 0;
        $uuid = isset($row['uuid']) ? (string) $row['uuid'] : '';
        $templateId = isset($row['template_id']) ? (int) $row['template_id'] : 0;
        $templateUuid = isset($row['template_uuid']) ? (string) $row['template_uuid'] : '';
        $templateVersion = isset($row['template_version']) ? (int) $row['template_version'] : 1;

        $title = isset($row['title']) && $row['title'] !== null ? (string) $row['title'] : null;
        $referenceNumber = isset($row['reference_number']) && $row['reference_number'] !== null
            ? (string) $row['reference_number']
            : null;

        // Prefer new generic columns; fall back to legacy norm/nopen.
        $subjectType = isset($row['subject_type']) && $row['subject_type'] !== null && $row['subject_type'] !== ''
            ? (string) $row['subject_type']
            : null;
        $subjectId = isset($row['subject_id']) && $row['subject_id'] !== null && $row['subject_id'] !== ''
            ? (string) $row['subject_id']
            : null;

        if ($subjectType === null && !empty($row['norm'])) {
            $subjectType = 'patient';
        }
        if ($subjectId === null && !empty($row['norm'])) {
            $subjectId = (string) $row['norm'];
        }

        $fieldValues = self::decodeJsonObject($row['field_values'] ?? null);
        $signatureValues = self::decodeJsonObject($row['signature_values'] ?? null);
        $metadata = self::decodeJsonObject($row['metadata'] ?? null);

        // Promote legacy hospital columns into field_values for read access.
        if (!empty($row['norm']) && !array_key_exists('norm', $fieldValues)) {
            $fieldValues['norm'] = (string) $row['norm'];
        }
        if (!empty($row['nopen']) && !array_key_exists('nopen', $fieldValues)) {
            $fieldValues['nopen'] = (string) $row['nopen'];
        }
        if (!empty($row['label']) && !array_key_exists('label', $fieldValues)) {
            $fieldValues['label'] = (string) $row['label'];
        }

        $status = isset($row['status']) ? (string) $row['status'] : 'draft';
        $contentHash = isset($row['content_hash']) && $row['content_hash'] !== null
            ? (string) $row['content_hash']
            : null;
        $publicSlug = isset($row['public_slug']) && $row['public_slug'] !== null
            ? (string) $row['public_slug']
            : null;
        $revision = isset($row['revision']) ? (int) $row['revision'] : 1;

        $ownerId = null;
        if (isset($row['owner_id']) && $row['owner_id'] !== null && $row['owner_id'] !== '') {
            $ownerId = (int) $row['owner_id'];
        } elseif (isset($row['created_by']) && $row['created_by'] !== null && $row['created_by'] !== '') {
            $ownerId = (int) $row['created_by'];
        }

        $createdAt = isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null;
        $updatedAt = isset($row['updated_at']) && $row['updated_at'] !== null ? (string) $row['updated_at'] : null;

        return new self(
            $id,
            $uuid,
            $templateId,
            $templateUuid,
            $templateVersion,
            $title,
            $referenceNumber,
            $subjectType,
            $subjectId,
            $fieldValues,
            $signatureValues,
            $metadata,
            $status,
            $contentHash,
            $publicSlug,
            $revision,
            $ownerId,
            $createdAt,
            $updatedAt
        );
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private static function decodeJsonObject($raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getTemplateId(): int
    {
        return $this->templateId;
    }

    public function getTemplateUuid(): string
    {
        return $this->templateUuid;
    }

    public function getTemplateVersion(): int
    {
        return $this->templateVersion;
    }

    /**
     * Human-readable title. Returns stored title kalau ada; otherwise synthesizes
     * dari subject/label/id — mirrors Filament getRecordTitleAttribute() pattern.
     * Never returns null (fallback ke "Document #{id}").
     */
    public function getTitle(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }
        // Fallback synthesis order:
        //   1) "{subject_type}: {subject_id}"  (patient/entity documents)
        //   2) "Doc #{id}"                     (fallback ultima)
        if ($this->subjectType !== null && $this->subjectId !== null) {
            return ucfirst($this->subjectType) . ': ' . $this->subjectId;
        }
        return 'Doc #' . $this->id;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    /** @return array<string,mixed> */
    public function getFieldValues(): array
    {
        return $this->fieldValues;
    }

    /** @return array<string,mixed> */
    public function getSignatureValues(): array
    {
        return $this->signatureValues;
    }

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function getPublicSlug(): ?string
    {
        return $this->publicSlug;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Serialize to associative array — for JSON responses, audit logging.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'template_id' => $this->templateId,
            'template_uuid' => $this->templateUuid,
            'template_version' => $this->templateVersion,
            'title' => $this->title,
            'reference_number' => $this->referenceNumber,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'field_values' => $this->fieldValues,
            'signature_values' => $this->signatureValues,
            'metadata' => $this->metadata,
            'status' => $this->status,
            'content_hash' => $this->contentHash,
            'public_slug' => $this->publicSlug,
            'revision' => $this->revision,
            'owner_id' => $this->ownerId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
