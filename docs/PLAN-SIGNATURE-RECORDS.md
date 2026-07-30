# Plan — Tanda tangan sebagai record, QR TTD, dan pertumbuhan penyimpanan

Status: **rancangan disepakati, belum dikerjakan** — keputusan §9 sudah diambil
Tanggal: 2026-07-30
Pemicu: pertanyaan "QR mode TTD isinya apa supaya standar industri?"

Dokumen ini menjawab pertanyaan itu, tapi jawabannya ternyata menuntut fondasi
yang belum ada. Plus dua bug yang ditemukan sambil menelusuri, dan satu masalah
penyimpanan yang muncul dari arah lain namun berakar di tempat yang sama.

---

## 1. Ringkasan

Ada **tiga** jalur QR di ezdoc, dan membedakannya menentukan segalanya:

| | Untuk apa | Isinya sekarang |
|---|---|---|
| **QR terpisah** (`qr-placeholder`, `data-qr="<field>"`) | Multifungsi — QR dari field apa pun, termasuk `{verify_url}` dokumen | Sudah benar, tidak diubah |
| **QR mode TTD** (toggle per slot) | QR **adalah tanda tangannya** | Belum bisa berisi apa pun yang bermakna |
| **Mode verify QR** (toggle dokumen, tombol "QR: ON/OFF") | Sembunyikan semua gambar TTD, ganti QR yang bisa dipindai | Verify URL dokumen — **sama untuk semua slot** |

QR mode TTD seharusnya menunjuk ke **tanda tangan itu**. Masalahnya: tanda
tangan belum berupa record apa pun. Tidak ada yang bisa ditunjuk.

Mode verify QR sudah punya alur interaksi yang benar (tombol → konfirmasi →
simpan → QR muncul) tapi isinya satu URL untuk semua. Setelah tanda tangan jadi
record, keduanya menyatu — lihat §6.

## 2. Keadaan sekarang (terverifikasi, bukan asumsi)

| Sudah ada | Belum tersambung |
|---|---|
| Tabel `ezdoc_signatures` lengkap — `signer_id`, `signer_role`, `signed_at DATETIME(3)`, `content_hash`, `verify_status`, `tsa_response` | **Tidak ada satu baris pun ditulis ke sana** |
| `HmacProvider` (L1) — `sign()` / `verify()`, hanya butuh `EZDOC_HMAC_SECRET` | Tidak dipanggil dari jalur mana pun |
| `SignatureRepository::insert()` | Tidak dipanggil dari jalur mana pun |
| URL verifikasi ber-HMAC (`<base>/<slug>?s=<mac>`) | HMAC hanya menutup slug, bukan `ttd_id` |
| Halaman verify host | Membaca TTD dari HTML template + JSON dokumen, bukan dari `ezdoc_signatures` |

TTD yang digambar petugas hanya tersimpan sebagai base64 di
`ezdoc_documents.signature_values`. Itu **gambar**, bukan tanda tangan yang bisa
diverifikasi: tidak ada catatan siapa, kapan, atau apa yang ditandatangani.

## 3. Tiga temuan

### 3.1 Hash dokumen tidak dikanonikalisasi — bug aktif

`doc_verify_canonical_data()` (sisi host) menyertakan JSON **apa adanya**:

```php
// data_fields & data_ttd sudah berupa JSON string dari DB, langsung include as-is
// (bukan decode+re-encode, karena JSON re-encoding bisa beda urutan key)
'data_ttd' => (string)($row['data_ttd'] ?? '{}'),
```

Komentarnya membalik logika kanonikalisasi. Mengambil string apa adanya membuat
hash bergantung pada urutan kunci sebagaimana tersimpan. Kalau dua orang
menandatangani dengan urutan berbeda, `signature_values` jadi
`{"ttd_b":…,"ttd_a":…}` alih-alih `{"ttd_a":…,"ttd_b":…}` — **isi identik, hash
berbeda.**

Akibatnya dokumen yang tidak diapa-apakan bisa dilaporkan berubah.

