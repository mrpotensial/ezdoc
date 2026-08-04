# Rencana — OTP jalur kedua: pasien yang memulai

**Status:** rencana, belum dikerjakan
**Ditulis:** 2026-08-03

## 1. Masalahnya

Sekarang OTP selalu dimulai petugas: klik tanda tangan → sistem mengirim pesan
WhatsApp ke pasien → pasien membaca kode → menyebutkannya ke petugas.

Pesan yang dimulai bisnis itu berbayar. Setiap kali petugas menekan "Kirim OTP",
ada ongkosnya — termasuk saat pasien salah ketik dan harus mengulang.

**Idenya:** balik arahnya. Pasien memindai QR, mengirim chat lebih dulu, lalu bot
membalas dengan kode sekali pakai. Karena pasien yang memulai percakapan,
balasannya masuk jendela layanan, bukan pesan broadcast.

> Besaran hematnya tergantung paket Qontak/Meta yang dipakai — angkanya perlu
> dicek sendiri. Yang pasti secara bentuk: pesan yang dimulai bisnis dan pesan
> balasan dalam percakapan yang dimulai pengguna ditagih berbeda.

Jalur lama **tidak dihapus**, tapi turun jadi cadangan. Sebagian pasien tidak bisa
atau tidak mau memindai QR, dan untuk mereka jalur lama satu-satunya cara.

| | Jalur | Kapan dipakai |
|---|---|---|
| **Utama** | Pasien memindai QR, bot membalas kode | Semua keadaan biasa |
| **Cadangan** | Petugas menekan "Kirim OTP" | Pasien tidak bisa memindai — tidak punya WhatsApp, telepon habis baterai, tidak paham QR |

Karena berbayar, jalur cadangan **tidak boleh jadi jalan yang paling mudah
ditekan**. Ia ada di balik satu langkah tambahan ("pasien tidak bisa memindai?"),
supaya tidak dipakai hanya karena lebih dekat ke jari.

## 2. Keadaan sekarang (dibaca dari kode, bukan diingat)

| | |
|---|---|
| Tabel | `ezdoc_ttd_otp_challenges` — `challenge_id` (PK), `doc_id`, `template_id`, `ttd_id`, `phone`, `otp_hash`, `attempts`, `verified_at`, `expires_at`, `sign_token`, `sign_token_used_at` |
| Layanan | `OtpService::createChallenge()` → kirim, `verify()` → terbitkan `sign_token` |
| Pengaman | maks 3 percobaan, jeda kirim 60 detik, TTL 60–900 detik, pesan gagal seragam supaya tidak bisa diintip |
| Pemakaian token | `checkSignToken()` saat validasi, dibelanjakan setelah dokumen tertulis |
| Infrastruktur masuk | Sudah ada: `chatbot/adapter_whatsapp.php`, kanal Redis `qontak_incoming` |

## 3. Kenapa ini bukan sekadar "tambah tombol"

Kedua jalur punya **bentuk yang berbeda secara mendasar**:

| | Jalur 1 (sekarang) | Jalur 2 (baru) |
|---|---|---|
| Yang memulai | petugas | pasien |
| Saat kode dibuat, sistem tahu… | dokumen + slot + nomor HP | **hanya nomor HP** |
| Kode terikat pada | `challenge_id` | nomor HP |
| Diverifikasi dengan | `challenge_id` + kode | nomor HP + kode |

Di jalur 2, kode lahir **sebelum ada yang tahu untuk dokumen mana**. Pasien
memindai QR di ruang tunggu; petugas mungkin baru membuka dokumennya semenit
kemudian.

Itu sebabnya kolom `template_id` dan `ttd_id` yang `NOT NULL` tidak bisa dipakai
apa adanya, dan kenapa jalur 2 butuh tempat sendiri.

## 4. Desain

### Tabel baru: `ezdoc_otp_vouchers`

"Voucher" — kode yang sudah ada tapi belum dipakai untuk apa pun.

```
id            BIGINT PK
phone         VARCHAR(30)   nomor pengirim, dinormalkan
code_hash     VARCHAR(255)  hash, bukan kodenya
issued_at     DATETIME
expires_at    DATETIME
claimed_at    DATETIME NULL
claimed_doc   INT NULL      dokumen yang akhirnya memakainya
claimed_ttd   VARCHAR(64) NULL
attempts      INT DEFAULT 0
source        VARCHAR(32)   'whatsapp_inbound'
provider_meta LONGTEXT NULL
```

Tabel terpisah, bukan melonggarkan tabel lama. Voucher dan challenge adalah dua
hal berbeda: challenge sudah tahu tujuannya, voucher belum. Menggabungkannya
berarti setengah kolom selalu kosong dan tidak ada yang tahu baris mana yang
mana.

### Alurnya

```
Pasien                          Bot                       Petugas
  │                              │                          │
  │─ pindai QR ─────────────────▶│                          │
  │  (buka WA, pesan terisi)     │                          │
  │                              │                          │
  │◀─ "Kode Anda: 483920" ───────│                          │
  │   berlaku 10 menit           │  simpan voucher          │
  │                              │  (phone, hash, expiry)   │
  │                                                         │
  │──────── sebutkan kodenya ──────────────────────────────▶│
                                                            │
                                          modal TTD: nomor HP + kode
                                                            │
                                          klaim voucher ────┤
                                          terbit sign_token │
```

### Yang membuat ini murah untuk dibangun

Klaim voucher **berakhir di tempat yang sama** dengan jalur 1: sebuah baris
challenge yang sudah `verified_at` dengan `sign_token` terisi.

Artinya `save_document.php` **tidak berubah sama sekali**. Kontrak sign token
tetap utuh, guard §7b tetap utuh, dan kedua jalur bertemu di satu titik yang
sudah teruji.

## 4b. Jalannya, dari mata orang

Bagian inilah yang dipakai kalau lupa alurnya. Yang di atas menjelaskan *kenapa*,
yang di bawah ini *apa yang benar-benar terjadi*.

**Adegan: Bu Siti mau tanda tangan surat persetujuan tindakan.**

### 1 — QR di ruang tunggu

Satu layar (atau stiker) menampilkan QR berisi `wa.me/62xxx?text=TTD-OTP`.
Statis: tidak berubah, tidak perlu dicetak ulang.

