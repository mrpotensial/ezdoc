# Beku PDF tiap tanda tangan

**Lahir**: 2026-08-11
**Status**: rencana, belum dikerjakan
**Sebelumnya**: [260805-psre-meterai.md](260805-psre-meterai.md) §7b (A2), yang membangun
tabel artefak dan `FreezeService` tapi belum menyambungkannya ke apa pun

---

## 1. Masalahnya, dibuktikan bukan diduga

Menyunting template **menimpa barisnya langsung**:

```php
// actions/template/save_template.php:103
'UPDATE ezdoc_templates SET name = ?, ..., content = ? WHERE id = ?'
```

Dan halaman cetak memuat template dari baris yang sama:

```php
// views/document/generate.php:263
'FROM ezdoc_templates WHERE id = ? AND content IS NOT NULL'
```

Akibatnya: **sunting template hari ini, dokumen yang ditandatangani tahun lalu
ikut berubah tampilannya.**

Kolom `version` dan `is_current` ada di tabelnya, tapi jalur sunting tidak
memakainya — ia menimpa, bukan menambah versi.

### Tanda tangannya sendiri tidak bohong

Perlu dibedakan, karena mudah tertukar:

| | Apa yang terjadi saat template disunting |
|---|---|
| Tanda tangan data (JCS) | **Tetap sah.** Ia mencakup `fields`, bukan tampilan. Tidak ada yang batal |
| Yang dibaca orang | **Berubah.** Tata letak, kalimat tetap, kop surat — semua ikut yang baru |

Jadi ini bukan lubang keamanan. Ini lubang **bukti**: yang bisa dibuktikan
adalah "data ini yang ditandatangani", bukan "beginilah dokumen yang dilihat
orang itu".

### Kenapa ini bukan cacat yang tidak disengaja

Sifat "sunting template → semua dokumen lama ikut rapi" **memang dipilih**.
Rencana lama menyebutnya *flexibility-first / re-render anytime*, dan itu
berguna: salah ketik di satu template diperbaiki sekali, dan seluruh dokumen
ikut benar.

Yang bertabrakan bukan implementasinya, melainkan dua kebutuhan yang sama-sama
sah — dan keduanya tidak bisa berlaku pada dokumen yang sama.

**Keputusan (2026-08-11, pemilik sistem):** begitu sebuah dokumen ditandatangani,
integritas menang atas kelenturan. Dokumen yang sudah ada tanda tangannya tidak
lagi ikut perbaikan template. Kehilangan itu disadari dan diterima.

---

## 2. Kapan dibekukan: tiap tanda tangan, bukan yang pertama, bukan yang terakhir

Usulan awal: bekukan saat tanda tangan **pertama** masuk. Ditolak, dan alasannya
menentukan bentuk seluruh rencana ini.

Dokumen tiga penanda tangan. Bekukan setelah yang pertama, dan arsipnya
mengabadikan dua kotak tanda tangan yang masih kosong. Begitu yang kedua masuk,
arsip itu bukan cuma tidak lengkap — ia **menyesatkan**, karena ia terlihat
seperti dokumen jadi.

Bekukan hanya di **akhir**, dan penanda tangan pertama tidak punya jawaban atas
pertanyaan yang paling mungkin ditanyakan kepadanya: *"waktu Bapak tanda tangan,
isinya seperti apa?"*

**Tiap tanda tangan menghasilkan potretnya sendiri.** Satu penanda tangan, satu
arsip — dokumen persis seperti yang **dia** lihat.

```
TTD dr. Andi  → siklus 1   (kotak pasien & saksi masih kosong)
TTD pasien    → siklus 2   (kotak saksi masih kosong)
TTD saksi     → siklus 3   (lengkap)   ← arsip berlaku
```

Siklus 1 dan 2 **tidak dibuang**. Ia bukan sampah, melainkan jawaban atas
pertanyaan yang berbeda.

### Skemanya sudah cukup, tanpa perubahan

`ezdoc_document_artifacts` sudah punya `cycle` dan `superseded_at`. Yang berubah
cuma maknanya, dan justru jadi lebih tepat:

