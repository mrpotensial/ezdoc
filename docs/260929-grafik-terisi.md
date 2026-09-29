# Grafik yang bisa diisi, dan ikut tercetak

Ditanya 29 Sep 2026: "apa bisa buat grafik yang bisa di isi juga ya? misal aku
punya halaman `form_partograf_cetak.php?id=7825`. aku ingin agar bisa di design
dan di gunakan di ezdoc_v2 apakah bisa?"

Lalu diperjelas, dan inilah inti yang membentuk seluruh rancangan ini:

> "yang tampil di generate itu tampilan cetak partografnya, dan bisa diisi
> langsung, jadi generate tetap 1 halaman"

Bisa. Dokumen ini menjelaskan bentuknya dan urutan pengerjaannya. Belum ada
kode.

## 1. Kenapa cara lama tidak bisa dipindah apa adanya

`form_partograf_cetak.php` menggambar dengan **ApexCharts**, yaitu JavaScript
di peramban. Mesin PDF ezdoc adalah **Dompdf 3.1.5**, dan Dompdf tidak
menjalankan JavaScript sama sekali.

Bukan cuma soal cetak. Setiap tanda tangan masuk, ezdoc **membekukan PDF di
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

Seluruh potongan itu 3,6 KB SVG, PDF-nya 2 KB, kualitas vektor.

**Akibat dari baris terakhir:** intensitas kontraksi partograf memakai tiga
isian kotak — titik-titik, arsir miring, penuh. Arsirnya **harus digambar
sebagai kumpulan `<line>`**, bukan `<pattern>`. Kalau tidak, ketiga tingkat
tercetak sama hitamnya dan tidak ada yang menyadarinya: gambarnya tetap muncul,
cuma artinya hilang.

## 3. Yang ternyata tidak perlu dipindahkan

Halaman input partograf lama **tidak memakai chart sama sekali**:

| Halaman | ApexCharts | Tabel |
|---|---|---|
| 11 halaman input (`_pembukaan`, `_kontraksi`, `_nadi`, …) | 0 | 1 masing-masing |
| `form_partograf_cetak.php` | 4 | 10 |

Dan di halaman cetak itu pun **hanya 3 panel yang benar-benar grafik**: DJJ,
pembukaan serviks, nadi. Sisanya — kontraksi, air ketuban, penyusupan, urine,
obat/cairan, suhu, tekanan darah — adalah **baris simbol yang sejajar pada
sumbu waktu yang sama**.

Jadi yang dibangun bukan pemindahan. Yang lama: isi lewat tabel di satu
halaman, lihat hasilnya di halaman lain. Yang diminta: **lembar cetaknya
sendiri yang diisi**.

## 4. Empat batasan yang membentuk rancangannya

1. **Dompdf tidak menjalankan JavaScript.** Perender grafik harus PHP.
2. **Pembekuan terjadi di server**, tanpa permintaan halaman.
3. **Tanda tangan mencakup nilai field.** `SignaturePayload::coveredKeysFor()`
   bekerja atas isi `field_values`. Angka yang hidup di tabel lain tidak
   tercakup apa pun.
4. **Kode pustaka harus netral.** Tidak boleh ada kelas bernama `Partograf` di
   `src/`. Begitu satu domain masuk ke kode pustaka, domain berikutnya menuntut
   hal yang sama.

Ditambah: server berjalan **PHP 7.4**.

## 5. Bentuknya

### 5.1 Satu papan, banyak panel, satu sumbu X

Keputusan: **satu elemen papan** yang memuat beberapa panel bertumpuk berbagi
satu sumbu X — bukan beberapa elemen grafik yang disusun sendiri di halaman.

Alasannya: guna partograf terletak pada **kesejajaran vertikalnya**. Jam 14:00
di baris pembukaan harus tepat di atas jam 14:00 di baris kontraksi. Kalau tiap
panel jadi elemen melayang yang digeser dengan mouse, melesetnya cuma soal
waktu.