### 2 — Bu Siti memindai

HP-nya membuka WhatsApp, chat ke nomor RS, kolom pesan **sudah terisi**
`TTD-OTP`. Dia tinggal tekan kirim. Tidak mengetik apa pun.

### 3 — Bot balas, sekitar 2 detik

```
*KODE TANDA TANGAN*

Kode Anda: *483920*
Berlaku 10 menit.

Sebutkan kode ini ke petugas.
Jangan berikan ke siapa pun selain petugas.

_RSIA Anugrah Kubu Raya_
```

Di belakang layar: webhook Qontak masuk → `handle_ttd_otp_wa()` mengenali kata
kuncinya → `OtpVoucherService` menerbitkan voucher untuk nomor Bu Siti → balas.

Belum ada yang tahu ini untuk dokumen apa. Memang belum ada dokumennya.

### 4 — Petugas membuka dokumen, klik slot TTD

```
Tanda Tangan Pasien

Nomor HP    0812-3456-7890          🔒 dari dokumen
            ✓ Sudah ambil kode · berlaku 7 menit lagi

Kode        [ _ _ _ _ _ _ ]

                              Pasien tidak bisa memindai?
```

Baris hijau itu inti perbaikannya: petugas tahu Bu Siti sudah pegang kode dan
masih berlaku, **sebelum** mengetik apa pun.

### 5 — Petugas mengetik kodenya

Cocok → voucher diklaim (sekali pakai, langsung mati) → sistem membuat challenge
yang statusnya *sudah terverifikasi* → `sign_token` terbit → kanvas tanda tangan
terbuka.

Dari titik ini ke bawah **persis seperti sekarang**: tanda tangan, simpan
otomatis, hash, record.

### Kalau tidak mulus

| Keadaan | Yang petugas lihat | Yang dia lakukan |
|---|---|---|
| Bu Siti belum sempat memindai | `— Belum ambil kode` | Tunggu, atau tunjuk QR-nya |
| Kodenya kedaluwarsa | `⚠ Kode sudah kedaluwarsa` | Minta Bu Siti kirim `TTD-OTP` lagi — gratis |
| Salah ketik kode | "Kode salah. Sisa 2 percobaan." | Ulangi |
| Habis 3 percobaan | Nomor itu terkunci sementara | Minta kode baru |
| Bu Siti pindai dari HP suaminya | `— Belum ambil kode` (yang dicek nomor di dokumen) | "Ubah nomor" → ketik nomor suami → status ketemu. Tercatat `manual` |
| Bu Siti tidak punya WhatsApp | | "Pasien tidak bisa memindai?" → jalur cadangan, tombol Kirim OTP muncul. **Ini yang berbayar** |

### Dua hal yang mudah terlupa saat mengerjakan

**Normalisasi nomor.** WhatsApp mengirim `6281234567890`; di dokumen biasanya
tertulis `081234567890`. Keduanya harus dinormalkan ke satu bentuk sebelum
dibandingkan — kalau tidak, statusnya akan **selalu** "belum ambil kode"
walaupun pasien sudah memindai, dan penyebabnya sulit ditebak. Wajib ada tesnya.

**Penjagaan `participant_type == 'customer'`.** Tanpa itu bot bisa membalas
pesannya sendiri dan berputar tanpa henti. Penjagaannya sudah ada di
`api_qontak.php:147` untuk antrian — ikut pakai, jangan bikin sendiri.

### Yang tidak berubah sama sekali

Tanda tangan, hash `sha256-jcs-v2`, `covered_fields`, pembekuan field, versi,
panel riwayat, dan `save_document.php`.

Jalur baru ini berhenti tepat di titik "kode terverifikasi". Dari situ ke bawah
memakai jalan lama yang sudah teruji.

## 5. QR-nya

### Pilihan A — QR statis (rekomendasi untuk mulai)

Satu QR, dipasang di layar ruang tunggu atau ditempel di meja:

```
https://wa.me/62xxxxxxxxxx?text=OTP%20TTD
```

Teks yang sudah terisi itu penting: bot memakainya untuk membedakan permintaan
OTP dari chat biasa. Tanpa penanda, bot akan membalas kode ke setiap orang yang
menyapa.

**Kelebihan:** satu QR untuk semuanya, tidak perlu dicetak ulang, cocok untuk
layar seperti `display_antrian_qr_v2.php`.
**Kekurangan:** kode hanya terikat nomor HP, bukan dokumen.

### Pilihan B — QR dinamis per dokumen

QR muncul di layar petugas, teksnya memuat token pendek yang menunjuk dokumen dan
slot. Kode yang dihasilkan hanya berlaku untuk tanda tangan itu.

**Kelebihan:** ikatannya jauh lebih kuat.
**Kekurangan:** pasien harus memindai dari layar petugas, jadi tidak bisa
dilakukan sambil menunggu.

### Saran

Mulai dari **A**, dan siapkan tempat untuk **B**. Keduanya memakai tabel dan
layanan yang sama; yang membedakan cuma apakah pesan pembuka membawa token
dokumen atau tidak.

## 6. Keamanan — apa yang berubah dan apa yang harus dijaga

### Yang melemah, dan harus diakui

Di jalur 1, **sistem yang memilih nomor tujuan** (dari field dokumen). Di jalur
2, petugas mengetik nomornya. Petugas yang berniat curang bisa memakai nomornya
sendiri: chat sendiri, dapat kode sendiri, tanda tangani atas nama pasien.

**Ini bukan celah baru** — modal jalur 1 juga mengizinkan nomornya diubah, jadi
kelemahan yang sama sudah ada hari ini. Tapi jalur 2 membuatnya lebih mudah,
jadi harus ditangani:

| Penjagaan | Cara |
|---|---|
| Nomor harus cocok dengan dokumen | Bandingkan dengan `data-otp-phone-source`. Beda → tolak |
| Kalau tetap perlu beda | Boleh, tapi **dicatat** di metadata tanda tangan dan log audit. Bisa diaudit, bukan diam-diam |
| Nomor yang dipakai ikut tercatat | Masuk ke `ezdoc_signatures.metadata`, jadi terlihat di panel riwayat |

Yang terakhir itu yang paling berharga: kalau nanti ada sengketa, riwayatnya
menyebut nomor mana yang menerima kode.