| | Dulu diartikan | Sekarang |
|---|---|---|
| `superseded_at` terisi | pembekuannya dicabut orang | digantikan potret yang lebih baru |
| `cycle` naik | bekukan ulang setelah dicabut | tanda tangan berikutnya masuk |

Pencabutan manual tetap ada dan tetap menuntut alasan tertulis. Yang otomatis
mengisi alasannya sendiri.

### Biayanya, disebut apa adanya

Satu render PDF per tanda tangan. Dokumen tiga penanda tangan berarti tiga
render, dan render terjadi di dalam permintaan yang menyimpan tanda tangan —
jadi penyimpanan jadi lebih lambat.

Diterima. Satu render dompdf terukur dalam ratusan milidetik, dan ia terjadi
pada aksi yang memang jarang: menandatangani, bukan mengetik.

Yang **tidak** diterima: membekukan di latar belakang lalu memberi tahu
pengguna "berhasil" sebelum arsipnya benar-benar ada. Kalau pembekuan gagal,
tanda tangannya harus ikut gagal — lihat §5.

---

## 3. Penghalangnya: perakitan HTML terkunci di dalam view

Diukur, bukan dikira-kira:

| | |
|---|---|
| Perakitan `$pdfHtml` | baris 1866–2086 `views/document/generate.php` — **221 baris** |
| Ukuran view keseluruhan | 3281 baris |
| Ditulis dalam | 19 potongan terpisah |
| Variabel yang dipakainya | ~20 (`$paperDim`, `$padLeft/Right/Top/Bottom`, `$pdfFields`, `$configTtd`, `$ttdValues`, `$pdfContent`, `$template`, …) |
| Fungsi bantu | `h()`, `t()`, `ezdoc_resolve_signature_image()` |

**Yang menentukan bukan 221 barisnya, melainkan ~20 masukannya.** Semuanya
dihitung lebih dulu di view yang sama — mengurai konfigurasi kop surat,
menyelesaikan nilai field, menyusun konfigurasi TTD. Memindahkan perakitannya
berarti ikut memindahkan yang menyiapkan masukannya.

Dan ini jalur yang **paling sering dipakai** di seluruh sistem. Kalau rusak,
semua cetakan rusak.

### Jebakan: dua perender yang mirip tapi sudah menyimpang

`generate.php` punya **dua** fungsi perender isi yang hampir sama:

| | |
|---|---|
| `renderContent()` (baris 1143) | untuk layar, 24 baris |
| `renderContentForPdf()` (baris 2144) | untuk PDF, 32 baris |

Godaan saat memindahkan: "keduanya hampir sama, satukan saja." **Jangan.**
Bedanya sebagian disengaja, dan menyatukannya akan mengubah salah satu:

| Beda | Disengaja? |
|---|---|
| Logo tidak ketemu → layar menulis `<span class="logo-empty">[nama]</span>`, PDF menulis kosong | **Ya.** Dokumen cetak tidak boleh memuat tulisan `[logo_kop]` |
| Layar menambahkan `alt="nama"` pada gambar, PDF tidak | Tidak penting, tapi mengubahnya tetap mengubah keluaran |
| Regex layar punya satu grup tangkap lagi (`style="..."`) yang **tidak pernah dipakai** callback-nya | Kelihatannya kecelakaan — tapi grup tambahan mengubah apa yang **cocok**, bukan cuma apa yang ditangkap |
| `$posOffsetQR` dan `$posOffsetTTD` di versi PDF, keduanya diisi 0 dan tidak pernah dibaca | Kode mati |
| Layar menerima `$param_is_locked`, PDF tidak | Ya — PDF tidak punya field yang bisa disunting |

**Aturan untuk Tahap 1: pindahkan apa adanya, jangan rapikan apa pun.** Kode
mati ikut dipindah, perbedaan yang kelihatan tidak masuk akal ikut dipindah.
Merapikan sambil memindahkan membuat tidak ada yang bisa menjawab pertanyaan
"kalau keluarannya berubah, karena pemindahannya atau karena perapiannya".

Rapikan setelah pemindahannya terbukti utuh, sebagai perubahan tersendiri yang
punya buktinya sendiri.

---

## 4. Rencana: dua tahap, dan alasan memecahnya

