/**
 * Mode contoh di designer menyimpan ke peramban, bukan jadi template.
 *
 * Skripnya diambil dari halaman yang sudah dirender lalu dijalankan di atas DOM
 * tiruan. Yang diperiksa akibatnya: simpan dibatalkan, isinya masuk
 * localStorage, dan muat ulang mengembalikan isi editor, nama, serta config —
 * ketiganya, karena memulihkan isi tanpa config menghasilkan dokumen yang
 * tampil beda dari yang disimpan.
 *
 * Argumen: berkas JS berisi skrip mode contoh designer.
 * Dipanggil dari tests/UI/ExampleInDesignerTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };
const skrip = fs.readFileSync(process.argv[2], 'utf8');

const buatEl = (extra = {}) => Object.assign({
    value: '', textContent: '', className: '',
    addEventListener(jenis, fn) { this._klik = fn; },
}, extra);

const bikinDunia = (simpanan) => {
    const el = {
        editor: buatEl({ value: '<p>isi awal contoh</p>' }),
        namaTemplate: buatEl({ value: 'Surat Keterangan Tindakan (contoh)' }),
        saveIndicator: buatEl(),
        ezExampleDesignerClear: buatEl(),
    };
    const ctx = {
        console, JSON, Object, Array, String, Number,
        setTimeout: (fn) => { ctx._tunda = fn; },
        localStorage: {
            getItem: (k) => (Object.prototype.hasOwnProperty.call(simpanan, k) ? simpanan[k] : null),
            setItem: (k, v) => { simpanan[k] = String(v); },
            removeItem: (k) => { delete simpanan[k]; },
        },
        document: {
            getElementById: (id) => el[id] || null,
            addEventListener() {},
        },
        location: { reload() { ctx._dimuatUlang = true; } },
        showToast: (t) => { ctx._kabar = t; },
    };
    ctx.window = ctx;
    ctx.addEventListener = (jenis, fn) => { (ctx._pendengar = ctx._pendengar || {})[jenis] = fn; };
    ctx._el = el;
    ctx._simpanan = simpanan;
    return ctx;
};

const jalankan = (ctx) => {
    vm.createContext(ctx);
    vm.runInContext(skrip, ctx, { filename: 'contoh-designer.js' });
};

// ── 1. Simpan dibatalkan dan ditulis ke peramban ─────────────────────────
const simpanan = {};
const dunia = bikinDunia(simpanan);
jalankan(dunia);

const kait = (dunia._pendengar || {})['ezdoc:before-save'];
if (!kait) gagal('kait simpan designer tidak dipasang');

const isi = {
    nama_template: 'Contoh Diubah',
    category: 'Contoh',
    doc_scope: 'patient',
    template_html: '<p>isi baru contoh</p>',
    config_ttd: '[{"id":"pasien"}]',
    config_header: '{"paperSize":"A4"}',
    verify_config: '{"show_patient":true}',
};
let dicegah = false;
kait({
    preventDefault: () => { dicegah = true; },
    detail: { formData: { get: (k) => (k in isi ? isi[k] : null) } },
});

if (!dicegah) gagal('simpan tidak dibatalkan — contohnya akan jadi template sungguhan');

const kunci = Object.keys(simpanan)[0];
if (!kunci || kunci.indexOf('ezdoc_example_designer_') !== 0) gagal('tidak ada draf di peramban');
const draf = JSON.parse(simpanan[kunci]);
if (draf.nama_template !== 'Contoh Diubah') gagal('nama template tidak tersimpan');
if (draf.template_html !== '<p>isi baru contoh</p>') gagal('isi editor tidak tersimpan');
if (draf.config_header !== '{"paperSize":"A4"}') gagal('config halaman tidak tersimpan');
if (draf.config_ttd !== '[{"id":"pasien"}]') gagal('config tanda tangan tidak tersimpan');

// ── 2. Muat ulang memulihkan isi, nama, dan config ───────────────────────
const dunia2 = bikinDunia(Object.assign({}, simpanan));
jalankan(dunia2);

if (dunia2._el.editor.value !== '<p>isi baru contoh</p>') gagal('isi editor tidak pulih');
if (dunia2._el.namaTemplate.value !== 'Contoh Diubah') gagal('nama template tidak pulih');
if (!dunia2.configHeader || dunia2.configHeader.paperSize !== 'A4') gagal('config halaman tidak pulih');
if (!Array.isArray(dunia2.configTtd) || dunia2.configTtd[0].id !== 'pasien') gagal('config tanda tangan tidak pulih');

// ── 3. Hapus draf mengosongkan lalu memuat ulang ─────────────────────────
dunia2._el.ezExampleDesignerClear._klik();
if (Object.keys(dunia2._simpanan).length !== 0) gagal('draf tidak terhapus');
if (!dunia2._dimuatUlang) gagal('halaman tidak dimuat ulang sesudah draf dihapus');

console.log('LULUS');
