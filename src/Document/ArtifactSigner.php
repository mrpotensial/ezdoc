<?php

declare(strict_types=1);

namespace Ezdoc\Document;

/**
 * Menyegel byte artefak sebelum ia disimpan.
 *
 * Dipasang ke {@see FreezeService}. Kalau ada, setiap pembekuan melewatinya —
 * jadi tidak ada jalan menyimpan artefak yang lupa disegel. Kalau tidak ada,
 * byte disimpan apa adanya, dan artefaknya tetap punya sidik jari isi lewat
 * penyimpannya.
 *
 * Dipisah jadi antarmuka karena penyegelnya berbeda-beda per pemasangan:
 * sertifikat lokal, HSM, atau API PSrE. Yang tidak berbeda cuma **kapan** ia
 * dipanggil — tepat di antara merender dan menyimpan, karena setelah tersimpan
 * byte-nya sudah jadi identitas artefaknya dan tidak boleh berubah lagi.
 *
 * Gagal harus melempar, bukan mengembalikan byte aslinya. Dokumen yang
 * seharusnya tersegel tapi diam-diam tidak jauh lebih berbahaya daripada
 * pembekuan yang gagal terang-terangan: yang pertama baru ketahuan saat ada yang
 * menyanggah, yang kedua ketahuan detik itu juga.
 *
 * PHP 7.4+ compatible.
 */
interface ArtifactSigner
{
    /**
     * @param string              $pdfBytes Byte hasil render, belum tersegel.
     * @param array<string,mixed> $context  Keterangan untuk dicantumkan di segelnya:
     *                                      `name`, `reason`, `location`, `document_uuid`.
     * @return string Byte yang sudah tersegel. Panjangnya boleh berbeda.
     *
     * @throws \Ezdoc\Exceptions\EzdocException kalau penyegelan gagal
     */
    public function sign(string $pdfBytes, array $context = []): string;

    /**
     * Nama yang dicatat di manifes artefak, mis. `local_pki`.
     *
     * Dipakai supaya setahun kemudian masih bisa dijawab: segel ini dibuat
     * dengan apa, dan karena itu apa yang bisa dibuktikannya.
     */
    public function name(): string;
}
