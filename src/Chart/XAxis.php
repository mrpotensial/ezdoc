<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

/**
 * Sumbu mendatar papan grafik — waktu, angka, atau kategori.
 *
 * ## Kenapa ini ada
 *
 * Sampai 30 Sep 2026 sumbu X papan ini **selalu** waktu, dan anggapan itu
 * tertanam di enam tempat: `Scale` membagi selisih detik dengan 3600,
 * `SeriesValues` memanggil `normaliseTime()` langsung, perender memformat
 * `H:i`, dan lapisan pegangan di peramban memakai `3600000` di lima baris.
 *
 * Selama anggapan itu tertanam di penyimpanan nilai, grafik sebar dan grafik
 * berkategori bukan penambahan melainkan pembongkaran — dan yang dibongkar
 * adalah bentuk nilai yang **ikut di-hash tanda tangan**. Antarmuka ini
 * memindahkan anggapan itu ke satu tempat, supaya yang berikutnya masuk
 * sebagai pemakai, bukan sebagai pembongkaran kedua.
 *
 * Lihat `docs/260930-grafik-jenis-baru.md` §5.
 *
 * ## Dua sifat yang wajib dipenuhi tiap pelaksana
 *
 * 1. **`normalise()` deterministik dan idempoten.** Nilainya masuk dokumen
 *    yang ditandatangani. `normalise(normalise($x))` harus sama dengan
 *    `normalise($x)`, selamanya, di server mana pun.
 * 2. **`column()` monoton.** Kalau `compare($a, $b) < 0` maka
 *    `column($a) <= column($b)`. Perender menggambar garis penghubung
 *    menurut urutan tersimpan; sumbu yang tidak monoton menghasilkan garis
 *    yang berbalik arah tanpa ada yang salah mengisi.
 *
 * PHP 7.4+ compatible.
 */
interface XAxis
{
    /** Penanda jenis, ikut dikirim ke peramban: 'time', 'value', 'category'. */
    public function kind(): string;

    /**
     * Bentuk baku sebuah nilai X — atau null kalau tidak terbaca.
     *
     * Yang dikembalikan inilah yang tersimpan di dokumen, bukan yang diketik.
     */
    public function normalise(string $raw): ?string;

    /** Urutan dua nilai yang sudah baku, seperti `strcmp`. */
    public function compare(string $a, string $b): int;

    /**
     * Jarak sebuah nilai dari awal sumbu, dalam satuan kolom.
     *
     * Null kalau sumbunya belum punya awal, atau nilainya tidak terbaca.
     * Pecahan diperbolehkan: pengukuran pukul 08.30 pada sumbu jam ada di
     * kolom 0,5.
     */
    public function column(string $x): ?float;

    /** Apakah sumbunya sudah punya titik awal. */
    public function hasOrigin(): bool;

    /**
     * Salinan sumbu ini dengan awal yang lain.
     *
     * Salinan, bukan perubahan di tempat: satu spesifikasi bisa digambar
     * berkali-kali dengan awal berbeda dalam satu permintaan, dan sumbu yang
     * berubah diam-diam membuat gambar kedua memakai awal gambar pertama.
     */
    public function withOrigin(?string $origin): self;

    /** Awal sumbu dalam bentuk baku, atau null. */
    public function origin(): ?string;

    /** Tulisan di bawah kolom ke-n. Kosong kalau sumbunya belum punya awal. */
    public function label(int $column): string;

    /**
     * Bekal untuk lapisan pegangan di peramban.
     *
     * @return array<string,mixed>
     */
    public function export(): array;
}