```
PAPAN  (satu elemen, satu sumbu X)
├── panel  garis    DJJ
├── panel  garis    Pembukaan serviks  + garis waspada & bertindak
├── panel  simbol   Air ketuban        U  J  M  K
├── panel  simbol   Penyusupan         0  1  2  3
├── panel  batang   Kontraksi /10 menit
├── panel  garis    Nadi & tekanan darah
└── panel  teks     Obat / cairan
     └────────────── sumbu X bersama: jam ──────────────┘
```

Empat jenis panel menutup seluruh kebutuhan partograf: **garis**, **batang**,
**simbol**, **teks**.

**Dan ini tetap umum.** Papan dengan satu panel garis = grafik biasa. Grafik
sederhana adalah kasus khususnya, bukan tambahan — jadi "nanti tidak cuma
partograf" tidak menuntut rancangan kedua.

### 5.2 Grafik = spesifikasi + angka

- **Spesifikasi** (`ChartSpec`): kisi, sumbu, garis acuan, panel apa saja, tiap
  deret dipasangkan ke nama field. Tersimpan **di template**, JSON, bukan kode.
- **Angka**: tersimpan **di dokumen**, di `field_values`.

Inilah yang menyelamatkan batasan (4). Partograf bukan kelas di pustaka;
partograf adalah **satu berkas spesifikasi**. Pustakanya cuma tahu papan,
panel, sumbu, deret. Rumah sakit lain menulis spesifikasi lain tanpa menyentuh
kode.

### 5.3 Satu perender, tiga tempat pakai

`src/Chart/` — PHP murni, keluarannya SVG, tanpa menyentuh basis data maupun
permintaan yang sedang berjalan:

```
ChartSpec  +  array angka   →   SvgChartRenderer   →   string SVG
```

| Tempat | Cara |
|---|---|
| Halaman generate | SVG disisipkan langsung ke HTML |
| PDF cetak | SVG dibungkus `<img src="data:image/svg+xml;base64,…">` |
| PDF beku saat tanda tangan | sama, lewat `lib/pdf_compose.php` |

**Syaratnya deterministik**: angka yang sama menghasilkan SVG yang sama, byte
per byte. Tanpa `uniqid()`, tanpa tanggal hari ini di dalam SVG, urutan atribut
tetap. Tanpa itu, "dokumen ini bisa dibangun ulang dari nilainya" tidak lagi
bisa dibuktikan — dan itu satu-satunya alasan tanda tangannya berarti.

### 5.4 Diisi di atas lembarnya sendiri

Halaman generate menampilkan **lembar cetaknya**, dan papan grafik diisi di
tempatnya. Satu halaman. Tidak ada tabel isian di samping, tidak ada pratinjau
terpisah.

Caranya, dan ini bagian yang paling mudah salah:

```
PHP  →  SVG  +  metadata skala { x0, y0, lebar_per_jam, tinggi_per_satuan }
                      ↓
JS   →  lapisan pegangan transparan DI ATAS SVG, memakai skala yang sama
```

- SVG-nya dari server — perender yang sama persis dengan yang mencetak.
- **JS tidak menggambar grafik.** JS hanya menaruh pegangan tak terlihat, dan
  mengubah klik menjadi nilai memakai skala yang dikirim server.
- Klik di kisi → titik baru. Geser → nilainya berubah. Klik ganda → hapus.
  Panel berisi beberapa deret punya pilihan deret aktif di pojoknya.
- Supaya terasa hidup: pegangan bergerak **seketika** saat digeser (lokal),
  lalu 250 ms setelah tangan berhenti, SVG baru diminta ke server dan
  menggantikan yang lama. Jeda itu tidak terasa, dan yang dilihat orang sudah
  benar sejak detik pertama.
- Belum ada: mengetik angka persis lewat kotak kecil (DJJ 144 lebih cepat
  diketik daripada digeser). Sementara ini nilainya dibulatkan ke langkah sumbu.
- Pegangan hanya ada di layar. SVG-nya sendiri tidak mengandungnya, jadi yang
  tercetak bersih tanpa perlakuan khusus.

