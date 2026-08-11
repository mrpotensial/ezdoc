# Rencana — tanda tangan tersertifikasi (PSrE) dan e-Meterai resmi

**Status:** rencana, belum dikerjakan
**Ditulis:** 2026-08-05

## 1. Keadaan sebenarnya, bukan yang tertulis di dokumen

`docs/PSRE-INTEGRATION.md` bilang `BaseRemoteProvider` "belum di-implement".
Berkasnya 802 baris. Dokumen itu tertinggal dari kodenya, seperti PRD sebelum
disinkronkan. Yang di bawah ini hasil pemeriksaan, bukan bacaan.

| Lapisan | Keadaan |
|---|---|
| **L1 — HMAC** | Jalan, ada tesnya |
| **L2 — LocalPKI** | Jalan, ada tesnya (KeyStore, PKCS#7, sertifikat) |
| **Penandatanganan PDF** | `PdfSigner` + tiga implementasi (External, JSignPdf, Openssl) + `PdfBytesRange`. Nyaris tanpa penanda TODO |
| **TSA (RFC 3161)** | `TimestampClient` + dua implementasi, encoder DER |
| **Kerangka jarak jauh** | `BaseRemoteProvider` (802 baris), `HttpClient`, `SignSession`, `OtpChallenge` — nyata, bukan stub |
| **L3 — Peruri** | **17 penanda TODO.** Pemetaan endpoint, skema payload, nama field respons, jalur auth — belum diisi |
| **L3 — Privy** | **14 penanda TODO.** Sama |
| **Tes provider PSrE** | **Tidak ada** |
| **e-Meterai** | **Belum ada sama sekali.** Yang ada cuma placeholder visual di template — gambar yang diunggah petugas, bukan meterai resmi |

Jadi yang kurang bukan arsitekturnya. Yang kurang adalah **pemetaan API vendor**
— dan itu menunggu kredensial sandbox, seperti yang dokumen itu sendiri sebut.

## 2. Yang paling penting dipahami sebelum apa pun: dua hal yang ditandatangani

Ini bukan detail teknis. Ini yang mengubah bentuk sistemnya.

**Sekarang** ezdoc menandatangani **data**. `SignaturePayload` merakit JSON
kanonis dari `document_uuid`, `template_uuid`, nilai field yang tercakup, dan
nilai tanda tangannya, lalu itu yang di-hash. PDF-nya dirender belakangan, dari
data yang sama.

**PAdES menandatangani byte PDF.** Bukan data yang menghasilkannya — berkasnya
sendiri, apa adanya, termasuk font dan tata letaknya.

Keduanya bukan hal yang sama, dan urutannya terbalik:

```
sekarang:   data → tanda tangan (hash data)  →  render PDF
PAdES:      data → render PDF → BEKUKAN     →  tanda tangan (byte PDF)
```

Akibatnya:

- **PDF jadi artefak yang berlaku**, bukan turunan yang bisa dirender ulang
  kapan saja. Ia harus disimpan, bukan dibangkitkan lagi — karena render ulang
  menghasilkan byte berbeda dan tanda tangannya batal
- **Apa pun yang mengubah PDF setelah ditandatangani membatalkannya**, kecuali
  dilakukan sebagai *incremental update* yang memang diizinkan tanda tangan
  sebelumnya (DocMDP)
- Record L1/L2 yang sekarang **tidak dibuang**. Ia menjawab pertanyaan berbeda:
  "apakah datanya berubah". Tanda tangan PDF menjawab "apakah berkas ini yang
  ditandatangani orang itu". Keduanya berguna

### 2b. Apakah hash yang sekarang salah? Tidak.

Pertanyaan yang wajar muncul dari bagian di atas, dan jawabannya perlu jelas
sebelum ada yang tergoda membuangnya.

Menandatangani data — bukan renderingnya — adalah keluarga pendekatan yang punya
standarnya sendiri:

| | |
|---|---|
| **RFC 8785 (JCS)** | Yang sudah kita ikuti. Ada persis untuk ini: menandatangani JSON terlepas dari format penulisannya |
| **XML Signature / XAdES** | Memakai kanonisasi C14N dengan alasan sama — satu dokumen XML punya banyak bentuk byte |
| **JWS / JOSE** | Menandatangani serialisasi kanonis dari klaim, bukan tampilan |
| **FHIR Digital Signatures** | Menandatangani resource-nya |

Kelebihannya nyata: tanda tangan data **selamat dari render ulang**. Ganti font,
perbaiki CSS cetak, upgrade dompdf — tetap sah, karena yang dijamin datanya.

Dan payload kita memuat `template_uuid` **dan** `template_version`, jadi revisi
template mengubah hash-nya. Itu jauh lebih kuat daripada menandatangani nilai
field saja.

**Tiga hal yang tetap tidak bisa dijawabnya** — dan ini alasan PSrE, bukan
alasan mengganti hash:

1. **Tidak tersertifikasi.** HMAC dan PKI lokal bukan sertifikat PSrE terdaftar.
   Celah hukum, bukan celah desain. Se-kanonis apa pun hash-nya, UU ITE Pasal 11
   tetap menuntut sertifikat
2. **Tidak mengikat apa yang dilihat manusia.** `template_version` menangkap
   perubahan template, bukan perubahan *perender*. Upgrade dompdf bisa
   menghasilkan tata letak berbeda dari data yang sama
3. **Tidak ada yang bisa memverifikasinya selain kita.** Adobe Reader membuka
   PDF ber-PAdES dan menyatakan sah. Hash JSON kita hanya bisa diverifikasi
   lewat halaman verify sendiri — dan itu bedanya besar untuk dokumen yang
   dibawa keluar

Jadi **ditambah, bukan diganti**. Yang berubah bukan cara hashing-nya,
melainkan status PDF: dari turunan yang boleh dibuang jadi arsip yang harus
disimpan.

## 2c. Tiga hal yang sama-sama disebut "tanda tangan"

Bagian ini ditulis setelah pertanyaan yang wajar: *"kalau e-Meterai dibubuhkan
setelah coretan TTD kita, bukankah jadi tidak valid?"* Jawabannya tidak — dan
alasannya mengurai hampir semua kebingungan di sekitar topik ini.

| | Apa itu | Statusnya |
|---|---|---|
| **Coretan / QR di modal** | Gambar. Piksel di dalam dokumen | **Isi dokumen**, sama seperti nama dan tanggal |
| **Hash L1/L2** | SHA-256 atas data kanonis | Kriptografis, atas **data** |
| **PAdES (L3)** | Tanda tangan PKI di dalam PDF | Kriptografis, atas **byte PDF** |

Coretan yang digambar pasien **bukan** tanda tangan kriptografis. Karena itu
membubuhkan meterai sesudahnya aman: pada titik itu belum ada apa pun yang
kriptografis untuk dibatalkan. Yang berbahaya hanya mengubah PDF **setelah** ada
tanda tangan PKI di dalamnya.

### Kenapa coretan tetap dipakai walau sudah ada PSrE

Bukan demi kriptografi — demi manusia. Dokumen dengan garis tanda tangan kosong
terlihat belum ditandatangani, walau di dalamnya ada tanda tangan PKI yang sah.
Di PAdES gambar itu jadi *appearance stream*: tampilan di atas tanda tangan
kriptografisnya. Kanvas di modal tidak berubah fungsinya.

### Kenapa record per-slot kita tetap dipakai

PAdES menandatangani **seluruh PDF**, bukan satu slot. Tiga penanda tangan
menghasilkan tiga tanda tangan PAdES yang masing-masing menutupi seluruh
dokumen. PAdES tidak menyatakan *"slot ttd2 milik dr. Budi"* — yang menyatakan
itu record kita.

Jadi bukan tumpang tindih: yang satu menjawab "berkas ini sah dan siapa yang
menandatanganinya", yang satu menjawab "baris tanda tangan ini punya siapa".

### Lebih dari satu tanda tangan

Ini yang membuat urutannya terasa membingungkan, dan jawabannya adalah memilih
model — bukan mengubah cara hashing.

**Model A — semua coretan dulu, sekali sahkan di akhir.** Semua pihak
menandatangani dokumen final yang sama, seperti menandatangani satu lembar
kertas di meja yang sama. Alur yang ada sekarang tidak berubah; cuma bertambah
satu aksi di ujung.

**Model B — berurutan, tiap orang menandatangani keadaan saat itu.** PAdES
mendukungnya lewat *incremental update*: tanda tangan kedua menutupi yang
pertama, bukan menimpanya. Ini model amplop DocuSign. Jauh lebih rumit — tiap
coretan berikutnya harus ditambahkan sebagai incremental update, bukan render
ulang.

**Saran: Model A.** Di loket rumah sakit semua pihak menandatangani di waktu
berdekatan dan memang melihat dokumen yang sama. Model B baru penting kalau
penanda tangannya terpisah hari dan masing-masing harus bisa membuktikan ia
hanya menyetujui keadaan saat itu.

### Jadi apakah langkah kita salah?

Tidak — **belum lengkap**. Kita membekukan *data*; PAdES butuh *berkas* yang
beku. Yang ditambahkan bukan mengganti pembekuan, melainkan menghasilkan
artefak dari keadaan beku itu.

## 3. Urutan yang tidak boleh dibalik: meterai dulu, tanda tangan kemudian

Meterai elektronik dibubuhkan **ke** PDF. Membubuhkannya setelah PDF
ditandatangani berarti mengubah berkas yang sudah ditandatangani — dan itu
membatalkan tanda tangannya.

```
render PDF final  →  bubuhkan e-Meterai  →  tanda tangan PSrE  →  simpan
```

> **Wajib dikonfirmasi ke vendor.** Sebagian penyedia membubuhkan meterai dan
> sekaligus menandatanganinya dalam satu panggilan, sehingga langkah ketiga
> tidak berdiri sendiri. Alur persisnya ikut dokumentasi penyedia yang dipilih,
> bukan asumsi rencana ini. Yang tidak berubah: **meterai tidak pernah setelah
> tanda tangan.**

## 4. Bentuk pengaturannya — satu centang, di tempat biayanya jatuh

Bagian ini ditulis ulang setelah dua koreksi dari diskusi. Versi pertamanya
menaruh pilihan `data-signature-level="1|2|3"` di panel designer. Itu salah dua
kali.

### Kesalahan pertama: istilah dalam kode bocor ke layar orang

"Level 1 / 2 / 3" adalah kosakata internal. Petugas yang membuat template tidak
peduli apa itu Level 2, dan tidak seharusnya perlu peduli. Yang ia tahu cuma
satu hal: dokumen ini perlu diakui hukum atau tidak.

Presedennya jelas. Saat mengirim amplop DocuSign, tidak ada yang memilih
"PAdES B-LT dengan timestamp RFC 3161". Mesin beratnya ada di setelan akun,
sekali diatur, lalu tidak pernah dilihat lagi.

**Dua lapisan, jangan dicampur:**

| Lapisan | Siapa | Isinya |
|---|---|---|
| Template | pembuat template, tiap hari | satu centang: perlu pengesahan hukum atau tidak |
| Pemasangan | admin, sekali saja | penyedia mana, akun meterai mana, negara mana |

Ini yang membuatnya fleksibel lintas negara tanpa template diubah sedikit pun:

| Dipasang di | Penyedianya |
|---|---|
| Indonesia | Peruri / Privy |
| Uni Eropa | penyedia bersertifikat eIDAS |
| Amerika | DocuSign / Adobe |

### Kesalahan kedua: menaruhnya per template, padahal biayanya per tanda tangan

Versi pertama sempat disederhanakan lagi jadi satu centang per template. Itu
juga salah, dan alasannya biaya.

Surat persetujuan tindakan punya tiga tanda tangan: pasien, dokter, saksi. Yang
benar-benar perlu kekuatan hukum mungkin cuma dokternya. Setelan per template
memaksa ketiganya berbayar — bayar tiga untuk kebutuhan satu.

**Tanda tangan tersertifikasi dibayar per tanda tangan. Jadi kontrolnya juga
per tanda tangan.**

```
┌─ Tanda Tangan Pasien ───────────────┐
│  ☐  Butuh pengesahan hukum          │
└─────────────────────────────────────┘

┌─ Tanda Tangan Dokter ───────────────┐
│  ☑  Butuh pengesahan hukum          │   ← ini saja yang berbayar
└─────────────────────────────────────┘
```

Satu kotak centang, di kartu TTD yang sudah ada di panel kanan designer —
sebaris dengan "Verifikasi OTP" dan "Access". Tidak ada dropdown, tidak ada
nama penyedia, tidak ada kata "level".

Dan biayanya langsung terbaca: hitung centangnya.

### Meterai: juga per penanda, bukan per dokumen

Awalnya rencana ini menulis "satu dokumen satu meterai". **Itu tidak cukup
pasti untuk dijadikan aturan di kode.**

Bea meterai dikenakan pada dokumennya, bukan pada tanda tangannya — tapi ada
kasus yang tidak jelas: dokumen rangkap yang tiap lembarnya asli, atau lampiran
yang dihitung dokumen tersendiri. Itu hukum pajak, dan jawabannya milik
konsultan pajak rumah sakit, bukan milik library.

**Untungnya tidak perlu diputuskan di kode.** Template sudah punya
`materai-placeholder`, dan sudah bisa lebih dari satu, masing-masing dengan id
sendiri. Jadi:

> **Berapa meterai = berapa penanda meterai di template itu.**

Yang menentukan pembuat template, berdasarkan nasihat hukumnya. Centangnya
mengikuti pola yang sama:

```
┌─ Meterai 1 ─────────────────────────┐
│  ☑  Pakai meterai resmi (berbayar)  │
│  ☐  Tempel manual (gambar)          │
└─────────────────────────────────────┘
```

### Stamp duty bukan khas Indonesia

Rencana ini sempat menulis meterai sebagai konsep Indonesia. Salah — India
punya e-stamping dan justru sangat ketat untuk perjanjian; Malaysia, Singapura,
dan Inggris punya stamp duty masing-masing.

Jadi antarmukanya memang harus umum: "bubuhkan materai fiskal ke dokumen ini".
Penyedianya yang berbeda per negara. Kalau pemasangan tidak punya penyedia
materai, centangnya tidak muncul sama sekali.

### Biayanya terlihat sejak template dibuat

Di bawah panel designer:

> Dokumen ini akan memakai **2 tanda tangan berbayar** + **1 meterai**

Supaya biayanya diketahui saat merancang, bukan saat tagihan datang.

## 4b. Mekanismenya — apa yang terjadi saat "Sahkan" ditekan

| | Langkah | Berbayar? |
|---|---|---|
| 1 | Periksa semua TTD sudah terisi. Kalau belum, tolak | — |
| 2 | Tampilkan biayanya, minta konfirmasi: *"Akan memakai 2 tanda tangan resmi dan 1 meterai. Sekali dijalankan tidak bisa dibatalkan."* | — |
| 3 | Render PDF final. Dari sini isinya tidak berubah lagi | — |
| 4 | Bubuhkan materai. Dapat PDF ber-materai + nomor seri | **ya** |
| 5 | **Simpan segera** PDF ber-materai + nomor serinya | — |
| 6 | Untuk tiap TTD bercentang: sesi ke penyedia → penyedia kirim kode ke penanda tangan → kode dimasukkan → penyedia menandatangani | **ya** |
| 7 | Simpan PDF final. Semua nomor seri dan waktu masuk riwayat | — |

### Aturan yang tidak boleh dilanggar: jangan pernah kehilangan yang sudah dibayar

Langkah 5 ada justru untuk itu.

Bayangkan meterai sudah terbubuh — uang sudah keluar — lalu penyedia tanda
tangan error. Kalau tidak ada yang disimpan, meterainya hangus dan harus dibeli
lagi. Uang hilang karena kesalahan perangkat lunak.

Dengan langkah 5, kegagalan di langkah 6 cukup diulang dari langkah 6.
Meterainya sudah aman.

Aturannya berlaku umum: **setiap langkah berbayar disimpan begitu selesai,
sebelum langkah berikutnya dimulai.**

## 5. Bentuk integrasinya — dua antarmuka, keduanya netral

Mengikuti pola `UserOtpProvider` dan `ActorDirectory` yang sudah terbukti:
library menyatakan kontraknya, aplikasi pemakai menyediakan implementasinya,
dan tidak ada nama vendor di dalam `src/`.

### `CertifiedSignatureProvider` — sudah setengah ada

`BaseRemoteProvider` + `SignSession` + `OtpChallenge` sudah mendefinisikannya.
Yang kurang cuma isi Peruri/Privy. **Tidak perlu antarmuka baru.**

### `FiscalStampProvider` — baru

Namanya bukan `MeteraiProvider`. Meterai nama Indonesianya; India, Malaysia,
Singapura, dan Inggris punya stamp duty masing-masing dengan nama sendiri.
Library menyebut konsepnya, aplikasi pemakainya menyebut namanya.

```php
interface FiscalStampProvider
{
    /** Sisa kuota, atau null kalau penyedia tidak memberi tahu. */
    public function quota(): ?int;

    /**
     * Bubuhkan satu materai ke PDF. Mengembalikan PDF hasil beserta nomor
     * serinya — nomor itu bukti pembayaran, bukan hiasan.
     */
    public function affix(string $pdfBytes, StampRequest $request): StampResult;
}
```

Nomor serinya wajib disimpan dan muncul di riwayat. Materai yang terbubuh tapi
tidak tercatat sama saja dengan uang yang hilang tanpa jejak — dan itu persis
yang dicegah langkah 5 di §4b.

Kalau pemasangan tidak menyambungkan penyedia apa pun, centang materai tidak
muncul di designer. Bukan disembunyikan karena rusak — memang tidak ada.

## 6. Yang benar-benar menghalangi: bukan kode

| Penghalang | Milik siapa |
|---|---|
| Kontrak + kredensial sandbox PSrE | Rumah sakit. Tanpa ini pemetaan API tidak bisa diisi — dan itu alasan aslinya kenapa Peruri/Privy masih stub |
| Akun distributor e-Meterai + saldo | Rumah sakit. Meterai dibeli di muka, per keping |
| Sertifikat per penanda tangan | Tiap dokter/pasien perlu sertifikat dari PSrE. Ini pendaftaran identitas, bukan konfigurasi |
| Keputusan hukum: dokumen mana perlu meterai | Rumah sakit + penasihat hukumnya |

**Tidak satu pun di antaranya bisa dipercepat dengan menulis kode.** Itu
sebabnya saran saya: mulai urusan kontraknya sekarang, dan kerjakan yang bisa
dikerjakan tanpa menunggu.

## 7. Yang bisa dikerjakan sekarang, tanpa vendor

| | Isi | Butuh vendor? |
|---|---|---|
| **P1** | Tabel `ezdoc_pdf_artifacts` — menyimpan PDF final yang ditandatangani, dengan hash isinya. Tanpa ini tidak ada tempat menaruh hasilnya | Tidak |
| **P2** | Antarmuka `FiscalStampProvider` + implementasi palsu untuk tes, tanpa satu pun nama vendor | Tidak |
| **P3** | Centang per TTD dan per penanda materai di designer + hitungan biaya yang terlihat (§4) | Tidak |
| **P4** | Alur "bekukan lalu tanda tangan": render PDF, simpan byte-nya, hitung hashnya, kunci dokumennya | Tidak |
| **P5** | Tes provider PSrE dengan `HttpClient` palsu — yang hari ini belum ada satu pun | Tidak |
| **P8** | ✅ **Selesai 2026-08-08.** `IncrementalPdfSigner` + `PdfSignaturePlaceholder`: incremental update + tambal `/ByteRange`, klaim PAdES-B-B, dipersempit ke PDF yang baru kita buat sendiri. 18 tes, termasuk ujung ke ujung dengan OpenSSL sungguhan | Tidak |
| **P9** | Sambungkan P8 ke pembekuan: render → `prepare()` → tanda tangani ringkasannya dengan `LocalPkiProvider` → `inject()` → simpan yang sudah bertanda tangan sebagai artefak | Tidak |
| **P6** | Isi pemetaan Peruri/Privy | **Ya** — kredensial sandbox |
| **P7** | Adapter materai di sisi consumer | **Ya** — akun distributor |

**P3 bisa dikerjakan lebih dulu dan berdiri sendiri.** Ia cuma menyimpan niat ke
atribut template; belum ada yang membacanya sampai P4. Sama seperti tahap
*expand* di rencana skema subjek — tidak bisa merusak apa pun.

P1–P5 semuanya di dalam `ezdoc/`, tidak menyentuh vendor, dan tetap berguna
kalau nanti PSrE-nya ternyata bukan Peruri maupun Privy.

## 7b. Yang diambil dari rencana lama (2026-05-04)

`pengeluaran/docs/20260504-tanda-tangan-elektronik-hybrid.md` ditulis untuk
sistem sebelum ezdoc ada. Nama tabelnya, helper-nya, dan identitasnya semua khas
rumah sakit — tapi alasannya banyak yang masih tajam, dan empat hal di sana
memang belum ada pada kita.

### Apakah tanda tangan kita harus pindah ke PDF?

**Menambah, bukan mengganti.** Keduanya menjawab pertanyaan berbeda, dan yang
satu tidak bisa menirukan yang lain:

| | Menjawab | Bisa dilakukan yang lain? |
|---|---|---|
| Tanda tangan data (JCS) kita | "Siapa menyatakan fakta yang mana" — per slot, per field | **Tidak.** PAdES tanda tangan ke-N mencakup byte 0..M, artinya seluruh isi sebelumnya termasuk tanda tangan sebelumnya. Ia rantai, bukan pembagian. Ia tidak bisa menyatakan "dokter menanggung diagnosis, pasien menanggung pernyataan persetujuan" |
| Tanda tangan PAdES ke byte PDF | "Dokumen persis seperti ini, tidak berubah, dan bisa diperiksa siapa pun di Adobe Reader" | **Tidak.** Hash data kita tidak bisa diverifikasi alat luar mana pun |

**Tapi kelemahan kita hari ini bukan hash-nya — melainkan tidak adanya artefak.**
Dokumen dirender ulang dari template setiap kali dibuka. Ubah templatenya, dan
dokumen yang ditandatangani tahun lalu **terlihat** berbeda, walau tanda
tangannya masih terverifikasi. Yang berubah bukan yang ditandatangani, tapi yang
dibaca orang. Itu lubangnya, dan §4.1 rencana lama menamai persis itu.

Membekukan artefak **tidak butuh vendor dan tidak butuh kriptografi baru**:
render sekali, simpan byte-nya, kunci dokumennya. Itu yang paling mendesak.

### Tanda tangan ke dalam berkas PDF tetap perlu, PSrE atau tidak

**Koreksi (2026-08-08).** Bagian ini sebelumnya menulis seolah menandatangani
byte PDF hanya berguna kalau PSrE tersambung, dan menyimpulkan "biar PSrE saja
yang menandatangani". Itu salah, dan menyempitkan masalahnya.

**PSrE itu pilihan, bukan keharusan.** Sebagian besar pemasangan tidak akan
punya kontrak PSrE — dan mereka tetap butuh berkasnya sendiri yang bisa
dibuktikan. Kalau tanda tangannya cuma ada di database kita, maka berkas PDF
yang keluar dari sistem cuma kertas biasa: dibawa ke mana pun, tidak ada yang
bisa memeriksanya tanpa membuka sistem kita.

Yang didapat tanda tangan sendiri (sertifikat lokal / institusi) di dalam PDF,
**tanpa PSrE sama sekali**:

| | Tanpa PSrE | Dengan PSrE |
|---|---|---|
| "Berkas ini berubah tidak sejak ditandatangani?" | **Bisa dijawab.** Ubah satu byte, pembaca PDF mana pun langsung melaporkannya | Sama |
| "Siapa yang menandatangani?" | Tertera, tapi penerbitnya belum tepercaya — Adobe menulis *"Validity unknown"* | Tepercaya sampai ke akar PSrE |
| Perlu kontrak / biaya | Tidak | Ya |

Kolom pertama itu **separuh dari syarat UU ITE Pasal 11(1)** — huruf c dan d,
"perubahan setelah penandatanganan dapat diketahui". Ia berdiri sendiri dan
tidak menunggu siapa pun.

### Yang menghalangi cuma satu langkah: menempelkannya ke berkas

Sertifikatnya bukan penghalang — `LocalPkiProvider` (L2) sudah ada.
`PkcsSevenEnvelope` sudah ada dan sudah diuji. `PdfBytesRange` sudah bisa
membaca maupun menghitung. Yang belum ada cuma langkah **menulis** tanda tangan
itu ke dalam PDF: `OpensslPdfSigner::embedSignature()` masih stub yang melempar.

| Jalur | Caranya | Konsekuensinya |
|---|---|---|
| **A. JSignPdf** | Panggil program Java dari luar PHP | Jalan hari ini, PAdES lengkap sampai B-LT. Tapi menuntut Java terpasang di server — untuk paket Packagist itu syarat yang berat |
| **B. Tanda tangan saat merender** | Perender menyisakan tempat `/Contents` sambil menulis PDF-nya | Tanpa Java. Tapi dompdf tidak bisa; harus ganti perender (TCPDF/mPDF) |
| **C. Tulis sendiri incremental update** | Tambah bagian baru di ujung PDF + tambal `/ByteRange` | Paling banyak kerja, dan alasan stub itu ditulis |
| **D. `ExternalPdfSigner`** | Diserahkan ke aplikasi pemakai / API PSrE | Pintu darurat, bukan jawaban |

**Saran: C, tapi dipersempit.**

Peringatan di stub itu sah untuk kasus umum — menandatangani PDF sembarangan
yang mungkin sudah punya tanda tangan lain, terenkripsi, atau terlinearisasi.
**Kasus kita bukan itu.** Kita menandatangani PDF yang baru saja kita buat
sendiri, sedetik sebelumnya, pada saat pembekuan. Tidak ada tanda tangan
sebelumnya, tidak ada enkripsi, dan strukturnya kita yang tentukan.

Bar-nya juga berbeda. Untuk tanda tangan sendiri, yang harus menerima hasilnya
adalah **pembaca PDF mana pun**, bukan PSrE. Dan itu bisa diuji di dalam repo:
`PdfBytesRange::fromPdf()` + `PkcsSevenEnvelope` sudah ada, jadi keluaran kita
bisa dibongkar ulang dan diperiksa sendiri di test.

Jadi bentuknya: satu penanda tangan in-house yang **menyatakan batasnya
sendiri** — hanya PDF tanpa tanda tangan sebelumnya, dan menolak dengan jelas di
luar itu. Yang diklaim PAdES-B-B (satu tanda tangan, tanpa data pencabutan),
bukan B-LT. `JSignPdfSigner` tetap ada untuk yang butuh B-LT/LTA.

Kalau nanti PSrE tersambung, tanda tangannya menumpuk **di atas** — dan itu
memang gunanya incremental update. PSrE yang melakukannya, bukan kita.

### Kapan dibekukan — otomatis, bukan tombol

Pertanyaan dari pemakaian (2026-08-08): *"kok perlu tombol sahkan? bukannya saat
TTD sudah dianggap sahkan?"*

**Betul, dan bawaannya memang harus otomatis.** Tombol terpisah di jalur biasa
cuma menambah satu langkah yang artinya sudah dilakukan langkah sebelumnya —
dan langkah yang artinya tidak jelas akan ditekan tanpa dibaca.

Aturannya: **dokumen dibekukan begitu slot TTD terakhir terisi.**

| Bentuk dokumen | Yang terjadi |
|---|---|
| Satu slot TTD | Ditandatangani → langsung beku. Tidak ada tombol |
| Beberapa slot | Beku setelah yang terakhir. Yang menandatangani lebih dulu tidak menunggu — field yang ia tanggung sudah beku sejak ia tanda tangan |
| Tanpa slot TTD sama sekali | Tidak ada yang memicu, dan memang tidak perlu: tidak ada pernyataan yang dilindungi |
| Ada meterai / PSrE menyala | **Baru di sini tombolnya perlu** |

Kasus terakhir berbeda karena ia **mengeluarkan biaya**. Meterai dibeli per
keping dan tanda tangan tersertifikasi dibayar per pemakaian. Yang menghabiskan
uang tidak boleh berjalan sebagai akibat samping dari menekan "tanda tangan" —
itu jenis kejutan yang baru ketahuan di tagihan bulan depan.

Jadi "Sahkan" di §4b **bukan** tombol pembekuan. Ia tombol pembelian, dan
pembekuan kebetulan ikut terjadi di dalamnya.

Rencana lama sampai pada tempat yang sama lewat jalan berbeda (§7.1 langkah 4:
"cek apakah semua TTD wajib sudah lengkap; kalau sudah → render, pin").

Satu akibat yang harus disebutkan: menemukan salah ketik **setelah** semua orang
menandatangani berarti mencabut pembekuan dengan alasan tertulis, lalu semua
tanda tangan diulang. Itu terasa berat, dan memang seharusnya — dokumen yang
sudah disetujui banyak orang tidak boleh bisa diubah diam-diam.

### Di mana berkas bekunya disimpan

**Di disk, bukan di tabel.** Ini menjawab keputusan §8 nomor 5.

`ezdoc_blobs` memang bawaannya database, dan itu benar untuk apa yang selama ini
disimpannya: gambar tanda tangan ~3,5 KB, jauh di bawah titik silang ~256 KB.
Dokumen yang sudah dirender ada di **sisi lain** titik silang itu — ratusan KB
sampai beberapa MB.

Karena itu `BlobStoreFactory::forArtifacts()` membalik bawaannya jadi disk.
Mewarisi satu pengaturan untuk dua benda yang berbeda dua orde besaran berarti
salah satunya pasti salah tempat, dan yang salah tempat baru terasa
berbulan-bulan kemudian sebagai tabel yang membengkak.

Yang **tidak** berubah: baris metadatanya tetap di `ezdoc_blobs`. Yang pindah
cuma byte-nya. Identitasnya tetap kunci isi, jadi memeriksa keutuhan tetap
otomatis — dan pindah backend tetap urusan konfigurasi, bukan perubahan skema.

```
EZDOC_ARTIFACT_STORAGE_BACKEND   'disk' (bawaan) | 'database'
EZDOC_ARTIFACT_STORAGE_PATH      path absolut, DI LUAR document root
                                 (jatuh ke EZDOC_STORAGE_PATH kalau kosong)
```

Kalau disk dipilih tapi tidak ada pathnya, pembekuan **ditolak** — bukan
diam-diam jatuh ke database. Jatuh diam-diam menaruh dokumen di tempat yang
tidak diniatkan operator dan tidak ia cadangkan.

### Mengikat keduanya

Artefak beku mencatat daftar tanda tangan data yang dibekukannya beserta
hashnya. Daftar itu ikut ditulis ke dalam PDF sebelum byte-nya di-hash, sehingga
satu pemeriksaan menjawab dua pertanyaan sekaligus: "apakah dokumen ini utuh"
dan "siapa menyatakan apa di dalamnya".

Kunci artefaknya **tidak perlu kolom hash baru** — `ezdoc_blobs` sudah
content-addressed (`key` = SHA-256 isinya), dan `BlobStore::get()` sudah wajib
menolak byte yang tidak cocok dengan kuncinya. Itu lebih kuat daripada "baca
file, hash ulang, bandingkan" di rencana lama.

### Empat yang diambil, berurutan

| | Isi | Butuh vendor? |
|---|---|---|
| **A1** | **State machine terikat penandatanganan.** `ezdoc_documents.status` sudah punya `draft/published/locked/archived`, tapi itu status penerbitan yang digerakkan tombol manual — tidak ada hubungannya dengan tanda tangan. Rencana lama §3 menyambungkannya, dengan angka yang menenangkan: **~90% dokumen tidak akan pernah dibekukan** | Tidak |
| **A2** | **Artefak beku** (= P1 + P4). Render sekali → `ezdoc_blobs` → kunci. Rencana lama §4 sudah matang detailnya: siklus tanda tangan, "berkas tidak pernah dihapus", dan alur cabut → sunting → bekukan ulang jadi artefak baru | Tidak |
| **A3** | **Halaman verifikasi publik** (§7.6 rencana lama). Kita punya mesinnya (`DocumentSignatureVerifier`) dan pembuat QR-nya, tapi **tidak ada halamannya** — QR di dokumen menunjuk ke tempat yang tidak ada. Inilah yang membuat syarat UU ITE 11(1) huruf c dan d bisa dibuktikan **orang luar** | Tidak |
| **A4** | **Saksi** (§5.4). OTP kita membuktikan seseorang memegang HP tertentu. Ia **tidak** membuktikan ada petugas yang menyaksikan. Untuk yang tidak punya HP, tidak bisa memindai, buta huruf, atau tidak sadar — hari ini kita tidak punya jawaban sama sekali. Presedennya bukan cuma lokal: *In-Person Signing* DocuSign berbentuk sama persis, "host" terautentikasi menyatakan menyaksikan | Tidak |

A4 menuntut satu hal kecil lebih dulu: **jenis pihak penanda tangan**. RBAC kita
menjawab "siapa yang boleh", bukan "ini pihak macam apa". Bentuk netralnya
`account | delegated | external | witness` — sejajar dengan tipe penerima
DocuSign, bukan `internal_auth`/`external_patient` yang khas rumah sakit.

### Yang sengaja TIDAK diambil

| | Kenapa |
|---|---|
| Foto wajah (Layer 2 rencana lama) | Biometrik = data pribadi spesifik (UU PDP Pasal 4(2) huruf d, GDPR Pasal 9). Beban kepatuhannya berat untuk library umum. Sediakan titik sambungnya, biar aplikasi pemakai yang memutuskan |
| Tata letak `/storage/signed_pdfs/YYYY/MM/` | `ezdoc_blobs` sudah lebih baik: content-addressed, dua backend, dan kuncinya sudah checksum-nya |
| Sidecar JSON | Sebagian tumpang tindih dengan log audit. Alasannya ("kalau DB rusak") masih sah, tapi cukup jadi metadata di artefaknya |
| `ENUM` di mana-mana | Harus jalan di lima grammar |
| `hasRole()`, tabel `pegawai`, NORM, `surat_dokumen_v2` | Khas rumah sakit |

### Urutan pembangunan yang diperbarui

```
A1 state turunan ──► A2 artefak beku ──► P8 tanda tangan ──► A3 halaman
     (murah,           (P1+P4,             sendiri ke PDF      verifikasi
   bingkai A2)       tanpa vendor)         (tanpa vendor)     (butuh A2)
                                                 │
                                                 ▼
                                       PSrE menumpuk di atas      A4 saksi
                                        (butuh vendor, P6)    (berdiri sendiri)
```

P8 sengaja **sebelum** PSrE, bukan sesudah. Ia tidak menunggu kontrak siapa pun,
dan ia yang membuat berkas keluaran berdiri sendiri sebagai bukti. PSrE
menambahkan kepercayaan pada identitasnya — bukan menambahkan keutuhannya.

## 8. Yang perlu diputuskan

| | Pertanyaan | Kenapa perlu sekarang |
|---|---|---|
| 1 | PSrE mana? Peruri, Privy, VIDA, Digisign, atau belum ada | Menentukan pemetaan mana yang diisi lebih dulu. Kalau belum ada, P1–P5 tetap jalan |
| 2 | Distributor e-Meterai mana, dan sudah ada saldo? | Meterai dibeli di muka |
| 3 | Dokumen mana yang perlu materai, dan **berapa** per dokumen? | Keputusan hukum, bukan teknis. Dokumen rangkap dan lampiran termasuk yang tidak jelas — tanyakan ke konsultan pajak. Kode tidak memaku jumlahnya: yang menentukan berapa penanda materai di templatenya |
| 3b | Kalau centangnya menyala tapi penyedianya belum tersambung, apa yang terjadi? | Saran: dokumen tetap bisa diisi dan ditandatangani biasa, diberi tanda **"belum disahkan"**, lalu bisa disahkan begitu penyedianya siap. Yang **tidak boleh**: sistem diam saja seolah sudah sah — petugas mengira dokumennya berkekuatan hukum padahal tidak, dan baru ketahuan saat dibawa ke pengadilan |
| 4 | Sertifikat penanda tangan: per dokter, atau satu sertifikat institusi? | Mengubah bentuk pendaftarannya sepenuhnya. Sertifikat institusi jauh lebih sederhana, tapi tanda tangannya jadi "RSIA Anugrah", bukan "dr. X" |
| ~~5~~ | ~~PDF yang ditandatangani disimpan di mana?~~ | **Sudah dijawab 2026-08-08**: `ezdoc_blobs`, backend disk. Lihat §7b |

## 9. Yang TIDAK termasuk

- Mengganti L1/L2. Keduanya menjawab pertanyaan berbeda dan tetap dipakai
- Memutuskan dokumen mana yang perlu meterai. Library menyediakan tempatnya,
  bukan jawabannya
- Menjanjikan keabsahan hukum. Yang bisa dijamin library: tanda tangannya dibuat
  dengan sertifikat dari PSrE terdaftar dan terverifikasi. Apakah dokumennya sah
  di pengadilan bergantung pada hal-hal di luar perangkat lunak
