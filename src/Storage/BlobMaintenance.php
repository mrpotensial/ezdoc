<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Schema\ColumnIntrospector;

/**
 * Pekerjaan pemeliharaan atas objek tersimpan: menyalin, memeriksa, mencari yatim.
 *
 * Satu tempat untuk tiga pemakai — dua CLI dan halaman admin. Sebelumnya tiap
 * pemakai menuliskan sendiri "objek mana yang masih dirujuk", dan yang satu
 * melewatkan arsip PDF beku: `purge` akan menghapus bukti.
 *
 * Semua metode di sini membaca basis data dan, paling jauh, MENULIS BERKAS.
 * Tidak ada satu pun yang mengubah baris — pemasangan lama yang membaca kolom
 * `ezdoc_blobs.content` harus tetap melihat isi yang sama sesudahnya.
 *
 * Dikerjakan per batch, dengan kunci lanjutan (`after_id`), bukan sekaligus:
 * satu permintaan web punya batas waktu, dan 21 MB objek tidak perlu masuk
 * memori bersamaan. Pola yang sama dengan backfill `find_in_batches` Rails.
 */
final class BlobMaintenance
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Kunci objek yang masih dirujuk sesuatu.
     *
     * Dua perujuk, dan keduanya wajib: slot tanda tangan di dokumen, dan arsip
     * tampilan beku. Dokumen terhapus ikut dihitung — penghapusannya bisa
     * dibatalkan, dan membuang gambarnya akan membuat pembatalan itu bohong.
     * Siklus arsip yang sudah digantikan juga: ia jawaban atas "apa yang dilihat
     * penanda tangan pertama".
     *
     * @return array<string,true>
     */
    public function referencedKeys(): array
    {
        $keys = [];

        foreach ($this->db->fetchAll('SELECT signature_values FROM ezdoc_documents WHERE signature_values IS NOT NULL') as $row) {
            $values = json_decode((string) $row['signature_values'], true);
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $key = Blob::keyFromReference($value);
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }

        if ($this->punyaTabel('ezdoc_document_artifacts')) {
            foreach ($this->db->fetchAll('SELECT blob_key FROM ezdoc_document_artifacts') as $row) {
                $key = (string) $row['blob_key'];
                if (preg_match('/^[0-9a-f]{64}$/', $key) === 1) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Objek yang tidak dirujuk siapa pun dan sudah melewati masa tenggang.
     *
     * Tenggangnya bukan kehati-hatian berlebih: gambar disimpan selama
     * penyimpanan yang masih bisa ditolak sesudahnya, dan purge yang berjalan di
     * celah itu akan menghapus objek yang sebentar lagi dirujuk.
     *
     * @return array{objek:list<array{key:string,byte:int,created_at:string}>,byte:int}
     */
    public function orphans(int $graceHours = 48): array
    {
        $referenced = $this->referencedKeys();
        $batas = (new \DateTimeImmutable('-' . max(0, $graceHours) . ' hours'))->format('Y-m-d H:i:s');

        $objek = [];
        $byte = 0;
        foreach ($this->db->fetchAll(
            'SELECT `key`, byte_size, created_at FROM ezdoc_blobs'
            . ' WHERE deleted_at IS NULL AND created_at < ? ORDER BY created_at',
            [$batas]
        ) as $row) {
            $key = (string) $row['key'];
            if (isset($referenced[$key])) {
                continue;
            }
            $objek[] = ['key' => $key, 'byte' => (int) $row['byte_size'], 'created_at' => (string) $row['created_at']];
            $byte += (int) $row['byte_size'];
        }

        return ['objek' => $objek, 'byte' => $byte];
    }

    /**
     * Salin satu batch isi kolom ke berkas. Basis data tidak disentuh.
     *
     * Diperiksa terhadap kuncinya sebelum disalin dan dibaca ulang sesudahnya:
     * kunci adalah hash isinya, jadi baris yang tidak cocok sudah rusak sebelum
     * sampai ke sini, dan menyalinnya hanya memberi objek rusak rumah kedua.
     *
     * Masalah dilaporkan sebagai KODE, bukan kalimat: pemanggilnya yang memilih
     * kata — CLI berbahasa Inggris, halaman admin lewat katalog bahasa.
     *
     * @return array{after_id:int,selesai:bool,disalin:int,sudah:int,rusak:int,gagal:int,byte:int,masalah:list<array{key:string,sebab:string,detail:string}>}
     */
    public function copyToFilesBatch(DiskBlobStore $files, int $afterId = 0, int $limit = 200, bool $apply = true): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, `key`, content FROM ezdoc_blobs'
            . ' WHERE id > ? AND deleted_at IS NULL AND content IS NOT NULL ORDER BY id LIMIT ' . max(1, $limit),
            [$afterId]
        );

        $hasil = [
            'after_id' => $afterId, 'selesai' => $rows === [], 'disalin' => 0,
            'sudah' => 0, 'rusak' => 0, 'gagal' => 0, 'byte' => 0, 'masalah' => [],
        ];

        foreach ($rows as $row) {
            $hasil['after_id'] = (int) $row['id'];
            $key = (string) $row['key'];
            $bytes = (string) $row['content'];

            if (!Blob::verify($key, $bytes)) {
                $hasil['rusak']++;
                $hasil['masalah'][] = ['key' => $key, 'sebab' => 'checksum_kolom', 'detail' => ''];
                continue;
            }

            if ($files->readFile($key) !== null) {
                $hasil['sudah']++;
                continue;
            }

            if (!$apply) {
                $hasil['disalin']++;
                $hasil['byte'] += strlen($bytes);
                continue;
            }

            // writeFile() membaca ulang sebelum kembali, jadi kembalinya sudah
            // berarti "ada di berkas, dan cocok dengan kuncinya".
            try {
                $files->writeFile($bytes);
            } catch (\Throwable $e) {
                $hasil['gagal']++;
                $hasil['masalah'][] = ['key' => $key, 'sebab' => 'tulis_gagal', 'detail' => $e->getMessage()];
                continue;
            }

            $hasil['disalin']++;
            $hasil['byte'] += strlen($bytes);
        }

        return $hasil;
    }

    /**
     * Periksa satu batch objek: byte-nya masih ada, dan masih cocok kuncinya.
     *
     * `$files` opsional. Tanpanya yang diperiksa hanya "objeknya terbaca";
     * dengannya, salinan berkas yang hilang atau rusak ikut dilaporkan walau
     * kolomnya masih utuh — justru itu yang perlu diketahui saat memindahkan
     * byte ke berkas.
     *
     * `$lain` untuk penyimpan KEDUA yang sah: pemasangan boleh menaruh dokumen
     * beku di folder lain daripada gambar tanda tangan, dan objek yang tinggal
     * di sana tidak terbaca store utama. Tanpa ini, tiap pemeriksaan akan
     * melaporkan arsip yang sehat sebagai hilang — peringatan yang salah, dan
     * peringatan yang salah mengajari orang mengabaikan semuanya.
     *
     * @return array{after_id:int,selesai:bool,diperiksa:int,utuh:int,masalah:list<array{key:string,sebab:string}>}
     */
    public function checkBatch(
        BlobStore $store,
        ?DiskBlobStore $files = null,
        int $afterId = 0,
        int $limit = 200,
        ?BlobStore $lain = null
    ): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, `key`, backend, content IS NULL AS tanpa_kolom FROM ezdoc_blobs'
            . ' WHERE id > ? AND deleted_at IS NULL ORDER BY id LIMIT ' . max(1, $limit),
            [$afterId]
        );

        $hasil = ['after_id' => $afterId, 'selesai' => $rows === [], 'diperiksa' => 0, 'utuh' => 0, 'masalah' => []];

        foreach ($rows as $row) {
            $hasil['after_id'] = (int) $row['id'];
            $hasil['diperiksa']++;
            $key = (string) $row['key'];

            // Dua tempat, ditanya terpisah. Store yang sedang dipakai belum
            // tentu yang menyimpan objek ini: baris yang byte-nya hanya di disk
            // (arsip PDF beku) tidak terbaca store berbackend kolom, dan
            // menuduhnya hilang akan membuat pemeriksaan ini berteriak tiap kali
            // dijalankan dari pemasangan yang backend-nya berbeda.
            $dariStore = $store->get($key) !== null;
            $dariBerkas = $files !== null && $files->readFile($key) !== null;
            if (!$dariStore && !$dariBerkas && $lain !== null) {
                $dariStore = $lain->get($key) !== null;
            }

            if (!$dariStore && !$dariBerkas) {
                $hasil['masalah'][] = ['key' => $key, 'sebab' => 'tak_terbaca'];
                continue;
            }

            // Salinan berkasnya: hanya dituntut untuk objek yang byte-nya ada di
            // kolom — itulah yang sedang dipindahkan ke berkas.
            if ($files !== null && !$row['tanpa_kolom'] && !$dariBerkas) {
                $hasil['masalah'][] = ['key' => $key, 'sebab' => 'berkas_hilang'];
                continue;
            }

            $hasil['utuh']++;
        }

        return $hasil;
    }

    /**
     * Ringkasan keadaan penyimpanan, untuk ditampilkan sebelum menjalankan apa pun.
     *
     * @return array{objek:int,byte:int,di_kolom:int,di_disk:int,berkas:int,inline:int,dirujuk:int}
     */
    public function summary(?DiskBlobStore $files = null): array
    {
        $baris = $this->db->fetchOne(
            'SELECT COUNT(*) AS objek, COALESCE(SUM(byte_size), 0) AS byte,'
            . ' SUM(CASE WHEN content IS NULL THEN 0 ELSE 1 END) AS di_kolom'
            . ' FROM ezdoc_blobs WHERE deleted_at IS NULL'
        ) ?: [];

        $objek = (int) ($baris['objek'] ?? 0);
        $diKolom = (int) ($baris['di_kolom'] ?? 0);

        $inline = 0;
        foreach ($this->db->fetchAll(
            "SELECT signature_values FROM ezdoc_documents WHERE signature_values LIKE '%data:image%'"
        ) as $row) {
            foreach ((array) json_decode((string) $row['signature_values'], true) as $value) {
                if (is_string($value) && SignatureImageStore::isDataUri($value)) {
                    $inline++;
                }
            }
        }

        return [
            'objek'    => $objek,
            'byte'     => (int) ($baris['byte'] ?? 0),
            'di_kolom' => $diKolom,
            'di_disk'  => $objek - $diKolom,
            'berkas'   => $files === null ? -1 : $this->hitungBerkas($files->root()),
            'inline'   => $inline,
            'dirujuk'  => count($this->referencedKeys()),
        ];
    }

    /** Berkas objek di bawah root, yang namanya kunci. -1 kalau foldernya tidak ada. */
    private function hitungBerkas(string $root): int
    {
        if (!is_dir($root)) {
            return -1;
        }

        $jumlah = 0;
        foreach ((array) glob($root . '/??', GLOB_ONLYDIR) as $dir) {
            foreach ((array) glob($dir . '/*') as $berkas) {
                if (preg_match('/^[0-9a-f]{64}$/', basename($berkas)) === 1) {
                    $jumlah++;
                }
            }
        }

        return $jumlah;
    }

    private function punyaTabel(string $nama): bool
    {
        try {
            return (new ColumnIntrospector($this->db))->columns($nama) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }
}
