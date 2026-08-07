<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Support\Clock;
use Ezdoc\Support\SystemClock;
use Ezdoc\UUID;
use mysqli;

/**
 * Gerbang persistensi untuk `ezdoc_document_artifacts`.
 *
 * Hanya dua tulisan yang diizinkan: menyisipkan artefak baru, dan menandai yang
 * lama sudah digantikan. Tidak ada UPDATE lain dan tidak ada DELETE — artefak
 * yang pernah dipegang orang di luar tidak boleh menghilang dari catatan hanya
 * karena ada versi yang lebih baru.
 *
 * Jamnya disuntikkan. Ini bukan kerapian: `OtpChallengeRepository` dulu memakai
 * `time()` asli sementara servisnya memakai jam beku, dan alurnya menolak token
 * yang baru saja diterbitkannya sendiri. Satu alur tidak boleh memakai dua jam.
 *
 * PHP 7.4+ compatible.
 */
final class ArtifactRepository
{
    /** @var Connection */
    private $db;

    /** @var Clock */
    private $clock;

    /** @var string Daftar kolom SELECT — sinkron dengan migrasinya. */
    private static $cols = 'id, uuid, document_id, cycle, blob_key, manifest, '
        . 'frozen_at, frozen_by, superseded_at, superseded_by, superseded_reason, created_at';

    /**
     * @param Connection|mysqli $db
     */
    public function __construct($db, ?Clock $clock = null)
    {
        if ($db instanceof Connection) {
            $this->db = $db;
        } elseif ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } else {
            throw new \InvalidArgumentException(
                'ArtifactRepository requires Ezdoc\\Db\\Connection or mysqli, got: '
                . (is_object($db) ? get_class($db) : gettype($db))
            );
        }

        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Artefak yang masih berlaku untuk sebuah dokumen, kalau ada.
     *
     * Inilah yang menjawab "apakah dokumen ini beku". Dipanggil di jalur simpan
     * maupun jalur baca, jadi ia punya indeksnya sendiri.
     */
    public function activeFor(int $documentId): ?DocumentArtifact
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::$cols . ' FROM `ezdoc_document_artifacts`'
            . ' WHERE `document_id` = ? AND `superseded_at` IS NULL'
            . ' ORDER BY `cycle` DESC LIMIT 1',
            [$documentId]
        );

        return $row === null ? null : DocumentArtifact::fromRow($row);
    }

    /**
     * Seluruh siklus sebuah dokumen, terbaru dulu.
     *
     * Yang sudah digantikan ikut. Panel riwayat perlu menunjukkan bahwa siklus 1
     * pernah ada dan kenapa ia dicabut — daftar yang cuma memuat yang aktif
     * membuat pencabutan tampak tidak pernah terjadi.
     *
     * @return list<DocumentArtifact>
     */
    public function historyFor(int $documentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::$cols . ' FROM `ezdoc_document_artifacts`'
            . ' WHERE `document_id` = ? ORDER BY `cycle` DESC',
            [$documentId]
        );

        return array_map(
            static function (array $r): DocumentArtifact {
                return DocumentArtifact::fromRow($r);
            },
            $rows
        );
    }

    /**
     * Cari artefak lewat uuid-nya — jalur yang dipakai halaman verifikasi.
     *
     * Lewat uuid, bukan id: tautan verifikasi dibawa ke luar, dan id yang
     * berurutan mengundang orang mencoba tetangganya.
     */
    public function findByUuid(string $uuid): ?DocumentArtifact
    {
        if ($uuid === '') {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT ' . self::$cols . ' FROM `ezdoc_document_artifacts` WHERE `uuid` = ? LIMIT 1',
            [$uuid]
        );

        return $row === null ? null : DocumentArtifact::fromRow($row);
    }

    /**
     * Nomor siklus berikutnya untuk sebuah dokumen.
     *
     * Dihitung dari yang tertinggi yang pernah ada, bukan dari jumlah baris —
     * baris tidak pernah dihapus, tapi menghitung baris tetap salah kalau nanti
     * ada jalur yang menyisipkan di luar urutan.
     */
    public function nextCycle(int $documentId): int
    {
        $max = $this->db->fetchScalar(
            'SELECT MAX(`cycle`) FROM `ezdoc_document_artifacts` WHERE `document_id` = ?',
            [$documentId]
        );

        return $max === null ? 1 : ((int) $max) + 1;
    }

    /**
     * Catat sebuah artefak baru.
     *
     * Pemanggilnya {@see FreezeService}, yang sudah menyimpan byte-nya dan
     * memastikan tidak ada artefak aktif lain. Repositori tidak memeriksa ulang:
     * kunci unik `(document_id, cycle)` yang menjadi penjaga terakhirnya, dan
     * penjaga di database tidak bisa dilewati balapan antar-proses.
     *
     * @param array<string,mixed> $manifest
     */
    public function insert(
        int $documentId,
        int $cycle,
        string $blobKey,
        array $manifest,
        string $frozenBy
    ): DocumentArtifact {
        $uuid = UUID::v7();
        $frozenAt = $this->clock->now()->format('Y-m-d H:i:s.v');
        $manifestJson = json_encode($manifest);

        $this->db->execute(
            'INSERT INTO `ezdoc_document_artifacts`'
            . ' (`uuid`, `document_id`, `cycle`, `blob_key`, `manifest`, `frozen_at`, `frozen_by`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $uuid,
                $documentId,
                $cycle,
                $blobKey,
                $manifestJson === false ? null : $manifestJson,
                $frozenAt,
                $frozenBy,
            ]
        );

        return new DocumentArtifact(
            (int) $this->db->lastInsertId(),
            $uuid,
            $documentId,
            $cycle,
            $blobKey,
            $manifest,
            $frozenAt,
            $frozenBy
        );
    }

    /**
     * Tandai artefak aktif sebuah dokumen sudah digantikan.
     *
     * Byte-nya TIDAK disentuh. Blob tetap di tempatnya, dan artefaknya tetap
     * bisa diambil lewat uuid — orang yang memegang cetakan siklus lama harus
     * tetap bisa memeriksanya, justru karena ia sudah tidak berlaku.
     *
     * @return bool false kalau memang tidak ada yang aktif
     */
    public function supersede(int $documentId, string $reason, string $actorId): bool
    {
        $affected = $this->db->execute(
            'UPDATE `ezdoc_document_artifacts`'
            . ' SET `superseded_at` = ?, `superseded_by` = ?, `superseded_reason` = ?'
            . ' WHERE `document_id` = ? AND `superseded_at` IS NULL',
            [
                $this->clock->now()->format('Y-m-d H:i:s.v'),
                $actorId,
                $reason,
                $documentId,
            ]
        );

        return $affected > 0;
    }
}