### Yang harus dijaga di sisi bot

| Risiko | Penjagaan |
|---|---|
| Orang membanjiri nomor WA untuk minta kode | Jeda per nomor (mis. 60 detik), maksimal N voucher aktif per nomor |
| Kode ditebak | 6 digit + maksimal 3 percobaan **per nomor**, bukan per voucher. Kalau per voucher, penyerang tinggal minta voucher baru |
| Kode dipakai ulang | Sekali pakai, ditandai saat diklaim |
| Menebak nomor mana yang punya kode aktif | Pesan gagal seragam — `OtpService` sudah begitu, ikuti |
| Voucher menumpuk | Yang kedaluwarsa dibersihkan berkala |

### Petunjuk status nomor — berguna, tapi ia sebuah oracle

Petugas perlu tahu apakah nomor yang dimasukkan **sudah mengambil kode** dan
**kodenya masih berlaku** — kalau tidak, salah ketik satu digit menghasilkan
"kode salah" berulang tanpa ada yang tahu sebabnya nomornya, bukan kodenya.

Tapi endpoint yang menjawab "nomor ini punya kode aktif?" adalah **alat
pengintai**. Siapa pun yang bisa memanggilnya dapat memetakan nomor mana yang
sedang menunggu tanda tangan. Itu persis hal yang dihindari `OtpService` dengan
pesan gagal seragam.

**Jalan keluarnya: jangan biarkan nomornya diketik bebas.**

Nomor pasien sudah ada di dokumen (`data-otp-phone-source`). Jadi:

| | |
|---|---|
| Field nomor | **terisi otomatis dan terkunci**, diambil dari dokumen |
| Di bawahnya | status voucher untuk nomor itu: *"Sudah ambil kode, berlaku 7 menit lagi"* / *"Belum ambil kode"* / *"Kode sudah kedaluwarsa"* |
| Kalau perlu nomor lain, atau dokumennya belum punya nomor | Kolomnya bisa diketik, dan asalnya dicatat sebagai `manual` — lihat §9 |

Ini menyelesaikan dua hal sekaligus:

- **Salah ketik hilang seluruhnya** — tidak ada yang diketik
- **Pengintaian hilang** — petugas cuma bisa bertanya tentang nomor yang memang
  sudah ada di dokumen yang sedang dibukanya

Untuk jalur "Ubah nomor" yang tersisa, penjagaannya: hak akses sama dengan hak
menandatangani slot itu, jeda antar-pemeriksaan, dan setiap pemeriksaan dicatat.
Petugas yang mencoba puluhan nomor akan terlihat di log.

Status **tidak pernah menyebut kodenya**, hanya ada/tidak dan sisa waktunya.

### Masa berlaku

Jalur 1 memakai 5 menit — wajar, kodenya dikirim tepat saat dibutuhkan.

Jalur 2 perlu lebih panjang: pasien memindai di ruang tunggu, lalu berjalan ke
meja. **10 menit** masuk akal. Jendela tebak jadi lebih lebar, jadi batas 3
percobaan per nomor itu yang menahannya, bukan masa berlakunya.

## 7. Pembagian pekerjaan

| Bagian | Di mana | Kenapa |
|---|---|---|
| Tabel + layanan voucher | **library** (`ezdoc/`) | Aturan keamanannya sama untuk semua pemakai |
| Endpoint terbitkan voucher | **library**, dipanggil consumer | Consumer cuma menyerahkan nomor pengirim |
| Bot WhatsApp | **consumer** (`chatbot/`) | Sudah ada infrastrukturnya; library tidak boleh tahu Qontak |
| Halaman QR | **consumer** | Tampilan ruang tunggu, urusan rumah sakit |
| Modal di generate | **library** | Bagian dari alur tanda tangan |

Library tetap netral: tidak ada "Qontak", tidak ada "WhatsApp" di `src/`. Yang
ada cuma "voucher diterbitkan untuk sebuah nomor".

## 7b. Sisi bot: ikut pola `antrian_wa_handler.php`

Sudah ada contohnya di rumah, dan bentuknya persis sama: pasien pindai QR → kirim
pesan berkata kunci → bot balas. Jadi jangan bikin pola baru, ikuti yang ini.

### Yang sudah benar di sana, tiru

| | Di `antrian_wa_handler.php` | Untuk OTP |
|---|---|---|
| Satu fungsi masuk, kembalikan `bool` | `handle_antrian_wa($text, $thread, $phone)` | `handle_ttd_otp_wa(...)` sama persis |
| Kata kunci dua suku, token opsional | `antrian-pendaftaran[-{token}]` | `ttd-otp[-{token}]` — parser `explode('-')` yang ada langsung cocok |
| Bukan kata kunci → `false`, lanjut ke handler lain | ✔ | ikuti |
| Klaim token anti-rebutan | `wa_claimToken()` — `UPDATE … WHERE is_used=0 AND expires_at>NOW()` lalu cek `affected_rows` | **pakai ide yang sama** untuk klaim voucher |
| Percobaan ulang dari HP yang sama tidak dibalas dua kali | `lost_same_user` | ikuti |
| Hanya membalas `participant_type == 'customer'` | ✔ di `api_qontak.php:147` | wajib — kalau tidak, bot membalas dirinya sendiri |

Pola `UPDATE`-dulu-lalu-`affected_rows` itu betul dan tidak perlu saya ganti.

### Yang harus diperbaiki dulu

`api_qontak.php:147-151`:

```php
if ($participant_type == 'customer') {
    if (handle_antrian_wa($text_trim, $thread_id, $account_uid)) {
        // Keyword antrian dikenali dan ditangani, lanjut return event seperti biasa
    }
}
```

Badan `if`-nya **kosong**. Nilai `true`/`false` yang susah payah dihitung
handler itu dibuang, jadi tidak ada satu pun kode di bawahnya yang tahu pesan ini
sudah dibalas.

Sekarang belum kelihatan karena `antrian-pendaftaran-xxx` kebetulan tidak cocok
dengan `mau daftar` maupun `chat dengan cs`, dan blok balasan AI di baris 338
hanya jalan untuk gambar/dokumen. **Aman karena kebetulan, bukan karena
dirancang.**

