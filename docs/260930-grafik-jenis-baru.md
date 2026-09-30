# Jenis grafik baru, satuan sumbu baru, dan contoh di luar rumah sakit

> Status: **fase 0–3 selesai, fase 4–9 belum dikerjakan.**
> Ditulis 30 Sep 2026 sesudah papan grafik terisi (lihat [260929-grafik-terisi.md](260929-grafik-terisi.md))
> dipakai untuk partograf, lalu diminta dipakai untuk yang lain.

Diminta pemakai:

> "saat ini kan cuma fokus grafik yang ada di partograf, apa bisa kita
> tambahkan jenis lain, sekaligus buat berkas contoh berkas lain yang
> menggunakan banyak grafik juga"
>
> "yang model timeline itu apa? apakah sudah ada, misalnya Medication
> timeline, mungkin bisa sekalian"
>
> "apakah bisa jenis dokumen diluar RS juga bisa, karena target kan bukan cuma
> RS"

Jawaban singkat untuk pertanyaan timeline: **belum ada, dan ia yang paling
banyak menuntut** — bukan karena gambarnya sulit, tapi karena ia satu-satunya
yang tidak muat di bentuk nilai yang dipakai sekarang. Alasannya di §4.

---

## 1. Apa yang sudah ada, dan tiga anggapan yang dilanggar

Papan grafik sekarang: satu papan, banyak panel bertumpuk, **satu sumbu X
bersama**. Spesifikasinya JSON di atribut `data-chart-spec`, nilainya field
dokumen biasa.

| Yang ada | Bentuknya |
|---|---|
| Jenis panel | `line`, `bar`, `symbol`, `text` |
| Cara gambar deret | `point+line`, `point`, `line` |
| Nilai satu deret | `[{"x":"2026-09-29 08:00","y":140}, …]` |
| Sumbu X | **selalu jam**: 1 kolom = 1 jam |
| Garis acuan | garis lurus dari (jam, nilai) ke (jam, nilai) |

Pekerjaan ini melanggar tiga anggapan yang sampai sekarang benar:

1. **"Satu kolom adalah satu jam."** Terpakai di `Scale`, perender, dan
   lapisan pegangan di peramban (lima tempat memakai angka `3600000`).
2. **"Satu nilai adalah satu titik pada satu waktu."** Timeline butuh
   rentang: obat yang jalan dari 08:00 sampai 14:00 bukan dua titik.
3. **"Nilai di luar sumbu tidak digambar."** Benar untuk titik — DJJ 1440
   adalah salah ketik. Tidak benar untuk rentang: infus yang mulai sebelum
   lembar ini dibuka memang benar-benar mulai sebelum lembar ini dibuka.

Ketiganya dilanggar dengan sengaja, dan tiap pelanggaran dijelaskan di
tempatnya.

### Tujuan akhirnya: semua jenis grafik

Diminta pemakai, dan itu mengubah rancangan ini:

> "karena goalnya kan bisa semua grafik nanti, tapi tidak langsung di kerjakan
> … hanya saja masukkan ke prd ini akan jadi goal agar tidak kerja 2x"

Rancangan pertama dokumen ini mengecualikan sebar (scatter), dua sumbu, dan
pai dengan alasan "tidak muat di papan bersumbu X waktu". Alasan itu salah:
yang tidak memuatnya bukan papannya, melainkan **anggapan** bahwa sumbu X
selalu waktu — anggapan yang justru sedang dibongkar di fase 1.

Jadi fase 1 tidak menambah dua satuan waktu. Ia **mengganti satu anggapan
dengan satu titik sambung** (§5), dan satuan hari serta bulan kebetulan yang
pertama lewat di situ. Yang dikerjakan sekarang tetap sama; yang berubah
bentuk kodenya, supaya sebar dan kawan-kawannya nanti masuk sebagai pemakai
titik sambung itu, bukan sebagai pembongkaran kedua.

Peta lengkapnya di §9.

---

## 2. Yang tidak boleh ikut berubah

Ini batas, bukan harapan. Semuanya sudah dijaga uji hari ini, dan uji itu
harus tetap hijau sesudah pekerjaan ini.

1. **Satu perender, di PHP.** Godaannya besar menggambar lagi di JavaScript
   supaya isiannya terasa lincah. Dua perender selalu berpisah diam-diam, lalu
   yang ditandatangani orang berbeda dari yang tercetak.
2. **Keluarannya deterministik.** Angka yang sama → byte yang sama. Tanpa
   `uniqid()`, tanpa jam dinding, pecahan lewat `number_format`. Ini
   satu-satunya alasan tanda tangan pada dokumen bergrafik berarti.
3. **Papan lama tergambar persis seperti sebelumnya.** Spesifikasi tanpa kunci
   baru harus menghasilkan SVG byte demi byte sama. Papan yang sudah
   ditandatangani harus bisa dibangun ulang.
4. **Deret tetap field dokumen biasa.** Dari situ pembekuan sesudah tanda
   tangan, salin ke versi baru, dan cakupan tanda tangan berlaku sendirinya.
   Bentuk penyimpanan baru apa pun harus tetap satu kolom `field_values`.
