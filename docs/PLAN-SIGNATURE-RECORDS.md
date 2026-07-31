# Plan — Tanda tangan sebagai record, QR TTD, dan pertumbuhan penyimpanan

Status: **Fase 0, 1 selesai; Fase 2 logika selesai** (2026-07-30) — sisanya rancangan disepakati, keputusan §9 diambil
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
| **QR mode TTD** (toggle per slot) | QR **adalah tanda tangannya** | ✅ pointer ke tanda tangan slot itu |
| **Mode verify QR** (toggle dokumen, tombol "QR: ON/OFF") | Sembunyikan semua gambar TTD, ganti QR yang bisa dipindai | ✅ "ubah semua slot ke mode QR sekaligus" |

QR mode TTD seharusnya menunjuk ke **tanda tangan itu**. Masalahnya: tanda
tangan belum berupa record apa pun. Tidak ada yang bisa ditunjuk.

Mode verify QR sudah punya alur interaksi yang benar (tombol → konfirmasi →
simpan → QR muncul) tapi isinya satu URL untuk semua. Setelah tanda tangan jadi
record, keduanya menyatu — lihat §6.

**Status: Fase 0–3 selesai 2026-07-30.** Tiga jalur jadi dua, isinya dari satu
tempat. Yang tersisa jalur S (penyimpanan gambar, §7) — mandiri dari ini.

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

### 3.1 Hash dokumen tidak dikanonikalisasi — ✅ diperbaiki Fase 0

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

**Diperbaiki di Fase 0** — `Ezdoc\Support\CanonicalJson`, lihat §8.

