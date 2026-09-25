# Contoh konversi dokumen

Satu dokumen kertas, dibedah sampai selesai jadi template ezdoc. Isinya berkas —
tidak ada baris database, dan contohnya tidak terdaftar sebagai template.

Halamannya: **`?ezdoc_page=contoh`** (menu "Example", untuk yang boleh mengelola
template). Halaman itu merender contoh ini dengan perender yang sama dengan yang
mencetak dokumen sungguhan, jadi contoh yang rusak langsung terlihat di sana.

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
blok bersyarat, dua tanda tangan (satu dengan OTP), materai, dan QR verifikasi.

## Cara memakainya

1. Buka **Templates** → buat template baru.
2. Di toolbar editor, pindah ke tampilan kode (Source code).
3. Tempel isi `template.html` apa adanya.
4. Atur halaman: A4, tegak, tepi 18 mm, mode continuous.
5. Unggah dua logo dengan nama persis: `kop` dan `stempel`.
6. Buat kueri tabel bernama `tindakan`, isi SQL-nya dari `contoh.json`, lalu
   sesuaikan nama tabel dan kolomnya dengan database Anda.

## Kalau contoh ini diubah

`tests/UI/ContohKonversiTest.php` menjaga tiga hal sekaligus: templatenya
benar-benar dirender tanpa menyisakan penanda, setiap fitur yang dijanjikan peta
memang ada, dan setiap field yang dipakai punya nilai contoh. Jalankan:

```
vendor/bin/phpunit --filter ContohKonversiTest
```
