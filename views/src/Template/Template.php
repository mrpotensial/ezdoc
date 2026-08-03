<?php

declare(strict_types=1);

namespace Ezdoc\Template;

use Ezdoc\Access\AccessConfig;

/**
 * Ezdoc\Template\Template — value object untuk 1 row ezdoc_templates.
 *
 * Immutable snapshot dari database row. JSON columns sudah di-decode ke array
 * saat konstruksi via {@see self::fromRow()}.
 *
 * Domain-agnostic: field_values (norm/nopen/dll) di-expose lewat metadata /
 * verify_config, bukan sebagai top-level getter — supaya library tidak
 * hospital-specific.
 *
 * PHP 7.4+ compatible.
 */
final class Template
{
    /** @var int */
    private $id;

    /** @var string */
    private $uuid;

    /** @var string */
    private $slug;

    /** @var int */
    private $version;

    /** @var bool */
    private $isCurrent;

    /** @var int|null */
    private $parentVersionId;

    /** @var string */
    private $name;

    /** @var string */
    private $category;

    /** @var string 'patient'|'general' */
    private $scope;

    /** @var string HTML content */
    private $content;

    /** @var string|null */
    private $contentHash;

    /** @var array<string,mixed> */
    private $signatureConfig;

    /** @var array<string,mixed> */
    private $layoutConfig;

    /** @var array<string,mixed> */
    private $verifyConfig;

    /** @var array<string,mixed> */
    private $accessConfig;

    /** @var array<string,mixed> */
    private $metadata;

    /** @var int|null */
    private $ownerId;

    /** @var bool */
    private $isActive;

    /** @var bool */
    private $isLocked;

    /** @var int */
    private $revision;

    /** @var string|null */
    private $deletedAt;

    /** @var string|null */
    private $deletedBy;

    /** @var string|null */
    private $deletedReason;

    /** @var int|null */
    private $createdBy;

    /** @var int|null */
    private $updatedBy;

    /** @var string|null */
    private $createdAt;

    /** @var string|null */
    private $updatedAt;

    /**
     * Constructor terima associative array — dipanggil lewat fromRow() atau
     * langsung untuk build in-memory template belum tersimpan (id=0).
     *
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id              = isset($data['id']) ? (int) $data['id'] : 0;
        $this->uuid            = isset($data['uuid']) ? (string) $data['uuid'] : '';
        $this->slug            = isset($data['slug']) ? (string) $data['slug'] : '';
        $this->version         = isset($data['version']) ? (int) $data['version'] : 1;
        $this->isCurrent       = !empty($data['is_current']);
        $this->parentVersionId = isset($data['parent_version_id']) && $data['parent_version_id'] !== null
            ? (int) $data['parent_version_id']
            : null;
        $this->name            = isset($data['name']) ? (string) $data['name'] : '';
        $this->category        = isset($data['category']) ? (string) $data['category'] : '';
        $this->scope           = isset($data['scope']) && (string) $data['scope'] === 'general'
            ? 'general'
            : 'patient';
        $this->content         = isset($data['content']) ? (string) $data['content'] : '';
        $this->contentHash     = isset($data['content_hash']) && $data['content_hash'] !== null
            ? (string) $data['content_hash']
            : null;
        $this->signatureConfig = self::normalizeJsonField($data, 'signature_config');
        $this->layoutConfig    = self::normalizeJsonField($data, 'layout_config');
        $this->verifyConfig    = self::normalizeJsonField($data, 'verify_config');
        $this->accessConfig    = self::normalizeJsonField($data, 'access_config');
        $this->metadata        = self::normalizeJsonField($data, 'metadata');
        $this->ownerId         = isset($data['owner_id']) && $data['owner_id'] !== null
            ? (int) $data['owner_id']
            : null;
        $this->isActive        = !array_key_exists('is_active', $data) || !empty($data['is_active']);
        $this->isLocked        = !empty($data['is_locked']);
        $this->revision        = isset($data['revision']) ? (int) $data['revision'] : 1;
        $this->deletedAt       = isset($data['deleted_at']) && $data['deleted_at'] !== null
            ? (string) $data['deleted_at']
            : null;
        $this->deletedBy       = isset($data['deleted_by']) && $data['deleted_by'] !== null
            ? (string) $data['deleted_by']
            : null;
        $this->deletedReason   = isset($data['deleted_reason']) && $data['deleted_reason'] !== null
            ? (string) $data['deleted_reason']
            : null;
        $this->createdBy       = isset($data['created_by']) && $data['created_by'] !== null
            ? (int) $data['created_by']
            : null;
        $this->updatedBy       = isset($data['updated_by']) && $data['updated_by'] !== null
            ? (int) $data['updated_by']
            : null;
        $this->createdAt       = isset($data['created_at']) && $data['created_at'] !== null
            ? (string) $data['created_at']
            : null;
        $this->updatedAt       = isset($data['updated_at']) && $data['updated_at'] !== null
            ? (string) $data['updated_at']
            : null;
    }

    /**
     * Factory: build Template dari DB row (JSON columns auto-decode).
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    /**
     * Normalize JSON column ke array. Accept:
     *   - null / empty string → []
     *   - JSON string         → decoded array (fallback [] kalau invalid)
     *   - already-array       → as-is
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeJsonField(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) return [];
        $val = $data[$key];
        if ($val === null || $val === '') return [];
        if (is_array($val)) return $val;
        if (is_string($val)) {
            $decoded = json_decode($val, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    // ─── Getters ─────────────────────────────────────────────────────────

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getIsCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function getParentVersionId(): ?int
    {
        return $this->parentVersionId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    /** @return array<string,mixed> */
    public function getSignatureConfig(): array
    {
        return $this->signatureConfig;
    }

