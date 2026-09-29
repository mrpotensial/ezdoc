# Grafik yang bisa diisi, dan ikut tercetak

Ditanya 29 Sep 2026: "apa bisa buat grafik yang bisa di isi juga ya? misal aku
punya halaman `form_partograf_cetak.php?id=7825`. aku ingin agar bisa di design
dan di gunakan di ezdoc_v2 apakah bisa?"

Jawabannya bisa — tapi tidak dengan cara halaman itu menggambar sekarang.
Dokumen ini menjelaskan kenapa, lalu bentuk yang benar, lalu urutan
pengerjaannya. Belum ada kode.

## 1. Kenapa cara sekarang tidak bisa dipindah apa adanya

`form_partograf_cetak.php` menggambar dengan **ApexCharts**, yaitu JavaScript
di peramban. Mesin PDF ezdoc adalah **Dompdf 3.1.5**, dan Dompdf tidak
menjalankan JavaScript sama sekali. Grafik yang digambar JavaScript berhenti di
layar.

Bukan cuma soal PDF cetak. Setiap tanda tangan masuk, ezdoc **membekukan PDF di
server**, tanpa peramban (`docs/260811-beku-pdf-tiap-tanda-tangan.md`). Jadi
"gambar di peramban lalu foto jadi PNG" pun tidak cukup: saat pembekuan
terjadi, tidak ada peramban yang bisa diminta menggambar.

**Grafiknya harus bisa digambar server, dari angka.**

## 2. Yang sudah dibuktikan, bukan diduga

Diuji langsung dengan Dompdf 3.1.5 + php-svg-lib milik aplikasi pemakai:

| Cara memasukkan gambar | Hasil di PDF |
|---|---|
| `<svg>` langsung di HTML | **diabaikan** — hanya teks di dalamnya yang bocor keluar sebagai teks biasa |
| `<img src="data:image/svg+xml;base64,…">` | **tergambar utuh**, vektor |
| `<img src="berkas.svg">` | tergambar utuh |

Lalu diuji potongan partograf sungguhan — kisi 16 jam × 10 cm, angka sumbu,
garis waspada, garis bertindak putus-putus, titik pembukaan yang terhubung,
batang kontraksi:

| Unsur | Hasil |
|---|---|
| `<line>`, `<rect>`, `<circle>`, `<path>`, `<polyline>` | jalan |
| `stroke-dasharray` (garis putus-putus) | jalan |
| `<text>` | jalan |
| `<g transform="translate(...) rotate(-90)">` — label sumbu Y berdiri | **jalan** |
| `<pattern>` untuk arsiran | **GAGAL DIAM-DIAM** — terisi hitam pekat |

Seluruh potongan itu 3,6 KB SVG dan menghasilkan PDF 2 KB. Kualitasnya vektor,
bukan raster.

**Akibat praktis dari baris terakhir:** intensitas kontraksi partograf memakai
tiga isian kotak — titik-titik, arsir miring, penuh. Arsirnya **harus digambar
sebagai kumpulan `<line>`**, bukan `<pattern>`. Kalau tidak, ketiga tingkat
intensitas tercetak sama hitamnya dan tidak ada yang menyadarinya: gambarnya
tetap muncul, cuma artinya hilang.

## 3. Empat batasan yang membentuk rancangannya

1. **Dompdf tidak menjalankan JavaScript.** Perender grafik harus PHP.
2. **Pembekuan terjadi di server.** Perender yang sama harus bisa dipanggil
   tanpa permintaan halaman, seperti `lib/pdf_compose.php` sekarang.
3. **Tanda tangan mencakup nilai field, bukan hasil kueri.**
   `SignaturePayload::coveredKeysFor()` bekerja atas isi `field_values`. Angka
   yang hidup di tabel lain tidak tercakup apa pun.
4. **Kode pustaka harus netral.** Tidak boleh ada kelas bernama `Partograf` di
   `src/`. Ini bukan formalitas — begitu satu domain masuk ke kode pustaka,
   domain berikutnya menuntut hal yang sama.

Ditambah: server berjalan **PHP 7.4**, jadi tanpa `match`, named argument, atau
properti bertipe union.

## 4. Bentuknya

### 4.1 Grafik = spesifikasi + angka

Dua hal yang dipisah tegas:

- **Spesifikasi** (`ChartSpec`): bentuk kisinya, sumbunya, garis acuannya,
  deret apa saja dan digambar sebagai apa. Tersimpan **di template**, bersama
  elemen grafiknya — JSON, bukan kode.
- **Angka**: tersimpan **di dokumen**, di `field_values`.

Inilah yang menyelamatkan batasan (4). Partograf bukan kelas di pustaka;
partograf adalah **satu berkas spesifikasi**. Pustakanya cuma tahu "kisi,
sumbu, deret, garis acuan". Rumah sakit lain yang butuh grafik lain menulis
spesifikasi lain, tanpa menyentuh kode.

