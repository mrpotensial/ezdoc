/**
 * Isi kartu daftar versi, dijalankan di atas DOM tiruan.
 *
 * Yang diperiksa percabangannya — lengkap, berjalan, draf, disembunyikan, dan
 * yang sedang dibuka — karena di situlah kesalahan tinggal. Angka "x dari y"
 * ikut diperiksa: itu satu-satunya alasan kartu ini dibuat.
 *
 * Argumen: berkas JS berisi renderVersionCard dan kawan-kawannya.
 * Dipanggil dari tests/UI/KartuVersiTest.php.
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
    disabled: false,
    type: '',
    title: '',
    dataset: {},
    atribut: {},
    anak: [],
    style: {},
    classList: { _isi: [], contains(k) { return this._isi.indexOf(k) !== -1; } },
    appendChild(c) { this.anak.push(c); return c; },
    setAttribute(k, v) { this.atribut[k] = String(v); },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.atribut, k) ? this.atribut[k] : null; },
    hasAttribute(k) { return Object.prototype.hasOwnProperty.call(this.atribut, k); },
    removeAttribute(k) { delete this.atribut[k]; },
    addEventListener(jenis, fn) { if (jenis === 'click') this._klik = fn; },
    getBoundingClientRect() { return this._kotak || { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }; },
    focus() {},
    };

    // innerHTML = '' memang mengosongkan anaknya di DOM sungguhan. Tanpa ini,
    // pemeriksaan di bawah membaca baris dari pemanggilan sebelumnya dan lulus
    // atas alasan yang salah.
    let _html = '';
    Object.defineProperty(el, 'innerHTML', {
        get() { return _html; },
        set(v) { _html = String(v); if (_html === '') el.anak = []; },
    });

    return el;
};

/** Semua teks di dalam satu elemen, digabung. */
const teks = (el) => (el.textContent || '') + el.anak.map(teks).join(' ');

const kartu = buatEl('aside');
const isi = buatEl('div');
const jumlah = buatEl('span');
const sidebar = buatEl('div');
kartu.setAttribute('hidden', '');
sidebar._kotak = { top: 20, bottom: 380, left: 1030, right: 1240, width: 210, height: 360 };

const ctx = {
    console,
    CURRENT_VERSION: 2,
    switchVersion: (v) => { ctx._pindah = v; },
    t: (kunci, params, bawaan) => {
        let out = String(bawaan || kunci);
        Object.keys(params || {}).forEach((k) => { out = out.split('{' + k + '}').join(params[k]); });
        return out;
    },
    document: {
        documentElement: { lang: 'id' },
        getElementById: (id) => {
            if (id === 'versionCard') return kartu;
            if (id === 'versionCardList') return isi;
            if (id === 'versionCardCount') return jumlah;
            if (id === 'toolbarPanel') return sidebar;
            return null;
        },
        createElement: buatEl,
    },
};
ctx.window = { innerHeight: 900, addEventListener() {} };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'kartu.js' });

if (typeof ctx.renderVersionCard !== 'function') gagal('renderVersionCard tidak terbentuk');

// ── Isinya ───────────────────────────────────────────────────────────────
ctx.renderVersionCard([
    { version: 3, signed_count: 0, is_hidden: 1, is_locked: 0, updated_at: '2026-09-23 09:03:00', created_by_name: 'Bidan Ratna' },
    { version: 2, signed_count: 1, is_hidden: 0, is_locked: 0, updated_at: '2026-09-22 09:02:00', created_by_name: 'dr. Andi' },
    { version: 1, signed_count: 3, is_hidden: 0, is_locked: 0, updated_at: '2026-09-21 09:01:00', created_by_name: 'dr. Andi' },
], 3);

if (kartu.hasAttribute('hidden')) gagal('kartunya tersembunyi padahal versinya tiga');
if (isi.anak.length !== 3) gagal('barisnya ' + isi.anak.length + ', harusnya 3');
if (jumlah.textContent !== '3 versions') gagal('jumlah versi: ' + jumlah.textContent);

const [v3, v2, v1] = isi.anak;

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

// v1: lengkap, dengan nama pembuatnya.
if (!teks(v1).includes('complete')) gagal('v1 bukan lengkap: ' + teks(v1));
if (!teks(v1).includes('Signed 3 of 3')) gagal('v1 tidak menyebut 3 dari 3');
if (!teks(v1).includes('dr. Andi')) gagal('nama pembuat tidak tampil');

v1._klik();
if (ctx._pindah !== 1) gagal('klik tidak pindah versi, dapat ' + ctx._pindah);

// ── Tingginya dibatasi ruang yang ada, bukan persentase layar ────────────
// Sidebar berakhir di 380 dari layar setinggi 900, jadi sisanya 900-380-8-12.
if (kartu.style.maxHeight !== '500px') gagal('batas tinggi kartu: ' + kartu.style.maxHeight);
if (kartu.style.top !== '388px') gagal('letak kartu: ' + kartu.style.top);

// Layar pendek: tetap disisakan cukup untuk satu baris yang bisa digulung.
ctx.window.innerHeight = 420;
ctx.positionVersionCard();
if (kartu.style.maxHeight !== '120px') gagal('di layar pendek batasnya: ' + kartu.style.maxHeight);
ctx.window.innerHeight = 900;

// ── Sidebar menempel ke dasar layar: kartunya naik ──────────────────────
sidebar._kotak = { top: 600, bottom: 880, left: 1030, right: 1240, width: 210, height: 280 };
ctx.positionVersionCard();
if (kartu.style.top !== 'auto') gagal('kartunya tidak naik ke atas sidebar');
if (kartu.style.bottom !== '308px') gagal('jarak dari dasar layar: ' + kartu.style.bottom);
sidebar._kotak = { top: 20, bottom: 380, left: 1030, right: 1240, width: 210, height: 360 };

// ── Sidebar digulung: kartunya ikut ─────────────────────────────────────
sidebar.classList._isi = ['collapsed'];
ctx.positionVersionCard();
if (!kartu.hasAttribute('hidden')) gagal('sidebar digulung tapi kartunya masih tampil');
sidebar.classList._isi = [];
ctx.positionVersionCard();
if (kartu.hasAttribute('hidden')) gagal('sidebar dibuka lagi tapi kartunya tidak kembali');

// ── Satu versi: tidak ada yang perlu dipilih ────────────────────────────
ctx.renderVersionCard([{ version: 1, signed_count: 0, is_hidden: 0, updated_at: '2026-09-21 09:01:00', created_by_name: '' }], 3);
if (!kartu.hasAttribute('hidden')) gagal('kartunya muncul padahal versinya cuma satu');

// ── Template tanpa slot tanda tangan ────────────────────────────────────
ctx.renderVersionCard([
    { version: 2, signed_count: 0, is_hidden: 0, updated_at: '2026-09-22 09:02:00', created_by_name: '' },
    { version: 1, signed_count: 0, is_hidden: 0, updated_at: '2026-09-21 09:01:00', created_by_name: '' },
], 0);
if (!teks(isi.anak[0]).includes('No signature slots')) gagal('tanpa slot ttd seharusnya dikatakan');
if (teks(isi.anak[0]).includes('Signed 0 of 0')) gagal('"0 dari 0" tercetak');

console.log('LULUS');
