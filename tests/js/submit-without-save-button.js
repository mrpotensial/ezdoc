/**
 * submitForm() tetap mengirim walau tombol Simpan tidak dirender.
 *
 * Dokumen yang sudah ditandatangani menampilkan New Version di tempat Update,
 * jadi `.btn-success` tidak ada di halaman. saveSign() tetap memanggil
 * submitForm(), karena menandatangani menyimpan sendiri.
 *
 * Sebelum penjagaannya ada, `const origText = btn.textContent` melempar
 * TypeError sebelum sempat mengirim apa pun: tanda tangannya tampil di layar,
 * tidak pernah tersimpan, dan hilang pada muat ulang berikutnya. Dilaporkan dari
 * pemakaian, pada tanda tangan lewat OTP.
 *
 * Dijalankan, bukan dibaca. Kegagalannya berupa pengecualian saat dijalankan —
 * pemeriksaan atas teks sumbernya akan melewatkan bentuk-bentuk lain dari
 * kesalahan yang sama.
 *
 * Dipanggil dari tests/UI/SubmitFormWithoutSaveButtonTest.php.
 * Argumen: berkas JS hasil ekstraksi dari blok <script>-nya.
 */
const vm = require('vm');
const fs = require('fs');

const el = (extra = {}) => Object.assign({
  value: '', textContent: '', disabled: false, innerHTML: '', className: '',
  style: {}, dataset: {}, files: [], children: [], parentElement: null,
  classList: { add() {}, remove() {}, contains: () => false, toggle() {} },
  getAttribute: () => null, setAttribute() {}, removeAttribute() {},
  addEventListener() {}, removeEventListener() {}, appendChild() {}, focus() {},
  querySelector: () => null, querySelectorAll: () => [], closest: () => null,
}, extra);

let terkirim = 0;
let galat = null;

const ctx = {
  console: { log() {}, warn() {}, error() {}, info() {} },
  setTimeout, clearTimeout, setInterval, clearInterval,
  JSON, Math, Date, Promise, Object, Array, String, Number, Boolean, Error, RegExp,
  encodeURIComponent, decodeURIComponent, parseInt, parseFloat, isNaN,
  FormData: class { append() {} entries() { return []; } get() { return null; } },
  CustomEvent: class { constructor(n, o) { this.type = n; Object.assign(this, o); } },
  localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
  history: { replaceState() {} },
  location: { href: '', search: '', pathname: '/' },
  navigator: { userAgent: 'node' },
  addEventListener() {}, removeEventListener() {}, dispatchEvent: () => true,

  fetch: () => {
    terkirim++;
    return Promise.resolve({
      json: () => Promise.resolve({ success: true, doc_id: 5, isEdit: true }),
    });
  },

  // Blok skrip lain di halaman menyediakan keduanya.
  t: (kunci, ganti, bawaan) => {
    // Satu-satunya jalan melihat pengecualian yang ditelan try/catch-nya.
    if (kunci === 'toast.save_failed') galat = ganti && ganti.error;
    return bawaan || kunci;
  },
  _ezdocEndpoint: () => '/docAction',
};

ctx.window = ctx;
ctx.globalThis = ctx;

ctx.document = {
  // Inti pengujiannya: tombol Simpan TIDAK ADA.
  querySelector: (sel) => (sel === '.btn-success' ? null : el()),
  querySelectorAll: () => [],
  getElementById: (id) => {
    if (id === 'inputNorm') return el({ value: '12345' });
    if (id === 'inputNopen') return el({ value: '678' });
    if (id === 'inputLabel') return el({ value: '-' });
    return el();
  },
  addEventListener() {}, removeEventListener() {},
  createElement: () => el(),
  body: el(), documentElement: el(),
};

vm.createContext(ctx);

// Tingkat teratasnya menyentuh kanvas dan hal lain yang tidak ada di sini, jadi
// ia akan berhenti di tengah. Deklarasi fungsi sudah terangkat sebelum baris
// pertama dijalankan, jadi submitForm() tetap terbentuk — dan itu yang diuji.
try {
  vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'generate_scripts.js' });
} catch (e) { /* diharapkan: tidak ada DOM sungguhan */ }

if (typeof ctx.submitForm !== 'function') {
  console.log('GAGAL: submitForm tidak terbentuk');
  process.exit(1);
}

// Validasi field butuh DOM sungguhan; yang diuji di sini bukan itu.
ctx.validateAllFields = () => true;
ctx.showToast = () => {};
ctx.ezdocAlert = () => {};
ctx.handleSaveRejection = () => {};

ctx.submitForm()
  .then(() => {
    if (terkirim > 0) {
      console.log('LULUS');
      process.exit(0);
    }
    console.log('GAGAL: tidak ada permintaan terkirim' + (galat ? ' — ' + galat : ''));
    process.exit(1);
  })
  .catch((e) => {
    console.log('GAGAL: submitForm melempar — ' + String(e && e.message).split('\n')[0]);
    process.exit(1);
  });