### Tahap 1 — ✅ selesai 2026-08-12

**Hasilnya: 10 dari 10 dokumen identik byte per byte.** Ukuran total kedua
tangkapan sama persis — 2.386.255 byte. Sepuluh template berbeda, dari 15 KB
sampai 1,1 MB.

Yang pindah ke `lib/pdf_compose.php`: kerangka dokumennya — CSS, `@page`, tiga
titik sisipan consumer, blok tanda tangan format lama, penutupnya. 221 baris
keluar dari view.

Ikut pindah ke `lib/view_helpers.php`: `h()` dan `t()`. Keduanya dideklarasikan
di dalam `generate.php` dengan penjaga `function_exists` — bekerja selama hanya
berkas itu yang memakainya, dan berhenti bekerja begitu perakitannya pindah.

**Yang BELUM ikut pindah, dan ini menghalangi Tahap 2:** perenderan isi
(`renderContentForPdf()`). Ia membaca permintaan yang sedang berjalan:

```php
$pickPdf = function($key) use ($dbFields) {
    if (isset($dbFields[$key]) …) return $dbFields[$key];
    if (isset($_POST[$key])   …) return $_POST[$key];    // ← permintaan
    if (isset($_GET[$key])    …) return $_GET[$key];     // ← permintaan
};
```

Dipanggil dari `save_document.php`, ia akan mengambil dari permintaan
**simpan**, bukan permintaan cetak. Itu bisa saja kebetulan menghasilkan yang
benar — dan "kebetulan benar" persis yang tidak boleh ada di jalur pembuat
bukti.

### Tahap 1b — ✅ selesai 2026-09-05

`renderContentForPdf()` menerima nilainya, bukan mengambilnya. Badan fungsinya
sekarang nol pembacaan `$_POST`/`$_GET`/`$_REQUEST`/`$_SESSION`/`$_SERVER`.

Tiga tempat, dan ketiganya berbeda sifat — karena itu ketiganya diserahkan
secara terpisah, bukan dengan mengoper `$_POST`/`$_GET` masuk (yang cuma
memindahkan masalahnya satu meter):

| Dulu | Sekarang | Kenapa terpisah |
|---|---|---|
| `$pickPdf`: dbFields → POST → GET | `$permintaan['values']` | "ambil yang pertama tidak kosong" — bisa diratakan jadi satu peta |
| `_show_verify_qr` di POST **atau** GET | `$permintaan['show_verify_qr']` | menanyakan "nilainya tepat `'1'`", bukan "tidak kosong". Diratakan, `=0` di POST akan menutupi `=1` di GET |
| `$_GET['doc_id'] ?? $_POST['_doc_id']` | `$permintaan['verify_doc_id']` | kunci berbeda per sumber, dan pakai `isset` bukan tidak-kosong |

Perakitannya pindah ke pemanggil HTTP, dan `ezdoc_pdf_request_values()` di
`lib/pdf_compose.php` yang meratakan yang pertama. Bawaan `verify_doc_id` adalah
dokumen yang sedang dirender — pemanggil HTTP tetap mengirimkan miliknya sendiri
supaya perilaku hari ini tidak bergeser sedikit pun.

**Buktinya sejauh ini:** transformasinya setara-per-konstruksi (ekspresi di
pemanggil salinan persis dari yang dulu tertanam), ditambah
`tests/Rendering/PdfRequestValuesTest.php` — 16 tes, termasuk perbandingan
langsung lawan tiruan rantai `if` yang lama. Dua uji mutasi merah lebih dulu:
perakitan yang memakai `array_merge` biasa, dan perender yang kembali membaca
`$_GET`.

**Yang BELUM:** perbandingan hasil sepuluh dokumen sungguhan seperti Tahap 1.
Alatnya masih ada (`page/ezdoc_capture_pdf.php`) dan perlu dijalankan dari
peramban yang sudah login. Sampai itu dikerjakan, buktinya bukan "10 dari 10
identik byte per byte" — sebut apa adanya.

### Tahap 1 — cara mengerjakannya (arsip)

Perakitan + penyiapan masukannya jadi kelas tersendiri. `generate.php`
memanggilnya. Keluarannya harus sama persis.

