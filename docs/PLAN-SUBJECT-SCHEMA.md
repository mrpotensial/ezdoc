# Rencana — `norm`/`nopen` jadi identitas subjek yang generik

**Status:** rencana, belum dikerjakan
**Ditulis:** 2026-08-04
**Menjawab:** PRD §3.9c dan §10.1 butir 0a

## 1. Keputusannya

Skema dokumen menyebut `norm` (nomor rekam medis) dan `nopen` (nomor
pendaftaran) — dua nama yang tidak berarti apa-apa di luar rumah sakit, di dalam
paket yang visinya sendiri berbunyi:

> **Domain-agnostic** — bukan hospital-only. … **International-first** — no
> locale/lang lock-in, no Indonesian-specific field name di core schema.
> — PRD §1

Diganti jadi identitas subjek yang generik.

### Kenapa sekarang, bukan nanti

Dari pekerjaan yang antre, ini satu-satunya yang **makin mahal tiap minggu**.

Selama ezdoc belum terbit di Packagist, ini satu migrasi milik sendiri. Setelah
terbit, ia jadi *breaking change* untuk setiap pemasang — major version bump,
dan mereka semua harus bermigrasi. Sementara PSrE tidak jadi lebih sulit kalau
dikerjakan bulan depan.

## 2. Bentuknya: tiga kolom, bukan dua

Godaan pertamanya adalah memeras keduanya jadi satu `subject_id`. Itu salah:
`norm` dan `nopen` bukan satu hal.

- `norm` = **siapa** — pasiennya, bertahan lintas kunjungan
- `nopen` = **kunjungan mana** — episodenya

Diperas jadi satu, pertanyaan "semua dokumen pasien ini di semua kunjungan"
tidak bisa dijawab lagi.

| Kolom baru | Menggantikan | Isi |
|---|---|---|
| `subject_type` | — (baru) | Jenis subjeknya: `patient`, `employee`, `student`, … |
| `subject_id` | `norm` | Identitas subjek di sistem pemakainya |
| `context_id` | `nopen` | Episode/konteksnya |

| Domain | `subject_type` | `subject_id` | `context_id` |
|---|---|---|---|
| Rumah sakit | `patient` | no. rekam medis | no. pendaftaran |
| HR | `employee` | NIP | nomor SK |
| Pendidikan | `student` | NIM | tahun ajaran |
| Legal | `party` | client id | nomor kontrak |
| Keuangan | `customer` | customer id | nomor invoice |
| Logistik | `shipment` | nomor BL/AWB | nomor batch |

### Presedennya

**FHIR `DocumentReference`** punya `subject` (Patient) dan `context.encounter`
(Encounter) — persis pasangan ini, dan itu standar kesehatan internasional.
Nama `context` diambil dari sana. Bentuk `type` + `id` sendiri adalah asosiasi
polimorfik: `morphTo` di Eloquent, asosiasi polimorfik Rails, kolom
diskriminator Doctrine.

### Yang sengaja TIDAK ditambahkan

**`context_type`.** Di dalam satu `subject_type`, arti konteksnya sudah tetap —
pasien punya kunjungan, pegawai punya periode SK. Menambahkannya memberi
keluwesan yang belum diminta siapa pun dan melebarkan indeks unik. Ditunda
sampai ada kasus nyata.

### Nullability

`norm`/`nopen` sekarang boleh NULL — itulah yang membuat dokumen "lingkup umum"
(tanpa pasien) bisa ada. Kolom barunya ikut boleh NULL, dan NULL tetap dianggap
berbeda di indeks unik MySQL. Sifat itu yang dipakai `uk_slot_version` sekarang,
jadi mengubahnya akan diam-diam mengubah arti keunikan.

`subject_type` juga NULL-able, **tanpa nilai bawaan**. Memberi bawaan `'patient'`
berarti memaku rumah sakit ke dalam skema library — persis yang sedang
dihilangkan. Pengisian `'patient'` untuk baris lama adalah **data**, bukan
bawaan skema.

## 3. Kunci unik yang berubah

```sql
-- sekarang
UNIQUE KEY uk_slot_version (template_uuid, norm, nopen, label, version)

-- nanti
UNIQUE KEY uk_slot_version (template_uuid, subject_type, subject_id, context_id, label, version)
```

`subject_type` ikut masuk karena pasien #1 dan pegawai #1 bukan orang yang sama.

## 4. Yang sudah diperiksa aman

Diperiksa sebelum rencana ini ditulis, bukan diasumsikan.

| Yang diperiksa | Hasil |
|---|---|
| Payload tanda tangan memuat `norm`/`nopen`? | **Tidak.** Isinya `document_uuid`, `template_uuid`, `template_version`, `fields`, `ttd_id`, `ttd_value`, `covered_fields`. Identitasnya bersandar pada `document_uuid` yang stabil — **tidak ada tanda tangan lama yang batal** |
| Verifikasi publik / QR memakainya? | **Tidak.** `DocumentSignatureVerifier` dan `doc_verify_helpers.php` bersih |
| Provider PSrE menyentuhnya? | **Tidak.** ~3.000 baris (Peruri, Privy, BaseRemoteProvider, SignSession) — satu-satunya kecocokan "subject" adalah `subject_cn` sertifikat X.509, hal yang berbeda |
| Halaman consumer? | **Nol pemakaian** di `page/ezdoc*.php` — semuanya mount tipis |