Yang benar: decode → urutkan kunci **rekursif** → encode deterministik → hash.
Standarnya RFC 8785 (JSON Canonicalization Scheme), padanan C14N untuk XML.

### 3.2 Tanda tangan tidak boleh menutup tanda tangan lain

Kalau hash tanda tangan A mencakup `signature_values` — yang berisi tanda
tangan B — maka B menandatangani sesudahnya akan **merusak A**:

```
A tanda tangan  → hash A dihitung (baru ada A)
B tanda tangan  → signature_values berubah
                → hash A tidak cocok
                → A dilaporkan "berubah"   ← padahal A tidak disentuh
```

Aturan bakunya: tanda tangan menutup **isi**, bukan tanda tangan orang lain. Di
PDF multi-signature tiap tanda tangan menutup byte sampai titiknya sendiri;
tanda tangan berikutnya ditambahkan sebagai incremental update dan tidak
membatalkan yang sebelumnya.

### 3.3 Gambar tanda tangan disimpan inline — lihat §7

---

## 4. Desain: signature record

Saat sebuah TTD diisi (mode gambar **maupun** QR), tulis satu baris:

| Kolom | Isi |
|---|---|
| `document_id` | dokumen |
| `signature_id_within_doc` | urutan slot dalam dokumen |
| `signer_id` | identitas dari sesi host |
| `signer_role` | role **saat itu** — snapshot, bukan referensi |
| `provider` / `level` | `hmac` / `1` untuk tahap awal |
| `envelope_format` / `envelope` | `hmac` / hasil `HmacProvider::sign()` |
| `content_hash` | lihat §5 |
| `signed_at` | sekarang, presisi milidetik |
| `verify_status` | `valid` |

`signer_role` di-snapshot dengan sengaja: kalau orangnya pindah jabatan tahun
depan, dokumen tetap menunjukkan jabatan saat menandatangani.

### Mode gambar tetap ada

Gambar dan QR adalah dua **tampilan** untuk satu tindakan yang sama. Bukan
pilihan antara aman dan tidak. Bagi petugas mode gambar tidak berubah: gambar,
simpan, selesai — hanya sekarang ada recordnya.

## 5. Aturan hash

### Per tanda tangan

```
content_hash = SHA256( canonical({
    document_uuid, template_uuid, template_version,
    fields:    <field_values, kunci terurut rekursif>,
    ttd_id:    <slot ini>,
    ttd_value: <nilai slot ini SAJA>
}) )
```

Slot lain tidak masuk sama sekali. Hasilnya:

| Kejadian | Tanda tangan A |
|---|---|
| Urutan penandatanganan berbeda | tidak terpengaruh |
| B menandatangani sesudahnya | tidak terpengaruh |
| Ada field diedit | **berubah** — memang pernyataannya berubah |
| Gambar A ditukar | **berubah** |
| Gambar B ditukar | A aman, B berubah |

### Dua hash, dua tujuan

| | Cakupan | Berubah saat |
|---|---|---|
| `documents.content_hash` (sudah ada) | seluruh dokumen termasuk semua TTD | tiap tanda tangan baru — **wajar**, ini sidik jari keadaan dokumen |
| `signatures.content_hash` (baru) | isi + satu slot | hanya kalau isi atau slot itu berubah |

Keduanya memakai fungsi kanonikalisasi yang sama. Tulis sekali, tes, pakai dua
tempat.

## 6. Desain QR mode TTD

```
<verify_base>/<slug>?s=<hmac(slug|ttd_id)>&ttd=<ttd_id>
```

| Bagian | Alasannya |
|---|---|
| `slug` | dokumen yang mana |
| `ttd_id` | tanda tangan yang mana — tanpa ini, empat penanda tangan menghasilkan empat QR identik |
| `s` | HMAC menutup **slug + ttd_id**. Kalau `ttd_id` di luar HMAC, `&ttd=` bisa ditukar dan QR "Kepala Unit" diarahkan ke tanda tangan orang lain |

URL lama (HMAC slug saja) harus tetap lolos — halaman verify mencoba dua-duanya.

