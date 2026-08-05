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

    /**
     * Episode/konteks subjeknya — kunjungan untuk pasien, periode SK untuk
     * pegawai, tahun ajaran untuk mahasiswa. Menggantikan `nopen`.
     *
     * Dipisah dari `subjectId` karena keduanya memang dua hal: yang satu
     * menyebut SIAPA dan bertahan lintas episode, yang satu menyebut EPISODE
     * MANA. Diperas jadi satu, "semua dokumen subjek ini di semua episode"
     * tidak bisa ditanyakan lagi.
     *
     * @var string|null
     */
    private $contextId;

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
     * Apakah `signature_values` ikut dimuat dari baris asalnya.
     *
     * Query daftar sengaja meninggalkannya — gambar tanda tangan 97% muatan
     * query itu, dan daftarnya tidak menampilkan satu pun. Yang tersisa adalah
     * bahaya kolom kosong: tanpa flag ini, dokumen yang datang dari daftar akan
     * menjawab "belum ditandatangani" dengan percaya diri.
     *
     * @var bool
     */
    private $signatureValuesLoaded;

    /**
     * @param array<string,mixed> $fieldValues
     * @param array<string,mixed> $signatureValues
     * @param array<string,mixed> $metadata
     * @param bool $signatureValuesLoaded Default true supaya pemanggil lama
     *   (yang memang menyertakan nilainya) tidak berubah artinya.
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
        ?string $contextId,
        array $fieldValues,
        array $signatureValues,
        array $metadata,
        string $status,
        ?string $contentHash,
        ?string $publicSlug,
        int $revision,
        ?int $ownerId,
        ?string $createdAt,
        ?string $updatedAt,
        bool $signatureValuesLoaded = true
    ) {
        $this->signatureValuesLoaded = $signatureValuesLoaded;
        $this->id = $id;
        $this->uuid = $uuid;
        $this->templateId = $templateId;
        $this->templateUuid = $templateUuid;
        $this->templateVersion = $templateVersion;
        $this->title = $title;
        $this->referenceNumber = $referenceNumber;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->contextId = $contextId;
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

        $contextId = isset($row['context_id']) && $row['context_id'] !== null && $row['context_id'] !== ''
            ? (string) $row['context_id']
            : null;

        // Jatuh ke kolom lama selama masa peralihan. `'patient'` disimpulkan di
        // sini, bukan ditulis migrasi: baris yang punya `norm` memang berasal
        // dari jalur pasien, tapi menuliskannya ke database berarti library
        // memutuskan domain milik aplikasi pemakainya.
        if ($subjectType === null && !empty($row['norm'])) {
            $subjectType = 'patient';
        }
        if ($subjectId === null && !empty($row['norm'])) {
            $subjectId = (string) $row['norm'];
        }
        if ($contextId === null && !empty($row['nopen'])) {
            $contextId = (string) $row['nopen'];
        }

        $fieldValues = self::decodeJsonObject($row['field_values'] ?? null);
        $metadata = self::decodeJsonObject($row['metadata'] ?? null);

        // `signature_values` sengaja tidak diambil query daftar: gambar tanda
        // tangan 97% muatannya, dan daftar tidak menampilkan satu pun. Lihat
        // DocumentRepository::$listCols.
        //
        // Yang harus dijaga di sini: kolom yang TIDAK DIMUAT tidak boleh terbaca
        // sama seperti dokumen yang memang belum ditandatangani. Keduanya akan
        // jadi `[]`, dan kode yang bertanya "sudah ditandatangani?" akan menjawab
        // "belum" untuk dokumen yang sudah — jawaban yang salah, diberikan dengan
        // percaya diri, tanpa satu pun error.
        $signatureValuesLoaded = array_key_exists('signature_values', $row);
        $signatureValues = $signatureValuesLoaded
            ? self::decodeJsonObject($row['signature_values'])
            : [];

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
            $contextId,
            $fieldValues,
            $signatureValues,
            $metadata,
            $status,
            $contentHash,
            $publicSlug,
            $revision,
            $ownerId,
            $createdAt,
            $updatedAt,
            $signatureValuesLoaded
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

    /** Episode/konteksnya — kunjungan, periode, tahun ajaran. Dulu `nopen`. */
    public function getContextId(): ?string
    {
        return $this->contextId;
    }

    /** @return array<string,mixed> */
    public function getFieldValues(): array
    {
        return $this->fieldValues;
    }

    /**
     * Sudah dimuat? Panggil ini sebelum getSignatureValues() kalau dokumennya
     * bisa berasal dari query daftar.
     */
    public function hasLoadedSignatureValues(): bool
    {
        return $this->signatureValuesLoaded;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws \LogicException kalau kolomnya tidak ikut dimuat.
     *
     * Melempar, bukan mengembalikan array kosong. Array kosong artinya "dokumen
     * ini belum ditandatangani" — pernyataan yang salah dan tidak bisa dibedakan
     * dari kebenaran. Lebih baik pemanggilnya gagal keras dan memuat ulang
     * dokumennya lewat findById() daripada melaporkan dokumen bertanda tangan
     * sebagai belum ditandatangani.
     *
     * Pola yang sama dipakai Doctrine (mengakses properti proxy yang belum
     * di-inisialisasi melempar) dan Eloquent dengan `preventLazyLoading()`.
     * Bedanya di sini kami tidak memuat sendiri secara diam-diam: lazy-load akan
     * menyembunyikan N+1 di halaman daftar, yang justru alasan kolom ini
     * dikeluarkan.
     */
    public function getSignatureValues(): array
    {
        if (!$this->signatureValuesLoaded) {
            throw new \LogicException(
                'signature_values was not loaded for document ' . $this->id
                . '. List queries omit it on purpose — the images are ~97% of the'
                . ' payload and no list renders them. Load the document with'
                . ' DocumentRepository::findById() when you need the signatures,'
                . ' or check hasLoadedSignatureValues() first.'
            );
        }

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
            'context_id' => $this->contextId,
            'field_values' => $this->fieldValues,
            // `null` saat kolomnya tidak dimuat, bukan `[]`. Ini yang masuk ke
            // respons JSON dan audit log: `[]` di sana akan tercatat sebagai
            // "dokumen tanpa tanda tangan", dan catatan audit yang salah lebih
            // buruk daripada tidak ada catatan.
            'signature_values' => $this->signatureValuesLoaded ? $this->signatureValues : null,
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