Dan itu akan pecah: `chatbot/adapter_whatsapp.php` sudah ditulis, docblock-nya
sendiri bilang "dipanggil dari `api/api_qontak.php` saat auto_reply aktif", tapi
sampai sekarang **belum dipanggil dari sana**. Begitu disambungkan, pesan
antrian akan dapat nomor antrian **dan** balasan AI sekaligus.

Perbaikannya kecil — satu penanda:

```php
$wa_handled = false;

if ($participant_type == 'customer') {
    $wa_handled = handle_antrian_wa($text_trim, $thread_id, $account_uid)
               || handle_ttd_otp_wa($text_trim, $thread_id, $account_uid);
}
```

lalu setiap blok yang membalas dibungkus `if (!$wa_handled)`. `||` sudah
hubung-singkat, jadi handler kedua tidak jalan kalau yang pertama sudah
menangani.

Ini yang bikin jawabannya "ya, `api_qontak.php` perlu diubah" — bukan karena OTP
butuh sesuatu yang khusus, tapi karena penanda "sudah ditangani" memang belum
pernah ada.

### Satu hal yang TIDAK ditiru

`wa_ensureTable()` menjalankan `CREATE TABLE IF NOT EXISTS` + `ALTER` + `DELETE`
setiap kali berkasnya di-include — DDL di jalur webhook. Ezdoc punya sistem
migrasi sendiri, jadi tabel voucher dibuat lewat migrasi, bukan lewat cara itu.

### Di mana berkasnya

`api/ttd_otp_wa_handler.php` — bersebelahan dengan `antrian_wa_handler.php`,
bukan di `chatbot/`. `chatbot/` isinya mesin AI percakapan; ini penangan kata
kunci, dan tetangganya sudah ada di `api/`.

Isinya tipis: baca nomor pengirim, panggil endpoint terbitkan voucher milik
ezdoc, balas kodenya. Semua aturan (jeda, batas percobaan, masa berlaku) ada di
`OtpVoucherService` di dalam library, tidak diulang di sini.

## 7c. Kompatibel dengan arsitektur PHP lain

Ezdoc dipasarkan sebagai *framework-agnostic library* (kata `composer.json`
sendiri). Fitur ini harus tetap bisa dipakai orang yang memakai Laravel,
Symfony, Slim, atau PHP polos — bukan cuma aplikasi ini.

### Aturan untuk kode di `src/`

| Aturan | Kenapa | Yang memakainya |
|---|---|---|
| Constructor injection, dependensi opsional | `new OtpVoucherService()` jalan sendiri, tapi container PSR-11 tetap bisa menyuntik | **Pola rumah sendiri** — `OtpService::__construct(?$repo, ?$provider)` sudah begitu, ikuti persis |
| Tidak menyentuh `$_POST`/`$_SESSION`/`$_SERVER` | Service dipanggil dari controller, konsol, cron, atau antrian — bukan cuma dari HTTP | league/oauth2-server, Doctrine |
| Tidak `echo`, tidak `exit`, tidak `header()` | Yang memutuskan bentuk respons itu host, bukan library | semua library serius |
| Lempar exception bertipe, bukan `ezdoc_respond_error()` | Host yang menerjemahkan ke HTTP/JSON/CLI | Symfony, Doctrine |
| Kembalikan **kode kejadian**, bukan kalimat | Kalimat Indonesia akan tetap Indonesia di antarmuka Inggris | **sudah jadi pola di sini** — `document_history.php` mengirim `event`, tampilan yang menerjemahkan |
| Semua tulis-baca lewat `Connection` + migrasi | Sudah ada 5 grammar; SQL mentah mysqli mengunci ke MySQL | Doctrine DBAL |

### Dua hal yang menghalangi, dan sebaiknya dibereskan sekalian

**1. Waktu diambil langsung dari `time()`.**

`OtpService.php:153,168,232` dan `OtpChallengeRepository.php` memanggil `time()`
dan `date()` langsung. Akibatnya masa berlaku, jeda kirim, dan kedaluwarsa cuma
bisa diuji dengan benar-benar menunggu.

Untuk voucher ini masalahnya lebih besar, karena **seluruh fiturnya soal waktu** —
"berlaku 7 menit lagi", jeda per nomor, voucher kedaluwarsa. Tanpa jam yang bisa
disuntik, penunjuk status itu tidak punya tes yang jujur.

Jawaban industrinya sudah baku: **PSR-20 `ClockInterface`** (diterima 2022).
Dipakai Symfony Clock, lcobucci/jwt, dan paket-paket league. Bentuknya satu
metode, `now(): DateTimeImmutable`.

```php
public function __construct(
    ?OtpVoucherRepository $repo = null,
    ?ClockInterface $clock = null   // default: jam sistem
) { ... }
```

Tambahnya opsional (`suggest`, dengan `SystemClock` bawaan kalau `psr/clock`
tidak dipasang), jadi tidak ada yang rusak. Kelas lama boleh menyusul belakangan.

**2. `ext-mysqli` masih `require`, bukan `suggest`.**

`composer.json:15` mewajibkan `ext-mysqli`. Padahal `PdoConnection` sudah ada dan
`ext-pdo` justru cuma ada di `suggest`. Artinya toko Laravel+Postgres **tidak
bisa memasang ezdoc sama sekali** hari ini, walaupun mereka tidak akan menyentuh
mysqli sedetik pun.

Pindahkan ke `suggest`, sejajar dengan `ext-pdo`. Ini perubahan satu baris dan
tidak mengubah perilaku aplikasi ini sedikit pun.

> Keduanya di luar lingkup OTP. Saya catat di sini karena ketemu saat memeriksa,
> bukan untuk dikerjakan diam-diam — putuskan sendiri mau sekarang atau nanti.

### Garis pemisahnya

```
src/Signature/Otp/OtpVoucherService.php   ← aturan: terbit, klaim, jeda, status
                                             tidak tahu HTTP, tidak tahu WhatsApp
        ▲
        │ dipanggil
        │
actions/…/otp_voucher_issue.php           ← adapter HTTP tipis, milik ezdoc
        ▲
        │ dipanggil
        │
api/ttd_otp_wa_handler.php                ← milik consumer, tahu Qontak
```

Yang paling atas bisa dipanggil dari Laravel controller, Symfony command, atau
skrip cron tanpa mengubah satu baris pun. Yang paling bawah boleh tahu Qontak
sepuasnya — ia memang tinggal di aplikasi ini.

