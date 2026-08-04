# Rencana — identitas siapa yang terlihat oleh siapa

**Status:** rencana, belum dikerjakan
**Ditulis:** 2026-08-04

## 1. Apa yang terbuka sekarang

Tiga tempat, tingkat bahayanya berbeda jauh.

| | Di mana | Isinya | Terlihat oleh |
|---|---|---|---|
| 1 | `views/document/generate.php:3167` — `window.EZDOC_SIGNER` | id + daftar role **milikmu sendiri** | dirimu sendiri |
| 2 | Panel riwayat — `renderHistoryEntry()` baris 1192 & 1211 | id, daftar role, **alamat IP** milik *semua* pelaku di slot itu | siapa pun dengan hak `view` di template |
| 3 | `ezdoc_signatures.signer_id` / `signer_role` | identitas penanda tangan | tersimpan; muncul lewat (2) |

**Nomor 2 yang serius.** Perawat yang boleh melihat dokumen dari sebuah template
bisa melihat alamat IP dan daftar role setiap dokter yang pernah menandatangani
di slot itu. Dokter yang menandatangani dari rumah membocorkan IP rumahnya ke
seluruh staf yang punya akses baca.

**Nomor 3 bukan masalah, dan tidak boleh diubah.** Record audit tanpa identitas
bukan record audit.

Itu pemisahannya:

> **Menyimpan itu benar. Yang perlu diatur adalah siapa boleh membaca field
> yang mana.**

## 2. Standar industrinya

| Sistem | Yang ditampilkan | Ke siapa |
|---|---|---|
| DocuSign Certificate of Completion | Nama, email, **IP** | Pihak dalam amplop itu, di dokumen bukti formal |
| Google Docs activity | Nama tampilan | Siapa pun yang punya akses |
| Confluence page history | Nama tampilan | Siapa pun yang punya akses |
| GitHub audit log | Username + **IP** | **Pemilik organisasi saja** |
| Atlassian org audit | Nama + email + **IP** | Admin saja |
| AWS CloudTrail | Principal penuh + **IP** | Butuh izin IAM eksplisit |

Dua pola yang konsisten:

**Manusia dapat nama, bukan nomor.** Tidak ada satu pun yang menampilkan id
internal di antarmuka. Nomor itu detail implementasi — tidak berguna buat
pembacanya, dan ia membocorkan bentuk serta ukuran tabel pengguna.

**IP selalu di balik gerbang yang lebih ketat daripada "bisa melihat
dokumen".** DocuSign menaruhnya di sertifikat bukti formal; GitHub membatasinya
ke pemilik organisasi. Tidak ada yang menampilkannya di feed aktivitas biasa.
Alasannya bukan selera: IP adalah data pribadi — GDPR Pasal 4 dan Resital 30
menyebutnya "pengenal daring", dan UU PDP No. 27/2022 memperlakukannya serupa.

Daftar role lebih ringan tapi tetap bermasalah: ia menunjukkan siapa
superadmin-nya, dan itu memilihkan target buat orang yang mau menipu lewat
telepon.

**Apakah aman sekarang?** Tidak ada celah yang bisa langsung dieksploitasi —
semua pembacanya staf yang sudah login. Tapi ini kelebihan pengungkapan:
endpoint memberi lebih dari yang dibutuhkan pembacanya, dan satu field di
antaranya data pribadi.

## 3. A — dua tingkat di endpoint riwayat

Disaring **di server**, di `actions/document/document_history.php`. Bukan
disembunyikan di JS: yang bocor itu JSON-nya, bukan tampilannya.

| Tingkat | Syarat | Melihat |
|---|---|---|
| Biasa | hak `view` di template (seperti sekarang) | siapa, kapan, apa |
| Audit | role di `EZDOC_AUDIT_DETAIL_ROLES` | ditambah **IP** dan **daftar role** |

Konstanta baru:

```php
// Default: ikut role manajer template. Tidak perlu konfigurasi baru untuk aman.
if (!defined('EZDOC_AUDIT_DETAIL_ROLES')) {
    define('EZDOC_AUDIT_DETAIL_ROLES', EZDOC_TEMPLATE_MANAGER_ROLES);
}
```

### Tidak ada nama role yang dipaku di library

Penting, karena tidak semua aplikasi pemakai punya role bernama `superadmin` —
sebagian bahkan tidak memakai role sama sekali.