**Data pasien tidak masuk QR.** QR yang tercetak di kertas bisa dipindai siapa
saja; URL verifikasi menyerahkan keputusan siapa boleh melihat apa ke halaman
verify, tempat kontrol akses berlaku.

**Waktu tanda tangan juga tidak masuk QR.** Timestamp yang dicetak di dalam QR
adalah teks tanpa jaminan — siapa pun bisa membuat QR bertanggal apa pun. Waktu
yang bisa dipercaya ada dua tingkat: `signed_at` di record (dijamin oleh kontrol
atas server), dan token RFC 3161 dari TSA (mengikat bahkan kalau server tidak
dipercaya). Klien TSA sudah ada di `HttpTimestampClient`, kolomnya sudah ada,
tapi belum pernah dipanggil.

### Tiga jalur QR menyatu jadi dua

Ada **tiga** jalur QR di generate.php, bukan dua. Yang ketiga terlewat pada
tinjauan pertama:

| | Pemicu | Isi sekarang |
|---|---|---|
| QR terpisah | `qr-placeholder` `data-qr="<field>"` | field apa pun, termasuk `{verify_url}` |
| TTD QR mode | toggle per slot (`_ttd_mode_<id>`) | pattern template / teks manual — **tanpa makna verifikasi** |
| **Verify QR mode** | toggle dokumen (`_show_verify_qr`), tombol toolbar "QR: ON/OFF" | verify URL dokumen — **sama untuk semua slot**, seperti diakui komentarnya sendiri |

**Mode verify QR sudah punya alur interaksi yang dicari:** tombol → modal
konfirmasi → simpan → reload → QR muncul. Sudah jalan, sudah ada modalnya.
Yang salah hanya isinya.

Jadi jangan dipensiunkan — **naikkan isinya, lalu keduanya menyatu:**

| | Setelah Fase 3 |
|---|---|
| TTD QR mode | pointer ke tanda tangan **slot ini** |
| Verify QR mode | **"ubah semua slot ke mode QR sekaligus"** |

Isinya datang dari satu tempat. Verify QR mode berhenti jadi fitur terpisah dan
jadi tombol kenyamanan: sekali klik untuk semua slot alih-alih satu per satu.

Yang berkurang: satu jalur render (`$verifyQrArea`), satu logika override
(`$imgDisplayOverride`), satu fungsi JS (`generateVerifyQr`). **Tidak ada fitur
yang hilang.**

**Alur modalnya dipakai ulang, bukan ditulis ulang.** Fase 3 tidak perlu
mengarang UI baru — `toggleVerifyQrMode()` + `confirmVerifyQrMode()` sudah
melakukan konfirmasi, simpan, dan reload. Yang berubah: apa yang disetelnya.

**Alasan yang harus dipertahankan:** mode ini menyembunyikan **semua** gambar
tanda tangan. Gambar bisa disalin dan ditempel ke dokumen lain; QR yang menunjuk
record server tidak bisa. Jadi "sembunyikan gambar, tampilkan QR" adalah pilihan
anti-pemalsuan yang sah, dan layak tetap ada sebagai toggle massal — bukan
sekadar sisa arsitektur lama.

### Yang ditampilkan halaman verify saat `?ttd=`

| | Dari |
|---|---|
| Siapa + perannya | `signer_id`, `signer_role` |
| **Kapan** | `signed_at` |
| Sah / berubah | hitung ulang hash isi sekarang, bandingkan `content_hash` |
| Dicabut | `verify_status` |

## 7. Pertumbuhan penyimpanan

### Hasil pengukuran (46 dokumen, 2026-07-30)

| | KB | Porsi |
|---|---|---|
| `signature_values` | **757** | **97,3%** |
| `field_values` | 21 | 2,7% |
| `floating_elements` | 0 | 0% |
| Rata-rata gambar TTD per dokumen | **16,5** | |

Terukur 778 KB, tabel di disk 1,7 MB — selisihnya overhead halaman InnoDB,
fragmentasi, dan indeks. Rasio ~2,2× itu normal untuk kolom JSON.