## 8. Fase

| | Isi |
|---|---|
| ~~**V1**~~ | **SELESAI** — lihat §8b di bawah |
| ~~**V2**~~ | **SELESAI** — lihat §8c di bawah |
| ~~**V3**~~ | **SELESAI** — lihat §8e di bawah |
| ~~**V4**~~ | **SELESAI** — lihat §8f di bawah |
| ~~**V5**~~ | **SELESAI** — lihat §8g di bawah |

V1 dan V2 bisa dites tanpa menyentuh tampilan sama sekali — voucher diterbitkan
lewat CLI, diklaim lewat CLI.

**V3 sebelum V4, bukan sesudahnya.** Karena jalur pindaian sekarang jalur utama,
modalnya tidak berguna sebelum ada QR dan bot yang menerbitkan kodenya. Kalau
V4 lebih dulu, petugas dapat kolom kode yang tidak mungkin diisi siapa pun.

## 8b. V1 — sudah dikerjakan

Semua di dalam folder `ezdoc/`. Migrasi baru **ditulis, belum dijalankan** ke
server mana pun.

### Berkas

| Berkas | Isi |
|---|---|
| `src/Support/Clock.php` | Antarmuka satu metode, bentuknya sama persis dengan PSR-20 |
| `src/Support/SystemClock.php` | Jam sungguhan; default di mana-mana |
| `src/Support/FrozenClock.php` | Jam yang cuma bergerak kalau disuruh. Sengaja di `src/`, bukan `tests/` — host juga menulis tes, dan test double yang tidak bisa mereka jangkau akan ditulis ulang dengan buruk. Symfony menaruh `MockClock` di komponennya karena alasan yang sama |
| `src/Support/PsrClock.php` | Membungkus jam PSR-20 milik host. Tidak menyebut `Psr\Clock\ClockInterface` di type hint — menyebutnya membuat berkas ini fatal di host yang tidak memasang paketnya |
| `migrations/2026_08_03_000001_create_ezdoc_otp_vouchers.php` | MySQL |
| `migrations/sqlite/…_create_ezdoc_otp_vouchers.sql` | Kembarannya, ditulis bersamaan bukan menyusul |
| `src/Signature/Otp/OtpVoucherRepository.php` | Klaim & pemakaian percobaan sebagai UPDATE bersyarat tunggal |
| `src/Signature/Otp/OtpVoucherService.php` | Aturannya: terbitkan, status, klaim, bersihkan |
| `src/Signature/Otp/OtpVoucherStatus.php` | `none` / `active` / `expired` / `locked` — kode, bukan kalimat |
| `src/Signature/Otp/OtpVoucherClaimResult.php` | Hasil klaim + sign token |
| `tests/Support/ClockTest.php` | 10 tes |
| `tests/Signature/Otp/OtpVoucherServiceTest.php` | 33 tes, SQLite sungguhan, jam beku sepanjang berkas |

Hasil: **1155 tes, 28402 asersi, PHPStan bersih.** Naik dari 1112.

### Yang berubah dari rencana, dan kenapa

**Normalisasi nomor tidak perlu ditulis.** `OtpService::normalizePhone()` sudah
ada dan sudah menangani `62…`, `08…`, `+62…`, dan spasi/strip. Tinggal dipakai.
Tesnya tetap ditulis, karena yang penting bukan siapa yang menulis fungsinya
tapi bahwa jalur ini benar-benar memakainya.

**`OtpChallengeRepository` ikut dapat jam.** Ini tidak direncanakan; ia muncul
sebagai dua tes yang gagal. Klaim voucher menulis baris challenge lewat jam
beku, lalu `verifySignToken()` membacanya lewat `time()` asli — dan menolak
token yang baru saja diterbitkannya sendiri.

Bukan tesnya yang salah. Satu alur tidak boleh memakai dua jam. Parameter
jamnya opsional, jadi pemanggil lama tidak terpengaruh, tapi `OtpVoucherService`
menurunkan jamnya sendiri saat membuat repository default.

**`composer.json`: `ext-mysqli` pindah ke `suggest`,** sejajar dengan `ext-pdo`
yang memang sudah di sana. `psr/clock` ditambahkan sebagai saran.

### Keputusan implementasi yang tidak ada di rencana

**Urutan saat klaim: bakar voucher dulu, tulis challenge kemudian.** Kalau
dibalik dan langkah kedua gagal, tertinggal challenge yang bisa dipakai
sementara vouchernya masih hidup — kodenya bisa dibelanjakan dua kali. Gagal
dengan urutan sekarang hanya menghanguskan satu kode; pasien mengirim
`TTD-OTP` lagi, gratis. Gagal dengan urutan sebaliknya menghancurkan sifat
sekali-pakai, yang jadi tumpuan seluruh desain.

**`otp_hash` pada challenge hasil klaim diisi hash dari byte acak.** Kolomnya
tidak boleh NULL, dan mengisinya dengan sesuatu yang bisa ditebak berarti
membuka pintu kedua ke tanda tangan yang sama — pintu yang melewati voucher.

**Percobaan tidak berkurang kalau kodenya sudah kedaluwarsa.** Kedaluwarsa
diperiksa sebelum percobaan dibebankan, jadi menunggu terlalu lama tidak
diam-diam memakan jatah tebakan.

## 8c. V2 — sudah dikerjakan

### Yang berubah dari rencana: tidak ada endpoint untuk menerbitkan voucher

Rencana menyebut "endpoint terbitkan voucher". Setelah dilihat lagi, itu bentuk
yang salah.

Pemanggilnya adalah penangan webhook milik aplikasi ini sendiri — mesin yang
sama, instalasi PHP yang sama, tanpa sesi browser. Menempuhnya lewat HTTP
berarti server memanggil dirinya sendiri, dan itu butuh kunci rahasia untuk
autentikasi: satu kredensial lagi untuk disimpan, dirotasi, dan bocor, ditukar
dengan nol manfaat. Ia juga mewarisi semua cara permintaan loopback bisa gagal
yang tidak berlaku untuk pemanggilan fungsi biasa — lapisan auth front
controller, proxy di tengah, TLS ke diri sendiri, aturan firewall yang ditulis
untuk lalu lintas luar.