**Tingkat 1 — library tidak menyebut nama apa pun.** Bawaannya kosong. Yang
memutuskan nama role adalah aplikasi pemakainya, lewat konstanta di atas.
Bentuknya sama persis dengan `EZDOC_TEMPLATE_MANAGER_ROLES` dan
`EZDOC_OTP_OVERRIDE_ROLES` yang sudah ada — jadi bukan pola baru, cuma knob
ketiga dengan bentuk yang sama.

**Tingkat 2 — array atau JSON, keduanya diterima.** Mengikuti
`ezdoc_template_manager_roles()` yang sudah menangani keduanya, jadi
`define(..., ['superadmin'])` maupun `define(..., json_encode(['superadmin']))`
sama-sama jalan.

**Tingkat 3 — aplikasi yang tidak berbasis role sekalipun tetap terlayani.**
`ezdoc_set_role_provider()` sudah ada: aplikasi pemakai bisa mengganti seluruh
penentu role. Kalau otorisasinya berbasis izin (`audit.view_ip`) atau atribut,
mereka menyetel `EZDOC_AUDIT_DETAIL_ROLES` ke nama izin itu dan provider mereka
yang menerjemahkan `hasRole('audit.view_ip')` jadi pemeriksaan izin.

Jadi library tidak pernah tahu — dan tidak perlu tahu — apa arti nama itu di
sistem pemakainya.

> **Ini mengubah perilaku, dan perubahannya disengaja.** `config.php:73`
> menyetel `EZDOC_TEMPLATE_MANAGER_ROLES` ke `[]` secara bawaan. Artinya
> selama belum dikonfigurasi, **tidak ada yang melihat IP** — termasuk
> superadmin. Itu arah gagal yang benar untuk kontrol privasi, tapi kalian
> harus tahu: setelah ini diterapkan, kolom IP hilang dari panel riwayat sampai
> `EZDOC_TEMPLATE_MANAGER_ROLES` (atau `EZDOC_AUDIT_DETAIL_ROLES`) diisi,
> misalnya `json_encode(['superadmin'])`.

Field yang tidak boleh dilihat **dihapus dari respons**, bukan dikosongkan —
kunci yang ada tapi kosong tetap memberi tahu bahwa ada sesuatu di sana.

## 4. B — nama, bukan nomor

Library tidak boleh tahu tabel `pegawai` milik aplikasi pemakainya. Jadi satu
antarmuka kecil, disambung lewat `Context` persis seperti `otpProvider`:

```php
namespace Ezdoc\Auth;

interface ActorDirectory
{
    /**
     * @param list<int> $ids
     * @return array<int,string> id => nama tampilan. Id yang tidak dikenal
     *         boleh dihilangkan; pemanggil yang memutuskan tampilan penggantinya.
     */
    public function displayNames(array $ids): array;
}
```

**Sekaligus banyak id, bukan satu per satu.** Riwayat 60 baris jangan sampai
jadi 60 query — itu masalah N+1 yang sama yang diselesaikan eager loading di
Doctrine dan Laravel.

Penyambungannya mengikuti pola `otpProvider` yang sudah ada di
`Context::fromGlobals()`:

1. `$GLOBALS['EZDOC_ACTOR_DIRECTORY']` — instance yang sudah jadi
2. `EZDOC_ACTOR_DIRECTORY_CLASS::fromGlobals()` — pabrik statis
3. `new EZDOC_ACTOR_DIRECTORY_CLASS()` — konstruktor tanpa argumen

Untuk aplikasi ini, penyambungnya beberapa baris di berkas hook yang sudah ada:

```sql
SELECT id_pegawai, nama_pegawai FROM pegawai WHERE id_pegawai IN (…)
```

**Kalau tidak disambung**, tampilannya jatuh ke `#18` seperti sekarang. Riwayat
tanpa pelaku sama sekali tidak ada gunanya, jadi jatuhnya harus ke sesuatu.

Catatan: record tanda tangan sudah punya `signer_name` di metadata (diambil di
modal tanda tangan), jadi baris "Ditandatangani" sudah menampilkan nama manusia
hari ini. Directory memperbaiki **jenis kejadian yang lain**.

## 5. C — `EZDOC_SIGNER` ikut memakai nama

Konfirmasi mode QR sekarang berbunyi "Menandatangani sebagai #18 ·
user,superadmin". Jadi "Menandatangani sebagai Budi Santoso".