**Sebab, seperti disebut komentar skemanya sendiri:**

```sql
signature_values JSON NULL COMMENT 'Base64 signature images per TTD placeholder'
```

Canvas tanda tangan berukuran **850×400** dan diekspor dengan
`toDataURL('image/png')` — RGBA penuh, tanpa dipangkas, tanpa penurunan
resolusi. 340.000 piksel, yang mayoritasnya transparan.

### Ada DUA masalah, dan yang lebih mahal bukan ukuran disk

**(a) Biaya baca — ini yang paling menyakitkan.**

`signature_values` termasuk dalam daftar kolom `SELECT` di
`DocumentRepository`, dan `findRecent()` melayani sampai **1000 baris**. Jadi
daftar dokumen menarik setiap gambar tanda tangan meski tidak satu pun
ditampilkan:

| Ukuran daftar | Data gambar yang ditarik |
|---|---|
| 100 baris | ~1,6 MB |
| 1000 baris | ~16 MB |

Itu terjadi pada setiap pemuatan daftar. Ukuran disk tumbuh perlahan; biaya ini
dibayar terus-menerus.

**(b) Ukuran disk.** 16,5 KB/dokumen → 10.000 dokumen ≈ 165 MB data gambar,
~360 MB di disk. Terkelola, tapi backup dan replikasi ikut membesar.

### Perbaikan, diurutkan dari rasio manfaat/risiko terbaik

| | Cara | Hasil | Risiko |
|---|---|---|---|
| **1** | **Keluarkan `signature_values` dari projeksi daftar.** Muat hanya saat satu dokumen dibuka | Menghilangkan ~97% muatan query daftar. Tanpa migrasi, tanpa perubahan skema | Butuh projeksi terpisah untuk daftar vs ambil-satu; setiap pemanggil yang mengandalkan kolom itu dari hasil daftar harus diperiksa |
| **2** | **Normalisasi gambar sebelum disimpan** — pangkas ke bounding box + turunkan resolusi. Tetap PNG lossless | 16,5 KB → ~4–6 KB (3–4×). Sepenuhnya di sisi klien, jadi hemat juga di jaringan | Perlu melacak bounding box saat menggambar; gambar lama tidak ikut mengecil kecuali diproses ulang |
| **3** | Pindahkan gambar ke `ezdoc_signatures.envelope` | Baris dokumen jadi ringan permanen | **Tidak dipilih** — digantikan nomor 4, yang mengeluarkan gambar dari DB sama sekali |
| **4** | **Gambar sebagai file di disk** | DB paling ringan; gambar bisa dilayani langsung web server tanpa lewat PHP | Backup jadi dua tempat, file bisa yatim, dan bisa hilang sinkron saat restore — lihat syarat di bawah |

**Yang tidak kusarankan:** lossy (WebP/JPEG) untuk gambar tanda tangan. Kompresi
lossy meninggalkan artefak pada garis tipis, dan ini artefak yang orang anggap
bukti. Penghematannya tidak sebanding dengan pertanyaan "kenapa tanda tangan
saya kelihatan beda".

### Keputusan (2026-07-30): nomor 1, 2, dan 4

Kerjakan **1** (projeksi daftar), **2** (normalisasi), dan **4** (file di disk).
Nomor 3 tidak lagi diperlukan kalau 4 dikerjakan.

Keberatan awal terhadap nomor 4 adalah backup di dua tempat yang harus
konsisten — itu tetap berlaku. Yang mengubah timbangannya: **setelah Fase 1,
bukti kriptografisnya (hash + HMAC) ada di DB.** Kalau file gambar hilang,
tanda tangannya masih bisa dibuktikan; yang hilang tanda visualnya, bukan
buktinya.

Dua syarat yang tidak boleh dilewat:

**(a) Simpan hash gambar itu sendiri di DB.** Tanpa ini file di disk bisa
ditukar tanpa jejak — untuk tanda tangan, justru itu yang paling tidak boleh
terjadi. Dengan hash di DB, file yang diganti atau rusak langsung terdeteksi.
Ini juga yang menjaga aturan §5 tetap utuh: `ttd_value` di dalam hash mengacu
ke hash gambar, bukan ke path-nya — path bisa berubah, isi tidak boleh.

**(b) Kebijakan hapus.** Soft-delete menyimpan file; hard-delete
membersihkannya. Tanpa aturan ini file yatim menumpuk dan tidak ada yang tahu
mana yang masih dipakai.

Ditambah: **prosedur backup harus menyebut kedua tempat**, dan pemulihan harus
memulihkan keduanya ke titik waktu yang sama.

---

## 7b. Penguncian saat ada tanda tangan

**Pertanyaannya:** kalau 3 orang harus menandatangani dan orang pertama sudah,
apakah dokumen langsung terkunci? Lalu bagaimana yang berikutnya?

Dua pola industri bertemu di satu aturan:

| | Pola |
|---|---|
| **Envelope** (DocuSign, Adobe Sign) | Isi dibekukan saat proses tanda tangan **dimulai**, bukan saat tanda tangan pertama masuk. Semua penanda tangan menandatangani dokumen yang sudah beku |
| **PDF incremental** | Tiap tanda tangan menutup byte sampai titiknya sendiri; yang berikutnya menempel tanpa membatalkan yang sebelumnya |

> **Isi yang dikunci. Slot tanda tangan tetap terbuka.**

Itu bukan dua keadaan dari satu flag — dua hal berbeda yang dikunci. `is_locked`
sekarang mencampur keduanya, dan itulah sebabnya menyimpan setelah satu tanda
tangan bisa tertolak seluruhnya.

**Ini bukan pilihan kebijakan, tapi konsekuensi aturan hash §5:**

- Hash A mencakup `fields` → mengedit field setelah A menandatangani merusak A
  → field **harus** beku sejak tanda tangan pertama
- Hash A **tidak** mencakup slot lain → B menandatangani tidak menyentuh A
  → slot **boleh** tetap terbuka

Desainnya tidak dikarang; sudah ditentukan oleh apa yang tanda tangan nyatakan.

| Keadaan | Boleh | Tidak boleh |
|---|---|---|
| Belum ada tanda tangan | semua | — |
| ≥1 tanda tangan | isi slot yang **belum** ditandatangani | mengedit field apa pun |
| Semua slot terisi | — | apa pun; `is_locked` boleh diset otomatis di sini |

Tidak butuh kolom baru: "sudah ada ≥1 tanda tangan" diturunkan dari
`ezdoc_signatures` begitu Fase 1 jalan. `is_locked` tetap berarti "tertutup
penuh".

Mengedit field setelah ada tanda tangan → ditolak, dengan **"buat versi baru"**
sebagai jalannya. Itu memang guna versioning.

Menandatangani ulang slot yang sudah ditandatangani → ditolak. Itu pencabutan
lalu tanda tangan baru, dan harus jadi tindakan eksplisit, bukan efek samping
menyimpan.

### Celah: tidak ada flag "wajib tanda tangan"

Slot TTD tidak punya atribut yang menyatakan tanda tangannya wajib. Jadi "semua
yang wajib sudah menandatangani" **tidak bisa dihitung** — hanya "semua slot
terisi".

Kalau alur kerja punya penanda tangan opsional (misal 2 dari 3 cukup), itu
butuh atribut baru di designer, dan `is_locked` otomatis harus menghitung
terhadap yang wajib saja. **Belum diputuskan** — jangan diarang saat
implementasi.

**Catatan tentang nomor 3:** setelah Fase 1 dikerjakan, gambar tanda tangan
punya tempat alami di `ezdoc_signatures` — tabel itu sudah punya
`envelope MEDIUMBLOB`. Jadi pemindahannya sebagian datang gratis. Tetap jangan
digabungkan ke fase awal: memindahkan data yang sudah ada punya risiko sendiri,
dan tidak perlu ditanggung bersamaan dengan perubahan lain.

### Query pengukuran (untuk diulang nanti)