Godaan yang harus ditolak: menggambar grafik di JavaScript supaya lincah, lalu
menggambarnya lagi di PHP supaya bisa dicetak. **Dua perender selalu berpisah
diam-diam**, lalu yang ditandatangani orang berbeda dari yang tercetak.

### 5.5 Bentuk nilainya — bagian yang paling mahal kalau salah

Nilai satu deret tersimpan sebagai JSON di `field_values`, dan JSON itu ikut
di-hash tanda tangan. Jadi bentuknya harus diputuskan sekali, dengan benar.

```json
[{"x":"2026-09-29 08:00","y":4},{"x":"2026-09-29 12:00","y":5}]
```

Empat keputusan di dalamnya:

1. **`x` menyimpan tanggal dan jam lengkap, bukan "08:00" saja.** Persalinan
   bisa melewati tengah malam. Kode lama menambalnya dengan
   `if (jam < jamAwal) jam += 24` — tambalan yang benar hanya selama
   persalinannya kurang dari 24 jam dan hanya kalau semua yang membacanya ingat
   aturan itu. Menyimpan tanggal sekaligus menghapus seluruh golongan bug itu.
   Di layar tetap tertulis "08:00"; tanggalnya diisi sendiri oleh halaman.
2. **`x` absolut, bukan relatif terhadap jam mulai.** Kalau jam mulainya
   dikoreksi belakangan, titik-titiknya tidak ikut bergeser — yang bergeser
   sumbunya, dan itu memang yang diinginkan.
3. **Urut menurut `x`, tanpa `x` kembar.** Satu deret = satu nilai per waktu.
   Disimpan sudah terurut supaya keluarannya deterministik.
4. **Satu deret = satu field.** Kontraksi punya jumlah dan durasi → dua deret
   yang berbagi satu panel, bukan satu deret bernilai ganda. Panel batang
   menggambar tingginya dari deret pertama dan kepekatannya dari deret kedua.

Panel simbol memakai bentuk yang sama, `y` berupa teks pendek:
`[{"x":"2026-09-29 08:00","y":"J"}]`.

Deret kosong = `[]`.

### 5.6 Ukuran, huruf, kertas

- SVG partograf penuh diperkirakan 8–15 KB per dokumen. `signature_values`
  sekarang 16,5 KB per dokumen, jadi kelas ukurannya tidak berubah.
- Hurufnya harus yang tersedia di Dompdf (Helvetica aman). Huruf yang tidak ada
  diganti diam-diam, dan angka sumbu jadi meleset.
- Partograf lebar: templatnya A4 melintang. Ezdoc sudah punya setelan kertas
  per template.

## 6. Tanda tangan dan izin

Kalau nilai grafik adalah field biasa, **tidak ada mekanisme baru yang perlu
dibuat**:

| Kebutuhan | Keadaannya |
|---|---|
| sudah ditandatangani → terkunci | sudah jalan (guard §7b membekukan field) |
| mau mengubah → salin ke versi baru | sudah jalan, dan sejak 29 Sep itu bawaannya |
| siapa boleh menandatangani, per kotak | sudah ada: `data-allowed-roles`, `data-allowed-users` |
| grafik tercakup tanda tangan | otomatis — `coveredKeysFor()` mencakup semua field |

**Satu hal yang belum ada, dan perlu diluruskan:** izin yang ada sekarang **per
dokumen**, bukan per field. "Konfig Akses" mengatur siapa boleh
create/edit/delete dokumennya. Yang per elemen hanya tanda tangan. Jadi hari
ini siapa pun yang boleh mengedit dokumen boleh mengisi field mana pun,
termasuk grafiknya.

"Panel DJJ hanya boleh diisi bidan, panel obat hanya dokter" adalah barang
baru. Polanya bisa meniru ttd, tapi titik penegakannya berbeda: ttd diperiksa
saat menandatangani, field harus diperiksa **saat menyimpan** — membandingkan
nilai lama dan baru, lalu menolak perubahan yang bukan haknya. Bukan di fase
awal.

## 7. Partograf, kasus terberatnya

Datanya sudah relasional dan terisi (dihitung 29 Sep 2026):

