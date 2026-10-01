/**
 * Perhitungan koordinat di peramban harus sama dengan di server.
 *
 * Perendernya satu — PHP — tapi lapisan pegangan wajib menghitung sendiri ke
 * dua arah: angka jadi tempat (menaruh pegangan) dan tempat jadi angka (orang
 * mengklik). Rumusnya karena itu ada di dua bahasa, dan dua salinan rumus
 * selalu berpisah diam-diam.
 *
 * Angka di bawah ini SENGAJA sama dengan yang diperiksa
 * tests/Chart/SvgChartRendererTest.php:
 *
 *   kiri 64 + (2 jam × 40) = 144
 *   atas 16 + ((10−5)/10 × 200) = 116
 *
 * Kalau yang satu berubah tanpa yang lain, salah satunya gagal.
 *
 * Argumen: berkas JS berisi blok papan grafik dari generate_scripts.php.
 * Dipanggil dari tests/Chart/KoordinatPeramabanTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };

// Cukup untuk memuat blok; fungsi yang menyentuhnya tidak dipanggil di sini.
const ctx = {
    console,
    document: { addEventListener() {}, querySelector() { return null; }, createElement() { return { style: {}, dataset: {} }; } },
    window: {},
    setTimeout,
    clearTimeout,
    Date,
    Math,
    JSON,
    Object,
    Array,
    String,
    parseFloat,
    parseInt,
    isFinite
};
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'chart.js' });

for (const nama of ['ezChartX', 'ezChartY', 'ezChartDariTempat', 'ezChartRapikan',
    'ezChartWaktuKeTeks', 'ezChartKolomDariWaktu', 'ezChartWaktuKolom']) {
    if (typeof ctx[nama] !== 'function') gagal(nama + ' tidak terbentuk');
}

// Skala yang sama dengan yang diekspor Scale::export() untuk papan uji itu.
const scale = {
    left: 64,
    column_width: 40,
    hours: 16,
    start: '2026-09-29 08:00',
    width: 716,
    height: 244,
    panels: [{ top: 16, height: 200, y_min: 0, y_max: 10, y_step: 1 }]
};

// ── Angka jadi tempat ────────────────────────────────────────────────────
const x = ctx.ezChartX(scale, '2026-09-29 10:00');
if (x !== 144) gagal('x 10:00 = ' + x + ', harusnya 144 (sama dengan sisi PHP)');

const y = ctx.ezChartY(scale.panels[0], 5);
if (y !== 116) gagal('y 5 = ' + y + ', harusnya 116 (sama dengan sisi PHP)');

// Di luar jendela waktu dan di luar sumbu: tidak ditempatkan, sama dengan PHP.
if (ctx.ezChartX(scale, '2026-09-30 08:00') !== null) gagal('waktu di luar jendela tetap ditempatkan');
if (ctx.ezChartY(scale.panels[0], 99) !== null) gagal('nilai di luar sumbu tetap ditempatkan');

// ── Tempat jadi angka ────────────────────────────────────────────────────
const keadaan = { scale: scale, spec: { panels: [{ series: [{ field: 'a' }] }] } };
const balik = ctx.ezChartDariTempat(keadaan, 144, 116);
if (!balik) gagal('titik yang sah tidak terbaca');
if (balik.x !== '2026-09-29 10:00') gagal('waktu hasil klik ' + balik.x);
if (balik.y !== 5) gagal('nilai hasil klik ' + balik.y);
if (balik.panel !== 0) gagal('panel hasil klik ' + balik.panel);

// Nilai dibulatkan ke langkah sumbunya: klik di antara dua garis mendarat di
// salah satunya, bukan di 5,37.
const dekat = ctx.ezChartDariTempat(keadaan, 144, 123);
if (dekat.y !== 5) gagal('nilai tidak dibulatkan ke langkah sumbu: ' + dekat.y);

// Klik di luar panel mana pun tidak menghasilkan apa-apa.
if (ctx.ezChartDariTempat(keadaan, 144, 5) !== null) gagal('klik di atas panel menghasilkan titik');
if (ctx.ezChartDariTempat(keadaan, 10, 116) !== null) gagal('klik di kiri sumbu menghasilkan titik');

// ── Bentuk bakunya sama dengan aturan server ─────────────────────────────
const rapi = ctx.ezChartRapikan([
    { x: '2026-09-29 12:00', y: 5 },
    { x: '2026-09-29 08:00', y: 4 },
    { x: '2026-09-29 12:00', y: 7 }
]);
if (rapi.length !== 2) gagal('waktu kembar tidak ditimpa: ' + rapi.length);
if (rapi[0].x !== '2026-09-29 08:00') gagal('tidak diurutkan menurut waktu');
if (rapi[1].y !== 7) gagal('yang menang bukan yang terakhir diketik');

// ── Bentuk baku rentang: harapannya datang dari PHP ────────────────
//
// `ezChartRapikan` adalah kembaran `SeriesValues::parse()`. Versi pertamanya
// menulis ulang tiap nilai jadi {x, y} — dan dengan itu membuang `x2` tanpa
// jejak: rentang yang baru dibuat jadi titik tanpa nilai lalu dibuang server,
// dan rentang yang sudah ada kehilangan jam selesainya begitu ada yang
// menyentuh papan itu. Gambarnya tetap muncul, cuma isinya berbeda.
if (process.argv[4]) {
    const kasus = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));
    let diperiksa = 0;

    for (const k of kasus) {
        const hasil = JSON.stringify(ctx.ezChartRapikan(k.masuk));
        if (hasil !== k.harap) {
            gagal('rapikan "' + k.nama + '":\n    JS  ' + hasil + '\n    PHP ' + k.harap);
        }
        diperiksa++;
    }

    if (diperiksa < 6) gagal('kasus rapikan cuma ' + diperiksa);
    console.log('kasus rapikan diperiksa: ' + diperiksa);
}

// ── Satuan hari dan bulan: angkanya datang dari PHP ──────────────────────
//
// Daftarnya TIDAK ditulis di sini. Ia dihitung `TimeAxis` di PHP lalu
// diserahkan lewat argumen, dan di sini dihitung ulang dengan rumus JS.
// Dua daftar yang sama-sama ditulis tangan cuma membuktikan bahwa yang
// menulisnya konsisten; yang perlu dibuktikan kedua rumusnya sepakat.
if (process.argv[3]) {
    const kasus = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
    let diperiksa = 0;

    for (const k of kasus) {
        const s = { left: 64, column_width: 40, hours: 400, start: k.origin,
            axis: { kind: 'time', unit: k.unit }, panels: [] };

        const kolom = ctx.ezChartKolomDariWaktu(s, ctx.ezChartTeksKeWaktu(k.waktu));
        if (kolom === null) gagal(k.unit + ' ' + k.waktu + ': tidak terbaca');
        if (Math.abs(kolom - k.kolom) > 1e-9) {
            gagal(k.unit + ' ' + k.origin + ' → ' + k.waktu
                + ': JS ' + kolom + ', PHP ' + k.kolom);
        }

        // Dan arah sebaliknya: kolom ke-n jatuh di waktu yang sama.
        if (k.awalKolom) {
            const balik = ctx.ezChartWaktuKolom(s, k.kolom);
            if (balik !== k.awalKolom) {
                gagal(k.unit + ' kolom ' + k.kolom + ': JS ' + balik + ', PHP ' + k.awalKolom);
            }
        }
        diperiksa++;
    }

    if (diperiksa < 20) gagal('kasusnya cuma ' + diperiksa + ' — daftarnya tidak sampai');
    console.log('kasus satuan diperiksa: ' + diperiksa);
}

console.log('LULUS');