Role dibuang dari sana — tidak ada gunanya untuk konfirmasi, dan ia menaruh
daftar role di HTML setiap halaman generate.

Kalau directory tidak tersambung, tetap `#18`. Yang **tidak** berubah: saat
identitasnya kosong, tulisannya tetap "tidak ada identitas — periksa
konfigurasi akses". Menandatangani sebagai "0" adalah masalah yang harus
terlihat sebelum ditandatangani.

## 6. Aman dan tidak bentrok — hasil pemeriksaan

Semua diperiksa sebelum rencana ini ditulis.

| Yang diperiksa | Hasil |
|---|---|
| `Context::__construct` tambah parameter ke-5 | **Aman.** Parameternya opsional; keempat pemanggil yang ada (`ContextTest` ×2, `LoggerTest`, `IssueVoucherHelperTest`) semuanya pakai 2 argumen |
| Nama konstanta `EZDOC_AUDIT_DETAIL_ROLES` | **Tidak bentrok.** 36 konstanta `EZDOC_*` yang ada sudah dicek; tidak ada yang mirip. `EZDOC_LOG_ACCESS_DEFAULT` yang namanya mirip itu soal lain — mencatat keputusan akses-bawaan, bukan akses ke log |
| Konsumen field `actor`/`roles`/`ip` | **Satu saja** — `generate_scripts.php` baris 1192 dan 1211. Tidak ada pemanggil lain |
| Tes yang bergantung pada bentuk respons riwayat | **Tidak ada.** `LoggerTest` menguji penulisan, bukan pembacaan; `ViewFunctionScopeTest` cuma memeriksa fungsinya ada |
| `renderHistoryEntry` punya jaring pengaman? | **Ya** — harness node yang menjalankan fungsi aslinya (dipakai sejak perbaikan `fmtDate`) |
| `EZDOC_SIGNER` dipakai di mana lagi | **Satu tempat** — `generate_scripts.php:1620`, konfirmasi mode QR |
| Apakah menyentuh alur tanda tangan / hash | **Tidak.** Yang berubah hanya tampilan dan penyaringan respons. `ezdoc_signatures` tidak disentuh, payload hash tidak disentuh |

Satu-satunya perubahan perilaku yang perlu diketahui adalah default
fail-closed di §3 — kolom IP hilang sampai role audit dikonfigurasi.

## 7. Temuan terpisah: `ezdoc/views/` berisi salinan seluruh proyek

Ketemu saat memeriksa bentrokan. Bukan bagian dari rencana ini, tapi perlu
dicatat.

### Bedakan dulu: mana yang asli, mana yang salinan

`ezdoc/views/` **memang folder yang benar dan dipakai**. `src/Http/Router.php`
memuat `views/document/generate.php` (baris 444) dan `views/layout.php` (baris
633). Yang asli dan wajib tetap ada:

```
views/_partials/   views/document/   views/admin/   views/layout.php
```

Yang **bukan** berkas tampilan, dan seharusnya tidak ada di sana:

```
views/src/  views/tests/  views/actions/  views/migrations/  views/lib/
views/cli/  views/public/ views/lang/     views/ezdoc-spec/  views/config/
views/views/  views/composer.json  views/bootstrap.php  views/autoload.php
views/phpunit.xml  views/phpstan.neon  …
```

Dari 253 berkas PHP di bawah `views/`, **199 adalah salinan**.

### Dari mana asalnya

Dua kejadian, jejaknya masih terlihat:

1. **`cli/publish.php all` diarahkan ke `ezdoc/views`.** `PublishCommand::publishAll()`
   membuat `$target/views`, `$target/assets`, dan `$target/config`. Dan memang
   ada `views/views/` dan `views/config/ezdoc.example.php` — persis bentuk itu.
   Perintah itu gunanya menyalin tampilan ezdoc ke folder aplikasi pemakai
   (mis. `resources/views/ezdoc` di Laravel) supaya bisa dimodifikasi; ia tidak
   dimaksudkan untuk diarahkan ke dalam ezdoc sendiri.
2. **Salinan seluruh akar proyek.** Itu yang menyeret `src/`, `tests/`,
   `migrations/`, `composer.json`. Bukan hasil `publish.php` — perintah itu
   tidak pernah menyalin `src/`.