5. **Bentuk nilai tumbuh dengan menambah kunci pilihan, tidak pernah dengan
   menambah bentuk kedua.** `x2` di §4 adalah contohnya; nanti `y` mungkin
   boleh berisi beberapa angka sekaligus (kotak-kumis). Tiap bentuk kedua
   berarti dua pembaca, dua penulis, dan dua jalur pembekuan yang bisa
   berpisah diam-diam.
6. **Batasan Dompdf.** `<pattern>` gagal senyap jadi hitam pekat; `class` dan
   `<style>` tidak diperlakukan seperti di peramban. Arsiran disusun dari
   `<line>`, tampilan lewat atribut.
7. **Library tetap netral.** Tidak ada "partograf", "MEOWS", atau nama domain
   lain sebagai nama kelas atau kunci spesifikasi. Semuanya masuk sebagai
   contoh di `contoh/`, bukan sebagai kode.

---

## 3. Fase 0 — sudah dijawab, 30 Sep 2026

**Ketiganya lulus.** Diuji dengan PDF sungguhan lewat jalur cetak yang sama
seperti dokumen (`<img src="data:image/svg+xml;base64,…">` → Dompdf), lalu
hasilnya dibaca piksel demi piksel, bukan ditatap. Tiap petak punya
pembanding di sebelahnya — `<pattern>` dulu lolos justru karena tidak ada
pembandingnya: gambarnya muncul, artinya hilang.

| Yang diuji | Hasil | Bukti terukur |
|---|---|---|
| `fill-opacity` pada `<path>` | **lulus** | `#cc0000` pada 0,25 terbaca `(242, 191, 191)` — **persis** hitungan teori di atas putih; pembanding pekat `(204, 0, 0)`; garis kisi di bawahnya tetap terlihat menembus |
| `<rect>` bertumpuk, warna beda | **lulus** | Sapuan satu kolom: biru 53 px → hijau 41 px → kuning 29 px, **tanpa satu baris putih pun** di perbatasan; perbandingan tingginya sesuai spesifikasi |
| Teks di atas `<rect>` berwarna | **lulus** | 544 piksel gelap di dalam kotak latar muda, 640 piksel putih di dalam kotak latar gelap — teksnya benar-benar tergambar di atas latar |

Tambahan yang ikut terjawab, dan ikut menentukan bentuk `bands`:

- `fill-opacity` pada `<rect>` **jalan**.
- `opacity` pada `<g>` **jalan**.

Jadi arsiran area digambar dengan opasitas sungguhan, bukan dengan warna muda
yang dihitung sendiri. Dan pita latar boleh memakai warna pekat ber-opasitas,
tidak harus warna muda yang disetel orang.

Berkas ujinya: `scratchpad/fase0_dompdf.php` → `fase0.pdf`.

### Temuan keempat, yang tidak dicari: label grafik tercetak berkait

Muncul saat membaca hasilnya — bentuk hurufnya tidak seperti di layar.

Perender memakai `font-family="Helvetica, Arial, sans-serif"` di **setiap**
teks. php-svg-lib tidak membaca daftar berkoma yang **berspasi**, lalu jatuh
ke huruf berkait. Diuji enam ejaan:

| `font-family` | Hasil di PDF |
|---|---|
| `Helvetica, Arial, sans-serif` ← **dipakai sekarang** | **berkait (Times)** |
| `Helvetica,Arial,sans-serif` | tak berkait ✓ |
| `Helvetica` | tak berkait ✓ |
| `Arial` | tak berkait ✓ |
| `sans-serif` | tak berkait ✓ |
| `helvetica` | tak berkait ✓ |

Spasi sesudah komanya yang mematikan. Akibatnya: **seluruh label grafik yang
tercetak selama ini berkait, sementara di layar tak berkait** — satu perender,
tapi dua rupa. Persis kelas kegagalan yang dijaga §2 butir 1, cuma penyebabnya
bukan dua perender melainkan satu perender yang tulisannya tidak dimengerti
mesin cetaknya.

Perbaikannya satu tetapan: `SvgChartRenderer::FONT` jadi
`'Helvetica,Arial,sans-serif'` — rantai cadangannya tetap utuh untuk peramban,
cuma spasinya dibuang.

**Sudah diperbaiki** atas keputusan pemilik dokumen, 30 Sep 2026. Yang
dijaga uji keluarannya, bukan tetapannya: tidak boleh ada spasi sesudah koma
di `font-family` mana pun yang tergambar. Partograf contoh dibandingkan byte
demi byte terhadap keluaran sebelum perubahan — selain nama fontnya, **tidak
satu piksel pun bergeser**.

Berkas ujinya: `scratchpad/fase0_font.php`.

### Satu batasan yang ikut ketahuan

Huruf bawaan Dompdf tidak punya lambang seperti ✓ dan ✗; keduanya tercetak
sebagai `?`. Contoh partograf tidak terpengaruh (isinya ASCII semua), tapi
contoh di §7 yang memakai centang harus memilih: lambang ASCII, atau huruf
yang memuatnya ikut dibenamkan. Diputuskan di fase 9.

