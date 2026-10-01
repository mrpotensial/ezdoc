/**
 * Mode contoh menyimpan ke peramban, bukan ke server.
 *
 * Skrip mode contoh diambil apa adanya dari halaman yang sudah dirender, lalu
 * dijalankan di atas DOM tiruan. Yang diperiksa akibatnya: simpan dibatalkan,
 * isinya masuk localStorage, dan muat ulang mengembalikannya — termasuk tanda
 * tangan dan kotak centang yang tidak dicentang.
 *
 * Argumen: berkas JS berisi skrip mode contoh.
 * Dipanggil dari tests/UI/ExampleBrowserSaveTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };

// ── DOM tiruan secukupnya ────────────────────────────────────────────────
const buatEl = (opsi = {}) => Object.assign({
    value: '',
    textContent: '',
    innerHTML: '',
    checked: false,
    type: 'text',
    isContentEditable: false,
    querySelector: () => null,
    addEventListener(jenis, fn) { this._klik = fn; },
}, opsi);

const bikinDunia = () => {
    const medan = {
        nama_pasien: buatEl({ name: 'nama_pasien', value: 'Siti Rahmawati' }),
        nama_pasien_bayangan: buatEl({ name: 'nama_pasien', value: 'Siti Rahmawati', type: 'hidden' }),
        umur: buatEl({ name: 'umur', value: '16' }),
        pulang_membaik: buatEl({ name: 'pulang_membaik', type: 'checkbox', checked: true }),
        dirujuk: buatEl({ name: 'dirujuk', type: 'checkbox', checked: false }),
    };
    const terlihat = {
        nama_pasien: buatEl({ isContentEditable: true, textContent: 'Siti Rahmawati' }),
    };
    const ttd = {
        pasien: buatEl({ name: 'pasien' }),
        perawat: buatEl({ name: 'perawat' }),
        dokter: buatEl({ name: 'dokter' }),
    };
    const pratinjauIsi = buatEl();
    const pratinjau = {
        perawat: buatEl({ querySelector: () => pratinjauIsi }),
    };

    const form = buatEl({
        querySelectorAll: (sel) => {
            if (sel === 'input[type="checkbox"][name]') return [medan.pulang_membaik, medan.dirujuk];
            const m = /\[name="([^"]+)"\]/.exec(sel);
            if (!m) return [];
            return Object.keys(medan).filter((k) => medan[k].name === m[1]).map((k) => medan[k]);
        },
        querySelector: (sel) => {
            const m = /\[name="([^"]+)"\]/.exec(sel);
            if (!m) return null;
            const k = Object.keys(medan).find((x) => medan[x].name === m[1]);
            return k ? medan[k] : null;
        },
    });

    const tombolHapus = buatEl();
    const dokumen = {
        getElementById: (id) => {
            if (id === 'mainForm') return form;
            if (id === 'ezExampleClear') return tombolHapus;
            if (id.indexOf('ttd_') === 0) {
                const nama = id.slice(4);
                if (ttd[nama]) return ttd[nama];
                // Field NAMA penanda tangan juga punya elemen bernama begini —
                // justru itu jebakannya.
                if (medan[nama]) return medan[nama];
                return null;
            }
            if (id.indexOf('preview_') === 0) return pratinjau[id.slice(8)] || null;
            return null;
        },
        querySelector: (sel) => {
            const m = /\[data-field="([^"]+)"\]/.exec(sel);
            return m ? (terlihat[m[1]] || null) : null;
        },
        addEventListener(jenis, fn) { (this._pendengar = this._pendengar || {})[jenis] = fn; },
    };

    const simpanan = {};
    const ctx = {
        console, JSON, Object, Array, String, Date, RegExp,
        FormData: class { constructor() { this._isi = []; } forEach(fn) { this._isi.forEach(([n, v]) => fn(v, n)); } },
        localStorage: {
            getItem: (k) => (Object.prototype.hasOwnProperty.call(simpanan, k) ? simpanan[k] : null),
            setItem: (k, v) => { simpanan[k] = String(v); },
            removeItem: (k) => { delete simpanan[k]; },
        },
        document: dokumen,
        location: { reload() { ctx._dimuatUlang = true; } },
        showToast: (teks, jenis) => { ctx._kabar = teks; },
        markClean: () => { ctx._bersih = true; },
    };
    ctx.window = ctx;
    ctx.addEventListener = (jenis, fn) => { (ctx._pendengarWindow = ctx._pendengarWindow || {})[jenis] = fn; };
    ctx._simpanan = simpanan;
    ctx._el = { medan, terlihat, ttd, pratinjau, pratinjauIsi, form, tombolHapus, dokumen };

    return ctx;
};

const skrip = fs.readFileSync(process.argv[2], 'utf8');

const jalankan = (ctx) => {
    vm.createContext(ctx);
    vm.runInContext(skrip, ctx, { filename: 'contoh-simpan.js' });
    const siap = (ctx.document._pendengar || {}).DOMContentLoaded;
    if (!siap) gagal('skrip tidak menunggu halaman siap');
    siap();
};

// ── 1. Simpan dibatalkan dan ditulis ke peramban ─────────────────────────
const dunia = bikinDunia();
jalankan(dunia);

const kait = (dunia._pendengarWindow || {})['ezcetak:before-save'];
if (!kait) gagal('kait simpan tidak dipasang');

const formData = new dunia.FormData();
formData._isi = [
    ['nama_pasien', 'Nama Diubah Contoh'],
    ['umur', '16'],
    ['perawat', 'data:image/png;base64,AAA'],
    ['pulang_membaik', '1'],
    ['_ajax', '1'],
    ['template_id', '0'],
    ['_doc_id', '0'],
];

let dicegah = false;
kait({ preventDefault: () => { dicegah = true; }, detail: { formData } });

if (!dicegah) gagal('simpan tidak dibatalkan — ia akan sampai ke server');

const kunci = Object.keys(dunia._simpanan)[0];
if (!kunci || kunci.indexOf('ezdoc_contoh_') !== 0) gagal('tidak ada yang tersimpan di peramban');

const simpanan = JSON.parse(dunia._simpanan[kunci]);
if (simpanan.isi.nama_pasien !== 'Nama Diubah Contoh') gagal('isian tidak tersimpan');
if (simpanan.isi.perawat !== 'data:image/png;base64,AAA') gagal('tanda tangan tidak tersimpan');
if (simpanan.isi._ajax || simpanan.isi.template_id || simpanan.isi._doc_id) gagal('urusan dalam ikut tersimpan');
if (simpanan.centang.dirujuk !== '0') gagal('kotak yang TIDAK dicentang tidak tercatat');
if (simpanan.centang.pulang_membaik !== '1') gagal('kotak yang dicentang tidak tercatat');
if (!dunia._kabar || dunia._kabar.indexOf('peramban') === -1) gagal('orangnya tidak diberi tahu ke mana simpanannya pergi');

// ── 2. Muat ulang mengembalikan isinya ───────────────────────────────────
const dunia2 = bikinDunia();
Object.keys(dunia._simpanan).forEach((k) => dunia2.localStorage.setItem(k, dunia._simpanan[k]));
jalankan(dunia2);

if (dunia2._el.terlihat.nama_pasien.textContent !== 'Nama Diubah Contoh') gagal('yang terlihat tidak pulih');
if (dunia2._el.medan.nama_pasien.value !== 'Nama Diubah Contoh') gagal('isian tidak pulih');
if (dunia2._el.medan.nama_pasien_bayangan.value !== 'Nama Diubah Contoh') gagal('input bayangan tidak ikut pulih');
if (dunia2._el.ttd.perawat.value !== 'data:image/png;base64,AAA') gagal('tanda tangan tidak pulih');
if (dunia2._el.pratinjauIsi.innerHTML.indexOf('<img src="data:image/png;base64,AAA"') !== 0) gagal('tanda tangan tidak terlihat lagi');
if (dunia2._el.medan.dirujuk.checked !== false) gagal('kotak centang tidak pulih');

// Nama penanda tangan BUKAN slot tanda tangan: ia tidak boleh dipulihkan
// sebagai gambar ke elemen ttd_<nama>.
if (dunia2._el.medan.nama_pasien.innerHTML) gagal('nama pasien diperlakukan sebagai slot tanda tangan');

// ── 3. Hapus simpanan mengosongkan, lalu memuat ulang halaman ────────────
dunia2._el.tombolHapus._klik();
if (Object.keys(dunia2._simpanan).length !== 0) gagal('simpanan tidak terhapus');
if (!dunia2._dimuatUlang) gagal('halaman tidak dimuat ulang setelah simpanan dihapus');

console.log('LULUS');