**Bukan langkah otomatis.** Tidak ada build step yang menjalankannya; keduanya
sekali jalan lalu ikut ter-commit. Jadi kalau dihapus, ia tidak akan kembali
sendiri.

### Mana yang asli — dicek, bukan ditebak

`views/views/` gampang disangka aslinya: nama berkas dan strukturnya persis
sama. Bukan.

| | `views/document/generate.php` | `views/views/document/generate.php` |
|---|---|---|
| Ukuran | 177 KB | 145 KB |
| Terakhir diubah | 2026-08-04 08:58 | 2026-07-29 15:55 |
| Dimuat `Router.php:444` | ✔ | — |

Yang paling jelas: salinan itu tidak punya satu pun pekerjaan setelah 29 Juli.
`signOtpStepVoucher`, `applySignToken`, `claimTtdVoucher`, `loadDocHistory`,
`renderHistoryEntry` — semuanya **0 kemunculan** di `views/views/`, padahal ada
di `views/`. Panel riwayat dibuat sebelum jalur OTP kedua, jadi salinan itu foto
keadaan sebelum panel riwayat pun ada.

Cara memeriksanya sendiri: cari `signOtpStepVoucher` di kedua berkas. Yang ada
di satu dan tidak di yang lain, itu yang hidup.

Salinannya **basi**. `views/actions/document/save_document.php` 468 baris,
aslinya 911, dan tidak punya satu pun dari:

- `covered_fields` (hash multi-penanda-tangan v2)
- penjaga pembekuan field §7b
- penomoran versi `MAX(version)+1`
- penolakan penghapusan tanda tangan
- perbaikan `Context::default()->db`

**Apakah bisa dijalankan? Tidak.** Sudah diuji:

- `views/public/config.php` mencari `../../koneksi.php`, yang dari sana berarti
  `ezdoc/koneksi.php` — tidak ada. Berkasnya cuma benar satu level lebih dalam
- Menjalankan `views/actions/document/delete_slot.php` langsung berhenti di
  `Call to undefined function ezdoc_respond_error()`
- Tidak ada `.htaccess` yang memblokirnya, tapi tidak diperlukan: tiap jalur
  masuk butuh `$conn`, dan tidak ada di salinan itu yang bisa membuatnya

Jadi **beban mati, bukan lubang aktif**. Tapi beban mati yang rapuh:

- Ia muncul di setiap pencarian teks. Sudah dua kali menyesatkan dalam satu
  sesi kerja
- Ia jadi hidup seketika kalau ada yang menaruh `ezdoc/koneksi.php`, atau
  menyalinnya lagi dengan kedalaman berbeda
- Ia ikut terbawa setiap kali folder ezdoc di-upload, 1,2 MB tanpa guna

**Saran: hapus semuanya kecuali berkas tampilan yang asli** (`_partials/`,
`document/`, `admin/`, `layout.php`). Tapi ini menghapus 199 berkas terlacak
git, jadi keputusannya di tangan kalian — saya tidak akan menghapusnya sendiri.

Aman dihapus karena tidak ada yang memuatnya: `Router.php` menunjuk
`views/document/` dan `views/layout.php`, tidak pernah `views/src/` atau
`views/actions/`. Kalau ragu, langkah paling hati-hati adalah menghapusnya di
cabang tersendiri lalu menjalankan seluruh tes dan membuka halaman generate.

## 8. Fase

| | Isi | Status |
|---|---|---|
| **I1** | `EZDOC_AUDIT_DETAIL_ROLES` + penyaringan dua tingkat di `document_history.php` + tes | **selesai** |
| **I2** | Antarmuka `ActorDirectory` + wiring `Context` + resolusi di `fromGlobals()` + tes | **selesai** |
| **I3** | `document_history.php` memakai directory; JS menampilkan nama, `#id` sebagai cadangan | **selesai** |
| **I4** | `EZDOC_SIGNER` pakai nama, role dibuang | **selesai** |
| **I5** | Penyambung untuk aplikasi ini (`pegawai` → nama) | **selesai** |

## 8b. Yang berubah dari rencana

### Bukan menambah mekanisme — menyatukan tiga yang sudah ada

Rencana ini menganggap belum ada cara mencari nama. Ternyata ada **tiga**, dan
tidak satu pun selesai:

