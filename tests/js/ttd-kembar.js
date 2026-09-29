/**
 * Salinan tanda tangan mendapat id sendiri.
 *
 * Fungsinya diambil apa adanya dari designer, lalu dijalankan di atas DOM
 * tiruan. Yang diperiksa dua sisi yang sama pentingnya: id kembar SELALU
 * dinamai ulang, dan nama penanda tangan yang sudah diganti orang TIDAK ikut
 * diubah — berbagi satu nama bisa disengaja, id kembar tidak pernah.
 *
 * Argumen: berkas JS berisi namaiUlangTtdKembar dan kawan-kawannya.
 * Dipanggil dari tests/UI/TtdKembarTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };

// ── DOM tiruan secukupnya ────────────────────────────────────────────────
const buatEl = (atribut, dalamKlon) => ({
    atribut: Object.assign({}, atribut),
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.atribut, k) ? this.atribut[k] : null; },
    setAttribute(k, v) { this.atribut[k] = String(v); },
    // Elemen sungguhan punya closest(); yang ditiru di sini cuma jawabannya:
    // apakah ia berada di dalam wadah tersembunyi bikinan TinyMCE.
    closest(pemilih) {
        if (pemilih !== '[data-mce-bogus], .mce-offscreen-selection') return null;
        return dalamKlon ? { nodeName: 'DIV' } : null;
    },
});

const buatAkar = (anak) => ({
    anak,
    querySelectorAll(pemilih) {
        if (pemilih !== '.ttd-placeholder[data-ttd]') return [];
        return this.anak.filter((el) => el.getAttribute('data-ttd'));
    },
});

const ctx = { console, Set, Date, Array, String, Object };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'ttd.js' });

if (typeof ctx.namaiUlangTtdKembar !== 'function') gagal('namaiUlangTtdKembar tidak terbentuk');

// ── Kasus 1: dua kotak, satu id ──────────────────────────────────────────
const a = buatEl({ 'data-ttd': 'ttd_100', 'data-nama-field': 'nama_ttd_100' });
const b = buatEl({ 'data-ttd': 'ttd_100', 'data-nama-field': 'nama_ttd_100' });
let diubah = ctx.namaiUlangTtdKembar(buatAkar([a, b]), new Set());

if (diubah !== 1) gagal('yang dinamai ulang ' + diubah + ', harusnya 1');
if (a.getAttribute('data-ttd') !== 'ttd_100') gagal('yang pertama ikut berganti id');
if (b.getAttribute('data-ttd') === 'ttd_100') gagal('salinannya masih memakai id yang sama');
if (b.getAttribute('data-nama-field') !== 'nama_' + b.getAttribute('data-ttd')) {
    gagal('nama penanda tangan salinannya tidak ikut: ' + b.getAttribute('data-nama-field'));
}

// ── Kasus 2: nama yang sudah diganti orang dibiarkan ────────────────────
const c = buatEl({ 'data-ttd': 'ttd_200', 'data-nama-field': 'nama_petugas' });
const d = buatEl({ 'data-ttd': 'ttd_200', 'data-nama-field': 'nama_petugas' });
ctx.namaiUlangTtdKembar(buatAkar([c, d]), new Set());

if (d.getAttribute('data-ttd') === 'ttd_200') gagal('id kembar tidak dinamai ulang');
if (d.getAttribute('data-nama-field') !== 'nama_petugas') {
    gagal('nama yang sengaja dipakai bersama ikut diubah: ' + d.getAttribute('data-nama-field'));
}

// ── Kasus 3: isi QR yang menyebut nama lama ikut disesuaikan ────────────
const e = buatEl({ 'data-ttd': 'ttd_300', 'data-nama-field': 'nama_ttd_300' });
const f = buatEl({
    'data-ttd': 'ttd_300',
    'data-nama-field': 'nama_ttd_300',
    'data-ttd-qr-data': 'Ditandatangani {{nama_ttd_300}} pada {{tanggal}}',
});
ctx.namaiUlangTtdKembar(buatAkar([e, f]), new Set());

const namaBaru = f.getAttribute('data-nama-field');
if (f.getAttribute('data-ttd-qr-data').indexOf('nama_ttd_300') !== -1) {
    gagal('isi QR masih menyebut nama lama: ' + f.getAttribute('data-ttd-qr-data'));
}
if (f.getAttribute('data-ttd-qr-data').indexOf(namaBaru) === -1) {
    gagal('isi QR tidak menyebut nama barunya');
}
if (f.getAttribute('data-ttd-qr-data').indexOf('{{tanggal}}') === -1) {
    gagal('bagian lain isi QR ikut rusak');
}

// ── Kasus 4: yang tidak kembar tidak disentuh ───────────────────────────
const g = buatEl({ 'data-ttd': 'ttd_400', 'data-nama-field': 'nama_ttd_400' });
const h = buatEl({ 'data-ttd': 'ttd_401', 'data-nama-field': 'nama_ttd_401' });
diubah = ctx.namaiUlangTtdKembar(buatAkar([g, h]), new Set());

if (diubah !== 0) gagal('yang idnya berbeda ikut dinamai ulang');
if (g.getAttribute('data-ttd') !== 'ttd_400' || h.getAttribute('data-ttd') !== 'ttd_401') {
    gagal('id yang sah berubah');
}

// ── Kasus 5: id yang sudah dipakai di dokumen dihitung terpakai ─────────
// Inilah jalur tempel: yang disalin bentrok dengan yang sudah ada di halaman.
const i = buatEl({ 'data-ttd': 'ttd_500', 'data-nama-field': 'nama_ttd_500' });
diubah = ctx.namaiUlangTtdKembar(buatAkar([i]), new Set(['ttd_500']));

if (diubah !== 1) gagal('tempelan yang bentrok dengan isi halaman tidak dinamai ulang');
if (i.getAttribute('data-ttd') === 'ttd_500') gagal('id tempelannya tidak berubah');

// ── Kasus 6: tiga kembar berturut-turut dapat id berbeda-beda ───────────
const tiga = [
    buatEl({ 'data-ttd': 'ttd_600', 'data-nama-field': 'nama_ttd_600' }),
    buatEl({ 'data-ttd': 'ttd_600', 'data-nama-field': 'nama_ttd_600' }),
    buatEl({ 'data-ttd': 'ttd_600', 'data-nama-field': 'nama_ttd_600' }),
];
ctx.namaiUlangTtdKembar(buatAkar(tiga), new Set());
const id = tiga.map((el) => el.getAttribute('data-ttd'));
if (new Set(id).size !== 3) gagal('tiga kembar tidak mendapat tiga id berbeda: ' + id.join(', '));

// ── Kasus 7: klon bikinan TinyMCE tidak dianggap kembar ─────────────────
// Widget contenteditable=false yang terpilih disalin TinyMCE ke wadah
// tersembunyi supaya bisa masuk papan klip. Salinan itu ada di DOM tapi tidak
// ada di getContent() — terbukti di browser sungguhan: 5 di DOM, 4 di isi.
// Kalau ikut dihitung, yang asli tampak kembar dan dinamai ulang tanpa sebab,
// dan id yang sudah dipakai dokumen lama ikut berubah.
const asli = buatEl({ 'data-ttd': 'ttd_700', 'data-nama-field': 'nama_ttd_700' });
const klon = buatEl({ 'data-ttd': 'ttd_700', 'data-nama-field': 'nama_ttd_700' }, true);
diubah = ctx.namaiUlangTtdKembar(buatAkar([asli, klon]), new Set());

if (diubah !== 0) gagal('klon bikinan TinyMCE ikut dinamai ulang');
if (asli.getAttribute('data-ttd') !== 'ttd_700') gagal('yang asli berganti id karena klonnya');

// Klon yang datang lebih dulu pun tidak boleh membuat yang asli tampak kembar.
const klon2 = buatEl({ 'data-ttd': 'ttd_800', 'data-nama-field': 'nama_ttd_800' }, true);
const asli2 = buatEl({ 'data-ttd': 'ttd_800', 'data-nama-field': 'nama_ttd_800' });
diubah = ctx.namaiUlangTtdKembar(buatAkar([klon2, asli2]), new Set());

if (diubah !== 0) gagal('klon yang berurutan lebih dulu tetap membuat yang asli dinamai ulang');
if (asli2.getAttribute('data-ttd') !== 'ttd_800') gagal('yang asli berganti id (klon lebih dulu)');

console.log('LULUS');
