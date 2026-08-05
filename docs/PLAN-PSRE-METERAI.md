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
| **P6** | Isi pemetaan Peruri/Privy | **Ya** — kredensial sandbox |
| **P7** | Adapter materai di sisi consumer | **Ya** — akun distributor |

**P3 bisa dikerjakan lebih dulu dan berdiri sendiri.** Ia cuma menyimpan niat ke
atribut template; belum ada yang membacanya sampai P4. Sama seperti tahap
*expand* di rencana skema subjek — tidak bisa merusak apa pun.

P1–P5 semuanya di dalam `ezdoc/`, tidak menyentuh vendor, dan tetap berguna
kalau nanti PSrE-nya ternyata bukan Peruri maupun Privy.

## 8. Yang perlu diputuskan

| | Pertanyaan | Kenapa perlu sekarang |
|---|---|---|
| 1 | PSrE mana? Peruri, Privy, VIDA, Digisign, atau belum ada | Menentukan pemetaan mana yang diisi lebih dulu. Kalau belum ada, P1–P5 tetap jalan |
| 2 | Distributor e-Meterai mana, dan sudah ada saldo? | Meterai dibeli di muka |
| 3 | Dokumen mana yang perlu materai, dan **berapa** per dokumen? | Keputusan hukum, bukan teknis. Dokumen rangkap dan lampiran termasuk yang tidak jelas — tanyakan ke konsultan pajak. Kode tidak memaku jumlahnya: yang menentukan berapa penanda materai di templatenya |
| 3b | Kalau centangnya menyala tapi penyedianya belum tersambung, apa yang terjadi? | Saran: dokumen tetap bisa diisi dan ditandatangani biasa, diberi tanda **"belum disahkan"**, lalu bisa disahkan begitu penyedianya siap. Yang **tidak boleh**: sistem diam saja seolah sudah sah — petugas mengira dokumennya berkekuatan hukum padahal tidak, dan baru ketahuan saat dibawa ke pengadilan |
| 4 | Sertifikat penanda tangan: per dokter, atau satu sertifikat institusi? | Mengubah bentuk pendaftarannya sepenuhnya. Sertifikat institusi jauh lebih sederhana, tapi tanda tangannya jadi "RSIA Anugrah", bukan "dr. X" |
| 5 | PDF yang ditandatangani disimpan di mana? | `ezdoc_blobs` sudah ada dan sudah punya dua backend — kandidat kuat, bukan tabel baru |

## 9. Yang TIDAK termasuk

- Mengganti L1/L2. Keduanya menjawab pertanyaan berbeda dan tetap dipakai
- Memutuskan dokumen mana yang perlu meterai. Library menyediakan tempatnya,
  bukan jawabannya
- Menjanjikan keabsahan hukum. Yang bisa dijamin library: tanda tangannya dibuat
  dengan sertifikat dari PSrE terdaftar dan terverifikasi. Apakah dokumennya sah
  di pengadilan bergantung pada hal-hal di luar perangkat lunak
