# Contoh konversi dokumen

Satu dokumen kertas, dibedah sampai selesai jadi template ezdoc. Isinya berkas —
tidak ada baris database, dan contohnya tidak terdaftar sebagai template.

Dua pintu:

- **`?ezdoc_page=contoh`** — halaman penjelasan (menu "Example", untuk yang boleh
  mengelola template). Ia merender contoh ini dengan perender yang sama dengan
  yang mencetak dokumen sungguhan, jadi contoh yang rusak langsung terlihat.
- **`?ezdoc_page=generate&template_id=contoh`** — contoh yang sama, dibuka di
  halaman pembuatan dokumen. Baca saja: terkunci, tombol Simpan mati, tanda
  tangan tidak ditawarkan, dan tidak ada dokumen yang dibuat.

## Isi folder

```
surat-keterangan-tindakan/
  asal.jpg        dokumen kertasnya (karangan; nama, alamat, angka tidak nyata)
  template.html   isi editor — inilah yang disalin ke designer
  contoh.json     config halaman, kueri tabel, nilai contoh, dan peta fitur
```

`contoh.json` berisi:

| kunci | isi |
| --- | --- |
| `config_header` | ukuran kertas, tepi, logo (data URI), dan `tableDbQueries` |
| `verify_config` | field yang tampil di halaman verifikasi |
| `nilai_contoh` | nilai field untuk merender pratinjau |
| `peta` | tiap bagian kertas → fitur ezdoc, markup-nya, dan tempat simpannya |

`peta` adalah sumber tunggal penjelasan: halaman contoh membacanya, termasuk
titik nomor yang ditempel di atas gambar (`pin.x` / `pin.y` dalam persen).

## Fitur yang dipakai contoh ini

Logo sebaris, logo mengambang di belakang teks, field teks/tanggal/angka/centang,
nilai bawaan tanggal, tabel dari kueri (kolom, rumus per baris, penjumlahan),
blok bersyarat, dan QR verifikasi.

Tanda tangannya tiga, sengaja berbeda aturan — di kertas ketiganya sama saja:

| slot | aturan |
| --- | --- |
| pasien | OTP ke nomor pada field `nomor_hp_pasien`, boleh gambar atau QR |
| perawat | hanya peran `perawat` / `kepala_ruangan` yang boleh menandatangani |
| dokter | mode QR saja (tanpa gambar) + nama bawaan |

**Materai sengaja TIDAK dipakai**: fiturnya belum selesai. Contoh itu disalin
orang, dan yang menyalin tidak punya cara tahu mana yang belum jadi.

## Cara memakainya

1. Buka **Templates** → buat template baru.
2. Di toolbar editor, pindah ke tampilan kode (Source code).
3. Tempel isi `template.html` apa adanya.
4. Atur halaman: A4, tegak, tepi 18 mm, mode continuous.
5. Unggah dua logo dengan nama persis: `kop` dan `stempel`.
6. Buat kueri tabel bernama `tindakan`, isi SQL-nya dari `contoh.json`, lalu
   sesuaikan nama tabel dan kolomnya dengan database Anda.
7. Sesuaikan `data-allowed-roles` pada tanda tangan dengan peran di aplikasi Anda.

## Kalau contoh ini diubah

Tiga berkas uji menjaganya: `tests/UI/ContohKonversiTest.php` (templatenya
benar-benar dirender tanpa menyisakan penanda, fiturnya ada, tiap field punya
nilai contoh), `tests/Template/ContohLoaderTest.php` (pemuatnya menolak nama
folder yang aneh), dan `tests/UI/ContohDiGenerateTest.php` (mode contoh tidak
bisa menulis apa pun). Jalankan:

```
vendor/bin/phpunit --filter Contoh
```