Penghalang terbesar yang dibayangkan — tanda tangan lama jadi tidak sah —
ternyata tidak ada.

## 5. Cakupan

| Tempat | Pemakaian | Berkas |
|---|---|---|
| `src/` + `lib/` + `actions/` | 73 | 13 |
| `views/` | 68 | 3 (`generate.php` 30, `generate_scripts.php` 34, `designer_scripts.php` 4) |
| Migrasi | — | `ezdoc_documents` MySQL + SQLite + blueprint |
| Consumer | 0 | — |

Yang terpadat: `src/Document/Document.php` (12), `TemplateParser.php` (11),
`actions/document/delete_version.php` (11), `DocumentRepository.php` (8).

## 6. Langkahnya — expand/contract

Pola *parallel change* (Sam Newman). Tidak ada momen aplikasi harus berhenti,
dan setiap langkah bisa dihentikan tanpa merusak langkah sebelumnya.

| | Langkah | Bisa dibalik? |
|---|---|---|
| **E1** | Tambah `subject_type`, `subject_id`, `context_id` berdampingan, NULL-able. Belum ada yang membacanya | Ya — kolom kosong tidak mengganggu apa pun |
| **E2** | Isi dari yang lama: `subject_id = norm`, `context_id = nopen`, `subject_type = 'patient'` | Ya |
| **E3** | Tulis ke **keduanya**. Baca masih dari yang lama | Ya |
| **E4** | Indeks unik baru ditambahkan berdampingan dengan yang lama | Ya |
| **C1** | Pindahkan **baca** ke kolom baru, termasuk `views/` dan parameter URL | Ya, selama kolom lama masih terisi |
| **C2** | Buang `uk_slot_version` lama, lalu kolom `norm`/`nopen` | **Tidak** — titik tanpa kembali |

E1–E4 aman dikerjakan kapan saja, bahkan sebelum PSrE. C2 sebaiknya menunggu
satu-dua minggu setelah C1 supaya kalau ada yang terlewat masih ketahuan selagi
datanya utuh.

## 7. Parameter URL: `norm=` dan `nopen=` tetap diterima

Tautan yang sudah tersimpan orang tidak boleh mati. Parameter lama diterima
sebagai alias:

```
?subject_id=1&context_id=1     ← bentuk baru
?norm=1&nopen=1                ← tetap jalan, dipetakan ke yang baru
```

Alias-nya murah dan menghapus hampir seluruh rasa sakitnya. Dibuang nanti di
major version, bukan sekarang.

## 8. Dari mana `subject_type` datang saat berjalan

Ini satu-satunya bagian yang benar-benar baru, bukan penggantian nama.

| Pilihan | |
|---|---|
| **A. Atribut di template** | `data-subject-type="patient"` di templatenya. Templatenya memang tahu ia untuk siapa |
| **B. Konstanta konfigurasi** | `EZDOC_DEFAULT_SUBJECT_TYPE`, satu nilai untuk seluruh pemasangan |
| **C. Dari parameter permintaan** | Ikut `?subject_type=` |

Saran: **A dengan B sebagai cadangan.** Template surat persetujuan pasien dan
template SK karyawan bisa hidup di pemasangan yang sama, jadi satu nilai global
tidak cukup; tapi tanpa bawaan, setiap template lama harus disunting sebelum
apa pun berjalan. C ditolak — nilai yang menentukan keunikan slot tidak boleh
datang dari permintaan.

## 9. Yang perlu diputuskan

| | Pertanyaan | Saran |
|---|---|---|
| 1 | Nama `context_id`, atau yang lain (`scope_id`, `occurrence_id`)? | `context_id` — mengikuti pembungkus `context` di FHIR |
| 2 | Dari mana `subject_type` datang? | Atribut template, dengan konstanta sebagai cadangan (§8) |
| 3 | Kapan C2 (buang kolom lama) dijalankan? | Satu–dua minggu setelah C1, bukan di hari yang sama |
| 4 | `context_type` ikut sekarang? | Tidak. Ditunda sampai ada kasus nyata |

## 10. Yang TIDAK termasuk

- Mengubah apa pun di lapisan tanda tangan. Payload, hash, dan verifikasi tidak
  disentuh — sudah diperiksa tidak memuat `norm`/`nopen`
- Menyatukan kolom generik lama yang masih tersisa di migrasi SQLite
  (`reference_number`, `rendered_html`, `owner_id`). Itu sisa desain lama;
  dibereskan terpisah supaya rencana ini tetap satu perubahan yang bisa dibaca