---

## 4. Bentuk nilai: satu tambahan, `x2`

Nilai deret hari ini:

```json
[{"x": "2026-09-29 08:00", "y": 140}]
```

Timeline butuh rentang. Ada dua jalan, dan yang kedua dipilih:

**Ditolak — bentuk penyimpanan kedua** (`[{"from":…,"to":…}]` khusus
timeline). Dua bentuk berarti dua pembaca, dua penulis, dua jalur pembekuan,
dan dua tempat yang bisa berpisah. Cakupan tanda tangan harus tahu keduanya.

**Dipilih — kunci `x2` yang boleh tidak ada:**

```json
[{"x": "2026-09-29 08:00", "x2": "2026-09-29 14:00", "y": "RL 500 cc"}]
```

Empat keadaan, semuanya menjelaskan dirinya sendiri:

| `x2` | Artinya |
|---|---|
| tidak ada | titik, persis seperti sekarang — **nilai lama tetap sah tanpa disentuh** |
| `false` | **rentang terbuka**: sudah mulai, belum selesai |
| waktu sah, lebih besar dari `x` | rentang tertutup |
| selain itu | seluruh rentangnya dibuang |

Yang terakhir dibuang, bukan diturunkan jadi titik: rentang yang berakhir
sebelum mulai adalah salah isi, dan menggambarnya sebagai titik menyembunyikan
kesalahan itu. `y` boleh kosong; kalau ada, ia label di dalam batangnya. Kunci
ganda yang tadinya `x` jadi `x` + `x2`, urut menurut `x` lalu `x2`.

### Kenapa rentang terbuka perlu bentuknya sendiri

Infus yang masih jalan **tidak punya** jam selesai. Tanpa keadaan ini, yang
mengisi cuma punya dua pilihan: tidak menulisnya — datanya hilang — atau
mengarang jam selesai. Yang kedua persis kegagalan yang dijaga di seluruh
berkas ini: dokumen yang memuat sesuatu yang tidak pernah dicatat siapa pun.

`false`, bukan "`x2` dikosongkan pada panel timeline". Kalau artinya bergantung
jenis panel, nilai yang sama berubah arti begitu spesifikasinya diubah — dan
spesifikasi bisa diubah sesudah dokumennya ditandatangani. Yang tersimpan
harus menjelaskan dirinya sendiri, tanpa membaca spesifikasi.

Satu parser, satu penulis, satu bentuk tersimpan. `SeriesValues` bertambah
kira-kira 35 baris.

### Rentang yang melewati tepi sumbu

Aturan "nilai di luar sumbu tidak digambar" **tidak berlaku** untuk rentang,
dan ini pelanggaran yang disengaja:

- Titik di luar sumbu hampir selalu salah ketik. Menggambarnya di tepi
  membuat angka yang tidak pernah diukur masuk ke dokumen yang ditandatangani.
- Rentang yang mulai sebelum lembar ini dibuka adalah **keadaan yang lazim**,
  bukan salah ketik. Infus memang sudah jalan sejak semalam.

Jadi: rentang dipotong di tepi sumbu, **dan ujung yang terpotong ditandai**
(takik/panah kecil di tepi) supaya "mulai lebih awal" terbaca berbeda dari
"mulai di sini". Rentang yang kedua ujungnya di luar sumbu dan di sisi yang
sama tidak menyentuh lembar ini sama sekali — tidak digambar.

---

## 5. Sumbu X: strategi, bukan anggapan

Ini bagian terpenting dokumen ini. Yang dikerjakan di fase 1 cuma jam, hari,
dan bulan — tapi **bentuk kodenya** yang menentukan apakah sebar, kategori,
dan pai nanti masuk dengan mudah atau menuntut pembongkaran kedua.

### Tiga jenis sumbu X

Sumbu X berhenti jadi "waktu" dan jadi salah satu dari tiga:

| `x.kind` | Isi nilai `x` | Untuk |
|---|---|---|
| `time` | `"2026-09-29 08:00"` | pemantauan, partograf, timeline — **satu-satunya yang diisi fase 1** |
| `value` | angka | sebar, berat-vs-tinggi, dosis-vs-respons |
| `category` | teks | batang per kategori, histogram, air terjun |

Bentuknya di spesifikasi:

```json
"x": {"kind": "time", "unit": "hour", "columns": 16}
"x": {"kind": "value", "min": 0, "max": 100, "step": 10}
"x": {"kind": "category", "labels": ["Jan", "Feb", "Mar"]}
```

Kunci lama (`hours`, `column_width`, `label_every`, `start_field`) tetap
dibaca **selamanya** sebagai `{"kind":"time","unit":"hour"}`. Membuangnya akan
mematikan tiap papan yang sudah tersimpan.

### Titik sambungnya

Satu antarmuka, tiga pelaksana. Yang dibangun fase 1 antarmukanya plus
`TimeAxis`; dua sisanya kelas kosong yang belum terdaftar.

