<?php

declare(strict_types=1);

namespace Ezdoc\Document;

/**
 * Ezdoc\Document\SaveDocumentResult — DTO returned by DocumentRepository::save().
 *
 * PHP 7.4 compatible. Immutable by convention.
 */
final class SaveDocumentResult
{
    /** @var int */
    private $documentId;

    /** @var string */
    private $uuid;

    /** @var int */
    private $revision;

    /** @var bool */
    private $isNew;

    /** @var string */
    private $contentHash;

    public function __construct(
        int $documentId,
        string $uuid,
        int $revision,
        bool $isNew,
        string $contentHash
    ) {
        $this->documentId = $documentId;
        $this->uuid = $uuid;
        $this->revision = $revision;
        $this->isNew = $isNew;
        $this->contentHash = $contentHash;
    }

    public function getDocumentId(): int
    {
        return $this->documentId;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'uuid' => $this->uuid,
            'revision' => $this->revision,
            'is_new' => $this->isNew,
            'content_hash' => $this->contentHash,
        ];
    }
}
