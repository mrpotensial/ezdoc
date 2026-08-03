<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Document\SaveDocumentRequest — DTO for DocumentRepository::save().
 *
 * PHP 7.4 compatible (no readonly, no promotion, no union types).
 * Fields are populated once in the constructor from an associative
 * array (typically $_POST or a decoded JSON body) and exposed via
 * getters. Direct property access is discouraged (public props omitted).
 *
 * @example
 *   $req = new SaveDocumentRequest([
 *     'template_id' => 42,
 *     'document_id' => null,
 *     'field_values' => ['name' => 'John'],
 *     'status' => 'draft',
 *   ]);
 */
final class SaveDocumentRequest
{
    /** @var int */
    private $templateId;

    /** @var int|null */
    private $documentId;

    /** @var string|null */
    private $subjectType;

    /** @var string|null */
    private $subjectId;

    /** @var string|null */
    private $title;

    /** @var string|null */
    private $referenceNumber;

    /** @var array<string,mixed> */
    private $fieldValues;

    /** @var array<string,mixed> */
    private $signatureValues;

    /** @var array<string,mixed> */
    private $metadata;

    /** @var string */
    private $status;

    /** @var int|null */
    private $expectedRevision;

    /** @var array<string> Allowed status values (mirrors schema ENUM). */
    private static $ALLOWED_STATUS = ['draft', 'published', 'locked', 'archived'];

    /**
     * @param array<string,mixed> $data
     * @throws ValidationException when required fields are missing/invalid
     */
    public function __construct(array $data)
    {
        // template_id — required, positive int
        if (!array_key_exists('template_id', $data) && !array_key_exists('templateId', $data)) {
            throw ValidationException::forField('template_id', 'required');
        }
        $rawTemplateId = $data['template_id'] ?? $data['templateId'];
        if (!is_numeric($rawTemplateId) || (int) $rawTemplateId <= 0) {
            throw ValidationException::forField('template_id', 'must be a positive integer');
        }
        $this->templateId = (int) $rawTemplateId;

        // document_id — optional
        $this->documentId = null;
        $rawDocId = $data['document_id'] ?? $data['documentId'] ?? null;
        if ($rawDocId !== null && $rawDocId !== '' && $rawDocId !== 0 && $rawDocId !== '0') {
            if (!is_numeric($rawDocId) || (int) $rawDocId <= 0) {
                throw ValidationException::forField('document_id', 'must be a positive integer when provided');
            }
            $this->documentId = (int) $rawDocId;
        }

        // subject_type / subject_id — optional strings
        $this->subjectType = self::nullableString($data, ['subject_type', 'subjectType'], 64);
        $this->subjectId = self::nullableString($data, ['subject_id', 'subjectId'], 128);

        // title / reference_number — optional strings
        $this->title = self::nullableString($data, ['title'], 255);
        $this->referenceNumber = self::nullableString($data, ['reference_number', 'referenceNumber'], 100);

        // JSON payloads — arrays only (already-decoded)
        $this->fieldValues = self::arrayOrEmpty($data, ['field_values', 'fieldValues']);
        $this->signatureValues = self::arrayOrEmpty($data, ['signature_values', 'signatureValues']);
        $this->metadata = self::arrayOrEmpty($data, ['metadata']);

        // status
        $status = 'draft';
        if (isset($data['status']) && is_string($data['status']) && $data['status'] !== '') {
            $status = $data['status'];
        }
        if (!in_array($status, self::$ALLOWED_STATUS, true)) {
            throw ValidationException::forField(
                'status',
                "must be one of: " . implode(', ', self::$ALLOWED_STATUS)
            );
        }
        $this->status = $status;

        // expectedRevision — optional (optimistic locking)
        $this->expectedRevision = null;
        $rawRev = $data['expected_revision'] ?? $data['expectedRevision'] ?? null;
        if ($rawRev !== null && $rawRev !== '') {
            if (!is_numeric($rawRev) || (int) $rawRev < 0) {
                throw ValidationException::forField('expected_revision', 'must be a non-negative integer');
            }
            $this->expectedRevision = (int) $rawRev;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string>       $keys
     */
    private static function nullableString(array $data, array $keys, int $maxLen): ?string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                $v = (string) $data[$k];
                if ($maxLen > 0 && strlen($v) > $maxLen) {
                    $v = substr($v, 0, $maxLen);
                }
                return $v;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string>       $keys
     * @return array<string,mixed>
     */
    private static function arrayOrEmpty(array $data, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                if (is_array($v)) return $v;
                if (is_string($v) && $v !== '') {
                    $decoded = json_decode($v, true);
                    if (is_array($decoded)) return $decoded;
                }
                return [];
            }
        }
        return [];
    }

    public function getTemplateId(): int
    {
        return $this->templateId;
    }

    public function getDocumentId(): ?int
    {
        return $this->documentId;
    }

    public function isUpdate(): bool
    {
        return $this->documentId !== null;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
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

    public function getExpectedRevision(): ?int
    {
        return $this->expectedRevision;
    }
}