| Yang diminta | Dipakai siapa |
|---|---|
| `position($xMentah): ?float` — nilai X jadi tempat mendatar | perender, lapisan pegangan |
| `fromPosition(float $px)` — kebalikannya | isian di peramban |
| `ticks(): array` — di mana labelnya | perender |
| `label($tick): string` | perender |
| `normalise($xMentah)` — bentuk baku yang tersimpan | `SeriesValues` |

Yang terakhir itu yang paling menentukan. Hari ini `SeriesValues` memanggil
`normaliseTime()` langsung — nama yang memaksa tiap nilai `x` jadi waktu, di
enam tempat. **Selama itu tidak dicabut, sebar tidak mungkin tanpa membongkar
ulang penyimpanan nilai**, dan penyimpanan nilai adalah yang ditandatangani
orang. Itu alasan sebenarnya fase 1 dikerjakan lebih dulu.

#### Satu pelaksana tidak cukup membuktikan bentuknya

Antarmuka yang dirancang dari satu contoh biasanya tidak muat untuk contoh
kedua: bentuknya ditebak dari yang ada, lalu yang berikutnya tidak masuk.
Tempelan `ValueAxis` yang sekadar memanggil metodenya juga tidak membuktikan
apa-apa — tempelan bisa dibentuk mengikuti antarmuka apa pun.

Jadi syaratnya: **fase 1 menggambar satu papan dua panel sungguhan lewat
`ValueAxis`, ujung ke ujung, dalam satu uji.** Ia berbeda dari waktu justru di
tempat yang penting — tanpa field jam mulai, tanpa kalender, labelnya angka,
dan `normalise()` mengembalikan pecahan, bukan teks. Kalau uji itu memaksa
antarmukanya berubah, ketahuannya di fase 1, bukan sesudah delapan fase
menumpuk di atasnya.

Di luar uji itu `ValueAxis` tidak didaftarkan, tidak muncul di penyunting, dan
tidak disebut di dokumentasi pemakai — supaya tidak ada spesifikasi orang yang
telanjur bergantung pada barang setengah jadi.

#### Skala Y ikut disiapkan, meski fiturnya belum

Sumbu Y kedua (§9 fase 10) belum dibangun, tapi `Scale::y()` menerima
**skala-yang-mana** sejak fase 1, bawaannya kiri. Alasannya satu: `bands`
(§6.3) melukis wilayah nilai, dan "wilayah nilai di skala yang mana" harus
punya jawaban sebelum `bands` ditulis. Kalau tidak, sumbu Y kedua nanti
memaksa membongkar `bands`.

Yang dipasang parameternya, bukan fiturnya. Biayanya hampir nol dan seluruhnya
di dalam satu kelas — beda dengan titik sambung sumbu X yang lintas berkas.

### Satuan waktu: jam, hari, bulan

Di dalam `TimeAxis`, kunci `unit` berisi `hour` (bawaan), `day`, atau `month`.

Nama `hours` menyesatkan begitu satuannya bukan jam: `columns` dibaca
duluan, `hours` jadi cadangan. Penyunting menulis `columns`, dan labelnya jadi
"Jumlah kolom".

### Bulan tidak boleh dihitung dari detik

Ini jebakan yang paling mungkin lolos ke produksi.

- **Jam**: `(ts2 − ts1) / 3600`. Benar.
- **Hari**: `(ts2 − ts1) / 86400` **salah di zona waktu ber-DST**. Indonesia
  tidak ber-DST, tapi library ini tidak boleh menganggapnya. Hitung lewat
  selisih kalender.
- **Bulan**: `(ts2 − ts1) / 2592000` **selalu salah** — Februari 28 hari,
  Januari 31. Rumusnya:
  `(y2−y1)×12 + (m2−m1) + (hari2−1) / jumlahHariBulan2`.

Dan perhitungan yang sama harus dijalankan **dua kali**: sekali di PHP,
sekali di peramban untuk lapisan pegangan. Selisih setengah kolom tidak pernah
terbaca sebagai bug, cuma sebagai "grafiknya agak meleset".

Penjagaannya sudah ada polanya: `tests/Chart/KoordinatPerambanTest.php`
menjalankan `tests/js/chart-koordinat.js` dengan Node dan membandingkan
hasilnya dengan PHP.

**Dikerjakan 30 Sep 2026, dan dua hal berubah dari rencana:**

1. **Daftar angka harapannya tidak ditulis tangan.** Ia dihitung `TimeAxis` di
   PHP lalu diserahkan ke Node, yang menghitung ulang dengan rumus JS. Dua
   daftar yang sama-sama ditulis tangan cuma membuktikan penulisnya
   konsisten; yang perlu dibuktikan kedua rumusnya sepakat.
2. **Ujinya berjalan di dua zona waktu**, UTC dan Europe/London. Uji mutasi
   menunjukkan kenapa: di zona tanpa DST, "selisih detik dibagi 86400"
   **kebetulan benar**, jadi uji yang cuma berjalan di situ tidak membedakan
   rumus yang benar dari rumus yang kebetulan benar. Tiga mutasi lolos justru
   karena itu, di kedua bahasa.