**Buktinya harus keras, bukan "kelihatannya sama":** cetak dokumen yang sama
sebelum dan sesudah pemindahan, lalu bandingkan **byte**-nya.

PDF memuat tanggal pembuatan dan pengenal acak, jadi dua render dari HTML yang
sama tidak pernah identik byte per byte. Yang dibandingkan karena itu adalah
**HTML sebelum masuk mesin PDF** — di situ tidak ada yang acak, dan di situ pula
seluruh keputusan tata letak sudah selesai.

```
Sebelum:  generate.php  → $pdfHtml  → simpan sebagai pembanding
Sesudah:  Composer      → $pdfHtml  → harus SAMA PERSIS
```

Kalau tidak sama, ada yang berubah, dan Tahap 2 tidak boleh dimulai.

**Alatnya: `page/ezdoc_capture_pdf.php`**, dibuka dari peramban dalam keadaan
sudah login. Sekali tekan, seluruh dokumen contoh terambil jadi satu berkas.

Versi baris perintahnya sempat dibuat lalu **dibuang**. `koneksi.php` menuntut
sesi login, jadi menjalankannya dari CLI berarti memalsukan login di server
produksi. Sesi palsu untuk sekadar membaca adalah pintu yang tidak semestinya
dibuka, dan pintu semacam itu tidak pernah tertutup lagi.

Peramban sudah punya sesi yang sah dan semua yang dibutuhkan halaman cetak. Yang
kurang cuma cara mengambil banyak dokumen sekaligus — dan itu satu halaman kecil,
bukan lapisan tiruan konteks web.

Dokumen yang diambil dipilih yang **paling beragam**, bukan yang paling baru:
satu per template, ditambah yang tanda tangannya terbanyak dan yang tanpa tanda
tangan sama sekali. Perubahan pada perakitan akan patah di kasus yang tidak
biasa, dan contoh yang seragam justru meloloskan kerusakan yang paling mungkin
terjadi.

**Urutannya tidak bisa dibalik.** Tangkapan "sebelum" harus diambil di server
**sebelum** satu baris pun dipindahkan. Tidak ada cara membuatnya belakangan —
kode lamanya sudah tidak ada di sana.

**Belum ada perilaku baru di tahap ini.** Tidak ada pembekuan, tidak ada
penyegelan. Murni pemindahan.

### Tahap 2a — rantai perendernya keluar dari view — ✅ selesai 2026-09-05

**Hasilnya: 24 dari 24 keluaran identik byte per byte, 340.414 byte.** Enam
template sungguhan dari basis data, empat varian masing-masing (tanpa ttd, ttd
lengkap, mode verify-QR, nilai dari permintaan).

Sepuluh fungsi keluar dari `views/document/generate.php` ke `lib/pdf_compose.php`
— 36.730 byte, view menyusut dari 183.316 jadi 146.586 byte:

`renderContentForPdf`, `renderFieldForPdf`, `processTabledbRows`,
`_tabledbRenderRow`, `_tabledbFormatValue`, `_tabledbComputeAggregate`,
`_tabledbParseFormat`, `_tabledbParseSelector`, `_tabledbClassifyRow`,
`executeDynQuery`.

Ikut pindah ke `lib/view_helpers.php`: `safeDataImg()` dan `safeSignatureValue()`.
Keduanya berpenjaga `function_exists` di view, jadi absennya dari luar view tidak
berisik — jalur materai cuma diam, dan gambarnya hilang dari dokumen beku tanpa
satu pun galat. Fixture-nya kebetulan tidak menyentuh materai, jadi pemindahan
ini **tidak** ikut terbukti oleh 24 berkas di atas; ia dipindah karena alasannya
sudah jelas, bukan karena tertangkap.

**Dua ketergantungan tersembunyi ketahuan saat rantainya berdiri sendiri:**

1. `processConditionalSections()` di `lib/doc_template_helpers.php` hanya dimuat
   oleh view. Sekarang ikut `bootstrap.php` dan autoload composer.
2. `lib/doc_meta_helpers.php` dan `lib/signature_image.php` ada di `bootstrap.php`
   tapi **tidak** di daftar `files` composer. Consumer yang memasang lewat
   Composer — cara distribusi yang dituju — tidak pernah mendapat keduanya.
   Dua pemuat, dua daftar; sekarang sama.