Yang terakhir bukan hipotetis di sini: commit `cada904` justru memperbaiki
ezdoc yang memanggil webhook consumer lewat loopback dan dibalas 403, dengan
memindahkannya jadi adapter in-process. Alasannya sama, arahnya kebalikan.

Jadi penerbitan voucher jadi **fungsi**, bukan endpoint:

```php
require_once __DIR__ . '/../ezdoc/bootstrap.php';
$result = ezdoc_issue_otp_voucher($senderPhone, ['message_id' => $msgId]);
```

Kalau suatu saat botnya pindah ke mesin lain, tambahkan transport di depan
fungsi itu — jangan ganti fungsinya. Aturannya tetap di `OtpVoucherService`.

### Berkas

| Berkas | Isi |
|---|---|
| `lib/otp_voucher.php` | `ezdoc_issue_otp_voucher()` — tidak pernah melempar (webhook yang mati berujung diulang dan mencetak kode yang tidak dibaca siapa pun), mengembalikan `issued` / `cooldown` + `retry_after` / `bad_phone` / `error` |
| `actions/document/otp_voucher_status.php` | Petunjuk status. Auth + akses template + RBAC slot + batas 10 pemeriksaan manual per menit per petugas + dicatat |
| `actions/document/claim_ttd_voucher.php` | Klaim kode → sign token. Aturan nomor **sama persis** dengan `send_ttd_otp.php` |
| `actions/_dispatcher.php` | Dua action baru terdaftar. Penerbitan sengaja tidak ada di sini |
| `lang/{id,en}/common.php` | 5 kunci baru, dua bahasa |
| `tests/Signature/Otp/IssueVoucherHelperTest.php` | 7 tes lewat Context sungguhan |

### Kenapa aturan nomornya diulang di klaim

`send_ttd_otp.php` menolak (atau mencatat) kode yang diarahkan ke nomor selain
yang tercatat, dijaga `EZDOC_OTP_ALLOW_PHONE_OVERRIDE` dan
`EZDOC_OTP_OVERRIDE_ROLES`. Aturan itu diulang di `claim_ttd_voucher.php`
dengan sengaja.

Kalau tidak, petugas yang dilarang mengalihkan kode tinggal pindah ke jalur ini
dan mendapat hasil yang sama — aturan dengan pintu di sebelahnya.

### Pesan gagal di sini sengaja dibedakan

`verify_ttd_otp.php` memakai satu pesan seragam supaya pemanggil tidak bisa
membedakan "challenge tidak ada" dari "kadaluarsa" dari "kode salah". Di sini
justru dibedakan, dan itu bukan kelalaian: pemanggilnya sudah lolos pemeriksaan
akses yang sama dengan endpoint status, yang menjawab pertanyaan itu secara
langsung. Menyeragamkannya hanya membuat petugas terus mengulang kode yang
sudah mati.

## 8d. Tiga migrasi SQLite basi — ditemukan saat mengerjakan V2

Bukan bagian dari OTP. Ketemu karena tes helper penerbitan butuh tabel audit,
dan tabelnya ternyata tidak punya kolom yang ditulis `Ezdoc\Audit\Logger`.

| Tabel | Yang hilang |
|---|---|
| `ezdoc_audit_log` | **Skemanya beda total.** `entity_type`/`created_at`, padahal Logger menulis `target_type`/`occurred_at` |
| `ezdoc_documents` | 16 kolom, termasuk `norm`, `nopen`, `label`, `version`, `signature_values` — dan `uk_slot_version` tidak ada sama sekali |
| `ezdoc_default_vars` | `is_enabled`, `metadata` |

Yang berbahaya bukan kesalahannya, tapi cara ia gagal. Logger menelan setiap
error dengan sengaja — pencatatan tidak boleh menjatuhkan operasi yang sedang
dicatatnya. Jadi di setiap host SQLite, log audit **diam-diam tidak mencatat
apa pun**, dan tidak ada yang tahu sampai ada yang membutuhkan datanya.

Berkas dialek yang **hilang** gagal di query pertama dan langsung ketahuan.
Berkas dialek yang **berbohong** jalan terus.

Ketiganya sudah ditulis ulang, plus `tests/Migrations/DialectParityTest.php`
yang menjalankan setiap berkas SQLite sungguhan lalu membandingkan
`PRAGMA table_info` dengan kolom yang dideklarasikan versi MySQL-nya. Tes ini
yang menemukan dua dari tiga.

> Database SQLite yang sudah ada tetap memegang tabel lama —
> `CREATE TABLE IF NOT EXISTS` tidak mengubah tabel yang sudah ada. Karena
> selama ini memang tidak mencatat apa-apa, hapus tabelnya lalu jalankan ulang.
>
> **Server MySQL tidak tersentuh sama sekali.** Yang diubah hanya berkas dialek
> SQLite, yang cuma dipakai tes dan mode demo.

## 8e. V3 — sudah dikerjakan (sisi consumer)

Perubahan pertama di luar folder `ezdoc/`.

| Berkas | Perubahan |
|---|---|
| `api/ttd_otp_wa_handler.php` | **Baru.** Kata kunci `ttd-otp[-{token}]`, bentuknya meniru `antrian_wa_handler.php` |
| `api/api_qontak.php` | Penanda `$wa_handled` + `require_once` handler baru |
| `page/display_ttd_otp_qr.php` | **Baru.** Halaman QR statis untuk layar ruang tunggu |

### Penanda `$wa_handled`

```php
$wa_handled = false;
if ($participant_type == 'customer') {
    $wa_handled = handle_antrian_wa($text_trim, $thread_id, $account_uid)
               || handle_ttd_otp_wa($text_trim, $thread_id, $account_uid);
}
```

Enam blok yang membalas sekarang dijaga `if (!$wa_handled && …)`: `mau daftar`,
`ya,robot saja`/`kembali ke robot`, `bicara ke petugas`, `kirim ulang`,
`chat dengan cs`, dan balasan AI untuk gambar/dokumen.

Sebelumnya nilai balik `handle_antrian_wa()` dibuang ke badan `if` yang kosong.
Aman selama ini cuma karena kebetulan tidak ada kata kunci yang bertabrakan —
dan akan pecah begitu `chatbot/adapter_whatsapp.php` disambungkan.