### Awal sumbu hari dan bulan dibulatkan

Tidak direncanakan; ketahuan saat menulis sisi peramban. Papan bulanan yang
dimulai 31 Januari membuat "satu bulan berikutnya" tidak punya jawaban — 31
Februari tidak ada — sementara labelnya tetap berbunyi "02/2026". Angka dan
tulisan menunjuk bulan yang berbeda.

Jadi awal sumbu **hari** dibulatkan ke tengah malam dan awal sumbu **bulan**
ke tanggal 1. Sumbu **jam** tidak: papan yang dimulai 08.30 sudah dipakai, dan
kolomnya memang 09.30.

Pembulatan itu juga membuat satu potong kode jadi berlebihan. Label bulan
semula menghitung tahun dan bulannya sendiri supaya `modify('+n month')` tidak
meluap; sesudah awalnya selalu tanggal 1, luapannya tidak mungkin terjadi, dan
kode itu dibuang. Uji mutasi yang menunjukkannya: mencabut cabang khusus itu
tidak membuat satu uji pun gagal.

### Label kolom

| `unit` | Bawaan | Kunci pengubah |
|---|---|---|
| `hour` | `08:00` | `label_format` (format `date()` PHP) |
| `day` | `29/09` | sama |
| `month` | `09/2026` | sama |

Angka, bukan nama bulan: nama bulan menuntut penerjemahan, dan penerjemahan
menuntut locale — yang berarti server dengan locale berbeda menghasilkan PDF
berbeda untuk dokumen yang sama. Itu melanggar §2 butir 2. Yang mau "Sep 2026"
menulisnya sendiri lewat `label_format`.

### Yang ikut tersentuh

| Berkas | Perubahannya |
|---|---|
| `src/Chart/ChartSpec.php` | kunci `x`, `unit`, `columns`, `label_format` |
| `src/Chart/XAxis.php` **(baru)** | antarmuka + `TimeAxis` |
| `src/Chart/Scale.php` | `hour()` → `column()`, pemetaan X dialihkan ke sumbu |
| `src/Chart/SeriesValues.php` | `normaliseTime()` → `normalise()` lewat sumbu |
| `src/Chart/SvgChartRenderer.php` | label kolom ikut satuan |
| `views/_partials/generate_scripts.php` | lima tempat ber-`3600000` |
| `views/_partials/designer_scripts.php` | isian satuan + data contoh ikut satuan |
| `actions/document/chart_render.php` | `scale` yang diekspor memuat `unit` |

---

## 6. Jenis dan cara gambar baru

Sesudah keputusan §10, **cuma satu yang benar-benar jenis panel baru**:
`timeline`. Sisanya cara gambar (`step`, `area`) atau kunci panel (`bands`,
`stacked`) — dan perbedaan itu yang menentukan berapa banyak kode tersentuh,
berapa banyak yang harus dijelaskan ke pemakai, dan berapa banyak gabungan
yang harus diuji.

| | Bentuknya | Di mana |
|---|---|---|
| Tangga | `draw: "step"` | §6.1 |
| Arsiran | `draw: "area"` | §6.2 |
| Pita nilai | kunci panel `bands` | §6.3 |
| Batang bertumpuk | kunci panel `stacked` | §6.4 |
| Timeline | **jenis panel** `timeline` | §6.5 |
| Acuan berkurva | kunci `points` pada `references` | §6.6 |

### 6.1 Tangga (`draw: "step"`) — cara gambar, bukan jenis panel

Nilai bertahan sampai diubah. Untuk tetes/menit, dosis, aliran oksigen: angka
yang **berlaku sepanjang rentang**, bukan pengukuran sesaat.

Garis biasa berbohong di sini. Ia menggambar perubahan bertahap dari 20 ke 40
tetes/menit sepanjang dua jam; yang benar-benar terjadi adalah 20 sampai
pukul 10, lalu 40. Dokumennya lalu memperlihatkan nilai yang tidak pernah
disetel siapa pun.

Bentuknya *step-after*: nilai berlaku dari waktunya sampai waktu berikutnya.
Masuk ke `ChartSpec::DRAWS` sebagai `step` dan `point+step`.

**Dikerjakan 30 Sep 2026.** Yang dijaga uji bukan "ada garisnya", melainkan
**ada simpul di sudutnya** — tanpa simpul itu, tangga dan garis lurus
menghasilkan gambar yang sama, dan ujinya tidak menguji apa pun.

### 6.2 Arsiran (`draw: "area"`) — cara gambar

Garis dengan bidang terisi sampai dasar panel. Untuk jumlah kumulatif. Masuk
ke `DRAWS` sebagai `area` dan `point+area`.

**Dikerjakan 30 Sep 2026** dengan `fill-opacity` sungguhan, sesuai hasil §3.

Dasarnya **nol kalau nol ada di dalam jangkauan sumbunya**, kalau tidak tepi
yang paling dekat dengan nol. Aturan itu cuma berbeda dari "tepi bawah" pada
panel yang melewati nol — balans cairan −1000..+2000 — dan uji mutasi
menunjukkan kedua aturan itu tidak terbedakan sampai panel seperti itu
diuji.

