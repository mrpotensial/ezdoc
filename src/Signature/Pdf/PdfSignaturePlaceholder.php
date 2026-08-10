<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;

/**
 * PDF yang sudah disiapkan untuk ditandatangani, tapi tanda tangannya belum ada.
 *
 * Ini bagian yang membuat penandatanganan PDF harus dua langkah, dan kenapa
 * `PdfSigner::embedSignature()` tidak bisa dipakai untuk tanda tangan terpisah
 * (*detached*): tanda tangannya menutupi byte PDF-nya sendiri, jadi byte itu
 * harus **sudah jadi** sebelum ada yang bisa menandatanganinya.
 *
 * Urutannya:
 *
 *   1. {@see IncrementalPdfSigner::prepare()} menyusun PDF lengkap dengan lubang
 *      kosong seukuran tanda tangan, lalu menulis `/ByteRange` yang menunjuk
 *      seluruh berkas **kecuali** lubang itu
 *   2. {@see digest()} menghitung ringkasan byte yang dicakup — inilah yang
 *      dikirim ke pemilik kunci: `LocalPkiProvider`, HSM, atau API PSrE
 *   3. {@see inject()} menaruh CMS yang kembali ke dalam lubangnya
 *
 * Langkah 3 **tidak boleh** mengubah satu pun byte di luar lubang. Kalau ia
 * mengubah, ringkasan yang ditandatangani tidak lagi cocok dengan berkasnya, dan
 * setiap pembaca PDF akan melaporkan dokumen sudah diubah. Karena itu lubangnya
 * berukuran tetap dan sisa ruangnya diisi nol — bukan dipotong sesuai panjang
 * tanda tangan yang sebenarnya.
 *
 * Bentuk dua langkah ini juga yang dituntut PSrE nanti: yang dikirim ke mereka
 * ringkasannya, bukan dokumennya.
 *
 * PHP 7.4+ compatible.
 */
final class PdfSignaturePlaceholder
{
    /** @var string PDF lengkap; lubang /Contents masih berisi nol */
    private $pdf;

    /** @var PdfBytesRange */
    private $range;

    public function __construct(string $pdf, PdfBytesRange $range)
    {
        $this->pdf = $pdf;
        $this->range = $range;
    }

    /**
     * PDF apa adanya, lubangnya masih kosong.
     *
     * Berguna untuk menyimpan sementara saat menunggu jawaban penyedia luar yang
     * lambat — bentuknya sudah final kecuali isi lubangnya.
     */
    public function pdf(): string
    {
        return $this->pdf;
    }

    public function byteRange(): PdfBytesRange
    {
        return $this->range;
    }

    /** Byte yang benar-benar dicakup tanda tangan: seluruh berkas kecuali lubangnya. */
    public function signedBytes(): string
    {
        return $this->range->computeHashedContent($this->pdf);
    }

    /**
     * Ringkasan byte yang dicakup, biner mentah.
     *
     * Inilah yang dikirim ke pemilik kunci. Bukan PDF-nya — sebagian besar HSM
     * dan API PSrE memang hanya menerima ringkasan, dan mengirim seluruh
     * dokumen ke pihak luar berarti mengirim isi medisnya juga.
     */
    public function digest(string $algo = 'sha256'): string
    {
        return PdfBytesRange::computeHash($this->pdf, $this->range, $algo);
    }

    /** Berapa byte CMS yang muat di lubangnya. */
    public function capacity(): int
    {
        // Lubang = '<' + hex + '>'. Tiap byte jadi dua huruf hex.
        return intdiv($this->range->getSignatureLength() - 2, 2);
    }

    /**
     * Taruh CMS yang sudah ditandatangani ke dalam lubangnya.
     *
     * Sisa ruangnya diisi nol, bukan dipotong. Memotongnya akan menggeser semua
     * byte sesudah lubang, dan seluruh tanda tangan langsung tidak sah.
     *
     * @param string $pkcs7Der CMS SignedData bentuk DER
     * @throws EzdocException kalau CMS-nya tidak muat
     */
    public function inject(string $pkcs7Der): string
    {
        if ($pkcs7Der === '') {
            throw new EzdocException('Tidak ada CMS untuk dimasukkan ke PDF.');
        }

        $capacity = $this->capacity();
        if (strlen($pkcs7Der) > $capacity) {
            // Menaikkan reserved_size dan menyiapkan ulang adalah jawabannya —
            // bukan memotong CMS-nya, yang cuma menghasilkan tanda tangan rusak
            // yang tampak seperti tanda tangan.
            throw new EzdocException(sprintf(
                'CMS %d byte tidak muat di lubang %d byte. Siapkan ulang dengan '
                . 'reserved_size lebih besar — sertifikat berantai panjang dan '
                . 'stempel waktu memang menambah ukurannya.',
                strlen($pkcs7Der),
                $capacity
            ));
        }

        $hex = bin2hex($pkcs7Der);
        $hex .= str_repeat('0', $this->range->getSignatureLength() - 2 - strlen($hex));

        $out = substr_replace(
            $this->pdf,
            '<' . $hex . '>',
            $this->range->getSignatureOffset(),
            $this->range->getSignatureLength()
        );

        // Penjaga terakhir. Kalau panjangnya bergeser satu byte saja, seluruh
        // offset sesudah lubang ikut bergeser dan tanda tangannya tidak akan
        // pernah cocok — kegagalan yang di luar sini muncul sebagai "dokumen
        // sudah diubah", pesan yang menuduh orang yang tidak bersalah.
        if (strlen($out) !== strlen($this->pdf)) {
            throw new EzdocException(
                'Memasukkan tanda tangan mengubah panjang berkas. Ini bug, bukan masukan yang salah.'
            );
        }

        return $out;
    }
}
