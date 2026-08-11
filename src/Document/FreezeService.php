<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Db\Connection;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Storage\BlobStore;
use Ezdoc\Storage\BlobStoreFactory;
use Ezdoc\Support\Clock;

/**
 * Membekukan sebuah dokumen jadi artefak, dan mencabutnya kembali.
 *
 * Lihat docs/260805-psre-meterai.md §7b (A2).
 *
 * ## Apa yang sebenarnya diperbaiki
 *
 * Tanda tangan data kita mencakup **fakta** — field mana yang ditanggung siapa.
 * Ia tetap terverifikasi walau templatenya berubah, dan memang begitu
 * seharusnya. Tapi yang **dibaca** orang lahir dari template, jadi mengubah
 * template mengubah tampilan dokumen yang dulu disetujui, tanpa satu pun tanda
 * tangan menjadi tidak sah.
 *
 * Membekukan menutup selisih itu: render sekali, simpan byte-nya, dan sejak itu
 * yang ditampilkan adalah byte tersebut — bukan hasil render ulang.
 *
 * ## Kenapa tidak ada kolom hash di mana pun
 *
 * `BlobStore` dialamatkan isinya: kuncinya SHA-256 byte-nya, dan `get()` wajib
 * menolak byte yang tidak cocok dengan kuncinya. Jadi "simpan hash lalu
 * bandingkan saat dibaca" sudah terjadi satu lapis di bawah sini, dan menyalin
 * hashnya ke kolom sendiri cuma melahirkan kebenaran kedua yang bisa berselisih.
 *
 * ## Yang TIDAK dilakukan kelas ini
 *
 * Ia tidak merender. Byte PDF-nya diterima dari pemanggil, karena backend render
 * adalah pilihan aplikasi pemakai ({@see \Ezdoc\Rendering\PdfRenderer}) dan
 * membekukan tidak boleh ikut memaksakannya.
 *
 * ## Penyegelan
 *
 * Kalau {@see ArtifactSigner} dipasang, setiap pembekuan melewatinya — tepat di
 * antara menerima byte dan menyimpannya. Letaknya di sini, bukan diserahkan ke
 * pemanggil, karena setelah tersimpan byte-nya sudah menjadi identitas
 * artefaknya: menyegel belakangan berarti menyegel dokumen yang berbeda.
 *
 * Tanpa penyegel, artefaknya tetap punya sidik jari isi lewat penyimpannya —
 * cukup untuk membuktikan berkasnya tidak berubah **selama masih di dalam
 * sistem**. Yang ditambahkan segel adalah kemampuan membuktikannya setelah
 * berkasnya keluar.
 *
 * PHP 7.4+ compatible.
 */
final class FreezeService
{
    /** @var Connection */
    private $db;

    /** @var ArtifactRepository */
    private $artifacts;

    /** @var BlobStore */
    private $blobs;

    /** @var ArtifactSigner|null */
    private $signer;

    /**
     * Alasan pencabutan sependek ini tidak menjelaskan apa pun.
     *
     * Angkanya sengaja kecil: penjaga ini menangkap "ok" dan ".", bukan menilai
     * mutu tulisan. Batas yang terlalu tinggi cuma melahirkan "aaaaaaaaaa".
     */
    public const MIN_REASON_LENGTH = 10;

    public function __construct(
        Connection $db,
        ?ArtifactRepository $artifacts = null,
        ?BlobStore $blobs = null,
        ?Clock $clock = null,
        ?ArtifactSigner $signer = null
    ) {
        $this->db = $db;
        $this->signer = $signer;
        $this->artifacts = $artifacts ?? new ArtifactRepository($db, $clock);
        // forArtifacts(), bukan make(): bawaan umum disetel untuk gambar tanda
        // tangan ~3,5 KB, sementara yang disimpan di sini dokumen utuh yang
        // ratusan KB sampai beberapa MB. Sisi lain titik silang yang sama.
        $this->blobs = $blobs ?? BlobStoreFactory::forArtifacts($db);
    }

    /** Dokumen beku kalau ia punya artefak yang belum digantikan. */
    public function isFrozen(int $documentId): bool
    {
        return $this->artifacts->activeFor($documentId) !== null;
    }

    public function active(int $documentId): ?DocumentArtifact
    {
        return $this->artifacts->activeFor($documentId);
    }