### 6.3 Pita nilai (`bands`) — kunci panel, bukan jenis baru

Rancangan pertama dokumen ini memasukkan jenis panel `heat`: satu baris sel
yang latarnya diwarnai menurut nilai. Jenis itu **dibatalkan**, dan bukan
karena hemat kode — proyek ini sendiri mempertahankan `symbol` dan `text`
sebagai dua nama untuk satu perender, alasannya "penulis spesifikasi
memikirkan keduanya sebagai hal berbeda".

Yang membatalkannya: **pita nilai berguna di luar baris sel.** Panel garis
juga sering butuh latar berpita — suhu 36–37,5 hijau, di atasnya kuning — dan
`heat` sebagai jenis panel tidak menyediakannya. Satu jenis panel yang cuma
menutupi separuh kebutuhannya bukan jenis, cuma kebetulan.

Jadi `bands` jadi kunci panel yang berlaku di panel mana pun:

```json
"bands": [
  {"from": 0, "to": 2, "color": "#dcfce7"},
  {"from": 3, "to": 5, "color": "#fef9c3"},
  {"from": 6,          "color": "#fecaca", "label": "Lapor DPJP"}
]
```

Aturannya satu — *"nilai di rentang ini warnanya ini"* — dan cuma tempat
melukisnya yang ikut bentuk panelnya:

| Panel | Yang diwarnai |
|---|---|
| `symbol`, `text` | latar tiap sel, menurut nilai sel itu |
| `line`, `bar` | pita mendatar di latar panel, menurut wilayah nilainya |

Satu kunci, dua bacaan yang sama-sama jelas, dan dua contoh di §7 (MEOWS dan
rantai dingin) memakai kunci yang sama.

**Dikerjakan 30 Sep 2026.** Dua hal ketahuan sesudah gambarnya dibaca,
bukan sesudah ujinya hijau:

1. **Latar sel menjulur ke luar kisi.** Papan 12 kolom punya 12 sel; batas
   ke-13 cuma garis penutup. Nilai yang jatuh tepat di situ tidak punya kolom
   di sebelah kanannya, dan latarnya terlukis di luar papan. Sekarang
   dipotong pada tepi kisi.
2. **Kecerahan harus dihitung berbobot, bukan dirata-rata.** Keduanya memberi
   jawaban sama untuk hampir semua warna — dan berbeda pada biru. `#1d4ed8`
   rata-ratanya terang (tinta hitam), padahal bagi mata ia gelap (tinta
   putih). Uji mutasi yang menunjukkan kedua rumus itu tidak terbedakan
   sampai ada pita biru yang diuji.

Selebihnya:

- `to` boleh tidak ada, berarti sampai tak hingga. Pita tak berujung dipotong
  pada tepi panelnya, bukan menjulur ke panel di atasnya.
- Pita yang bertumpang tindih ditolak saat validasi — "nilai 4 warnanya apa"
  tidak boleh punya dua jawaban.
- Kunci `axis` (`left`/`right`) disediakan sejak awal walau sumbu Y kedua
  belum ada; alasannya di §5.
- Warna teksnya **dihitung**, tidak disetel: hitam atau putih menurut
  kecerahan latar (rumus luminansi WCAG). Yang menyetel latar gelap tidak
  boleh berakhir dengan angka hitam di atas merah tua yang tidak terbaca.

### 6.4 Batang bertumpuk (`stacked: true` pada panel `bar`)

Deret ditumpuk berurutan; tingginya jumlah. Untuk cairan masuk vs keluar.

**Panel bertumpuk diisi dengan mengetik angka, tanpa seret.** Klik satu
kolom → kotak isian, seperti baris sel.

Alasannya bukan seretnya sulit dibuat, tapi **nilainya bukan jenis yang
ditaksir mata**. Orang menulis "250 cc" karena membaca kantong, bukan karena
membidik tinggi batang. Seret di situ memberi ketepatan semu pada angka yang
sebetulnya dihitung.

Dan aturan seret apa pun punya lubang yang sama: potongan bernilai nol tidak
punya tinggi, jadi tidak punya pegangan — nilai baru tidak bisa dibuat dengan
seret sama sekali. Menambal lubang itu berarti pegangan yang muncul di tempat
yang tidak ada isinya, dan itu lebih membingungkan daripada tidak ada seret.

Polanya bukan pengecualian baru: baris sel di halaman generate **sudah**
diisi dengan mengetik (`tanya: true` di `generate_scripts.php`). Dengan ini
bagian tersulit fase 4 hilang — yang tersisa cuma gambarnya.

### 6.5 Timeline (`type: "timeline"`) — jenis panel baru

Tiap deret satu jalur; nilainya rentang (§4). Untuk jadwal obat, waktu infus,
jam operasi mesin, jadwal perawatan.

- Jalur ditumpuk di dalam tinggi panel, setinggi bagian yang sama rata.
- Nama deret ditulis di kolom kiri, sebagai label jalur.
- Label rentang ditulis di dalam batangnya kalau muat; kalau tidak, dipotong
  dengan "…". Dipotong, bukan dikecilkan hurufnya: satu papan dengan tujuh
  ukuran huruf lebih sulit dibaca daripada satu label terpotong.
