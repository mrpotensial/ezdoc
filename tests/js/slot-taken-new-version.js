/**
 * Penolakan simpan untuk form BARU yang menabrak dokumen bertanda tangan.
 *
 * Menjalankan handleSaveRejection(), showNewVersionModal() dan
 * doCreateNewVersion() yang sesungguhnya — diekstrak dari halaman — dengan
 * dialog, fetch, dan DOM tiruan, lalu mencetak apa yang terjadi sebagai JSON.
 *
 * Argumen:
 *   1. berkas JS berisi fungsi-fungsi itu (diekstrak oleh PHPUnit)
 *   2. skenario: slot-buka | slot-versi-baru | slot-tutup | beku-biasa
 *
 * Dipanggil dari tests/UI/SlotTakenDialogTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const skenario = process.argv[3] || 'slot-versi-baru';

const catatan = { dialog: null, kiriman: [], tujuan: '', dibersihkan: false, galat: null };

// Form baru: slot halaman ini kosong. Untuk skenario dokumen yang sudah terbuka,
// slot halamannya terisi — dan dialah yang harus dipakai.
const halaman = skenario === 'beku-biasa'
  ? { norm: '77', nopen: '88', label: 'A' }
  : { norm: '', nopen: '', label: '-' };

const jawabanDialog = {
  'slot-buka': true,
  'slot-versi-baru': 'secondary',
  'slot-tutup': false,
  'beku-biasa': true,
}[skenario];

class FormDataTiruan {
  constructor() { this.isi = new Map(); }
  append(k, v) { this.isi.set(k, String(v)); }
  get(k) { return this.isi.has(k) ? this.isi.get(k) : null; }
}

const el = (extra = {}) => Object.assign({
  value: '', innerHTML: '', style: {}, remove() {}, appendChild() {},
}, extra);

const ctx = {
  console, Promise, JSON, Object, Array, String, Number, parseInt, URLSearchParams,
  FormData: FormDataTiruan,
  CURRENT_NORM: halaman.norm,
  CURRENT_NOPEN: halaman.nopen,
  CURRENT_LABEL: halaman.label,
  templateId: 3,
  location: { href: '' },
  t: (kunci, ganti, bawaan) => bawaan || kunci,
  _ezdocEndpoint: () => '/docAction',
  _preservedParams: () => new URLSearchParams(),
  markClean: () => { catatan.dibersihkan = true; },
  showToast: () => {},
  ezdocAlert: (pesan) => { catatan.galat = pesan; },
  ezdocConfirm: (pesan, opsi) => {
    catatan.dialog = {
      pesan,
      konfirmasi: opsi && opsi.confirmText,
      kedua: (opsi && opsi.secondaryText) || '',
    };
    return Promise.resolve(jawabanDialog);
  },
  fetch: (url, init) => {
    const fd = init.body;
    const aksi = fd.get('_doc_action');
    catatan.kiriman.push({
      aksi, norm: fd.get('norm'), nopen: fd.get('nopen'), label: fd.get('label'),
    });
    const jawaban = aksi === 'list_versions'
      ? { success: true, versions: [{ version: 1, is_locked: 0 }, { version: 2, is_locked: 1 }] }
      : { success: true, version: 3 };
    return Promise.resolve({ json: () => Promise.resolve(jawaban) });
  },
};
ctx.window = ctx;
ctx.document = {
  getElementById: () => null,
  querySelector: (sel) => (sel.indexOf('newVerSource') !== -1 ? el({ value: 'blank' }) : null),
  createElement: () => el(),
  body: el(),
};

vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'slot-taken.js' });

const penolakan = skenario === 'beku-biasa'
  ? {
      success: false,
      message: 'This document has been signed…',
      remedy: 'new_version',
      changed_fields: ['nama'],
    }
  : {
      success: false,
      message: 'A signed document (#5) already exists…',
      remedy: 'slot_taken',
      existing_doc_id: 5,
      existing_version: 2,
      matched_slot: { norm: '1', nopen: '11', label: '-' },
    };

(async () => {
  await ctx.handleSaveRejection(penolakan);
  // Modalnya terbuka; orangnya memilih "Blank" lalu menekan buat.
  if (catatan.kiriman.some((k) => k.aksi === 'list_versions')) {
    await ctx.doCreateNewVersion();
  }
  catatan.tujuan = ctx.location.href;
  console.log(JSON.stringify(catatan));
})().catch((e) => {
  console.log(JSON.stringify({ lempar: String(e && e.message) }));
  process.exit(1);
});