| Tabel | Baris | Isi per baris |
|---|---|---|
| `form_partograf` | 2.223 | satu per persalinan (`id_partograf`) |
| `form_partograf_pembukaan` | 3.589 | `jam_periksa`, `besar_pembukaan`, `besar_penurunan` |
| `form_partograf_kontraksi` | 9.333 | `jam_periksa`, `jumlah_kontraksi`, `durasi_kontraksi` |
| `form_partograf_detak_jantung_janin` | 9.351 | `jam_periksa`, `total_denyut` |
| `_nadi`, `_suhu`, `_tekanan_darah`, `_urine`, `_air_ketuban`, `_penyusupan`, `_obat_cairan` | — | pola sama |

`id=7825` di URL yang ditanyakan adalah `id_partograf`. Satu persalinan
rata-rata 1–2 titik pembukaan dan 4 titik kontraksi — deretnya pendek.

**Data lama tidak dipindahkan.** Sistem lama dibiarkan hidup dan tidak disentuh;
ezdoc dipakai untuk dokumen baru. Karena itu tidak ada kode migrasi, dan bentuk
JSON di §5.5 bebas ditentukan sekarang tanpa harus mencocokkan apa pun.

**Query DB ditunda.** Fitur `tabledb` bisa membaca tabel partograf, tapi selama
pengisian dilakukan langsung di ezdoc ia tidak dibutuhkan. Kalau nanti berguna,
syaratnya satu: hasil kueri **disalin ke `field_values` saat menyimpan**, bukan
dibaca ulang tiap dokumen dibuka. Kalau dibaca ulang, grafiknya berubah
diam-diam setelah ditandatangani — dan tanda tangan yang menutupi sesuatu yang
bisa berubah tidak menutupi apa pun.

## 8. Urutan pengerjaan

Fase 0–3 **sudah dikerjakan** 29 Sep 2026 (commit 46bb6fb, 8652df1). Fase 4
belum.

| Fase | Hasil yang bisa dipakai | |
|---|---|---|
| 0 | Bentuk `ChartSpec` + `SvgChartRenderer` (panel garis, kisi, sumbu, garis acuan) + ujinya | selesai |
| 1 | Elemen papan di designer + panel pengaturnya. Grafik tampil di dokumen dan di PDF | selesai |
| 2 | Jenis field **deret** + penyimpanan nilainya. Grafik sudah terisi, walau belum interaktif | selesai |
| 3 | Lapisan pegangan: klik, geser, hapus, ketik angka persis — di atas lembarnya sendiri | selesai |
| 4 | Panel simbol, batang, teks + spesifikasi partograf + contoh di `contoh/` | belum |

Fase 2 sudah menghasilkan dokumen yang utuh dan tercetak benar. Fase 3
membuatnya enak dipakai. Fase 4 membuatnya partograf.

Dua hal ditemukan saat mengerjakannya, keduanya kegagalan senyap:

1. **Nilai hilang kalau perendernya tak terjangkau.** Isian tersembunyi semula
   ditulis hanya sesudah server menjawab. Sekarang ditulis lebih dulu dalam
   bentuk baku yang sama, dan gambar yang tertinggal dikatakan.
2. **Nilai grafik tidak ikut tersimpan.** Daftar field yang disimpan disusun
   dari penanda `{{...}}`, nama field tanda tangan, dan `data-qr` — deret
   grafik bukan satu pun dari ketiganya. Seluruh fitur tampak bekerja sampai
   titik terakhir, lalu angkanya hilang.

## 9. Yang tidak dikerjakan

- **`form_partograf_cetak.php` dan kawan-kawannya tidak disentuh.** Tetap hidup,
  tetap mencetak. Yang dibangun jalur kedua, bukan pengganti.
- **Tidak ada migrasi data lama.**
- **ApexCharts tidak dipakai di mana pun di ezdoc**, termasuk untuk pratinjau.
- **Tidak ada grafik yang dibaca ulang dari basis data saat dokumen dibuka.**
- **Izin per field** — ditunda, lihat §6.