```
ChartSpec (di template, JSON)
  ukuran      : 560 × 300 pt
  sumbu_x     : { jenis: "jam", mulai_dari_field: "jam_mulai", panjang: 16, satuan: "jam" }
  sumbu_y     : { min: 0, maks: 10, langkah: 1, judul: "Pembukaan serviks (cm)" }
  kisi        : { kolom: true, baris: true }
  acuan       : [ { jenis: "garis", dari: [0,4], ke: [8,10], gaya: "penuh",   nama: "waspada" },
                  { jenis: "garis", dari: [4,4], ke: [12,10], gaya: "putus",  nama: "bertindak" } ]
  deret       : [ { field: "pembukaan", gambar: "titik+garis", tanda: "lingkaran", warna: "#cc0000" },
                  { field: "penurunan", gambar: "titik",       tanda: "silang" } ]
```

### 4.2 Satu perender, tiga tempat pakai

`src/Chart/` — PHP murni, keluarannya SVG, tanpa menyentuh basis data maupun
permintaan yang sedang berjalan:

```
ChartSpec  +  array angka   →   SvgChartRenderer   →   string SVG
```

Dipakai bertiga:

| Tempat | Cara |
|---|---|
| Layar (halaman dokumen) | SVG disisipkan langsung ke HTML |
| PDF cetak | SVG dibungkus `<img src="data:image/svg+xml;base64,…">` |
| PDF beku saat tanda tangan | sama, lewat `lib/pdf_compose.php` |

**Syaratnya deterministik**: angka yang sama harus menghasilkan SVG yang sama,
byte per byte. Tanpa itu, "dokumen ini bisa dibangun ulang dari nilainya" tidak
lagi bisa dibuktikan — dan itu satu-satunya alasan tanda tangannya berarti.
Artinya: tanpa `uniqid()`, tanpa tanggal hari ini di dalam SVG, urutan atribut
tetap.

Satu perender, bukan dua. Godaannya besar untuk menulis kembar di JavaScript
supaya pratinjaunya lincah — dan dua perender selalu berpisah diam-diam, lalu
yang tercetak berbeda dari yang dilihat orang saat menandatangani. Pratinjau
layar memanggil perender yang sama lewat satu endpoint kecil, ditunda ~300 ms
setelah ketikan berhenti. Pola yang sama dipakai Kroki dan server PlantUML:
satu perender di server, dipakai pratinjau maupun hasil akhir.

### 4.3 Angka dari mana — dua pintu, satu simpanan

Ini bagian yang paling menentukan arti dokumennya.

**Pintu A — diisi tangan.** Jenis field baru: **deret** (berulang). Di halaman
dokumen ia tampil sebagai kisi kecil:

```
Pembukaan serviks
┌────────┬───────┬───┐
│ Jam    │ cm    │   │
├────────┼───────┼───┤
│ 08:00  │ 4     │ ✕ │
│ 12:00  │ 5     │ ✕ │
│ 14:00  │ 7     │ ✕ │
└────────┴───────┴───┘
[+ tambah baris]
```

Nilainya masuk `field_values` sebagai JSON — **tercakup tanda tangan**, sama
seperti field lain.

**Pintu B — dari Query DB.** Fitur `tabledb` yang sudah ada bisa membaca tabel
partograf apa adanya. Nol pengetikan untuk data yang sudah ada.

**Tapi keduanya bermuara ke satu tempat.** Hasil kueri **disalin ke
`field_values` saat dokumen disimpan**, bukan dibaca ulang tiap kali dokumen
dibuka. Tanpa penyalinan itu, grafiknya berubah diam-diam ketika bidan menambah
satu baris pemeriksaan sesudah dokumennya ditandatangani — dan tanda tangan
yang menutupi sesuatu yang bisa berubah tidak menutupi apa pun.

Ini bukan pola baru: sama dengan cara ezdoc sudah menyalin kop ke template
(`Contoh::pasangKop()`) dan menyalin isi versi lama ke versi baru. Kueri jadi
**isian awal**, bukan sumber yang hidup terus.

### 4.4 Di designer

Elemen baru `.chart-placeholder`, sekelas dengan `.ttd-placeholder` dan
`.logo-placeholder` yang sudah ada — artinya ia ikut jalur yang sudah teruji:
disisipkan dari toolbar, dipindai `scanChartPlaceholders()`, diatur dari panel
kanan, bisa melayang atau inline, dan ikut disalin-tempel (termasuk penamaan
ulang id kembar yang baru dibereskan 29 Sep).

Panel kanannya mengatur: ukuran, sumbu, kisi, garis acuan, dan tiap deret
dipasangkan ke nama field. Yang rumit — partograf — tidak diketik ulang tiap
kali: ada tombol muat dari spesifikasi yang sudah jadi.

