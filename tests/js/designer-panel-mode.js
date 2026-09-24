/**
 * Dua mode panel designer: "Selected" hanya menampilkan elemen yang diklik.
 *
 * Skrip halamannya diekstrak lalu dijalankan di atas DOM tiruan. Yang diperiksa
 * bukan teks sumbernya melainkan akibatnya: seksi mana yang bertanda aktif,
 * kartu mana yang bertanda aktif, dan apakah keadaan "belum ada yang dipilih"
 * muncul.
 *
 * Argumen: berkas JS berisi modul ezdocPanelMode.
 * Dipanggil dari tests/UI/DesignerPanelModeTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const SEKSI = ['page', 'field', 'logo', 'ttd', 'materai', 'tabledb', 'marker', 'qr', 'cond', 'verify', 'access'];
const KARTU = ['field:nama', 'field:alamat', 'ttd:ttd1'];

const kelas = (awal = []) => {
    const isi = new Set(awal);
    return {
        _isi: isi,
        add: (c) => isi.add(c),
        remove: (c) => isi.delete(c),
        contains: (c) => isi.has(c),
        toggle: (c, paksa) => (paksa ? isi.add(c) : isi.delete(c)),
    };
};

const buatEl = (extra = {}) => Object.assign({
    classList: kelas(), dataset: {}, style: {},
    addEventListener(jenis, fn) { this._klik = fn; },
    setAttribute(k, v) { this._attr = this._attr || {}; this._attr[k] = v; },
    getAttribute(k) { return (this._attr || {})[k]; },
}, extra);

const seksiEl = {};
SEKSI.forEach((k) => { seksiEl[k] = buatEl({ dataset: { ezSection: k } }); });
const kartuEl = {};
KARTU.forEach((k) => { kartuEl[k] = buatEl(); });

const kosongEl = buatEl({ classList: kelas(['hidden']) });
const tombolMode = {
    simple: buatEl({ dataset: { ezMode: 'simple' } }),
    all: buatEl({ dataset: { ezMode: 'all' } }),
};

const sidebar = buatEl({
    querySelectorAll: (sel) => {
        if (sel === '[data-ez-section]') return SEKSI.map((k) => seksiEl[k]);
        if (sel === '[data-focus-target]') return KARTU.map((k) => kartuEl[k]);
        return [];
    },
    querySelector: (sel) => {
        let m = /\[data-ez-section="([a-z]+)"\]/.exec(sel);
        if (m) return seksiEl[m[1]] || null;
        m = /\[data-focus-target="([^"]+)"\]/.exec(sel);
        if (m) return kartuEl[m[1]] || null;
        return null;
    },
});

let pengamatCb = null;
let antrianFrame = [];

// Tiruan dari ezdocSetActivePanelCard milik halaman: satu kartu aktif saja.
const setAktif = (kartu) => {
    Object.keys(kartuEl).forEach((k) => {
        if (kartuEl[k] !== kartu) kartuEl[k].classList.remove('is-active');
    });
    if (kartu) kartu.classList.add('is-active');
};

const ctx = {
    console, Set, Object, JSON,
    ezdocSetActivePanelCard: setAktif,
    MutationObserver: class {
        constructor(cb) { pengamatCb = cb; }
        observe() { this._aktif = true; }
    },
    requestAnimationFrame: (fn) => { antrianFrame.push(fn); },
    localStorage: { _v: {}, getItem(k) { return this._v[k] || null; }, setItem(k, v) { this._v[k] = v; } },
    document: {
        getElementById: (id) => (id === 'ezSidebar' ? sidebar : (id === 'ezPanelEmpty' ? kosongEl : null)),
        querySelectorAll: (sel) => (sel === '[data-ez-mode]' ? [tombolMode.simple, tombolMode.all] : []),
        addEventListener() {},
    },
};
ctx.window = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'panel-mode.js' });

const panel = ctx.window.ezdocPanelMode;
const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };
if (!panel) gagal('modul ezdocPanelMode tidak terbentuk');

panel.mulai();

// Bawaannya panel penuh: tidak ada yang berubah bagi yang sudah terbiasa.
if (sidebar.getAttribute('data-mode') !== 'all') gagal('mode awal bukan all');
if (!kosongEl.classList.contains('hidden')) gagal('keadaan kosong tampil di mode penuh');

// Mode Selected tanpa pilihan: ajakan memilih, bukan panel kosong tanpa kabar.
panel.setMode('simple');
if (kosongEl.classList.contains('hidden')) gagal('keadaan kosong tidak muncul');
if (SEKSI.some((k) => seksiEl[k].classList.contains('ez-seksi-aktif'))) gagal('ada seksi aktif padahal belum memilih');

// Klik sebuah field: seksinya saja yang aktif, dan kartunya saja.
panel.pilih('field', 'nama');
if (!seksiEl.field.classList.contains('ez-seksi-aktif')) gagal('seksi field tidak aktif');
if (SEKSI.filter((k) => seksiEl[k].classList.contains('ez-seksi-aktif')).length !== 1) gagal('lebih dari satu seksi aktif');
if (!kartuEl['field:nama'].classList.contains('ez-kartu-aktif')) gagal('kartu field yang dipilih tidak aktif');
if (!kartuEl['field:nama'].classList.contains('is-active')) gagal('kartu tidak bertanda aktif');
if (kartuEl['field:alamat'].classList.contains('ez-kartu-aktif')) gagal('kartu lain ikut aktif');
if (!kosongEl.classList.contains('hidden')) gagal('keadaan kosong masih tampil');

// Pindah ke elemen lain: pilihan lama dilepas.
panel.pilih('ttd', 'ttd1');
if (seksiEl.field.classList.contains('ez-seksi-aktif')) gagal('seksi sebelumnya masih aktif');
if (!seksiEl.ttd.classList.contains('ez-seksi-aktif')) gagal('seksi ttd tidak aktif');
if (kartuEl['field:nama'].classList.contains('ez-kartu-aktif')) gagal('kartu sebelumnya masih aktif');

// Kembali ke panel penuh: tidak ada seksi yang ditandai, jadi semuanya
// terlihat lagi. Tanda kartu boleh tetap ada — di panel penuh ia tidak
// menyembunyikan apa pun, dan justru menunjukkan yang sedang dikerjakan.
panel.setMode('all');
if (sidebar.getAttribute('data-mode') !== 'all') gagal('mode tidak kembali ke all');
if (SEKSI.some((k) => seksiEl[k].classList.contains('ez-seksi-aktif'))) gagal('tanda seksi tertinggal');

// Tanda aktif tidak bergantung mode: di panel penuh pun kartu yang sedang
// dikerjakan harus terlihat.
panel.setMode('all');
panel.pilih('ttd', 'ttd1');
if (!kartuEl['ttd:ttd1'].classList.contains('is-active')) gagal('mode penuh: kartu tidak bertanda aktif');

// Pilihan mode diingat.
if (ctx.localStorage.getItem('ezdoc_designer_panel_mode') !== 'all') gagal('mode tidak tersimpan');

// Tombol tab memindahkan mode.
tombolMode.simple._klik();
if (sidebar.getAttribute('data-mode') !== 'simple') gagal('tombol tab tidak memindahkan mode');

// Daftar panel dirender ulang sesudah memilih — persis yang terjadi pada
// elemen mengambang, karena posisinya tersimpan lalu daftarnya dibangun ulang.
// Penandanya harus terpasang lagi; kalau tidak, panel tampak kosong.
panel.setMode('simple');
panel.pilih('field', 'nama');
kartuEl['field:nama'] = buatEl();          // kartu lama terbuang, diganti yang baru
if (!pengamatCb) gagal('perubahan isi panel tidak diamati');
pengamatCb([{ type: 'childList' }]);
antrianFrame.splice(0).forEach((fn) => fn());
if (!kartuEl['field:nama'].classList.contains('ez-kartu-aktif')) gagal('penanda tidak dipasang ulang setelah render ulang');
if (!kartuEl['field:nama'].classList.contains('is-active')) gagal('tanda aktif tidak dipasang ulang setelah render ulang');
if (!seksiEl.field.classList.contains('ez-seksi-aktif')) gagal('seksi tidak aktif lagi setelah render ulang');

// Jenis yang tidak punya seksi diabaikan, bukan mengosongkan panel.
panel.pilih('field', 'nama');
panel.pilih('entah', 'x');
if (!seksiEl.field.classList.contains('ez-seksi-aktif')) gagal('pilihan tak dikenal menghapus pilihan yang sah');

console.log('LULUS');
