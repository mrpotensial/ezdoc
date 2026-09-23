# Gambar tanda tangan ke berkas, tanpa mengubah tabel

Diminta 22 Sep 2026: "aku perlu fitur untuk migrasikan ttd ke file juga, tapi
tanpa merusak tabel, karena v1 masih pakai tabel".

## Keadaan sebelum ini

| | Jumlah |
|---|---|
| Gambar tanda tangan di kolom `ezdoc_blobs.content` | 2.847 (21,5 MB), checksum semua cocok |
| Dokumen yang merujuknya (`blob:sha256-…`) | 1.043 dari 1.174 |
| Gambar yang masih inline di `signature_values` | 38, di 24 dokumen |
| PDF beku di disk | 2 |

v1 membaca gambar dari kolom `content`. Menyalakan backend `disk` begitu saja
merusak dua arah: v2 tidak bisa membaca 2.847 gambar yang hanya ada di kolom,
dan v1 tidak bisa membaca tanda tangan baru dari v2 (kolomnya NULL).

## Caranya: expand → migrate → contract

Pola migrasi data daring (Stripe: dual write → backfill → baca dari yang baru →
berhenti menulis yang lama). Rails ActiveStorage menyediakan `MirrorService`
untuk tahap yang sama.

1. **expand — mode `mirror`** (`MirrorBlobStore`). Setiap tanda tangan baru
   ditulis ke kolom *dan* ke berkas. Barisnya persis seperti sebelumnya
   (`backend = 'database'`, `content` terisi), jadi v1 tidak melihat bedanya.
   Membaca: berkas dulu, lalu kolom; yang hanya ada di kolom langsung disalin
   ke berkas saat dibaca.
2. **migrate — `cli/mirror-blobs-to-disk.php`**. Menyalin semua isi kolom ke
   berkas. **Tidak menulis ke basis data sama sekali.** Diperiksa checksum-nya
   sebelum disalin, dan dibaca ulang sesudahnya.
3. **contract — nanti, setelah v1 berhenti.** Ganti ke `disk`, lalu kosongkan
   kolom `content`. Belum dibuat, sengaja.

Gambar inline (38) tidak ikut: mereka bukan objek, dan mengubahnya mengubah
nilai yang ditandatangani (lihat `cli/migrate-signature-images.php`).

## Setelan

Hanya di `ezdoc_v2/public/config.php` — **jangan** di `koneksi.php` atau
`ezdoc_otp_setup.php`. v1 tidak mengenal nilai `mirror`.

```php
define('EZDOC_STORAGE_BACKEND', 'mirror');
define('EZDOC_STORAGE_PATH', dirname(__DIR__, 2) . '/storage'); // folder yang sama dengan PDF beku
```

## Menjalankan penyalin di server

Jalankan sebagai pengguna PHP-FPM, supaya berkasnya milik proses yang akan
membacanya. Cek namanya: `ps -o user= -C php-fpm | sort -u` (biasanya `apache`).

```bash
P=/var/www/html/production/webapps/application/SIMpel/pengeluaran   # sesuaikan

# 1. lihat dulu — tidak menulis apa pun
sudo -u apache env EZDOC_BOOTSTRAP=$P/koneksi.php EZDOC_STORAGE_PATH=$P/storage \
  php $P/ezdoc_v2/cli/mirror-blobs-to-disk.php

# 2. kalau angkanya masuk akal
sudo -u apache env EZDOC_BOOTSTRAP=$P/koneksi.php EZDOC_STORAGE_PATH=$P/storage \
  php $P/ezdoc_v2/cli/mirror-blobs-to-disk.php apply
```

Yang benar di akhir: `The database was not modified.` Aman diulang — yang sudah
ada di disk dilewati. Kode keluar 1 berarti ada yang rusak atau gagal.

## Lewat halaman admin

`?ezdoc_page=admin_migrate` (superadmin) punya seksi **Object storage**:

- ringkasan: objek, di kolom, di disk, berkas, gambar inline, yang dirujuk
- **Lihat dulu** dan **Salin tanda tangan ke berkas** — per batch 200 objek,
  peramban memanggil berulang sambil membawa `after_id`, karena satu permintaan
  web punya batas waktu
- **Periksa kesehatan** — byte tiap objek masih cocok kuncinya, dan salinan
  berkasnya ada
- **Objek tak dirujuk** — melihat saja; menghapusnya tetap lewat CLI, karena
  yang hilang adalah byte

Yang dijalankan halaman itu kelas yang sama dengan CLI (`BlobMaintenance`),
jadi tidak ada versi kedua yang bisa berbeda diam-diam.

Seksi **Adapters & permissions** di halaman yang sama menyebut apa yang
terpasang dari aplikasi pemakai (koneksi, perender PDF, perender QR, penyimpan
objek, kunci tanda tangan, penyedia role) dan menandai izin yang perlu
diperhatikan: folder objek yang tidak bisa ditulis, folder objek yang ada di
dalam document root, atau pengguna basis data yang memegang DROP/FILE/SUPER.

Seksi **Config helper** menghasilkan isi `public/config.php` untuk disalin.
Halaman ini tidak pernah menulis berkas PHP.

## Cadangan

Byte-nya kini di dua tempat. Folder `storage/` harus ikut dicadangkan bersama
basis data — ia juga berisi PDF beku, yang memang hanya ada di sana.

## Ditemukan sekalian: purge akan menghapus PDF beku

`cli/purge-orphan-blobs.php` hanya menghitung rujukan dari `signature_values`.
PDF beku dirujuk `ezdoc_document_artifacts.blob_key`, jadi `apply` akan
menghapus semuanya begitu lewat 48 jam. Belum pernah terjadi (tidak terjadwal
di `worker`), dan sudah diperbaiki di v2.

**Jangan jalankan `purge-orphan-blobs.php` dari `ezdoc/` (v1)** — salinan di
sana belum diperbaiki.