    /** @return array<string,mixed> */
    public function getLayoutConfig(): array
    {
        return $this->layoutConfig;
    }

    /** @return array<string,mixed> */
    public function getVerifyConfig(): array
    {
        return $this->verifyConfig;
    }

    /** @return array<string,mixed> */
    public function getAccessConfig(): array
    {
        return $this->accessConfig;
    }

    /** @return array<string,mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function getIsLocked(): bool
    {
        return $this->isLocked;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    public function getDeletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function getDeletedReason(): ?string
    {
        return $this->deletedReason;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // ─── Domain helpers ──────────────────────────────────────────────────

    /**
     * Parse access_config JSON ke object berkelas AccessConfig — untuk
     * di-consume oleh AccessControl service.
     */
    public function getAccessConfigObject(): AccessConfig
    {
        return AccessConfig::fromArray($this->accessConfig);
    }

    /**
     * Cek apakah template masih hidup (belum soft-deleted).
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Serialize kembali ke assoc array — JSON columns dikembalikan sebagai
     * associative array (bukan JSON string). Consumer yang mau simpan ke DB
     * harus json_encode() sendiri.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'slug'              => $this->slug,
            'version'           => $this->version,
            'is_current'        => $this->isCurrent ? 1 : 0,
            'parent_version_id' => $this->parentVersionId,
            'name'              => $this->name,
            'category'          => $this->category,
            'scope'             => $this->scope,
            'content'           => $this->content,
            'content_hash'      => $this->contentHash,
            'signature_config'  => $this->signatureConfig,
            'layout_config'     => $this->layoutConfig,
            'verify_config'     => $this->verifyConfig,
            'access_config'     => $this->accessConfig,
            'metadata'          => $this->metadata,
            'owner_id'          => $this->ownerId,
            'is_active'         => $this->isActive ? 1 : 0,
            'is_locked'         => $this->isLocked ? 1 : 0,
            'revision'          => $this->revision,
            'deleted_at'        => $this->deletedAt,
            'deleted_by'        => $this->deletedBy,
            'deleted_reason'    => $this->deletedReason,
            'created_by'        => $this->createdBy,
            'updated_by'        => $this->updatedBy,
            'created_at'        => $this->createdAt,
            'updated_at'        => $this->updatedAt,
        ];
    }
}
