# Hapus versi: sebenarnya "sembunyikan", dan sebaiknya jujur begitu

**Lahir**: 2026-09-01
**Status**: rencana, belum dikerjakan
**Pemicu**: pertanyaan apakah pengguna biasa boleh ikut menghapus versi

---

## 1. Pertanyaannya berubah dua kali

**Mula-mula**: *"delete versi kan sudah soft delete, mungkin pengguna biasa bisa
ikut menghapus?"*

**Setelah datanya dilihat**: ternyata ada dua dokumen pasien bertanda tangan yang
terhapus hari ini, dan tidak ada apa pun yang menahannya. Rencana pertama karena
itu berpusat pada **penjaga**.

**Setelah dipikir ulang** (usul pemilik sistem): kalau menghapus sebenarnya cuma
menyembunyikan dari daftar — dokumennya tetap ada, tetap bisa dibuka, tetap
berlaku kalau sudah terbit, dan pengguna bisa mengembalikannya sendiri — maka
yang dibutuhkan bukan penjaga yang lebih ketat, melainkan **nama dan tampilan
yang jujur**.

Yang kedua benar. Dan yang mengejutkan: **sistemnya sudah sebagian besar
begitu.**

---

## 2. Keadaan sekarang, apa adanya

| | |
|---|---|
| Bentuk penghapusan | **Soft delete** — `deleted_at` + `deleted_by`, baris tidak pernah hilang |
| Dokumen terhapus masih bisa dibuka? | **Ya**, lewat `&preview_deleted=1`. Ada spanduk `DELETED` beserta tanggal dan pelakunya |
| Siapa yang bisa memakainya | **Superadmin saja** — `$preview_deleted = !empty($_GET['preview_deleted']) && $isSuperadmin` |
| Ada jalan menuju ke sana? | **Tidak.** Tidak satu pun tombol menambahkan parameter itu, dan `list_versions.php` menyaring `deleted_at IS NULL` |
| Nomor versi setelah disembunyikan | **Tidak didaur ulang** — sembunyikan v3, simpanan berikutnya jadi v4. Sudah benar, tidak perlu diapa-apakan |
| Tercatat di audit | Ya — `doc.deleted`, 20 baris |
| Alasan penghapusan | Tidak diminta, tidak dicatat |
| Mengembalikan | `restore_slot` — superadmin saja, dan **per slot**, bukan per versi |
| Izin menghapus | Superadmin bawaannya; bisa dilebarkan per template lewat `access_config.delete` (dipakai 0 dari 19 template) |
| Penjaga isi | Hanya `is_locked`. Tanda tangan **tidak** menahan |

Jadi bangunannya sudah berdiri. Yang kurang: pintu masuknya, tombol
kembalikannya, dan namanya.

---

## 3. Yang paling perlu diperbaiki: layar berbohong

```js
'Delete version v{version}? This cannot be undone.'
```

**Ini tidak benar.** Soft delete jelas bisa dibatalkan — `restore_slot` sudah
melakukannya.

Kalimat itu merusak ke dua arah sekaligus:

- Yang **seharusnya** membuang versi salah-isi jadi ragu, mengira permanen
- Yang **sudah** membuang tidak pernah meminta pengembalian, karena diberi tahu
  itu mustahil

Data hari ini cocok dengan gejala kedua: dua dokumen *Discharge Planning*
bertanda tangan (subjek 122605 dan 70825) terhapus, dan tidak ada yang
menanyakannya kembali.

**Keduanya masih utuh dan bisa dikembalikan.**

Kalimat peringatan yang salah lebih berbahaya daripada tidak ada peringatan:
orang menyesuaikan perilakunya dengan yang dikatakan sistem, dan di sini yang
dikatakan sistem keliru.

### Dan satu lagi: komentar mengaku baca-saja, kodenya tidak

```php
// Allow superadmin to preview soft-deleted document via ?preview_deleted=1 (read-only mode)
$preview_deleted = !empty($_GET['preview_deleted']) && $isSuperadmin;
```

`$isEditMode = true` tetap dipasang sesudahnya. Jadi dokumen tersembunyi terbuka
dalam keadaan **tampak bisa disunting**.

Penyimpanannya memang menahan — `save_document.php` menyaring `deleted_at IS
NULL`, jadi suntingannya tidak akan mendarat di baris itu. Tapi yang dilihat
pengguna adalah halaman yang mengundang disunting, dan suntingannya menghilang
tanpa penjelasan. Itu jenis kebingungan yang mahal dicari sebabnya.

---

## 4. Bentuk yang dituju

**Menghapus = menyembunyikan dari daftar.** Bukan membuang, bukan membatalkan.

| Sifat | Setelah "dihapus" |
|---|---|
| Barisnya | Tetap ada, tidak pernah dibuang |
| Bisa dibuka | Ya, dengan spanduk yang menyatakan keadaannya |
| Muncul di daftar versi | Tidak, kecuali penyaringnya dinyalakan |
| Dokumen yang sudah terbit | **Tetap berlaku** — menyembunyikan dari daftar tidak menarik kembali apa yang sudah di tangan orang |
| Bisa dikembalikan | Ya, oleh yang bisa menyembunyikannya |
| Tanda tangannya | Utuh, tidak tersentuh |

