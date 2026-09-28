# Field hitung

Field yang nilainya **dihitung dari field lain**, bukan diketik: total skor,
jumlah biaya, selisih dua angka.

Sebelum ini penjumlahan hanya ada untuk tabel yang datang dari kueri database
(`{{tabledb.ns.sum:…}}`). Isian formulir tidak punya apa pun, jadi totalnya
diketik ulang orang — dan yang diketik ulang bisa salah tanpa ada yang tahu.

## Cepat

Di designer: toolbar **Field → Hitung (rumus)**, isi nama field lalu rumusnya.
Sesudah itu rumus dan jumlah desimalnya bisa diubah dari kartu field di panel
kanan (kolom **Rumus** dan **Desimal**).

Markup yang tersimpan:

```html
<span class="field-placeholder" data-type="calc"
      data-formula="bishop_pembukaan + bishop_pendataran + bishop_penurunan"
      data-decimals="0">{{total_bishop}}</span>
```

| Atribut | Arti |
| --- | --- |
| `data-type="calc"` | menandai field hitung |
| `data-formula` | rumusnya: nama field lain, `+ - * / ( )`, angka |
| `data-decimals` | angka di belakang koma (0–6, bawaan 0) |

## Bahasanya sama dengan rumus tabel

Rumusnya memakai penghitung yang sama dengan `{{tabledb.ns.=qty*harga}}` —
[`Ezdoc\Table\FormulaEvaluator`](../src/Table/FormulaEvaluator.php). Tidak ada
`eval()`, tidak ada pemanggilan fungsi, tidak ada akses properti: hanya angka,
nama field, empat operator, dan kurung.

```
total_a + total_b            nama polos
{total_a} + {total_b}        berkurung, seperti di rumus tabel — sama saja
(harga * jumlah) - diskon    kurung dan perkalian
```

Nama yang dirujuk adalah **nama field**, yang sama dengan yang ada di dalam
`{{ }}`.

## Aturan nilai

- Isian yang **kosong atau bukan angka dihitung nol**. Formulir skor diisi
  bertahap; kalau satu butir kosong mengosongkan totalnya, angkanya baru
  muncul di isian terakhir.
- Kalau **semua** field yang dirujuk kosong, hasilnya **kosong**. Formulir yang
  belum disentuh siapa pun tidak boleh tercetak dengan `0` di kolom skor.
- Kotak centang bernilai **1** saat dicentang (`1`, `Ya`, `true`, `on`).
- Pembagian nol dan rumus yang salah tulis menghasilkan **kosong**, bukan pesan
  galat — dokumen yang dicetak tidak memuat keluhan program. Kesalahan
  rumusnya terlihat di designer, bukan di kertas pasien.

## Dihitung dua kali, dengan sengaja

| Di mana | Kapan | Untuk apa |
| --- | --- | --- |
| Peramban | tiap isian yang dirujuk berubah | angkanya berubah saat itu juga |
| Server | saat dokumen dirender / dicetak | yang tercetak tidak bergantung pada peramban siapa pun |

Nilai hasil hitungan **ikut tersimpan** bersama dokumen (lewat input
tersembunyi), jadi halaman verifikasi dan PDF beku menampilkan angka yang sama
dengan yang dilihat petugas.

Keduanya dijaga uji yang menjalankan kasus yang sama lewat dua sisi:
`tests/Format/CalcFieldTest.php` dan `tests/js/field-hitung.js`.

## Blok bersyarat boleh membaca hasilnya

```html
<div class="conditional-section" data-cond="total_bishop&lt;6">
  <p>Skor di bawah 6 — pertimbangkan pematangan serviks lebih dulu.</p>
</div>
```

Nilai field hitung dilengkapi **sebelum** syarat dievaluasi
([`CalcField::lengkapiNilai()`](../src/Format/CalcField.php)). Tanpa itu,
syaratnya membandingkan dengan kosong — yang dibaca nol — dan kalimat "skor di
bawah 6" muncul di dokumen yang skornya justru 8.

## Lebih dari satu, dan berantai

Satu dokumen boleh punya banyak field hitung, dan sebuah field hitung boleh
merujuk field hitung lain:

```html
<span class="field-placeholder" data-type="calc" data-formula="a + b">{{subtotal}}</span>
<span class="field-placeholder" data-type="calc" data-formula="subtotal - diskon">{{total}}</span>
```

Rantainya dihitung **dua putaran**, di server maupun di peramban. Lebih panjang
dari itu tidak dikejar — rantai sepanjang itu lebih jernih ditulis satu rumus.

## Yang belum ada

- Fungsi (`sum()`, `if()`, `round()`) — belum ada, dan belum tentu perlu:
  yang dibutuhkan formulir skor sejauh ini cukup dengan empat operator.
- Merujuk kolom tabel kueri (`tabledb`) dari field hitung. Tabel punya
  penjumlahannya sendiri (`sum:`), dan keduanya hidup di dunia yang berbeda:
  satu per baris hasil kueri, satu per dokumen.
- Mengganti nama field TIDAK ikut memperbarui rumus yang menyebutnya. Kalau
  mengganti nama, periksa kolom **Rumus** di panel.

## Contoh lengkap

`contoh/skor-bishop/` — lembar Skor Bishop: lima butir angka, satu field
hitung yang menjumlahkannya, dan satu blok bersyarat yang membaca hasilnya.
Buka lewat **Example** → pilih "Lembar Skor Bishop (contoh)", atau langsung:

```
?ezdoc_page=contoh&contoh=skor-bishop
?ezdoc_page=generate&template_id=contoh:skor-bishop
?ezdoc_page=designer&action=edit&id=contoh:skor-bishop
```
