/**
 * Isi panel daftar versi, dijalankan di atas DOM tiruan.
 *
 * Yang diperiksa percabangannya — lengkap, berjalan, draf, disembunyikan, dan
 * yang sedang dibuka — karena di situlah kesalahan tinggal. Angka "x dari y"
 * ikut diperiksa: itu satu-satunya alasan panel ini dibuat.
 *
 * Argumen: berkas JS berisi renderVersionPanel dan kawan-kawannya.
 * Dipanggil dari tests/UI/PanelVersiTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };

// ── DOM tiruan secukupnya ────────────────────────────────────────────────
const buatEl = (tag) => {
    const el = {
        tag,
        className: '',
        textContent: '',
        innerHTML: '',
        disabled: false,
        type: '',
        dataset: {},
        atribut: {},
        anak: [],
        appendChild(c) { this.anak.push(c); return c; },
        setAttribute(k, v) { this.atribut[k] = String(v); },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.atribut, k) ? this.atribut[k] : null; },
        addEventListener(jenis, fn) { if (jenis === 'click') this._klik = fn; },
        focus() {},
    };
    return el;
};

/** Semua teks di dalam satu elemen, digabung. */
const teks = (el) => (el.textContent || '') + el.anak.map(teks).join(' ');

const daftar = buatEl('div');
const jumlah = buatEl('span');
const kartu = buatEl('aside');
const kartuIsi = buatEl('div');
kartu.hasAttribute = function (k) { return Object.prototype.hasOwnProperty.call(this.atribut, k); };
kartu.removeAttribute = function (k) { delete this.atribut[k]; };
kartu.style = {};
kartu.setAttribute('hidden', '');

const ctx = {
    console,
    window: { innerHeight: 900, addEventListener() {} },
    CURRENT_VERSION: 2,
    switchVersion: (v) => { ctx._pindah = v; },
    toggleVersionPanel: () => { ctx._tutup = true; },
    t: (kunci, params, bawaan) => {
        let out = String(bawaan || kunci);
        Object.keys(params || {}).forEach((k) => { out = out.split('{' + k + '}').join(params[k]); });
        return out;
    },
    document: {
        documentElement: { lang: 'id' },
        getElementById: (id) => {
            if (id === 'versionPanelList') return daftar;
            if (id === 'versionPanelCount') return jumlah;
            if (id === 'versionCard') return kartu;
            if (id === 'versionCardList') return kartuIsi;
            return null;
        },
        createElement: buatEl,
    },
};
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'panel.js' });

if (typeof ctx.renderVersionPanel !== 'function') gagal('renderVersionPanel tidak terbentuk');

// ── Kasusnya ─────────────────────────────────────────────────────────────
ctx.renderVersionPanel([
    { version: 3, signed_count: 0, is_hidden: 1, is_locked: 0, updated_at: '2026-09-23 09:03:00', created_by_name: 'Bidan Ratna' },
    { version: 2, signed_count: 1, is_hidden: 0, is_locked: 0, updated_at: '2026-09-22 09:02:00', created_by_name: 'dr. Andi' },
    { version: 1, signed_count: 3, is_hidden: 0, is_locked: 0, updated_at: '2026-09-21 09:01:00', created_by_name: 'dr. Andi' },
], 3);

if (daftar.anak.length !== 3) gagal('barisnya ' + daftar.anak.length + ', harusnya 3');
if (jumlah.textContent !== '3 versions') gagal('jumlah versi: ' + jumlah.textContent);

const [v3, v2, v1] = daftar.anak;

// v3: draf dan disembunyikan.
if (!teks(v3).includes('draft')) gagal('v3 bukan draf: ' + teks(v3));
if (!teks(v3).includes('hidden')) gagal('v3 tidak ditandai disembunyikan');
if (!teks(v3).includes('Signed 0 of 3')) gagal('v3 tidak menyebut 0 dari 3: ' + teks(v3));
if (v3.dataset.hidden !== '1') gagal('v3 tidak membawa penanda tersembunyi');