    /**
     * Bekukan rendering sebuah dokumen.
     *
     * @param string              $bytes       Byte hasil render — biasanya PDF.
     * @param string              $actorId     Identitas buram yang membekukan.
     * @param string              $contentType Tipe MIME byte-nya.
     * @param array<string,mixed> $context     Diteruskan ke penyegel: `name`,
     *                                         `reason`, `location`.
     *
     * @throws ValidationException kalau byte-nya kosong, atau dokumennya sudah beku
     * @throws NotFoundException   kalau dokumennya tidak ada
     */
    public function freeze(
        int $documentId,
        string $bytes,
        string $actorId,
        string $contentType = 'application/pdf',
        array $context = []
    ): DocumentArtifact {
        if ($bytes === '') {
            throw new ValidationException('Tidak ada byte untuk dibekukan.');
        }

        $exists = $this->db->fetchOne(
            'SELECT `id`, `uuid` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
            [$documentId]
        );
        if ($exists === null) {
            throw new NotFoundException('Dokumen tidak ditemukan: ' . $documentId);
        }

        // Diperiksa di sini supaya pesannya bisa menjelaskan; kunci unik
        // (document_id, cycle) yang jadi penjaga sebenarnya kalau dua permintaan
        // datang bersamaan.
        if ($this->artifacts->activeFor($documentId) !== null) {
            throw new ValidationException(
                'Dokumen sudah beku. Cabut pembekuannya dulu kalau memang perlu diubah.'
            );
        }

        $manifest = $this->buildManifest($documentId, (string) ($exists['uuid'] ?? ''));

        // Disegel SEBELUM disimpan. Kuncinya adalah sidik jari isi, jadi
        // menyegel setelah menyimpan akan menghasilkan byte lain — artefak yang
        // tersimpan dan artefak yang tersegel jadi dua benda berbeda.
        //
        // Gagal di sini menggagalkan seluruh pembekuan. Dokumen yang seharusnya
        // tersegel tapi diam-diam tidak baru ketahuan saat ada yang menyanggah,
        // dan saat itu sudah terlambat.
        if ($this->signer !== null) {
            $bytes = $this->signer->sign($bytes, $context + [
                'document_uuid' => (string) ($exists['uuid'] ?? ''),
            ]);

            $manifest['seal'] = [
                'provider' => $this->signer->name(),
                'format'   => 'pades-b-b',
            ];
        }

        // Byte-nya disimpan lebih dulu. Kalau langkah ini gagal, tidak ada baris
        // artefak yang menunjuk isi yang tidak pernah ada — dan menyimpan byte
        // yang tidak jadi dirujuk siapa pun tidak merusak apa-apa, karena
        // kuncinya isi: pembekuan berikutnya dengan byte sama memakai baris itu.
        $blob = $this->blobs->put($bytes, $contentType);

        return $this->artifacts->insert(
            $documentId,
            $this->artifacts->nextCycle($documentId),
            $blob->key(),
            $manifest,
            $actorId
        );
    }

    /**
     * Cabut pembekuan supaya dokumennya bisa disunting lagi.
     *
     * Byte-nya tidak dihapus dan artefaknya tidak dibuang — ia cuma ditandai
     * sudah digantikan. Orang yang memegang cetakan siklus lama harus tetap bisa
     * memeriksanya, justru karena ia sudah tidak berlaku.
     *
     * @throws ValidationException kalau alasannya kosong atau terlalu pendek
     */
    public function unfreeze(int $documentId, string $reason, string $actorId): bool
    {
        $reason = trim($reason);

        // Alasannya wajib. Pencabutan tanpa alasan membuat catatan siklus
        // menjawab "kapan" tapi tidak "kenapa" — dan pertanyaan yang diajukan
        // setahun kemudian selalu yang kedua.
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new ValidationException(
                'Alasan pencabutan minimal ' . self::MIN_REASON_LENGTH . ' karakter.'
            );
        }

        return $this->artifacts->supersede($documentId, $reason, $actorId);
    }

    /**
     * Byte artefak, atau null kalau isinya tidak bisa diambil.
     *
     * Null di sini berarti sesuatu yang serius: kuncinya ada di baris artefak
     * tapi isinya hilang atau tidak lagi cocok dengan kuncinya. Pemanggil harus
     * memperlakukannya sebagai peringatan integritas, bukan sekadar cache miss.
     */
    public function bytes(DocumentArtifact $artifact): ?string
    {
        return $this->blobs->get($artifact->getBlobKey());
    }

    /**
     * Tanda tangan mana yang ikut dibekukan artefak ini.
     *
     * Inilah yang mengikat dua pertanyaan jadi satu pemeriksaan: byte artefak
     * menjawab "apakah dokumen ini utuh", manifesnya menjawab "siapa menyatakan
     * apa di dalamnya". Tanpa manifes, keduanya harus ditanyakan terpisah dan
     * tidak ada yang membuktikan keduanya bicara tentang dokumen yang sama.
     *
     * `content_hash` ikut disalin, bukan cuma uuid-nya. Rujukan yang menyebut
     * baris bisa menunjuk isi lain besok; rujukan yang menyebut isi tidak bisa.
     *
     * @return array<string,mixed>
     */
    private function buildManifest(int $documentId, string $documentUuid): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `uuid`, `signer_id`, `signer_role`, `provider`, `level`,'
            . ' `content_hash`, `signed_at`, `metadata`'
            . ' FROM `ezdoc_signatures`'
            . ' WHERE `document_id` = ? AND `deleted_at` IS NULL'
            . ' ORDER BY `signature_id_within_doc`',
            [$documentId]
        );

        $signatures = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];

            $signatures[] = [
                'uuid'         => (string) ($row['uuid'] ?? ''),
                'ttd_id'       => isset($meta['ttd_id']) ? (string) $meta['ttd_id'] : '',
                'signer_id'    => (string) ($row['signer_id'] ?? ''),
                'signer_name'  => isset($meta['signer_name']) ? (string) $meta['signer_name'] : null,
                'signer_role'  => $row['signer_role'] !== null ? (string) $row['signer_role'] : null,
                'provider'     => (string) ($row['provider'] ?? ''),
                'level'        => (int) ($row['level'] ?? 0),
                'content_hash' => (string) ($row['content_hash'] ?? ''),
                'signed_at'    => (string) ($row['signed_at'] ?? ''),
            ];
        }

        return [
            'document_uuid' => $documentUuid,
            'signatures'    => $signatures,
        ];
    }
}
