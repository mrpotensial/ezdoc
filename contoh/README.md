# Contoh konversi dokumen

Satu dokumen kertas, dibedah sampai selesai jadi template ezdoc. Isinya berkas —
tidak ada baris database, dan contohnya tidak terdaftar sebagai template.

Dua pintu:

- **`?ezdoc_page=contoh`** — halaman penjelasan (menu "Example", untuk yang boleh
  mengelola template). Ia merender contoh ini dengan perender yang sama dengan
  yang mencetak dokumen sungguhan, jadi contoh yang rusak langsung terlihat.
- **`?ezdoc_page=generate&template_id=contoh`** — contoh yang sama, dibuka di
  halaman pembuatan dokumen, dan **boleh dicoba**: isian hidup, tanda tangan
  bisa digambar, tombol Simpan bisa ditekan. Simpannya dibatalkan lewat kait
  `ezcetak:before-save` lalu ditulis ke `localStorage` peramban. Database tidak
  disentuh, OTP tidak dikirim, dan tidak ada dokumen yang dibuat.

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

**Satu tombol: “Simpan jadi template”.** Ada di tiga tempat contohnya bisa
dilihat — halaman Contoh (dengan kotak nama), pita kuning di halaman Buat
dokumen, dan pita kuning di designer. Sekali tekan, contohnya jadi template
sungguhan: isi, pengaturan kertas, logo, kueri tabel, tanda tangan, dan
pengaturan halaman verifikasi ikut semua.

Yang perlu diperiksa sesudahnya:

1. **Kueri tabel** — nama tabel dan kolom di SQL-nya masih milik contoh ini.
2. **`data-allowed-roles`** pada tanda tangan — sesuaikan dengan peran di
   aplikasi Anda.

Yang dijaga tombol itu: ia hanya **menambah**, tidak pernah mengubah template
yang sudah ada, dan nama yang sudah dipakai ditolak (bukan ditimpa, bukan
digandakan diam-diam).

Di designer, yang tersimpan adalah **yang sedang di layar** — jadi rancangannya
boleh dirapikan dulu di sana sebelum ditekan.

## Kalau contoh ini diubah

Tiga berkas uji menjaganya: `tests/UI/ContohKonversiTest.php` (templatenya
benar-benar dirender tanpa menyisakan penanda, fiturnya ada, tiap field punya
nilai contoh), `tests/Template/ContohLoaderTest.php` (pemuatnya menolak nama
folder yang aneh), dan `tests/UI/ContohDiGenerateTest.php` (mode contoh tidak
bisa menulis apa pun). Jalankan:

```
vendor/bin/phpunit --filter Contoh
```
