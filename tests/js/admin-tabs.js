/**
 * Tab halaman pengaturan: yang dipilih tampil, yang lain disembunyikan.
 *
 * Dijalankan, bukan dibaca — skrip halamannya diekstrak lalu dijalankan di atas
 * DOM tiruan. `?tab=` harus menang atas pilihan terakhir yang tersimpan: tautan
 * ke satu tab harus membuka tab itu.
 *
 * Argumen: berkas JS hasil ekstraksi. Dipanggil dari tests/UI/AdminNavTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const KUNCI = ['storage', 'adapters', 'config', 'migrations'];

const kelas = () => {
    const isi = new Set(['hidden']);
    return {
        _isi: isi,
        add: (c) => isi.add(c),
        remove: (c) => isi.delete(c),
        contains: (c) => isi.has(c),
        toggle: (c, paksa) => (paksa === undefined ? (isi.has(c) ? isi.delete(c) : isi.add(c)) : (paksa ? isi.add(c) : isi.delete(c))),
    };
};

const el = (extra = {}) => Object.assign({
    classList: kelas(), style: {}, dataset: {}, textContent: '', innerHTML: '',
    addEventListener() {}, setAttribute() {}, scrollTop: 0, disabled: false,
}, extra);

const panel = {};
const tombol = [];
KUNCI.forEach((k) => {
    panel[k] = el();
    tombol.push(el({ dataset: { ezTab: k }, addEventListener(jenis, fn) { this._klik = fn; } }));
});

const ctx = {
    console, Promise, JSON, Object, Array, String, Number, Math, URLSearchParams, Set,
    setTimeout, FormData: class { append() {} },
    localStorage: { _v: 'migrations', getItem(k) { return this._v; }, setItem(k, v) { this._v = v; } },
    location: { search: '?tab=adapters', href: 'http://x/' },
    fetch: () => Promise.resolve({ text: () => Promise.resolve(JSON.stringify({ ok: true, ringkas: { objek: 1, di_kolom: 1, di_disk: 0, berkas: 0, inline: 0, dirujuk: 1 } })) }),
    document: {
        querySelectorAll: (sel) => (sel === '[data-ez-tab]' ? tombol : []),
        querySelector: (sel) => {
            const m = /\[data-ez-panel="([a-z]+)"\]/.exec(sel);
            return m ? (panel[m[1]] || null) : null;
        },
        getElementById: () => el(),
        createRange: () => ({ selectNodeContents() {} }),
    },
};
ctx.window = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'admin.js' });

const tampil = (k) => !panel[k].classList.contains('hidden');
const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };

// `?tab=adapters` menang atas 'migrations' yang tersimpan.
if (!tampil('adapters')) gagal('tab dari URL tidak dibuka');
KUNCI.filter((k) => k !== 'adapters').forEach((k) => {
    if (tampil(k)) gagal('tab ' + k + ' ikut tampil');
});

// Menekan tab lain memindahkan yang tampil, satu saja.
tombol.find((b) => b.dataset.ezTab === 'config')._klik();
if (!tampil('config')) gagal('tab yang ditekan tidak tampil');
if (tampil('adapters')) gagal('tab sebelumnya tidak disembunyikan');
if (ctx.localStorage._v !== 'config') gagal('pilihan tidak diingat');

console.log('LULUS');