### Ezdoc dimuat lambat, dan tanpa migrasi otomatis

`ttd_otp_load_ezdoc()` baru menyentuh `ezdoc/bootstrap.php` **setelah** kata
kuncinya cocok. Memuatnya di setiap pesan WhatsApp berarti satu perjalanan ke
database untuk chat yang tidak ada hubungannya dengan dokumen.

`EZDOC_AUTO_MIGRATE` dimatikan di jalur ini. Webhook bukan tempat menjalankan
DDL: ia jalan tanpa pengawasan dan bisa beberapa sekaligus. Kalau tabelnya
memang belum ada, penerbitan gagal rapi lewat balasan "layanan sedang tidak
tersedia". Tabelnya dibuat lewat jalur web biasa.

### Halaman QR

Statis: `wa.me/{nomor}?text=TTD-OTP`. Yang sekali pakai kodenya, bukan QR-nya —
jadi tidak perlu dicetak ulang dan tidak perlu token berputar seperti antrian.

Nomornya dibaca dari `antrian_setting`, mencoba `wa_nomor_ttd` dulu lalu jatuh
ke `wa_nomor_pendaftaran`. Kalau nanti tanda tangan dipisah ke nomor sendiri,
tinggal tambah satu baris setting — tanpa mengubah kode. **Tidak ada baris
yang ditulis ke database**; kalau keduanya tidak ada, dipakai nomor bawaan.

ES5 dipertahankan mengikuti `display_antrian_qr_v2.php`: layar ruang tunggunya
iPad Mini 1 (iOS 9.3.5), tanpa arrow function, `let`/`const`, atau template
literal. Kalau skrip QR gagal dimuat, nomor dan kata kuncinya ditampilkan
sebagai teks — layar kosong tidak memberi tahu siapa pun apa yang harus
dilakukan.

### Cara mengujinya

`handle_ttd_otp_wa()` kode consumer, di luar suite PHPUnit ezdoc. Diuji lewat
skrip yang menstub `qontak_request_bot()` dan `ezdoc_issue_otp_voucher()`, lalu
memanggil fungsi aslinya:

- 2 kata kunci dikenali (`ttd-otp`, `ttd-otp-{token}`), token sampai ke metadata
- 10 kata kunci **tidak** diambil alih — termasuk `antrian-pendaftaran`,
  `mau daftar`, `otp-ttd`, `ttdotp`, dan pesan kosong
- 3 hasil gagal (`cooldown`/`bad_phone`/`error`) punya balasan sendiri, tidak
  ada yang membocorkan kode
- Hasil rusak dari ezdoc tidak melempar keluar — webhook yang mati akan diulang
  Qontak, dan pasien bisa menerima beberapa kode sekaligus

## 8f. V4 — sudah dikerjakan (modal di generate)

Modal tanda tangan yang sama, isinya sekarang tiga panel yang bergantian:

| Panel | Kapan |
|---|---|
| `signOtpStepVoucher` | **Utama.** Terbuka begitu slot ber-OTP dibuka |
| `signOtpStep1` (kirim) | Setelah menekan "Pasien tidak bisa memindai?" |
| `signOtpStep2` (masukkan) | Setelah kode dikirim lewat jalur cadangan |

Ada jalan pulang dari cadangan ("Kembali ke kode dari pasien"). Tanpa itu,
petugas yang salah menekan hanya punya satu pilihan tersisa: mengirim pesan
berbayar.

### Nomor terkunci

Diisi otomatis dari sumber yang sama dengan jalur lama — DOM dulu (koreksi yang
belum disimpan), baru nilai server. Kolomnya `readonly` dengan ikon gembok.

"Ubah nomor" membukanya, dan sekali dibuka **tidak dikunci lagi** selama modal
terbuka: mengembalikan nomor dokumen diam-diam akan membuang apa yang baru saja
diketik petugas.

### Petunjuk status, dan kenapa ia tidak boleh diperiksa berkala untuk semua

Server membatasi pemeriksaan nomor bebas 10 per menit. Memeriksa tiap 10 detik
memakan hampir seluruh jatah itu — dan jatahnya ada supaya nomor tidak bisa
disapu satu per satu.

Jadi pemeriksaan berkala **hanya untuk nomor yang berasal dari dokumen**, dan
untuk nomor itu servernya tidak dibatasi maupun dicatat, karena tidak ada nomor
pilihan pemanggil yang bisa dipakai mengintai. Begitu petugas menekan "Ubah
nomor", pemeriksaan berkala berhenti.

Gunanya berkala: pasien memindai saat modal sudah terbuka, dan statusnya
berubah sendiri tanpa petugas menekan apa pun.

### `applySignToken()` — satu jalan keluar untuk dua jalur

Kedua jalur berakhir di fungsi yang sama: simpan token, buat input tersembunyi
di form, buka kanvas, sembunyikan panel.

Sebelumnya `verifyTtdOtp()` melakukannya sendiri. Membiarkan jalur voucher
menyalin blok itu berarti dua salinan yang harus dijaga sejajar — dan yang
paling mudah tertinggal justru input tersembunyi yang dibaca saat menyimpan.
Tanpa itu, penyimpanan ditolak dengan "verifikasi OTP diperlukan" tepat setelah
petugas berhasil memverifikasi.

### Cara mengujinya

`ViewFunctionScopeTest` diperluas: enam fungsi baru wajib ada di lingkup global.
Empat di antaranya dipanggil dari atribut `onclick=`, yang hanya melihat lingkup
global — kalau nested, berkasnya tetap lolos parse dan lolos lint, lalu gagal
saat petugas menekan tombolnya.

Perilakunya diuji dengan menjalankan JS aslinya di atas DOM tiruan, 33
pemeriksaan:

- Panel mana yang tampil di tiap perpindahan, termasuk nomor yang ikut pindah
  ke jalur cadangan
- Enam keadaan status dan warnanya, plus bahwa teksnya tidak pernah memuat
  angka enam digit
- Nomor **tidak** dikirim ke server saat asalnya dokumen, dan dikirim setelah
  "Ubah nomor"
- Kode 5 digit ditolak sebelum menyentuh jaringan
- Sisa percobaan hanya muncul untuk `invalid`, bukan untuk `expired`
- Klaim berhasil membuka kanvas dan mengisi input `_sign_token_*`
- Pemeriksaan berkala jalan untuk nomor dokumen, berhenti saat modal ditutup,
  dan tidak pernah jalan untuk nomor manual

