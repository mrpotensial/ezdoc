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

---

## 4. Rencana: dua tahap, dan alasan memecahnya

### Tahap 1 — pindahkan, tanpa mengubah perilaku sedikit pun

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

**Belum ada perilaku baru di tahap ini.** Tidak ada pembekuan, tidak ada
penyegelan. Murni pemindahan.

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

## 6. Yang perlu diputuskan sebelum Tahap 2

| | Pertanyaan | Catatan |
|---|---|---|
| 1 | Dokumen lama yang sudah ditandatangani tapi belum punya artefak — dibiarkan, atau dibekukan sekali saat pertama dicetak? | Membekukannya sekarang berarti mengabadikan tampilan **hari ini**, bukan tampilan saat ia ditandatangani. Itu tidak jujur. Saran: dibiarkan, dan ditandai "tanpa arsip tampilan" |
| 2 | Kalau kunci penyegel belum terpasang, tetap bekukan tanpa segel? | Saran: ya. Beku tanpa segel masih menjawab "tampilannya tidak berubah di dalam sistem". Segel menambah kemampuan membuktikannya di luar |
| 3 | Berapa lama artefak disimpan? | Rekam medis di Indonesia: minimal 5 tahun (Permenkes 24/2022). Ini keputusan pemasangan, bukan library |

---

## 7. Yang TIDAK termasuk

- Membuat template benar-benar berversi (sunting → baris baru). Itu memperbaiki
  masalah yang sama dari arah lain, tapi melawan sifat yang memang dipilih —
  lihat §1
- Halaman verifikasi publik. Ia **membutuhkan** rencana ini, bukan sebaliknya
- PSrE dan e-Meterai. Keduanya menumpuk di atas artefak, dan keduanya menunggu
  kontrak
