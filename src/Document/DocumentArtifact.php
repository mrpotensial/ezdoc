<?php

declare(strict_types=1);

namespace Ezdoc\Document;

/**
 * Satu rendering dokumen yang sudah dibekukan jadi byte tetap.
 *
 * Lihat docs/260805-psre-meterai.md §7b (A2). Sebelum ini dokumen selalu dirender
 * ulang dari template. Tanda tangan data kita tetap terverifikasi kalau
 * templatenya berubah — ia mencakup data, bukan tampilan — tapi yang dibaca
 * orang jadi berbeda dari yang dulu mereka setujui. Artefak menutup selisih itu.
 *
 * Objek ini tidak memuat byte-nya. Ia memuat `blobKey`, dan byte-nya diambil
 * dari {@see \Ezdoc\Storage\BlobStore} saat benar-benar dibutuhkan — daftar
 * artefak sebuah dokumen tidak boleh menyeret beberapa megabyte PDF hanya untuk
 * menampilkan tanggal.
 *
 * `blobKey` **adalah** hash artefaknya, bukan penunjuk ke tempat hashnya
 * disimpan. `ezdoc_blobs` dialamatkan isinya, jadi kunci yang menyebut isi tidak
 * bisa berubah arti besok.
 *
 * Immutable: artefak yang sudah dibekukan tidak pernah disunting. Membekukan
 * ulang melahirkan siklus baru.
 *
 * PHP 7.4+ compatible.
 */
final class DocumentArtifact
{
    /** @var int */
    private $id;

    /** @var string */
    private $uuid;

    /** @var int */
    private $documentId;

    /** @var int */
    private $cycle;

    /** @var string */
    private $blobKey;

    /** @var array<string,mixed> */
    private $manifest;

    /** @var string */
    private $frozenAt;

    /** @var string */
    private $frozenBy;

    /** @var string|null */
    private $supersededAt;

    /** @var string|null */
    private $supersededReason;

    /**
     * @param array<string,mixed> $manifest
     */
    public function __construct(
        int $id,
        string $uuid,
        int $documentId,
        int $cycle,
        string $blobKey,
        array $manifest,
        string $frozenAt,
        string $frozenBy,
        ?string $supersededAt = null,
        ?string $supersededReason = null
    ) {
        $this->id = $id;
        $this->uuid = $uuid;
        $this->documentId = $documentId;
        $this->cycle = $cycle;
        $this->blobKey = $blobKey;
        $this->manifest = $manifest;
        $this->frozenAt = $frozenAt;
        $this->frozenBy = $frozenBy;
        $this->supersededAt = $supersededAt;
        $this->supersededReason = $supersededReason;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $manifest = [];
        if (isset($row['manifest']) && is_string($row['manifest']) && $row['manifest'] !== '') {
            $decoded = json_decode($row['manifest'], true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        } elseif (isset($row['manifest']) && is_array($row['manifest'])) {
            $manifest = $row['manifest'];
        }

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['uuid'] ?? ''),
            (int) ($row['document_id'] ?? 0),
            (int) ($row['cycle'] ?? 0),
            (string) ($row['blob_key'] ?? ''),
            $manifest,
            (string) ($row['frozen_at'] ?? ''),
            (string) ($row['frozen_by'] ?? ''),
            isset($row['superseded_at']) && $row['superseded_at'] !== null
                ? (string) $row['superseded_at']
                : null,
            isset($row['superseded_reason']) && $row['superseded_reason'] !== null
                ? (string) $row['superseded_reason']
                : null
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getDocumentId(): int
    {
        return $this->documentId;
    }

    public function getCycle(): int
    {
        return $this->cycle;
    }

    /** SHA-256 isinya — sekaligus kunci di `ezdoc_blobs`. */
    public function getBlobKey(): string
    {
        return $this->blobKey;
    }

    /** @return array<string,mixed> */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function getFrozenAt(): string
    {
        return $this->frozenAt;
    }

    public function getFrozenBy(): string
    {
        return $this->frozenBy;
    }

    public function getSupersededAt(): ?string
    {
        return $this->supersededAt;
    }

    public function getSupersededReason(): ?string
    {
        return $this->supersededReason;
    }

    /**
     * Artefak yang masih berlaku — inilah yang membuat dokumennya beku.
     *
     * Statusnya diturunkan dari sini, bukan disimpan sebagai kolom di dokumen.
     * Kolom terpisah harus dijaga tetap sejalan dengan tabel ini, dan yang bisa
     * berselisih akan berselisih.
     */
    public function isActive(): bool
    {
        return $this->supersededAt === null;
    }

    /**
     * Tanda tangan yang dibekukan artefak ini, dari manifesnya.
     *
     * @return list<array<string,mixed>>
     */
    public function signatures(): array
    {
        $sigs = $this->manifest['signatures'] ?? [];

        return is_array($sigs) ? array_values($sigs) : [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'document_id'       => $this->documentId,
            'cycle'             => $this->cycle,
            'blob_key'          => $this->blobKey,
            'manifest'          => $this->manifest,
            'frozen_at'         => $this->frozenAt,
            'frozen_by'         => $this->frozenBy,
            'superseded_at'     => $this->supersededAt,
            'superseded_reason' => $this->supersededReason,
            'active'            => $this->isActive(),
        ];
    }
}