```sql
SELECT
    COUNT(*)                                                   AS baris,
    ROUND(SUM(LENGTH(COALESCE(field_values,'')))/1024)         AS field_kb,
    ROUND(SUM(LENGTH(COALESCE(signature_values,'')))/1024)     AS ttd_kb,
    ROUND(SUM(LENGTH(COALESCE(floating_elements,'')))/1024)    AS floating_kb,
    ROUND(AVG(LENGTH(COALESCE(signature_values,'')))/1024, 1)  AS ttd_kb_rata2
FROM ezdoc_documents;
```

Jalankan ulang setelah S2 untuk mengukur hasilnya — `ttd_kb_rata2` yang
diharapkan turun dari 16,5 ke ~4–6.

---

## 8. Fase

Tiap fase berguna sendiri dan bisa dihentikan di situ.

Fase S di bawah **tidak bergantung** pada yang lain — bisa dikerjakan lebih
dulu, sejajar, atau ditunda, tanpa memengaruhi apa pun.

### Fase S — penyimpanan (lihat §7)

- **S1.** Keluarkan `signature_values` dari projeksi daftar; projeksi terpisah
  untuk daftar vs ambil-satu. Periksa setiap pemanggil yang mengandalkan kolom
  itu dari hasil daftar. *Tidak menyentuh data lama.*
- **S2.** Pangkas gambar ke bounding box + turunkan resolusi sebelum dikirim,
  tetap PNG lossless. Sisi klien.
- **S3.** Pindahkan gambar ke disk. Wajib menyertakan: hash gambar di DB
  (deteksi tukar/rusak), kebijakan hapus (soft vs hard), dan prosedur backup
  yang menyebut kedua tempat. **Ada migrasi data** — 46 dokumen saat ini.

S1 menghilangkan ~97% muatan query daftar; S2 mengecilkan file 3–4×; S3
mengeluarkan gambar dari DB sepenuhnya.

Urutannya bukan sembarang: **S2 sebelum S3**. Memindahkan file yang sudah
dinormalisasi lebih murah daripada memindahkan yang belum, lalu menormalisasi
di tempat baru.

### Fase 0 — kanonikalisasi (kecil, memperbaiki bug aktif)

- Fungsi kanonikalisasi JSON: urutkan kunci rekursif, encode deterministik
- Pakai di `doc_verify_canonical_data()`
- Test: urutan kunci berbeda → hash sama; isi berbeda → hash beda