### 3.2 Tanda tangan tidak boleh menutup tanda tangan lain — ✅ diterapkan Fase 1

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
<verify_base>/<slug>?s=<mac>&ttd=<ttd_id>
```

| Bagian | Alasannya |
|---|---|
| `slug` | dokumen yang mana |
| `ttd_id` | tanda tangan yang mana — tanpa ini, empat penanda tangan menghasilkan empat QR identik |
| `s` | HMAC menutup **slug + ttd_id**. Kalau `ttd_id` di luar HMAC, `&ttd=` bisa ditukar dan QR "Kepala Unit" diarahkan ke tanda tangan orang lain |

Bentuk yang ditandatangani, bukan sekadar `slug|ttd_id` — lihat Fase 3 untuk
alasan panjang ditulis di depan tiap komponen.

URL lama (HMAC slug saja, tanpa `&ttd=`) tetap lolos. Tapi URL yang **membawa**
`ttd` wajib punya MAC yang mencakup ttd itu: menerima MAC slug-saja di situ
berarti siapa pun bisa menempel `&ttd=` pilihannya ke URL dokumen yang sah.

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

### Revisi keputusan (2026-07-30, sesudah S2): abstraksi dulu, backend jadi config

Keputusan di bawah (nomor 4, file di disk) **direvisi**. Dua hal mengubahnya, dan
keduanya baru terlihat setelah S2 diukur.

**(a) S2 justru membuat scan daftar lebih berat.** `ezdoc_documents` pakai
`ROW_FORMAT=Dynamic`, page 16 KB, MySQL 8.0.33 — terverifikasi, bukan asumsi. Di
format itu kolom variabel dipindah off-page hanya kalau barisnya melebihi separuh
page (~8126 byte):

| | avg `signature_values` | Baris | Penyimpanan |
|---|---|---|---|
| Sebelum S2 | 17.664 B | ~18 KB | off-page — inline cuma pointer 20 byte |
| Sesudah S2 | ~3.500 B | ~4 KB | **inline di clustered index** |

`EXPLAIN` query daftar: `type=ref key=idx_deleted, Backward index scan` — index
sekunder lalu ambil baris dari clustered index satu per satu. Lebar baris langsung
menentukan berapa page dibaca. Jadi S1 saja tidak cukup lagi: gambarnya ikut
terbaca walau tidak di-SELECT.

**(b) Standar industri memang disk — tapi ada ambangnya, dan 3,5 KB di sisi yang
salah.** Rails ActiveStorage, Django `FileField`, Laravel `Storage`, Symfony
VichUploader: semuanya menaruh byte di luar DB. Tapi referensi kanoniknya,
**"To BLOB or Not To BLOB"** (Sears, van Ingen, Gray — Microsoft Research, 2006),
mengukurnya: objek **di bawah ~256 KB lebih baik di database**, di atas ~1 MB
lebih baik di filesystem. Tanda tangan 3,5 KB ada 70× di bawah ambang itu — NTFS
cluster 4 KB berarti file 3,5 KB memakai lebih banyak ruang daripada kolom yang
dipadatkan, dan tiap baca jadi open/read/close sendiri.

Satu keberatan yang dipakai sebelumnya **gugur**: "file yatim" bukan alasan
menolak disk. ActiveStorage punya masalah yang sama dan menyelesaikannya dengan
purge job terjadwal (`Blob.unattached`), bukan dengan menghindari disk.

**Yang sebenarnya diajarkan standar itu bukan "disk", tapi bentuknya:** satu baris
blob yang jadi sumber kebenaran identitas (`key`, `checksum`, `byte_size`,
`content_type`) dan service yang bisa ditukar. Dengan bentuk itu, "disk atau
tabel" berhenti jadi percabangan arsitektur dan jadi pilihan config.

Untuk tanda tangan, tujuan akhir industri sebenarnya bukan disk maupun tabel —
DocuSign dan Adobe Sign menanam tampilannya **di dalam PDF yang ditandatangani**
(appearance stream PAdES), DB cuma menyimpan jejak audit. Di luar lingkup rencana
ini, tapi ia menjelaskan kenapa jangan terlalu banyak diinvestasikan pada
"di mana PNG-nya disimpan".

#### Keputusan (2026-07-30)

| | |
|---|---|
| Bentuk | Tabel `ezdoc_blobs` + `BlobStore` yang bisa ditukar |
| Default | **backend database** — yang diukur lebih baik di 3,5 KB |
| Disk | **dibangun dan siap pakai**, dipilih lewat config |
| Akses file (disk) | Di luar webroot, dilayani action PHP yang memeriksa hak akses. Tanda tangan bukan aset publik: sebagai file statis, siapa pun yang punya URL bisa melihatnya, dan URL bocor lewat riwayat browser, log proxy, dan referer |
| Nomor 4 (file di disk sebagai satu-satunya jalan) | **Dibatalkan** — jadi salah satu backend, bukan arsitekturnya |

#### Kunci = isi, dan kenapa itu wajib

`key` adalah SHA-256 byte-nya. Bukan pilihan gaya: hash tanda tangan (§5)
mencakup `ttd_value`, dan begitu gambarnya keluar dari baris dokumen `ttd_value`
jadi referensi. Referensi yang menyebut **lokasi** bisa menunjuk byte lain besok;
yang menyebut **isi** tidak bisa. Ini persis syarat (a) di bawah — "simpan hash
gambar di DB" — terpenuhi dengan sendirinya, karena kuncinya sudah checksum-nya.

Konsekuensi lain yang ikut gratis: byte identik yang disimpan dua kali menempati
satu baris, dan file yang ditukar **tidak bisa** lolos `get()` — mismatch dibaca
sebagai *tidak ada*, bukan sebagai byte lain yang diam-diam dilayani.

#### Status implementasi

| | |
|---|---|
| S3a — `Blob`, `BlobStore`, `DatabaseBlobStore`, `DiskBlobStore` | ✅ 51 test, satu kontrak dijalankan ke **kedua** backend |
| S3b — migrasi `ezdoc_blobs` (MySQL + SQLite) | ✅ paritas ditulis bersamaan, bukan menyusul |
| S3c — `BlobStoreFactory` | ✅ konstanta lalu env (preseden `SigningKey`), default `database` |
| S3d — jalur simpan | ✅ `SignatureImageStore::valueToPersist()` |
| S3e — jalur baca | ✅ `ezdoc_resolve_signature_image()`, layar + PDF |
| S3f — backfill + purge | ✅ dua CLI, dry-run terverifikasi ke server |

#### Koreksi: "memindahkan byte apa adanya menjaga hash" — SALAH

Ditulis di revisi di atas dan tidak benar. Byte-nya memang tidak berubah, tapi
yang masuk hash adalah **nilai slot di dokumen**, bukan byte-nya. Mengganti data
URI dengan referensi mengubah `ttd_value`, jadi tanda tangan yang sudah tercatat
akan menghitung ulang ke hash lain dan terbaca `tampered`.

Konsekuensinya berlaku di dua tempat, dan keduanya dijaga dengan aturan yang sama:

| | |
|---|---|
| Jalur simpan | `valueToPersist()` mengembalikan nilai apa adanya kalau sama dengan yang tersimpan. Form mengirim ulang setiap tanda tangan pada tiap simpan — tanpa aturan ini, penyimpanan pertama setelah fitur aktif akan menulis ulang **setiap** slot dan membatalkan setiap tanda tangan di dokumen itu |
| Backfill | Slot yang punya record tanda tangan dilewati, dan dilaporkan. Gambarnya tetap inline sampai dokumennya dapat versi baru — dan itu memang gunanya versi baru |

Terukur di server (dry-run, 2026-07-30): **36 gambar (756,8 KB) bisa dipindah,
1 gambar (18,5 KB) harus tetap** karena slotnya sudah punya record.

Jalan keluar untuk yang tersisa, kalau nanti diperlukan: versi payload baru yang
mengkanonikalisasi `ttd_value` jadi hash byte gambarnya, sehingga data URI dan
referensi menghasilkan hash yang sama. `content_hash_algo` sudah menamai bentuk
payload, jadi v1 dan v2 bisa hidup berdampingan. Di luar lingkup sekarang.

#### Keputusan yang diambil saat implementasi

| | |
|---|---|
| Gambar dibuka **inline ke halaman**, bukan dilayani URL tersendiri | URL gambar tanda tangan yang bisa diambil sendiri bocor lewat riwayat browser, log proxy, dan header referer. Di dalam halaman, byte-nya ikut kontrol akses halaman itu. Jalur PDF menuntut hal sama karena alasan lain: dompdf tidak membawa sesi |
| Input tersembunyi tetap membawa **referensi mentah**, bukan gambar hasil resolusi | Kalau tidak, tiap simpan akan terlihat seperti perubahan nilai slot — dan lihat koreksi di atas |
| Consumer tanpa tabel `ezdoc_blobs` tetap jalan | Diperiksa sekali per request; gambar tetap inline seperti sebelumnya. Menggagalkan penyimpanan karena optimasi penyimpanan belum dipasang adalah pertukaran yang tidak masuk akal |
| Backfill membaca ulang sebelum menulis dokumen | Backfill yang menulis dokumen dulu lalu menemukan masalah sudah kehilangan satu-satunya salinan |
| Purge punya masa tenggang (default 48 jam) | Tanda tangan disimpan saat menyimpan dokumen yang masih bisa **ditolak** sesudahnya — oleh guard §7b, oleh validasi. Purge di jendela itu akan menghapus objek yang percobaan berikutnya akan rujuk |
| Dokumen soft-deleted dihitung merujuk | Soft delete bisa dibatalkan; membuang gambarnya membuatnya diam-diam tidak bisa |
| Tanpa FK dari blob ke dokumen | Satu objek bisa dirujuk banyak dokumen (isi sama → kunci sama), jadi tidak ada satu induk. Keterjangkauan dihitung dengan memindai referensi — pola `Blob.unattached` ActiveStorage |

Kontraknya dijalankan ke kedua backend dengan tuntutan yang sama, sengaja: janji
"pindah backend cuma config" hanya berlaku kalau keduanya menjawab tuntutan yang
sama. Suite terpisah per backend akan menyimpang, dan penyimpangannya muncul
sebagai "tadinya jalan sebelum kita pindah".

Sampai S3c, **tidak ada perilaku yang berubah** — kelas-kelasnya belum terpasang
ke jalur mana pun. Migrasinya bisa dijalankan lebih dulu dengan aman.

### Keputusan LAMA (2026-07-30): nomor 1, 2, dan 4 — lihat revisi di atas

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

### S1 — projeksi daftar ✅ SELESAI 2026-07-30

`DocumentRepository::$listOmittedCols = ['signature_values']`.
`findRecent()` / `listByTemplate()` / `listByStatus()` memakai `listCols()`;
`findById()` / `findByUuid()` / `findByPublicSlug()` tetap penuh. Tanpa migrasi,
tanpa perubahan skema. Pemisahan projeksi daftar dan ambil-satu itu pola biasa —
`$hidden` Eloquent, partial DQL Doctrine, `.only()`/`.defer()` Django.

**Yang ditambahkan di atas pola itu, dan inti keamanannya:** `Document` mencatat
apakah kolomnya ikut dimuat. Tanpa itu, dokumen dari daftar akan menjawab
"belum ditandatangani" — `[]`, tidak bisa dibedakan dari kebenaran, diberikan
dengan percaya diri, tanpa satu pun error.

| | |
|---|---|
| `hasLoadedSignatureValues()` | Ditanyakan kalau dokumennya bisa berasal dari daftar |
| `getSignatureValues()` | **Melempar** `LogicException` kalau tidak dimuat, dan pesannya menyebut id dokumen serta remedinya (`findById()`) |
| `toArray()` | `null`, bukan `[]` — ini yang masuk respons JSON dan audit log, dan catatan audit yang salah lebih buruk daripada tidak ada catatan |

Melempar, bukan lazy-load. Doctrine melempar pada properti proxy yang belum
di-inisialisasi; Eloquent dengan `preventLazyLoading()` juga. Memuat sendiri
secara diam-diam akan menyembunyikan N+1 di halaman daftar — justru alasan kolom
ini dikeluarkan.

21 test baru (`tests/Document/`), area yang sebelumnya nol test. Diverifikasi
menangkap regresinya: mengosongkan `$listOmittedCols` membuat 5 test gagal.

### S2 — normalisasi gambar ✅ SELESAI 2026-07-30

`normaliseSignaturePng()` di `generate_scripts.php`. Dua langkah, keduanya di
sisi klien jadi hemat di jaringan juga: pangkas ke kotak tinta, lalu turunkan
resolusi sampai sisi terpanjang ≤ 400 px. Tetap PNG lossless.

**Terukur pada 37 gambar tanda tangan sungguhan (bukan perkiraan):**

| | |
|---|---|
| Sebelum | 580,8 KB — rata-rata **15,7 KB** |
| Sesudah | 129,6 KB — rata-rata **3,5 KB** |
| Faktor | **4,48×** |

Rencana memperkirakan 3–4×; hasilnya sedikit lebih baik. Dan dimensinya
menunjukkan **penghematannya hampir seluruhnya dari pemangkasan**, bukan dari
penurunan resolusi: 850×400 → 229×325, 172×285, 257×309. Batas 400 px jarang
tersentuh, jadi mayoritas tanda tangan tidak diresampling sama sekali — lossless
tanpa syarat, tanpa pelembutan garis. Batas itu tetap ada sebagai plafon untuk
orang yang menggambar selebar kanvas.

Kotak tintanya dihitung dari **data piksel**, bukan dari pelacakan goresan.
Modal ini juga memuat tanda tangan yang sudah ada ke kanvas untuk diedit;
pelacakan goresan tidak melihat piksel dari gambar itu, jadi menyimpan tanpa
menggambar apa pun akan memangkas habis tanda tangan yang sudah ada. Memindai
alpha melihat semuanya. (Latar putih kanvas dari CSS, bukan dicat ke bitmap-nya,
jadi alpha bisa dipercaya.)

**Bonus yang tidak direncanakan:** kanvas kosong sekarang ditolak. Sebelumnya ia
tersimpan sebagai data URI 850×400 penuh transparansi — "tanda tangan" yang tidak
menggambarkan apa pun, yang menandai slotnya sudah ditandatangani dan, sejak
Fase 1, menuliskan record tanda tangan untuk goresan yang tidak ada.

**Gambar lama TIDAK BOLEH diproses ulang.** Ini syarat kebenaran, bukan
kemudahan: `ttd_value` ada di dalam hash tanda tangan (§5), jadi meng-encode
ulang byte-nya mengubah hash dan setiap tanda tangan yang sudah tercatat akan
berubah jadi `tampered`. Penghematan 4,48× berlaku untuk tanda tangan baru saja,
dan itu memang satu-satunya yang benar.

**Belum ada harness test JS di repo ini** (tidak ada `package.json`).
`normaliseSignaturePng()` diverifikasi lewat pengukuran terhadap gambar sungguhan,
bukan lewat unit test. Itu celah nyata yang layak dicatat.

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

### Fase 0 — kanonikalisasi ✅ SELESAI 2026-07-30

- **`Ezdoc\Support\CanonicalJson`** — RFC 8785 (JCS): sort kunci rekursif
  menurut **UTF-16 code unit** (bukan code point — bedanya nyata untuk emoji
  dan CJK jarang, dan sudah dibuktikan empiris), tanpa whitespace, escaping
  minimal, non-ASCII literal.
  `encode()`, `hash()`, `fromJson()`.
- **Float ditolak**, menyimpang dari §3.2.2.3 dengan sengaja: keluaran float PHP
  bergantung pada `serialize_precision`, jadi nilai yang sama bisa jadi `0.1` di
  satu host dan `0.10000000000000001` di host lain. Hash tanda tangan yang
  berubah karena php.ini lebih buruk daripada yang menolak dihitung. Pesan
  errornya menyebut path kunci yang bermasalah.
- **25 test.** Yang diuji sifatnya, bukan string harapan: dua input yang membawa
  data sama tidak boleh menghasilkan byte berbeda, dan data berbeda tidak boleh
  bertabrakan. Termasuk bentuk payload per-tanda-tangan §5.
- `doc_verify_canonical_data()` memakainya — terbukti: urutan kunci tersimpan
  tidak lagi mengubah hash, isi yang diubah tetap mengubahnya.

**Regresi yang tertangkap sebelum sampai server:**
`page/form_pembuat_surat_cetak_v2.php` memanggil
`doc_verify_compute_and_store_hash()` tanpa pernah memuat autoloader ezdoc —
halaman itu masih hidup, dirujuk dari `ajax_open_dokumen.php`. Kelas baru itu
akan membuatnya fatal. `doc_verify_canonical_data()` sekarang memuat
autoloader-nya sendiri kalau belum ada, jadi file helper itu berdiri sendiri
apa pun urutan include pemanggilnya.

**Keputusan §9 #1 gugur — dan ke arah yang lebih sederhana.**

Fakta yang ditemukan saat mengerjakannya: `content_hash` **tidak pernah
dibandingkan** oleh kode mana pun, dan **tidak pernah ditampilkan** — tidak di
view, tidak di halaman verify, tidak tercetak. Hanya dihitung, disimpan, dan
dikembalikan di respons simpan untuk debug.

Jadi jalur ganda tidak diperlukan: mengubah kanonikalisasi tidak punya korban.
Tidak ada hash tercetak yang jadi tak cocok. 46 baris lama menyimpan nilai yang
kini basi, dan tidak ada yang membacanya — akan terkoreksi sendiri saat
dokumennya disimpan berikutnya.

Versioning algoritma tetap relevan, tapi tempatnya di Fase 1:
`ezdoc_signatures.content_hash_algo` (kolomnya sudah ada), untuk hash
per-tanda-tangan yang **memang** akan diverifikasi.

### Fase 1 — tanda tangan jadi record ✅ SELESAI 2026-07-30

**1a — fondasi.** `SigningKey` (HKDF RFC 5869, pemisahan domain dari secret
verify URL; secret <32 byte ditolak bukan di-stretch; absen → `null` supaya
penyimpanan tidak terhalang) dan `SignaturePayload` (aturan §5 jadi kode,
`ALGO = sha256-jcs-v1`). 27 test.

**1b — pencatatan.** `save_document.php` menulis satu baris per TTD baru/berubah:
signer + role snapshot, `content_hash` §5, amplop HMAC L1, `signed_at` presisi
milidetik. Terverifikasi terhadap MySQL nyata: hash berbeda per slot, pencatatan
idempoten, dan record-nya benar-benar bisa diverifikasi — hitung ulang hash lalu
cek amplop memberi SAH, dan pecah setelah satu field diubah.

Dua cacat ketemu sambil mengerjakan: `$existingTtdValues` diambil dengan
`_doc_id` mentah sebelum pencarian slot mengubah `$doc_id` (dipakai snapshot
terpisah); dan `date('Y-m-d H:i:s.v')` selalu menghasilkan `.000` karena `date()`
menerima timestamp integer detik — presisi yang jadi alasan kolom `DATETIME(3)`
terbuang tanpa suara.

**1c — penguncian §7b.** Diuji kelima kasusnya terhadap MySQL:

| Kasus | Hasil |
|---|---|
| Tanda tangan slot A | SUKSES |
| Tanda tangan slot B (A sudah ada) | **SUKSES** — slot tetap terbuka |
| Ubah nilai field | DITOLAK 409 |
| Ganti tanda tangan A | DITOLAK 409 |
| Simpan ulang tanpa perubahan | SUKSES |

Baris kedua itu inti §7b: orang kedua dan ketiga tetap bisa menandatangani.
Baris terakhir juga wajib — form mengirim ulang semua tanda tangan tiap simpan,
jadi tanpa itu setiap penyimpanan setelah tanda tangan pertama akan gagal.

Penolakannya membawa jalan keluar, bukan cuma "tidak boleh":
`signed_slots`, `changed_fields`, dan `remedy: new_version`.

**Guard ini pengingat dini, bukan batas keamanannya.** Verifikasi tetap
membandingkan hash dan akan melaporkan perubahan apa pun, tertangkap di sini
atau tidak. Karena itu guard-nya menyasar kasus yang jelas dan membiarkan field
BARU lewat — itu bisa template bertambah kolom, bukan orang mengubah pernyataan
yang sudah ditandatangani.

⚠️ **Inert sampai `EZDOC_HMAC_SECRET` diset.** Sudah diwiring di `koneksi.php`
dari `doc_verify_get_secret()`.

### Fase 1 — rincian rencana awal

- Saat TTD berubah nilainya → `SignatureRepository::insert()` dengan hash §5
- L1 HMAC (`HmacProvider`), tanpa sertifikat, tanpa TSA
- Berguna langsung: jejak siapa/kapan, dan perubahan isi setelah tanda tangan
  jadi terdeteksi — bahkan sebelum ada UI apa pun

### Fase 2 — verifikasi per tanda tangan ✅ LOGIKA SELESAI 2026-07-30

`Ezdoc\Signature\DocumentSignatureVerifier` — 17 test, SQLite in-memory.

Menanyakan **dua hal terpisah** per tanda tangan, dan memisahkannya itu seluruh
nilainya:

| Pertanyaan | Menjawab |
|---|---|
| Amplop HMAC-nya sah? | Apakah *record*-nya asli — ada yang mengedit barisnya? |
| Isi masih menghasilkan hash sama? | Apakah *dokumennya* berubah setelah ditandatangani? |

Menggabungkannya jadi satu boolean menghilangkan beda yang penting. Dokumen
yang diedit setelah tanda tangan itu kejadian biasa — seseorang merevisi dan
seharusnya membuat versi baru. Baris tanda tangan yang amplopnya gagal itu hal
lain: DB ditulis langsung, atau kuncinya berganti. Yang pertama masalah alur
kerja, yang kedua perlu diselidiki.

Status: `valid`, `tampered`, `untrusted`, `unverifiable`, `revoked`.
`summarise()` mengambil verdict terburuk — dokumen tidak jadi "sah" karena
mayoritas tanda tangannya sah.

Tampil di `verifikasi/index.php`, dan `?ttd=` diterima — pengikatan MAC-nya
dikerjakan di Fase 3.

### Fase 2 — rincian rencana awal

- Terima `?ttd=`, tampilkan tabel §6
- Tanpa ini, Fase 1 mengumpulkan data yang belum bisa dilihat

### Fase 3 — QR TTD + alur klik + penyatuan tiga jalur QR ✅ SELESAI 2026-07-30

**Pengikatan `ttd_id` ke MAC.** `doc_verify_sign_slug($slug, $ttdId = '')`.
Bentuk tingkat dokumen tetap HMAC atas slug mentah — itu satu-satunya alasan
cabangnya ada, supaya QR yang sudah tercetak tetap lolos. Bentuk per-tanda-tangan
memakai framing bertag dengan panjang di depan tiap komponen:

```
'ezdoc-ttd-v1' | strlen(slug) ':' slug | strlen(ttd_id) ':' ttd_id
```

Panjang ditulis di depan nilainya, bukan cuma dipisah pemisah. Dengan pemisah
saja, `sign('abc','def')` dan `sign('abc|def')` menghasilkan string yang sama —
hari ini tak terjangkau karena slug base62, tapi itu bersandar pada kebetulan
format. Pola length-prefix yang sama dipakai transcript hash TLS 1.3 dan
canonical request AWS SigV4. Slug non-base62 juga jatuh ke cabang bertag, jadi
konstruksinya injektif tanpa syarat.

`doc_verify_check_signature($slug, $sig, $ttdId = '')` **mewajibkan** MAC yang
mencakup `ttd` kalau URL membawanya. Menerima MAC slug-saja di situ akan
meniadakan seluruh gunanya: ambil URL dokumen yang sah, tempel `&ttd=` apa pun.

`?ttd=` diparse dan divalidasi **sebelum** pemeriksaan MAC di
`verifikasi/index.php` — nilainya ikut ditandatangani, jadi tidak boleh dibaca
ulang setelahnya.

**Nilai slot punya dua bentuk sah.** `Ezdoc\Signature\SignatureValue` — 14 test.
Sebelum ini mode QR **tidak pernah menulis record apa pun**: gerbang di
`save_document.php` menuntut data URI, jadi mode QR terlihat menandatangani
sementara QR-nya menunjuk record yang tidak ada. Sekarang `qr:v1` diterima —
dicocokkan persis, bukan prefix — dan lewat RBAC serta OTP yang sama.

Pemisahan gambar dari tanda tangannya sendiri bukan penemuan baru: PAdES
membedakan *appearance stream* dari nilai tanda tangan, dan invisible signature
sah tanpa appearance sama sekali.

**Pointer hanya muncul kalau slot sudah ditandatangani.** Layar maupun PDF.
QR tercetak yang menunjuk record yang belum ada akan selamanya menjawab "tanda
tangan tidak dikenal", dan kertas tidak bisa ditarik. Isi tersimpan dan pattern
template tidak ikut aturan ini — keduanya tidak pernah mengklaim ada record.
Itu juga yang membuat dokumen lama dengan QR yang diisi tangan tetap apa adanya.

**Urutan resolusi isi QR, sama di tiga tempat** (layar, PDF, JS):
`isi tersimpan` → `pattern template` → `pointer per tanda tangan`. Pattern yang
disetel template tapi field-nya kosong **tidak** diganti diam-diam dengan pointer.

**Alur klik.** Modal tanda tangan yang sama dipakai ulang dengan isi tengah
ditukar (`#signQrConfirmArea`). Alasannya bukan hemat markup: OTP, RBAC, cek
dokumen terkunci, dan pengelolaan sign token semuanya hidup di sana. Menandatangani
memicu `submitForm()` — tanda tangan adalah tindakan commit, tidak punya draft.