Yang dijaga tes: `tests/Rendering/PdfContentChainTest.php` memanggil rantainya
tanpa me-require view sama sekali. Kesetaraan byte dibuktikan di luar suite,
karena bahannya template sungguhan milik pemasangan ini.

### Tahap 2 — bekukan tiap tanda tangan masuk

Setelah bisa dipanggil dari mana saja, `save_document.php` memakainya.

```
tanda tangan tercatat
  → rakit HTML          (kelas dari Tahap 1)
  → render jadi PDF     (PdfRenderer::render — sudah ada sejak 2026-08-11)
  → segel               (PkiArtifactSigner, kalau kuncinya terpasang)
  → simpan jadi artefak (FreezeService)
```

Dan jalur cetak berubah: dokumen yang punya artefak berlaku **menyajikan
berkasnya**, tidak merender ulang. Itu sekaligus yang membuat sunting template
tidak lagi menyentuhnya.

### Kenapa dipecah

Tahap 1 murni pemindahan yang benar-salahnya bisa dibuktikan. Tahap 2 menambah
perilaku baru. Digabung, dan kalau ada yang rusak kita tidak tahu yang mana
penyebabnya — pemindahan yang meleset, atau perilaku baru yang salah.

---

## 5. Aturan yang tidak boleh dilanggar

**Pembekuan gagal → tanda tangannya ikut gagal.** Bukan "tanda tangan berhasil,
arsip menyusul". Yang kedua menghasilkan tanda tangan tanpa bukti tampilan, dan
kekurangannya baru ketahuan saat ada yang menyanggah — ketika sudah terlambat.

**Byte yang disimpan adalah byte yang disegel.** Kunci artefak adalah sidik jari
isinya; menyegel setelah menyimpan menghasilkan byte lain. `FreezeService` sudah
menegakkan ini.

**Siklus lama tidak pernah dihapus.** Ia jawaban atas pertanyaan penanda tangan
sebelumnya.

**Yang menyajikan dokumen beku tidak boleh merender ulang.** Kalau ia merender
ulang, seluruh rencana ini tidak ada gunanya.

---

## 6. Keputusan — ✅ semua terjawab 2026-09-05

| | Pertanyaan | Keputusan |
|---|---|---|
| 1 | Dokumen lama yang sudah ditandatangani tapi belum punya artefak | **Dibiarkan**, ditandai "tanpa arsip tampilan". Membekukannya sekarang berarti mengabadikan tampilan **hari ini**, bukan tampilan saat ia ditandatangani — dan itu tidak jujur |
| 2 | Kalau kunci penyegel belum terpasang, tetap bekukan tanpa segel? | **Ya.** Beku tanpa segel sudah menjawab masalah utamanya: sunting template tidak lagi mengubah dokumen lama. Segel menambah kemampuan membuktikan ke pihak LUAR, dan itu tidak menghalangi. Menunggu kunci berarti dokumen yang ditandatangani minggu ini kehilangan kesempatan diarsipkan selamanya — waktunya tidak bisa diulang |
| 3 | Berapa lama artefak disimpan? | **Minimal 5 tahun** (Permenkes 24/2022). Keputusan pemasangan, bukan library |

Akibat dari (1) dan (2) yang perlu dinyatakan: artefak yang dibekukan tanpa segel
**tidak otomatis bersegel** ketika kuncinya dipasang nanti. Menyegel belakangan
menghasilkan berkas dengan sidik jari berbeda, dan §5 melarang menukar byte yang
sudah disimpan. Jadi yang lama tetap "beku tanpa segel" — sama seperti dokumen
lama pada keputusan (1).

---

## 7. Yang TIDAK termasuk

- Membuat template benar-benar berversi (sunting → baris baru). Itu memperbaiki
  masalah yang sama dari arah lain, tapi melawan sifat yang memang dipilih —
  lihat §1
- Halaman verifikasi publik. Ia **membutuhkan** rencana ini, bukan sebaliknya
- PSrE dan e-Meterai. Keduanya menumpuk di atas artefak, dan keduanya menunggu
  kontrak
