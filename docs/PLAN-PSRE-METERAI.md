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

## 4. Kapan dokumen perlu meterai, dan siapa yang memutuskan

Bea Meterai diatur UU No. 10/2020 dan PP No. 86/2021, tarif Rp10.000. Yang
dikenai antara lain dokumen yang dipakai sebagai alat bukti dan dokumen bernilai
di atas ambang tertentu.

**Library tidak boleh memutuskan ini.** Apakah sebuah surat persetujuan tindakan
perlu meterai adalah pertanyaan hukum milik rumah sakitnya, bukan pertanyaan
teknis. Yang disediakan library: tempat menyatakan keputusannya, per template.

Sama untuk tingkat tanda tangan — tidak semua dokumen butuh L3. Kandidat
bentuknya, mengikuti pola atribut yang sudah dipakai `data-require-otp`:

```html
<div class="ttd-placeholder"
     data-ttd="ttd1"
     data-signature-level="3"      <!-- 1 | 2 | 3 -->
     data-require-meterai="1">
```

## 5. Bentuk integrasinya — dua antarmuka, keduanya netral

Mengikuti pola `UserOtpProvider` dan `ActorDirectory` yang sudah terbukti:
library menyatakan kontraknya, aplikasi pemakai menyediakan implementasinya,
dan tidak ada nama vendor di dalam `src/`.

### `CertifiedSignatureProvider` — sudah setengah ada

`BaseRemoteProvider` + `SignSession` + `OtpChallenge` sudah mendefinisikannya.
Yang kurang cuma isi Peruri/Privy. **Tidak perlu antarmuka baru.**

### `MeteraiProvider` — baru

```php
interface MeteraiProvider
{
    /** Sisa kuota, atau null kalau penyedia tidak memberi tahu. */
    public function quota(): ?int;

    /**
     * Bubuhkan satu meterai ke PDF. Mengembalikan PDF hasil beserta nomor seri
     * meterainya — nomor itu bukti yang harus tersimpan, bukan hiasan.
     */
    public function affix(string $pdfBytes, MeteraiRequest $request): MeteraiResult;
}
```

Nomor seri meterai wajib disimpan dan muncul di riwayat. Meterai yang terbubuh
tapi tidak tercatat sama saja dengan Rp10.000 yang hilang tanpa jejak.

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
| **P2** | Antarmuka `MeteraiProvider` + `FakeMeteraiProvider` untuk tes, tanpa satu pun nama vendor | Tidak |
| **P3** | Atribut per-template: `data-signature-level`, `data-require-meterai`, plus penegakannya saat menyimpan | Tidak |
| **P4** | Alur "bekukan lalu tanda tangan": render PDF, simpan byte-nya, hitung hashnya, kunci dokumennya | Tidak |
| **P5** | Tes provider PSrE dengan `HttpClient` palsu — yang hari ini belum ada satu pun | Tidak |
| **P6** | Isi pemetaan Peruri/Privy | **Ya** — kredensial sandbox |
| **P7** | Adapter e-Meterai di sisi consumer | **Ya** — akun distributor |

P1–P5 semuanya di dalam `ezdoc/`, tidak menyentuh vendor, dan tetap berguna
kalau nanti PSrE-nya ternyata bukan Peruri maupun Privy.

## 8. Yang perlu diputuskan

| | Pertanyaan | Kenapa perlu sekarang |
|---|---|---|
| 1 | PSrE mana? Peruri, Privy, VIDA, Digisign, atau belum ada | Menentukan pemetaan mana yang diisi lebih dulu. Kalau belum ada, P1–P5 tetap jalan |
| 2 | Distributor e-Meterai mana, dan sudah ada saldo? | Meterai dibeli di muka |
| 3 | Dokumen mana yang perlu meterai? | Keputusan hukum, bukan teknis. Tanpa ini P3 tidak punya isi |
| 4 | Sertifikat penanda tangan: per dokter, atau satu sertifikat institusi? | Mengubah bentuk pendaftarannya sepenuhnya. Sertifikat institusi jauh lebih sederhana, tapi tanda tangannya jadi "RSIA Anugrah", bukan "dr. X" |
| 5 | PDF yang ditandatangani disimpan di mana? | `ezdoc_blobs` sudah ada dan sudah punya dua backend — kandidat kuat, bukan tabel baru |

## 9. Yang TIDAK termasuk

- Mengganti L1/L2. Keduanya menjawab pertanyaan berbeda dan tetap dipakai
- Memutuskan dokumen mana yang perlu meterai. Library menyediakan tempatnya,
  bukan jawabannya
- Menjanjikan keabsahan hukum. Yang bisa dijamin library: tanda tangannya dibuat
  dengan sertifikat dari PSrE terdaftar dan terverifikasi. Apakah dokumennya sah
  di pengadilan bergantung pada hal-hal di luar perangkat lunak