// v2: yang sedang dibuka, sebagian, dan tidak bisa diklik.
if (!teks(v2).includes('open now')) gagal('v2 tidak ditandai sedang dibuka');
if (!teks(v2).includes('in progress')) gagal('v2 bukan berjalan: ' + teks(v2));
if (!teks(v2).includes('Signed 1 of 3')) gagal('v2 tidak menyebut 1 dari 3');
if (v2.getAttribute('aria-current') !== 'true') gagal('v2 tidak ditandai aria-current');
if (v2.disabled !== true) gagal('versi yang sedang dibuka masih bisa diklik');
if (v2._klik) gagal('versi yang sedang dibuka masih punya penangan klik');

// v1: lengkap.
if (!teks(v1).includes('complete')) gagal('v1 bukan lengkap: ' + teks(v1));
if (!teks(v1).includes('Signed 3 of 3')) gagal('v1 tidak menyebut 3 dari 3');
if (!teks(v1).includes('dr. Andi')) gagal('nama pembuat tidak tampil');

// Mengklik versi lain: panelnya menutup lalu pindah.
v1._klik();
if (ctx._tutup !== true) gagal('panelnya tidak ditutup saat memilih versi');
if (ctx._pindah !== 1) gagal('pindah ke versi ' + ctx._pindah + ', harusnya 1');

// ── Kartu yang selalu tampil ─────────────────────────────────────────────
ctx.renderVersionCard([
    { version: 3, signed_count: 0, is_hidden: 0, updated_at: '2026-09-29 10:14:00' },
    { version: 2, signed_count: 1, is_hidden: 0, updated_at: '2026-09-26 09:02:00' },
    { version: 1, signed_count: 3, is_hidden: 0, updated_at: '2026-09-25 14:40:00' },
], 3);

if (kartu.hasAttribute('hidden')) gagal('kartunya tetap tersembunyi padahal versinya tiga');
if (kartuIsi.anak.length !== 3) gagal('baris kartu ' + kartuIsi.anak.length + ', harusnya 3');

const [k3, k2] = kartuIsi.anak;
if (!teks(k3).includes('v3 · 0/3')) gagal('baris kartu v3: ' + teks(k3));
if (!teks(k2).includes('open now')) gagal('baris kartu yang sedang dibuka tidak ditandai');
if (k2.disabled !== true) gagal('baris kartu yang sedang dibuka masih bisa diklik');

ctx._pindah = null;
k3._klik();
if (ctx._pindah !== 3) gagal('klik kartu tidak pindah versi, dapat ' + ctx._pindah);

// Satu versi saja: tidak ada yang perlu dipilih, kartunya tidak muncul.
kartuIsi.anak = [];
ctx.renderVersionCard([{ version: 1, signed_count: 0, is_hidden: 0, updated_at: '2026-09-25 14:40:00' }], 3);
if (!kartu.hasAttribute('hidden')) gagal('kartunya muncul padahal versinya cuma satu');

// ── Template tanpa slot tanda tangan ─────────────────────────────────────
daftar.anak = [];
ctx.renderVersionPanel([{ version: 1, signed_count: 0, is_hidden: 0, updated_at: '2026-09-21 09:01:00', created_by_name: '' }], 0);
if (!teks(daftar.anak[0]).includes('No signature slots')) {
    gagal('tanpa slot ttd seharusnya dikatakan, bukan "0 dari 0"');
}
if (teks(daftar.anak[0]).includes('Signed 0 of 0')) gagal('"0 dari 0" tercetak');

// ── Tidak ada versi sama sekali ──────────────────────────────────────────
daftar.anak = [];
ctx.renderVersionPanel([], 3);
if (!teks(daftar.anak[0] || buatEl('p')).includes('No versions yet')) gagal('daftar kosong tidak dikatakan');

console.log('LULUS');