- Rentang yang bertumpang tindih di jalur yang sama digambar bertumpuk
  setengah tinggi, bukan disembunyikan.
- **Rentang terbuka** (§4, `x2: false`) digambar sampai tepi kanan dengan
  penanda ujung-terpotong yang sama — "masih berjalan" terbaca berbeda dari
  "selesai tepat di tepi".

**Isian di peramban** (bagian terbesar dari fase ini):

| Perbuatan | Hasilnya |
|---|---|
| Seret mendatar di jalur kosong | rentang baru dari kolom awal ke kolom akhir |
| Seret ujung rentang | ujung itu bergeser |
| Seret badan rentang | seluruhnya bergeser, panjangnya tetap |
| Klik ganda | ketik labelnya |
| Seret ujung kanan ke luar tepi | rentangnya jadi terbuka (`x2: false`) |
| Klik lalu Delete | rentangnya dihapus |

### 6.6 Garis acuan berbentuk kurva

Untuk grafik pertumbuhan: kurva acuan WHO bukan garis lurus. `references`
bertambah kunci `points`:

```json
{"points": [[0, 3.3], [1, 4.5], [2, 5.6]], "label": "Median", "color": "#16a34a"}
```

Garis lurus `from`/`to` yang ada sekarang tidak berubah.

---

## 7. Berkas contoh

Tujuh, dan **tiga di antaranya bukan rumah sakit** — diminta begitu, dan juga
karena contoh yang semuanya medis membuat library ini terbaca sebagai library
medis. Ia bukan.

| # | Contoh | Memperlihatkan | Satuan |
|---|---|---|---|
| 1 | Balans cairan 24 jam | batang bertumpuk, arsiran kumulatif | jam |
| 2 | Peringatan dini obstetri (MEOWS) | `bands` di baris sel, garis | jam |
| 3 | Observasi pasca-operasi | tangga (tetes/menit), timeline obat | jam |
| 4 | Pemantauan bayi baru lahir | garis, simbol, `bands` di baris sel | jam |
| 5 | **Rantai dingin vaksin** | `bands` sebagai pita latar, batas 2–8 °C | **hari** |
| 6 | **Riwayat perawatan mesin** | timeline, tangga (jam operasi) | **hari** |
| 7 | **Grafik pertumbuhan anak** | kurva acuan, sumbu bulan | **bulan** |

Nomor 2 dan 5 sengaja dipasangkan: keduanya memakai `bands`, tapi yang satu
mewarnai sel dan yang satu melukis pita latar. Kalau satu kunci untuk dua
bacaan itu ternyata membingungkan, ketahuannya di situ.

Nomor 5 dan 6 dipilih bukan sekadar supaya ada yang bukan rumah sakit: yang
pertama satu-satunya yang menguji satuan hari, yang kedua satu-satunya yang
menguji timeline tanpa konteks medis. Contoh yang tidak menguji apa pun tidak
perlu dibuat.

Semuanya masuk `contoh/<slug>/` dengan `template.html` + `contoh.json`, pola
yang sudah ada.

---

## 8. Urutan pengerjaan

Tiap fase berdiri sendiri: boleh berhenti di mana saja dan yang sudah jadi
tetap berguna.

| Fase | Hasil | Berat |
|---|---|---|
| 0 | ~~Tiga jawaban Dompdf di §3~~ | **selesai 30 Sep** |
| 1 | ~~Titik sambung sumbu X + `TimeAxis` + kesamaan PHP↔JS~~ | **selesai 30 Sep** |
| 2 | ~~`draw`: `step`, `area`~~ | **selesai 30 Sep** |
| 3 | ~~Kunci panel `bands` (sel berwarna + pita latar)~~ | **selesai 30 Sep** |
| 4 | `stacked` pada panel `bar` (gambar saja, isian diketik) | kecil |
| 5 | `x2` di `SeriesValues` + panel `timeline` (gambar) | sedang |
| 6 | Isian timeline di peramban | **besar** |
| 7 | `references.points` | kecil |
| 8 | Isian semuanya di penyunting designer | sedang |
| 9 | Tujuh berkas contoh | sedang |

Fase 1 dan 6 yang paling mungkin memakan waktu dua kali lipat dari dugaan.
Fase 1 karena aritmetika kalender harus sama persis di dua bahasa, dan karena
ia menyentuh penyimpanan nilai — yang ditandatangani orang. Fase 6 karena
seret-ujung-rentang menyentuh jalur kejadian tetikus yang sudah pernah
menyimpan bug senyap (`mouseleave` fase tangkap membatalkan seretan pada
gerakan pertama).

Fase 10–14 di §9 sengaja tidak dijadwalkan. Yang penting sekarang bukan
kapan dikerjakan, melainkan bahwa fase 1 tidak menutup pintunya.

---

## 9. Peta tujuan: sisa jenis grafik