| | Di mana | Masalahnya |
|---|---|---|
| 1 | `$ctx->roleProvider->displayName($id)` di `generate.php:556` | Dipanggil lewat `method_exists`. Tidak ada di antarmuka `RoleProvider`, tidak ada implementasinya di library, dan berkas spesifikasi yang dirujuk komentarnya (`ezdoc-spec/context/role_provider.md#displayName`) **tidak ada**. PHPStan menandainya sebagai metode tak terdefinisi |
| 2 | `ezdoc_fetch_creator_name()` di `lib/doc_meta_helpers.php` | Menjalankan `SELECT nama_pegawai FROM pegawai` **dari dalam library**. Nama tabel milik satu rumah sakit, dipaku ke dalam paket yang dimaksudkan untuk dipasang orang lain |
| 3 | Panel riwayat | Tidak ada — langsung `#18` |

Menambahkan `ActorDirectory` sebagai cara keempat akan memperburuknya. Jadi
dibuat satu pintu, `ezdoc_actor_names()` di `lib/actor_names.php`, dengan urutan:

1. `ActorDirectory` milik aplikasi pemakai — jalur yang benar
2. `RoleProvider::displayName()` kalau kebetulan ada — usang, dipertahankan
   supaya pemasangan yang terlanjur memakainya tidak rusak
3. `ezdoc_fetch_creator_name()` — usang, jalur terakhir

Ketiga pemanggil (riwayat, nama pembuat dokumen, konfirmasi penanda tangan)
sekarang lewat pintu itu.

> **Sisa pekerjaan yang belum dilakukan:** `ezdoc_fetch_creator_name()` masih
> ada di `lib/doc_meta_helpers.php` dan masih menyebut tabel `pegawai` di dalam
> library. Sekarang ia cuma cadangan terakhir dan tidak akan terpanggil selama
> `PegawaiActorDirectory` tersambung — tapi ia tetap melanggar aturan netralitas
> library. Menghapusnya berarti menghapus fungsi publik, jadi keputusannya
> di tangan kalian.

### Berkas

| Berkas | |
|---|---|
| `src/Auth/ActorDirectory.php` | **baru** — antarmuka jamak |
| `lib/actor_names.php` | **baru** — `ezdoc_actor_names()`, satu pintu + jalur usang |
| `src/Context.php` | parameter ke-5, `withActorDirectory()`, resolusi di `fromGlobals()`, dan keempat `with*()` ikut membawanya |
| `lib/authorization.php` | `ezdoc_audit_detail_roles()`, `ezdoc_can_view_audit_detail()` |
| `config.php` | `EZDOC_AUDIT_DETAIL_ROLES` |
| `actions/document/document_history.php` | penyaringan dua tingkat + nama + penanda `audit_detail` |
| `views/document/generate.php` | `EZDOC_SIGNER` pakai nama tanpa role; nama pembuat lewat pintu yang sama |
| `views/_partials/generate_scripts.php` | riwayat dan konfirmasi QR menampilkan nama |
| `tests/Auth/AuditDetailAccessTest.php` | **baru** — 7 tes |
| `tests/Auth/ActorNamesTest.php` | **baru** — 9 tes |
| `adapters/PegawaiActorDirectory.php` | **baru**, di luar ezdoc — implementasi untuk aplikasi ini |
| `ezdoc_otp_setup.php` | menyambungkan directory + menyetel `EZDOC_AUDIT_DETAIL_ROLES` |

**1206 tes, 28604 asersi, PHPStan bersih.**

### Satu tes yang layak disebut

`ActorNamesTest::testTheDirectorySurvivesEveryImmutableCopy()`. `with*()`
mengembalikan salinan; lupa membawa satu dependensi adalah kehilangan yang
senyap — semuanya tetap jalan, nama saja yang berhenti muncul, dan tidak ada
yang menunjuk ke salinan yang menjatuhkannya. Keempat `with*()` diperiksa.

## 9. Yang perlu diputuskan

| | Pertanyaan | Keputusan |
|---|---|---|
| 1 | Role mana yang boleh melihat IP? | **`['superadmin']` untuk aplikasi ini**, disetel lewat `EZDOC_AUDIT_DETAIL_ROLES`. Library tidak memaku nama apa pun — lihat "Tidak ada nama role yang dipaku" di §3 |
| 2 | Daftar role ikut disembunyikan bersama IP? | **Ya.** Ia menunjukkan siapa superadmin-nya |
| 3 | Salinan `ezdoc/views/` dihapus? | Belum diputuskan — lihat §7 untuk apa itu sebenarnya |