**Verify QR mode berubah arti** dari "override semua slot dengan verify URL
dokumen" jadi "ubah semua slot ke mode QR sekaligus". Ia memaksa QR bahkan pada
slot yang template-nya cuma mengizinkan gambar, karena alasannya bertahan: ia
menyembunyikan **semua** gambar tanda tangan, dan gambar bisa disalin ke dokumen
lain sementara pointer ke record tidak bisa.

**Yang dihapus, tanpa fitur yang hilang:** `$verifyQrArea`,
`$imgDisplayOverride`, `generateVerifyQr()`, `initVerifyQrOnLoad()`, CSS
`.ttd-area-verify-qr`/`.ttd-verify-qr-preview`, dan input readonly "URL
Verifikasi" di modal — URL itu tidak ada di QR mana pun lagi.

**Default `{verify_url}` untuk QR TTD dibatalkan.** Itu tugas QR terpisah.
Penggantinya pointer per tanda tangan, dan sengaja **bukan** pattern yang bisa
diketik: nilainya dihitung ulang tiap render dari slug + ttd_id, jadi rotasi
kunci atau pindah domain tidak membekukan URL basi ke dalam dokumen.

**Jalan pulang untuk nilai manual.** Mengosongkan isi QR mengembalikannya ke
otomatis; keterangan di prompt menyebut apa yang sebenarnya kembali — pattern
template kalau ada, kalau tidak pointer per tanda tangan.

