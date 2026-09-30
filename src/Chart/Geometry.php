<?php

declare(strict_types=1);

namespace Ezdoc\Chart;

/**
 * Jarak tetap di sekeliling papan grafik, dalam satuan gambar.
 *
 * Dikumpulkan di satu tempat karena dipakai tiga pihak yang harus sepakat:
 * {@see ChartSpec} saat menghitung tinggi papan, {@see SvgChartRenderer} saat
 * menggambar, dan {@see Scale} saat mengubah klik di layar menjadi angka.
 * Kalau ketiganya memakai angkanya sendiri-sendiri, titik yang diklik orang
 * mendarat di tempat lain — dan selisih beberapa piksel itu tidak pernah
 * terlihat sebagai bug, cuma sebagai "grafiknya agak meleset".
 *
 * PHP 7.4+ compatible.
 */
final class Geometry
{
    /** Ruang kiri untuk angka dan judul sumbu Y yang berdiri. */
    public const LEFT = 64.0;

    /** Ruang atas sebelum panel pertama. */
    public const TOP = 16.0;

    /** Ruang kanan, supaya titik di jam terakhir tidak terpotong. */
    public const RIGHT = 12.0;

    /** Ruang bawah untuk label jam. */
    public const BOTTOM = 28.0;

    /**
     * Ruang bawah kalau tidak ada baris jam di dasar papan.
     *
     * Barisnya bisa dipindah ke bawah panel mana pun (lembar partograf lama
     * menaruhnya tepat di bawah pembukaan serviks). Kalau dasarnya jadi
     * kosong, 28 satuan itu cuma ruang putih yang ikut tercetak.
     */
    public const BOTTOM_BARE = 8.0;

    /** Tinggi satu baris jam. */
    public const HOUR_ROW = 14.0;

    /** Jarak antar panel yang bertumpuk. */
    public const PANEL_GAP = 14.0;

    private function __construct()
    {
    }
}
