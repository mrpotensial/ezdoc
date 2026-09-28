# Contoh template

Template yang hidup sebagai **berkas**, bukan baris database. Bisa dibuka,
diisi, ditandatangani, dan diubah di designer tanpa satu baris pun masuk
database — lalu disimpan jadi template sungguhan kalau memang cocok.

Tiga pintu untuk tiap contoh:

- **`?ezdoc_page=contoh&contoh=<nama-folder>`** — halaman penjelasan (menu
  "Example", untuk yang boleh mengelola template). Ia merender contohnya dengan
  perender yang sama dengan yang mencetak dokumen sungguhan, jadi contoh yang
  rusak langsung terlihat.
- **`?ezdoc_page=generate&template_id=contoh:<nama-folder>`** — dibuka di
  halaman pembuatan dokumen dan **boleh dicoba**: isian hidup, tanda tangan
  bisa digambar, tombol Simpan bisa ditekan. Simpannya dibatalkan lewat kait
  `ezcetak:before-save` lalu ditulis ke `localStorage` peramban. Database tidak
  disentuh, OTP tidak dikirim, dan tidak ada dokumen yang dibuat.
- **`?ezdoc_page=designer&action=edit&id=contoh:<nama-folder>`** — dibuka di
  designer. Simpannya juga mendarat di peramban saja.

## Yang ada sekarang

| Folder | Isi | Fitur yang ditonjolkan |
| --- | --- | --- |
| `surat-keterangan-tindakan/` | Surat keterangan tindakan | Tabel dari kueri (rumus per baris + penjumlahan), logo mengambang di belakang teks, QR verifikasi, tiga tanda tangan dengan aturan berbeda, blok bersyarat |
| `panggul-dalam/` | Pemeriksaan panggul dalam (pelvimetri klinis) | 21 field pilihan, field angka berbatas, field hitung (konjugata vera = CD &minus; 1,5), blok bersyarat atas hasil hitungan |
| `klasifikasi-robson/` | Klasifikasi Robson (WHO) | Field pilihan, 16 field centang dalam tabel, blok bersyarat atas field centang |
| `skor-flamm-geiger/` | Skor Flamm &amp; Geiger | Field hitung menjumlahkan lima parameter, field angka dengan batas berbeda-beda, field centang syarat |
| `skor-bishop/` | Lembar Skor Bishop | Field hitung, field angka berbatas, blok bersyarat |

Empat yang terakhir satu rangkaian: bentuk kertasnya berasal dari satu berkas
cetak obstetri yang dipecah jadi empat template terpisah, karena keempatnya
diisi di saat yang berbeda oleh orang yang berbeda.

## Isi folder

```
<nama-folder>/
  template.html   isi editor — inilah yang jadi isi template
  contoh.json     config halaman, kueri tabel, nilai contoh, dan peta fitur
  asal.jpg        (opsional) dokumen kertasnya, untuk dibandingkan
```

`contoh.json` berisi:

| kunci | isi |
| --- | --- |
| `nama_template` | nama yang dipakai; akhiran "(contoh)" dibuang saat disimpan |
| `config_header` | ukuran kertas, tepi, logo (data URI), dan `tableDbQueries` |
| `verify_config` | field yang tampil di halaman verifikasi |
| `nilai_contoh` | nilai field untuk merender pratinjau |
| `peta` | tiap bagian kertas → fitur ezdoc, markup-nya, dan tempat simpannya |

`peta` adalah sumber tunggal penjelasan: halaman contoh membacanya, termasuk
titik nomor yang ditempel di atas gambar (`pin.x` / `pin.y` dalam persen) kalau
folder itu punya `asal.jpg`.

**Materai sengaja TIDAK dipakai** di contoh mana pun: fiturnya belum selesai.
Contoh itu disalin orang, dan yang menyalin tidak punya cara tahu mana yang
belum jadi.

## Cara memakainya

**Satu tombol: "Simpan jadi template".** Ada di tiga tempat contohnya bisa
dilihat — halaman Contoh (dengan kotak nama), pita kuning di halaman Buat
dokumen, dan pita kuning di designer. Sekali tekan, contohnya jadi template
sungguhan: isi, pengaturan kertas, logo, kueri tabel, tanda tangan, dan
pengaturan halaman verifikasi ikut semua.

Yang perlu diperiksa sesudahnya:

1. **Kop** — contoh memakai kop karangan ("Rumah Sakit Contoh Sejahtera").
   Ganti logo dan dua baris teks di bagian atas template dengan milik Anda.
2. **Kueri tabel** — nama tabel dan kolom di SQL-nya masih milik contoh.
3. **`data-allowed-roles`** pada tanda tangan — sesuaikan dengan peran di
   aplikasi Anda.

Yang dijaga tombol itu: ia hanya **menambah**, tidak pernah mengubah template
yang sudah ada, dan nama yang sudah dipakai ditolak (bukan ditimpa, bukan
digandakan diam-diam).

Di designer, yang tersimpan adalah **yang sedang di layar** — jadi rancangannya
boleh dirapikan dulu di sana sebelum ditekan.

## Membuat contoh baru

Cukup buat folder berisi `template.html` dan `contoh.json`; pemuatnya menemukan
sendiri, dan uji di bawah langsung berlaku untuk folder baru itu. Nama folder
hanya boleh huruf kecil, angka, dan tanda hubung.

## Kalau contoh ini diubah

- `tests/UI/SemuaContohTest.php` berlaku untuk **setiap** folder: templatenya
  benar-benar dirender tanpa menyisakan penanda, tiap field punya nilai contoh
  (kecuali field hitung, yang nilainya datang dari rumus), rumus hanya menyebut
  field yang ada, tipe fieldnya dikenali perender, field pilihan punya
  pilihannya dan nilainya benar-benar terpilih, petanya lengkap.
- `tests/UI/ContohKonversiTest.php` menjaga yang khas surat keterangan
  tindakan (tabel kueri, letak cap, isi halamannya).
- `tests/UI/SimpanContohJadiTemplateTest.php` menjaga tombol simpannya.

```
vendor/bin/phpunit --filter Contoh
```