Pola ini bukan barang baru: Gmail memisahkan *Archive* dari *Delete*, Trello dan
GitHub memakai *Archive* dan *Close* untuk maksud yang sama. Yang membedakan
keduanya selalu satu hal — apakah ada yang benar-benar hilang.

---

## 5. Yang kurang, tiga saja

### P1 — Katakan yang sebenarnya *(paling mendesak, paling murah)*

- Konfirmasi berhenti mengatakan *"cannot be undone"*
- Kata kerjanya diganti: **Sembunyikan**, bukan **Hapus**
- Kalimatnya menyebutkan apa yang benar-benar terjadi: hilang dari daftar,
  masih bisa dibuka, bisa dikembalikan

Tidak ada perubahan perilaku sama sekali di sini — hanya berhenti berbohong.
Karena itu ia bisa jalan lebih dulu tanpa menunggu apa pun.

### P2 — Pintu masuk, hak, dan kejujuran soal baca-saja

**Cara melihatnya sudah ada** — `&preview_deleted=1` membuka dokumen tersembunyi
lengkap dengan spanduknya. Jadi P2 bukan membangun yang baru, melainkan
membereskan tiga hal di sekitarnya:

| | |
|---|---|
| **Pintu masuk** | Penyaring "tampilkan yang disembunyikan" di daftar versi, yang menautkan ke `&preview_deleted=1`. Sekarang parameternya harus diketik sendiri, dan `doc_id`-nya harus sudah diketahui — praktis tidak bisa ditemukan siapa pun |
| **Hak** | Sekarang superadmin saja. Di model "sembunyikan", yang bisa menyembunyikan harus bisa melihat dan mengembalikan — kalau tidak, tiap salah ketuk jadi tiket ke superadmin |
| **Baca-saja** | Paksa `edit-off` saat `preview_deleted`, atau berhenti mengklaimnya di komentar. Yang sekarang: mengklaim, tapi tidak menegakkan |

Ditambah `restore_version` yang mengenai **satu versi**, bukan seluruh slot.
`restore_slot` yang ada tetap, untuk kasus aslinya.

Inilah sebabnya dua dokumen *Discharge Planning* terasa hilang: secara teknis
ada, secara praktis tidak bisa ditemukan siapa pun.

### P3 — Alasan, hanya untuk yang bertanda tangan

Menyembunyikan dokumen kosong tidak perlu penjelasan. Menyembunyikan yang sudah
ditandatangani orang lain perlu — bukan sebagai penghalang, melainkan sebagai
catatan.

- Alasan wajib **hanya** kalau versinya punya tanda tangan aktif
- Masuk ke audit, bukan cuma ditampilkan di layar
- Berlaku untuk semua peran, superadmin sekalipun

Sengaja **tidak menolak mutlak**. Dokumen uji dan salah-isi memang perlu
disingkirkan, dan aturan tanpa jalan keluar yang sah akan dilangkahi lewat SQL
langsung — yang tidak tercatat sama sekali.

---

## 6. Yang TIDAK dijawab bentuk ini, dan perlu diputuskan

Menyembunyikan menjawab *"jangan tampilkan di daftar saya"*.

Ia **tidak** menjawab *"dokumen ini salah, jangan dipercaya lagi"*.

Keduanya sering tertukar, dan akibatnya berbeda jauh:

| | Sembunyikan | Batalkan |
|---|---|---|
| Yang sudah dicetak dan dipegang orang | Tetap berlaku | **Tidak berlaku lagi** |
| Halaman verifikasi | Tetap sah | Harus menyatakan sudah dibatalkan |
| Alasan | Perlu kalau bertanda tangan | Selalu wajib |

Bahayanya kalau cuma ada satu tombol: orang yang ingin **membatalkan** dokumen
keliru akan menekan **sembunyikan**. Dokumennya lenyap dari daftar, pasien tetap
memegang kertas yang sudah salah, dan tidak ada apa pun yang menyatakannya.

**Ini keputusan yang belum diambil.** Pilihannya:

| | |
|---|---|
| **A** | Cukup sembunyikan saja untuk sekarang. Membatalkan menyusul kalau memang muncul kebutuhannya |
| **B** | Sekalian buat keduanya: *Sembunyikan* dan *Batalkan*, dengan akibat yang berbeda di halaman verifikasi |

Saran: **A**. Halaman verifikasi publik sendiri belum ada, dan "batalkan" tanpa
tempat yang menyatakannya batal hanyalah "sembunyikan" dengan nama lain.

---

## 7. Yang perlu diputuskan

| | Pertanyaan | Catatan |
|---|---|---|
| 1 | Dua dokumen *Discharge Planning* hari ini — dikembalikan atau memang benar disingkirkan? | Keputusan klinis. Keduanya masih utuh |
| 2 | Sembunyikan saja dulu (A), atau sekalian dengan Batalkan (B)? | Lihat §6 |
| 3 | Setelah P1–P3, siapa yang boleh menyembunyikan? | Kalau menyembunyikan memang bisa dibatalkan sendiri, melebarkannya jauh lebih ringan risikonya daripada dugaan awal |

---

## 8. Yang TIDAK termasuk

- Hard delete. Tidak ada rencana membuatnya
- Memberi tahu penanda tangan saat dokumennya disembunyikan — masuk akal, tapi
  butuh kanal pemberitahuan yang belum ada
- Mengubah `restore_slot`. P2 menambah, bukan mengganti