> Dua kegagalan pertama harness ternyata dari harness-nya sendiri: `let` di
> tingkat atas skrip `vm` tidak jadi properti sandbox, jadi keadaan dalam tidak
> bisa dibaca dari luar. Diperbaiki dengan menguji perilaku yang terlihat —
> yang memang seharusnya diuji.

## 8g. V5 — sudah dikerjakan

### `cli/purge-expired-vouchers.php`

Dry-run dulu, sama seperti `purge-orphan-blobs.php`. Pemeriksaan sebelumnya
disebutkan angkanya, lalu `apply` untuk benar-benar menghapus.

**Voucher yang sudah diklaim tidak pernah dihapus.** Baris itu adalah
penghubung antara sebuah tanda tangan dan kode yang mengizinkannya — nomor mana
yang meminta, kapan, dan apakah nomornya dari dokumen atau diketik. Itu
pertanyaan yang benar-benar ditanyakan saat ada sengketa. Yang jadi sampah
hanya kode yang tidak pernah dipakai siapa pun.

Yang belum diklaim pun disimpan sehari dulu (`EZDOC_VOUCHER_GRACE_HOURS`).
Kode yang kedaluwarsa sepuluh menit lalu masih jadi bukti: "pasien bilang sudah
minta kode tapi tidak pernah bisa" cuma bisa dijawab selama barisnya ada.
Barisnya kecil — satu nomor dan satu hash — jadi menyimpannya nyaris tidak ada
ongkosnya dibanding yang bisa dijawabnya.

`countExpired()` dan `countClaimed()` ditambahkan ke repository supaya dry-run
punya angka. Ada tesnya yang memastikan angka pratinjau **sama** dengan jumlah
yang benar-benar dihapus — pratinjau yang berbeda dari kenyataan lebih buruk
daripada tidak ada pratinjau.

### Lima kejadian di panel riwayat

| Kejadian | Label |
|---|---|
| `otp.voucher_issued` | Pasien meminta kode |
| `otp.voucher_claimed` | Kode dari pasien terverifikasi |
| `otp.voucher_claim_failed` | Kode dari pasien ditolak |
| `otp.voucher_claimed_override` | Kode terverifikasi ke nomor berbeda |
| `otp.voucher_lookup_manual` | Status diperiksa untuk nomor yang diketik |

Dua yang terakhir sengaja punya baris sendiri, bukan digabung ke yang biasa.
Pertanyaan saat sengketa adalah "dari mana nomornya", bukan "apakah ada kode" —
kalau keduanya terlihat sama di riwayat, jawabannya harus digali dari metadata.

> **`otp.voucher_issued` tidak akan muncul di panel riwayat dokumen.** Ia
> dicatat saat pasien mengirim pesan, jauh sebelum ada yang membuka dokumennya,
> jadi `doc_id`-nya NULL dan panel yang menyaring per-slot tidak akan
> menemukannya. Itu benar, bukan bug: penerbitan milik nomor, bukan milik
> dokumen. Labelnya tetap didaftarkan supaya halaman audit global nanti punya
> kata-katanya.

Semua kunci ada di dua bahasa, dan `TranslationUsageTest` memverifikasi setiap
kunci yang dipakai memang ada di keduanya.

## 9. Yang perlu diputuskan sebelum mulai

Semua sudah diputuskan. Tidak ada yang menggantung.

| | Pertanyaan | Keputusan |
|---|---|---|
| 1 | Nomor HP di modal: dikunci atau bebas diketik? | **Dikunci** ke nomor dokumen. Ada "Ubah nomor" untuk kasus langka, dan pemakaiannya dicatat |
| 2 | Kalau nomor di dokumen **kosong**, jalur QR boleh dipakai? | **Boleh, tapi wajib dicatat.** Kolomnya bisa diketik, setiap pemakaian masuk log audit sebagai nomor yang diisi manual |
| 3 | Satu voucher untuk berapa slot? | **Satu voucher satu slot.** Kalau satu kode membuka banyak tanda tangan, satu kebocoran membuka semuanya |
| 4 | QR statis atau dinamis? | **Statis dulu.** Dinamis menyusul kalau perlu — tabel dan servicenya sama |
| 5 | ~~Kapan tombol cadangan ditawarkan?~~ | **Dicoret.** Tautan "Pasien tidak bisa memindai?" selalu ada, tanpa hitungan mundur. Petugas di loket melihat pasiennya langsung — dia lebih tahu daripada timer |

### Akibat keputusan 1 + 2: satu aturan, bukan dua

Keputusan 1 melahirkan jalur "Ubah nomor". Keputusan 2 melahirkan jalur "kolom
kosong jadi bisa diketik". Keduanya sebenarnya hal yang sama: **nomor yang tidak
berasal dari dokumen.**

Jadi jangan ditulis sebagai dua cabang. Satu aturan saja — **catat asal
nomornya**:

| Asal | Kapan | Dicatat |
|---|---|---|
| `document` | Nomor diambil dari field dokumen | tidak perlu |
| `manual` | Petugas mengetiknya — entah karena mengubah, entah karena dokumennya kosong | ya: log audit `otp.phone_manual` + masuk `ezdoc_signatures.metadata` |

Melacak *asal* sebuah nilai, bukan cuma nilainya, itu pola yang sudah baku:
DocuSign mencatat bagaimana identitas penerima ditetapkan, bukan cuma siapa
penerimanya.

Untungnya buat kita: satu aturan yang sama menutup dua lubang sekaligus, dan
panel riwayat yang sudah jadi otomatis menampilkannya tanpa kode tambahan.

Batasnya tetap: pemeriksaan status untuk nomor `manual` kena jeda dan ikut
tercatat, supaya petugas yang mencoba puluhan nomor terlihat di log — persis
seperti di §6.

## 10. Yang TIDAK termasuk

- Mengganti jalur 1 — ia tetap ada, dan tetap satu-satunya cara untuk pasien
  yang tidak bisa memindai QR
- Verifikasi identitas di luar penguasaan nomor HP. OTP membuktikan orang itu
  memegang telepon itu, bukan bahwa dia pasiennya
- Percakapan bot di luar penerbitan kode