Tidak dikerjakan sekarang, **tapi bukan ditolak**. Tiap baris di sini sudah
punya jalan masuknya lewat titik sambung §5, jadi tidak ada yang menuntut
pembongkaran kedua. Urutannya usulan; yang lebih dulu dibutuhkan didahulukan.

| Fase | Jenis | Yang dibutuhkannya | Berat |
|---|---|---|---|
| 10 | **Sumbu Y kedua (kanan)** | kunci panel `y2` + deret `axis: "right"` | kecil |
| 11 | **Sebar (scatter), XY** | `x.kind: "value"` | sedang |
| 12 | **Batang per kategori, histogram, air terjun** | `x.kind: "category"` | sedang |
| 13 | **Pai, donat, gauge, radar** | panel yang keluar dari sumbu X bersama | sedang |
| 14 | **Kotak-kumis, lilin** | `y` boleh beberapa angka | sedang |

Tiga catatan yang menentukan bentuk fase 1–9, jadi ditulis sekarang:

1. **Sumbu Y kedua muat di papan yang sekarang.** Panel sudah punya satu
   skala Y; yang kedua cuma skala lain plus angka di tepi kanan. Bisa
   dikerjakan kapan saja, dan tidak menunggu apa pun. Ia ditaruh di fase 10
   hanya karena belum ada yang memintanya.
2. **Pai tidak butuh papan kedua.** Panel pada dasarnya sebuah kotak; pai
   digambar di dalam kotaknya sambil mengabaikan kolom. Yang diperlukan cuma
   satu saklar panel "tidak ikut sumbu X bersama" — bukan mesin gambar
   kedua. Anggapan lama ("semua panel berbagi sumbu X") dicabut di sini.
3. **Nilai pai dan batang berkategori sudah punya bentuknya** begitu `x`
   berhenti dipaksa jadi waktu: `{"x": "Laki-laki", "y": 42}`. Itu akibat
   langsung dari §5, dan alasan kenapa §5 dikerjakan lebih dulu.

### Yang benar-benar tidak dikerjakan

- **Sumbu X menit atau detik.** Belum ada yang memintanya; `hour` dengan
  `sub_columns` sudah menutupi pemantauan tiap 15 menit.
- **Zona waktu per papan.** Semua waktu memakai zona server, seperti sekarang.
- **Kurva acuan yang dihitung** (z-score WHO dari rumus). Titik-titiknya
  ditulis di spesifikasi. Rumus di dalam library berarti library ini ikut
  bertanggung jawab atas kebenaran angka medis.
- **Impor nilai dari sistem lain.** Di luar cakupan library.
- **Grafik gabungan dua papan berbeda dalam satu gambar.** Dua papan, dua
  elemen.

---

## 10. Keputusan, 30 Sep 2026

Lima hal yang sempat dibiarkan terbuka, dan jawabannya. Ditulis di sini supaya
yang mengerjakannya tahu apa yang sudah ditimbang — dan supaya yang mau
membalikkannya tahu apa yang harus dibantah.

1. **Jenis panel `heat` dibatalkan; `bands` jadi kunci panel.** Pita nilai
   berguna juga di panel garis, dan jenis panel yang cuma menutupi separuh
   kebutuhannya bukan jenis. (§6.3)
2. **Batang bertumpuk diisi dengan mengetik, tanpa seret.** Angkanya dibaca
   dari kantong, bukan ditaksir dari tinggi batang; dan potongan bernilai nol
   tidak punya pegangan untuk diseret. (§6.4)
3. **Rentang terbuka masuk, lewat `x2: false`.** Tanpa itu, yang mengisi harus
   memilih antara data hilang dan jam selesai yang dikarang. (§4)
4. **Titik sambung sumbu X tetap dibangun di fase 1, tapi ujinya harus
   menggambar papan sungguhan lewat `ValueAxis`.** Tempelan yang sekadar
   memanggil metodenya tidak membuktikan apa pun. (§5)
5. **Sumbu Y kedua tetap di fase 10, tapi parameternya dipasang sejak fase 1.**
   `bands` melukis wilayah nilai, jadi "di skala yang mana" harus punya jawaban
   sebelum `bands` ditulis. Yang dipasang parameternya, bukan fiturnya. (§5)

### Yang masih benar-benar terbuka

- **Lambang ✓ dan ✗ tercetak `?`** (§3). Contoh mana yang boleh memakainya,
  dan apakah huruf yang memuatnya dibenamkan — diputuskan di fase 9.
- **Apakah satu kunci `bands` untuk dua bacaan terasa wajar di penyunting.**
  Di gambar sudah terjawab: dua bacaannya dicetak berdampingan dalam satu
  papan dan keduanya terbaca sebagaimana mestinya. Di formulir belum —
  ketahuannya di fase 9, saat contoh nomor 2 dan 5 dibuat bersebelahan.
- **Apakah `x.kind` kelak memuat `columns` juga.** Sekarang `columns` dibaca
  dari `x`, dari akar papan, dan dari `hours` lama — tiga tempat untuk satu
  angka. Ketiganya dibaca selamanya, tapi penyunting hanya menulis satu.
