# Contoh template

Template yang hidup sebagai **berkas**, bukan baris database. Bisa dibuka,
diisi, ditandatangani, dan diubah di designer tanpa satu baris pun masuk
database — lalu disimpan jadi template sungguhan kalau memang cocok.

Tiga pintu untuk tiap contoh:

- **`?ezdoc_page=example&example=<nama-folder>`** — halaman penjelasan (menu
  "Example", untuk yang boleh mengelola template). Ia merender contohnya dengan
  perender yang sama dengan yang mencetak dokumen sungguhan, jadi contoh yang
  rusak langsung terlihat.
- **`?ezdoc_page=generate&template_id=example:<nama-folder>`** — dibuka di
  halaman pembuatan dokumen dan **boleh dicoba**: isian hidup, tanda tangan
  bisa digambar, tombol Simpan bisa ditekan. Simpannya dibatalkan lewat kait
  `ezcetak:before-save` lalu ditulis ke `localStorage` peramban. Database tidak
  disentuh, OTP tidak dikirim, dan tidak ada dokumen yang dibuat.
- **`?ezdoc_page=designer&action=edit&id=example:<nama-folder>`** — dibuka di
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

### Yang memakai papan grafik

Satu papan, banyak panel, satu sumbu waktu bersama. Sumbunya bisa jam, hari,
atau bulan; isian papannya ditempel sebagai nilai field biasa, jadi dokumennya
tetap satu baris di database.

| Folder | Isi | Sumbu | Fitur yang ditonjolkan |
| --- | --- | --- | --- |
| `partograf/` | Partograf WHO | jam | 13 panel satu sumbu, garis acuan WASPADA/BERTINDAK dengan nama miring, baris sel, isian batang bertingkat |
| `lembar-pemantauan/` | Lembar pemantauan persalinan | jam | Papan sederhana berdampingan dengan tabel biasa |
| `balans-cairan/` | Balans cairan 24 jam | jam | Batang bertumpuk dua arah (masuk ke atas, keluar ke bawah), kurva kumulatif berarsir |
| `meows/` | Peringatan dini obstetri (MEOWS) | jam | Satu kunci `bands` dibaca dua cara: pita latar di panel garis, dan latar sel di baris skor |
| `observasi-pasca-operasi/` | Observasi pasca-operasi | jam | Timeline obat tiga jalur termasuk rentang tanpa jam selesai, tangga tetes/menit |
| `pemantauan-bayi-baru-lahir/` | Pemantauan bayi baru lahir | jam | Pita suhu normal, baris ikterus berwarna dengan tulisan putih di atas latar gelap |
| `rantai-dingin/` | Kartu suhu lemari vaksin | **hari** | Tiga pita bercelah, pita tanpa ujung atas, dua garis batas |
| `riwayat-perawatan-mesin/` | Riwayat perawatan mesin | **hari** | Timeline keadaan mesin, tangga jam operasi, batang hasil dengan hari kosong |
| `pertumbuhan-anak/` | Kartu pertumbuhan anak 0&ndash;24 bulan | **bulan** | Tiga acuan berkurva (`points`, bukan `from`/`to`) dengan nama mengikuti garisnya |

Tiga yang terakhir sengaja **di luar rumah sakit** — puskesmas, pabrik, dan
posyandu. Papan grafiknya bukan fitur rekam medis; yang membedakannya dari
lembar pemantauan cuma isian dan satuan sumbunya.

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

1. **Kueri tabel** — nama tabel dan kolom di SQL-nya masih milik contoh.
2. **`data-allowed-roles`** pada tanda tangan — sesuaikan dengan peran di
   aplikasi Anda.

Yang dijaga tombol itu: ia hanya **menambah**, tidak pernah mengubah template
yang sudah ada, dan nama yang sudah dipakai ditolak (bukan ditimpa, bukan
digandakan diam-diam).

Di designer, yang tersimpan adalah **yang sedang di layar** — jadi rancangannya
boleh dirapikan dulu di sana sebelum ditekan.

## Kop surat

Berkas contoh di sini memakai kop **karangan** ("Rumah Sakit Contoh
Sejahtera"), supaya pustaka ini tidak memihak satu organisasi pun. Kop
sungguhan datang dari konfigurasi mount aplikasi:

```php
'letterhead.name'    => 'NAMA ORGANISASI ANDA',
'letterhead.address' => ['Baris satu', 'Baris dua'],   // string juga boleh
'letterhead.city'    => 'Kota',                        // di atas tanda tangan
'letterhead.logo'    => __DIR__ . '/logo.png',         // atau data URI
```

**Atau langsung dari halaman contoh.** Di sana ada panel **Kop surat**: nama,
alamat, kota, dan logo bisa diisi dan disimpan tanpa menyentuh berkas PHP —
tiap pemasangan berbeda, dan yang mengaturnya belum tentu punya akses ke
berkasnya. Yang disimpan dari sana menimpa nilai config; tombol "Kembalikan ke
bawaan" menghapusnya lagi.

Tersimpan sebagai satu berkas `letterhead.json` di folder penyimpanan
(`EZDOC_STORAGE_PATH`, atau `EZDOC_LETTERHEAD_PATH` kalau ingin tempat lain).
Bukan baris basis data: ini pengaturan satu pemasangan, bukan data dokumen —
tidak ada tabel baru, dan membatalkannya cukup dengan menghapus berkas itu.
Tanpa folder penyimpanan, panelnya mengatakan begitu dan config tetap dipakai.

Yang diganti adalah tiga penanda di dalam template — `ezdoc-kop-nama`,
`ezdoc-kop-alamat`, `ezdoc-kop-kota` — plus logo `kop` di pengaturan halaman.
Penggantian terjadi saat contohnya dimuat, jadi yang tampil di layar dan yang
tersimpan jadi template adalah kop Anda. Tanpa keduanya, contohnya tetap
terbuka dengan kop karangan.

Logo ikut tertanam di tiap template yang dibuat dari contoh, jadi berkas yang
lebih lebar dari 400 piksel dikecilkan otomatis saat dibaca.

## Membuat contoh baru

Cukup buat folder berisi `template.html` dan `contoh.json`; pemuatnya menemukan
sendiri, dan uji di bawah langsung berlaku untuk folder baru itu. Nama folder
hanya boleh huruf kecil, angka, dan tanda hubung.

## Kalau contoh ini diubah

- `tests/UI/AllExamplesTest.php` berlaku untuk **setiap** folder: templatenya
  benar-benar dirender tanpa menyisakan penanda, tiap field punya nilai contoh
  (kecuali field hitung, yang nilainya datang dari rumus), rumus hanya menyebut
  field yang ada, tipe fieldnya dikenali perender, field pilihan punya
  pilihannya dan nilainya benar-benar terpilih, petanya lengkap.
- `tests/UI/ExampleConversionTest.php` menjaga yang khas surat keterangan
  tindakan (tabel kueri, letak cap, isi halamannya).
- `tests/UI/SaveExampleAsTemplateTest.php` menjaga tombol simpannya.
- `tests/UI/ExampleLetterheadUiTest.php` dan `tests/Template/ExampleLetterheadTest.php` menjaga
  pengaturan kopnya, termasuk bahwa berkas contohnya tidak ikut berubah.

```
vendor/bin/phpunit --filter Contoh
```