**Bug lain yang tertangkap sambil jalan:**

| | Temuan |
|---|---|
| `doc_verify_resolve_qr_value()` | Regex penyembuhannya menuntut segmen path `/verifikasi/`, padahal base produksi berupa subdomain. Penyembuhan MAC/base basi **tidak pernah jalan** di konfigurasi sekarang |
| i18n | `materai.warning_title` dan `confirm.delete_logo` tidak ada di kedua bahasa; `designer.alert.generic_failed` kehilangan `{reason}` di id; `designer.toast.save_failed` memakai `{error}` di en padahal pemanggilnya mengirim `message` |

Dua tes baru menutup kelas bug i18n itu, bukan cuma kejadiannya:
`TranslationParityTest` (kunci + bentuk + placeholder sejajar antar bahasa) dan
`TranslationUsageTest` (setiap kunci yang dipanggil view benar-benar ada, dan
placeholder yang dikirim pemanggil dipakai terjemahannya).

Self-test URL verifikasi: `pengeluaran/doc_verify_selftest.php`, 32 pemeriksaan.

### Fase 3b — nama penanda tangan ditangkap saat menandatangani ✅ 2026-07-30

Ditemukan saat pemakaian: dokumen 37 punya `nama_ttd_1785380818383 = ''` sementara
slotnya **sudah ditandatangani**. Petugas menandatangani lebih dulu, lalu mengetik
namanya, lalu ditolak §7b — dan nama yang benar tidak akan pernah bisa masuk.
Setiap simpan sesudahnya gagal pada field yang sama, termasuk menekan tombol
"QR: ON→OFF" yang cuma menyetel tampilan tapi ikut mengirim ulang seluruh form.
Itu satu bug, bukan dua.