### 4.5 Ukuran dan huruf

- SVG partograf penuh diperkirakan 8–15 KB per dokumen. Dibanding
  `signature_values` yang sekarang 16,5 KB per dokumen, ini tidak mengubah
  kelas ukurannya.
- Hurufnya harus yang tersedia di Dompdf (Helvetica aman). Huruf yang tidak
  ada diganti diam-diam, dan angka sumbu jadi meleset.
- Partograf lebar. Templatnya kemungkinan besar A4 melintang — ezdoc sudah
  punya setelan kertas per template.

## 5. Partograf, kasus terberatnya

Datanya sudah relasional dan sudah terisi (dihitung di basis data pemakai
29 Sep 2026):

| Tabel | Baris | Isi per baris |
|---|---|---|
| `form_partograf` | 2.223 | satu per persalinan (`id_partograf`) |
| `form_partograf_pembukaan` | 3.589 | `jam_periksa`, `besar_pembukaan`, `besar_penurunan` |
| `form_partograf_kontraksi` | 9.333 | `jam_periksa`, `jumlah_kontraksi`, `durasi_kontraksi` |
| `form_partograf_detak_jantung_janin` | 9.351 | `jam_periksa`, `total_denyut` |
| `_nadi`, `_suhu`, `_tekanan_darah`, `_urine`, `_air_ketuban`, `_penyusupan`, `_obat_cairan` | — | pola sama: `jam_periksa` + nilai |

`id=7825` di URL yang ditanyakan adalah `id_partograf`. Satu persalinan
rata-rata punya 1–2 titik pembukaan dan 4 titik kontraksi — deretnya pendek,
jadi kisi isian tangan pun tidak melelahkan.

Yang membuatnya berat bukan datanya, melainkan gambarnya:

- kisi tetap 16 jam, sumbu 0–10 cm;
- garis waspada dan garis bertindak yang posisinya baku;
- beberapa grafik **bertumpuk berbagi satu sumbu X** (pembukaan, DJJ, nadi,
  kontraksi, obat) — jadi `ChartSpec` harus mengenal susunan bertingkat, bukan
  satu grafik tunggal;
- kotak kontraksi bertingkat tiga (titik / arsir / penuh) — dan arsirnya harus
  garis, lihat §2.

## 6. Urutan pengerjaan

| Fase | Isi | Sudah terbukti? |
|---|---|---|
| 0 | Spesifikasi `ChartSpec` + `SvgChartRenderer` (garis, titik, kisi, sumbu, garis acuan) + ujinya | jalur SVG→PDF **sudah** |
| 1 | Elemen grafik di designer + panel pengaturnya | pola placeholder **sudah ada** |
| 2 | Jenis field **deret** + kisi isiannya + pratinjau lewat endpoint | belum ada padanannya di ezdoc |
| 3 | Penyalinan hasil Query DB ke `field_values` saat simpan | pola salin **sudah ada** |
| 4 | Spesifikasi partograf (berkas, bukan kode) + grafik bertumpuk + arsiran garis + contoh di `contoh/` | bagian tergambarnya **sudah diuji** |

Fase 0–1 sudah menghasilkan sesuatu yang bisa dipakai: grafik garis sederhana
yang diisi tangan. Partograf utuh baru selesai di fase 4.

## 7. Yang tidak dikerjakan

- **`form_partograf_cetak.php` tidak disentuh.** Ia tetap hidup dan tetap
  mencetak. Yang dibangun adalah jalur kedua, bukan penggantian.
- **ApexCharts tidak dipakai di mana pun di ezdoc**, termasuk untuk pratinjau
  layar. Dua perender akan berpisah.
- **Tidak ada grafik yang dibaca ulang dari basis data saat dokumen dibuka.**
  Lihat §4.3.

## 8. Yang masih perlu diputuskan

1. **Partograf jadi satu elemen grafik bertingkat, atau beberapa elemen yang
   disusun di halaman?** Satu elemen lebih mudah dijaga kesejajarannya; beberapa
   elemen lebih lentur tapi sumbu X-nya harus disamakan sendiri.
2. **Dokumen yang sudah ada di `form_partograf` mau ikut dipindahkan, atau
   ezdoc hanya untuk persalinan baru?** Memindahkan berarti membuat dokumen
   ezdoc dari 2.223 baris yang ada.
3. **Siapa yang menandatangani partograf, dan di titik mana?** Partograf diisi
   bertahap selama berjam-jam. Kalau ditandatangani di tengah, isian sesudahnya
   membuat dokumennya beku dan harus lewat versi baru. Ini menentukan apakah
   partograf lebih cocok ditandatangani sekali di akhir.

Pertanyaan ketiga yang paling menentukan, dan jawabannya bukan urusan teknis.