**Hash dokumen lama akan berbeda dari yang tersimpan.** Keputusan (§9 #1):
simpan versi algoritma di `content_hash_algo`, jangan hitung ulang. Dokumen
lama tetap diverifikasi dengan algoritma lamanya, dokumen baru pakai yang
kanonik. Alasannya: dokumen yang sudah tercetak mungkin hash-nya tercatat di
tempat lain, dan menghitung ulang berarti mengubah nilai yang sudah beredar.

Konsekuensi yang harus diterima: verifier menyimpan dua jalur hash selamanya.
Itu harga yang layak dibanding membatalkan bukti yang sudah dikeluarkan.

### Fase 1 — tanda tangan jadi record

- Saat TTD berubah nilainya → `SignatureRepository::insert()` dengan hash §5
- L1 HMAC (`HmacProvider`), tanpa sertifikat, tanpa TSA
- Berguna langsung: jejak siapa/kapan, dan perubahan isi setelah tanda tangan
  jadi terdeteksi — bahkan sebelum ada UI apa pun

### Fase 2 — halaman verify per tanda tangan

- Terima `?ttd=`, tampilkan tabel §6
- Tanpa ini, Fase 1 mengumpulkan data yang belum bisa dilihat

### Fase 3 — QR TTD + alur klik + penyatuan tiga jalur QR

- HMAC diperluas ke `slug|ttd_id`, URL lama tetap lolos
- Alur: klik mode QR → konfirmasi → OTP kalau slot minta → tercatat → QR muncul.
  **Pakai ulang** `toggleVerifyQrMode()` / `confirmVerifyQrMode()` yang sudah
  melakukan konfirmasi-simpan-reload; jangan tulis UI baru
- Verify QR mode berubah arti: dari "override semua slot dengan verify URL
  dokumen" jadi "ubah semua slot ke mode QR sekaligus". Isinya ikut jalur
  per-tanda-tangan yang sama
- Hapus jalur render duplikat: `$verifyQrArea`, `$imgDisplayOverride`,
  `generateVerifyQr()`
- Batalkan default `{verify_url}` untuk QR TTD (keliru — itu tugas QR terpisah)
- Beri jalan pulang untuk nilai manual yang membeku: konten tersimpan menang
  atas pattern, jadi nilai yang pernah diketik bertahan selamanya tanpa cara
  mengembalikannya ke otomatis

### Di luar lingkup rencana ini

L2/L3 sertifikat, pemanggilan TSA, UI pencabutan, migrasi penyimpanan gambar.
Masing-masing di atas fondasi ini dan pekerjaan sendiri.

## 9. Keputusan — sudah diambil (2026-07-30)

| # | Pertanyaan | Keputusan |
|---|---|---|
| 1 | Hash lama: hitung ulang, atau simpan versi algoritma? | **Simpan versi** di `content_hash_algo`. Dokumen lama tetap diverifikasi dengan algoritma lamanya; hash yang sudah beredar tidak diubah. Harganya: verifier menyimpan dua jalur selamanya |
| 2 | Mulai dari Fase 0 atau langsung 1? | **Fase 0 dulu.** Fase 1 memakai fungsi kanonikalisasi yang sama, jadi menulisnya sekali dan dites di tempat yang lebih sederhana |
| 3 | Penyimpanan gambar | **Terukur:** 97,3% dari data adalah gambar TTD, rata-rata 16,5 KB/dokumen. Kerjakan **S1** + **S2** + **S3 (file di disk)**. Syarat S3: hash gambar di DB, kebijakan hapus, backup dua tempat. Kompresi lossy tetap ditolak |
| 4 | Klik mode QR pada dokumen belum tersimpan | **Simpan otomatis.** Menandatangani adalah tindakan commit — tanda tangan tidak punya konsep draft |
| 5 | Penguncian saat ada tanda tangan (§7b) | **Isi dikunci, slot tetap terbuka.** Bukan pilihan kebijakan — konsekuensi aturan hash §5. Tidak butuh kolom baru |

### Belum diputuskan

| | Pertanyaan | Kenapa ditahan |
|---|---|---|
| A | Flag "wajib tanda tangan" per slot | Tidak ada atributnya sekarang, jadi "semua yang wajib sudah tanda tangan" tak bisa dihitung. Kalau ada penanda tangan opsional, ini perlu. Jangan diarang saat implementasi |

### Urutan pengerjaan

```
S1 ──── S2 ──── S3            (mandiri; S2 sebelum S3)
        Fase 0 ── Fase 1 ── Fase 2 ── Fase 3
        kanonik   record     verify    QR + alur klik
                    │
                    └─ §7b penguncian ikut di sini
```

Jalur S mandiri, boleh kapan saja — kecuali S2 harus sebelum S3. Fase 0→3
berurutan karena tiap fase memakai hasil sebelumnya. Aturan penguncian §7b
menempel di Fase 1, karena baru di situ "sudah ada tanda tangan" bisa
ditanyakan.

## 10. Perubahan yang sudah masuk sebelum rencana ini

Dua hal sudah terlanjur dikerjakan saat pertanyaannya belum tuntas dijawab:

| | Status |
|---|---|
| Tombol "Edit QR" jadi hover-only | **Tetap** — benar dan tidak tergantung rencana ini. Sekalian menemukan bahwa tulisan "Edit QR" selama ini ikut tercetak di kertas |
| Default `{verify_url}` untuk QR TTD | **Keliru** — menduplikasi QR terpisah dan tidak menyebut tanda tangan siapa pun. Dibatalkan di Fase 3, atau lebih awal kalau diminta |
