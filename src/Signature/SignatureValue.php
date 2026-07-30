<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

/**
 * Nilai satu slot tanda tangan di dokumen — apa artinya, dan apa bukan.
 *
 * Sebuah slot dulunya cuma punya dua keadaan: kosong, atau data URI gambar.
 * Itu cukup selama satu-satunya cara menandatangani adalah menggambar. Mode QR
 * merusak asumsi itu: tidak ada gambar yang dihasilkan, jadi slot-nya tetap
 * kosong, jadi tidak ada record tanda tangan yang tertulis — sementara QR yang
 * tercetak menunjuk record itu. Yang memindai akan mendarat pada tanda tangan
 * yang tidak ada.
 *
 * Kelas ini memberi mode QR nilai tersendiri: penanda tindakan, bukan gambar.
 * Pemisahan gambar dari tanda tangan itu sendiri bukan penemuan baru — PAdES dan
 * dictionary tanda tangan PDF sudah membedakan *appearance stream* dari nilai
 * tanda tangannya, dan tanda tangan tak terlihat (invisible signature) sah tanpa
 * appearance sama sekali. Yang mengikat adalah recordnya; gambar hanyalah
 * tampilan.
 *
 * Nilainya diberi versi (`qr:v1`) supaya bentuk penanda bisa berubah nanti tanpa
 * membuat dokumen lama jadi ambigu.
 */
final class SignatureValue
{
    /**
     * Penanda "slot ini ditandatangani lewat mode QR".
     *
     * Sengaja pendek dan konstan. Isi QR-nya TIDAK disimpan di sini: URL itu
     * turunan dari slug + ttd_id, dihitung ulang tiap render, dan menyimpannya
     * akan membuat kunci HMAC yang dirotasi atau base URL yang berubah
     * membekukan URL basi ke dalam dokumen.
     */
    public const QR_ACT = 'qr:v1';

    /** Prefix data URI — satu-satunya bentuk nilai gambar yang pernah ditulis. */
    private const IMAGE_PREFIX = 'data:';

    /**
     * Nilainya sebuah gambar tanda tangan yang bisa dirender?
     *
     * Dipakai jalur render sebelum mengeluarkan `<img src=...>`. Tanpa
     * pemeriksaan ini, penanda mode QR akan berakhir sebagai atribut src dan
     * memunculkan ikon gambar rusak di dokumen.
     */
    public static function isImage(string $value): bool
    {
        return strncmp($value, self::IMAGE_PREFIX, strlen(self::IMAGE_PREFIX)) === 0;
    }

    /** Nilainya penanda tindakan mode QR? */
    public static function isQrAct(string $value): bool
    {
        return $value === self::QR_ACT;
    }

    /**
     * Slot ini sudah ditandatangani?
     *
     * Sengaja lebih luas dari `isImage() || isQrAct()`: nilai apa pun yang tidak
     * kosong berarti seseorang mengisi slot ini. Dokumen lama mungkin menyimpan
     * bentuk yang tidak lagi dikenali kelas ini, dan menyebutnya "belum
     * ditandatangani" akan menghapus tanda tangan yang sungguhan terjadi.
     */
    public static function isSigned(string $value): bool
    {
        return trim($value) !== '';
    }
}