Guard-nya benar. Nama di bawah tanda tangan ada di dalam `fields`, jadi ia
tercakup hash — dan kalau bisa diubah sesudahnya, tanda tangan yang sama bisa
diatributkan ke orang lain. Mengecualikannya justru lebih buruk: aplikasi
mengizinkan perubahan yang lalu dilaporkan `tampered` oleh verifier.

Yang salah alurnya. Perbaikannya mengikuti DocuSign dan Adobe Sign — nama
penanda tangan ditangkap di dialog penandatanganan, bukan sebagai isi dokumen
yang diedit belakangan:

| | |
|---|---|
| Modal tanda tangan | Meminta nama, prefilled dari nilai sekarang, ditulis ke field **sebelum** tanda tangan diambil. Berlaku untuk mode gambar maupun QR |
| Nama kosong | Dikonfirmasi, tidak diblokir — sebagian slot memang tidak mencetak nama (cap, saksi tanpa identitas). Tapi konfirmasinya menyebut bahwa tidak ada kesempatan kedua |
| Setelah ditandatangani | Field-nya `contenteditable="false"` dengan penjelasan, jadi tidak ada lagi mengetik ke field beku lalu menabrak dinding |
| Record | `metadata.signer_name` — recordnya jadi bisa dibaca sendiri, tidak perlu membuka JSON dokumen untuk tahu nama siapa yang tercetak |
| Halaman verify | Menampilkan nama **dari record**, bukan dari dokumen. Dokumen menyatakan keadaan sekarang; record menyatakan apa yang ditandatangani. Saat keduanya berbeda, hash sudah melaporkannya, dan menampilkan keduanya membuat perbedaan itu terbaca alih-alih cuma "gagal" |
| Penolakan §7b | Menawarkan "Buat Versi Baru" — remedi yang server sebut di `remedy`. Sebelumnya toast tanpa jalan keluar |

Empat test verifier baru, termasuk pembedaan "tanpa nama" dari "belum dicatat":
nilai `null`, bukan `''`, karena keduanya fakta yang berbeda.

**Dokumen yang sudah dalam keadaan itu** (mis. #37) tetap perlu versi baru —
isinya beku dan itu memang aturannya. Yang berubah: sekarang tombolnya ada.

**Belum dikerjakan di fase ini:** flag "wajib tanda tangan" per slot (§9 A).
Halaman legacy `form_pembuat_surat_cetak_v2/v3.php` punya salinan `renderContent`
sendiri dan belum tahu `qr:v1` — dokumen yang ditandatangani mode QR akan
menampilkan gambar rusak kalau dibuka di sana.

### Di luar lingkup rencana ini

L2/L3 sertifikat, pemanggilan TSA, UI pencabutan, migrasi penyimpanan gambar.
Masing-masing di atas fondasi ini dan pekerjaan sendiri.

## 9. Keputusan — sudah diambil (2026-07-30)

| # | Pertanyaan | Keputusan |
|---|---|---|
| 1 | Hash lama: hitung ulang, atau simpan versi algoritma? | **Gugur.** Ternyata `content_hash` tidak pernah dibandingkan maupun ditampilkan di mana pun, jadi tidak ada korban — tidak perlu jalur ganda. Versioning pindah ke Fase 1 di `ezdoc_signatures.content_hash_algo`, tempat hash-nya memang akan diverifikasi |
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
