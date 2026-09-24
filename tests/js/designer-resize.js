/**
 * Ubah ukuran langsung di designer: tarik sudut atau sisinya seperti jendela.
 *
 * Kode penangannya diekstrak apa adanya dari designer_scripts.php lalu
 * dijalankan di atas DOM tiruan. Yang diperiksa akibatnya — lebar yang berubah
 * selama ditarik, angka yang tersimpan saat dilepas, dan Esc yang
 * mengembalikan — bukan teks sumbernya.
 *
 * Argumen: berkas JS berisi window.buatDelegasi(...).
 * Dipanggil dari tests/UI/DesignerResizeTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };
const px = (v) => (typeof v === 'string' && v.slice(-2) === 'px') ? parseFloat(v) : NaN;

// ── DOM tiruan secukupnya ────────────────────────────────────────────────
const turun = (el) => {
    const keluar = [];
    (function jalan(n) { n.children.forEach((c) => { keluar.push(c); jalan(c); }); })(el);
    return keluar;
};

const cocok = (n, sel) => String(sel).split(',').some((bagian) => {
    const s = bagian.trim();
    if (s === '') return false;
    const tag = (s.match(/^[a-zA-Z]+/) || [null])[0];
    if (tag !== null && n.tag !== tag) return false;
    const kelas = s.match(/\.[A-Za-z0-9_-]+/g) || [];
    if (kelas.some((k) => !n.classList.contains(k.slice(1)))) return false;
    const attr = s.match(/\[([a-z-]+)="([^"]*)"\]/);
    if (attr && n.getAttribute(attr[1]) !== attr[2]) return false;
    return true;
});

const bikin = (tag, opsi) => {
    const o = opsi || {};
    const el = {
        tag,
        _kelas: new Set(o.kelas || []),
        _attr: Object.assign({}, o.attr),
        style: Object.assign({}, o.style),
        children: [],
        parentNode: null,
        _lebar: o.lebar || 0,
        _tinggi: o.tinggi || 0,
        naturalWidth: o.naturalWidth || 0,
        naturalHeight: o.naturalHeight || 0,
        textContent: '',
        contentEditable: '',
    };
    el.classList = {
        add: (c) => { el._kelas.add(c); },
        remove: (c) => { el._kelas.delete(c); },
        contains: (c) => el._kelas.has(c),
    };
    Object.defineProperty(el, 'className', {
        get: () => [...el._kelas].join(' '),
        set: (v) => { el._kelas = new Set(String(v).split(/\s+/).filter(Boolean)); },
    });
    Object.defineProperty(el, 'offsetWidth', {
        get: () => { const w = px(el.style.width); return isNaN(w) ? el._lebar : w; },
    });
    Object.defineProperty(el, 'offsetHeight', {
        get: () => {
            const h = px(el.style.height);
            if (!isNaN(h)) return h;
            // Tinggi "auto" pada gambar = ikut proporsi aslinya.
            if (el.style.height === 'auto' && el.naturalWidth > 0) {
                return Math.round(el.offsetWidth * el.naturalHeight / el.naturalWidth);
            }
            return el._tinggi;
        },
    });
    el.getAttribute = (k) => (Object.prototype.hasOwnProperty.call(el._attr, k) ? el._attr[k] : null);
    el.setAttribute = (k, v) => { el._attr[k] = String(v); };
    el.appendChild = (c) => { c.parentNode = el; el.children.push(c); return c; };
    el.removeChild = (c) => { el.children = el.children.filter((x) => x !== c); c.parentNode = null; return c; };
    el.querySelector = (sel) => turun(el).filter((n) => cocok(n, sel))[0] || null;
    el.querySelectorAll = (sel) => turun(el).filter((n) => cocok(n, sel));
    el.closest = (sel) => { let n = el; while (n) { if (n.classList && cocok(n, sel)) return n; n = n.parentNode; } return null; };
    (o.anak || []).forEach((c) => el.appendChild(c));
    return el;
};

const iframeDoc = bikin('#document');
iframeDoc.head = iframeDoc.appendChild(bikin('head'));
iframeDoc.body = iframeDoc.appendChild(bikin('body'));
iframeDoc.createElement = (t) => bikin(t);
iframeDoc._pendengar = [];
iframeDoc.addEventListener = (jenis, fn, tangkap) => { iframeDoc._pendengar.push({ jenis, fn, tangkap: !!tangkap }); };

const kirimEvent = (jenis, target, data) => {
    const ev = Object.assign({ type: jenis, button: 0, clientX: 0, clientY: 0, key: '' }, data || {});
    ev.target = target;
    ev.preventDefault = () => { ev._cegah = true; };
    ev.stopPropagation = () => { ev._stop = true; };
    const urut = iframeDoc._pendengar.filter((p) => p.jenis === jenis && p.tangkap)
        .concat(iframeDoc._pendengar.filter((p) => p.jenis === jenis && !p.tangkap));
    for (const p of urut) {
        p.fn(ev);
        if (ev._stop) break;   // menghentikan rambatan = penangan lain tidak kebagian
    }
    return ev;
};

// ── Isi dokumen tiruan ───────────────────────────────────────────────────
const gambar = (lebar) => bikin('img', { style: { width: lebar + 'px' }, naturalWidth: 200, naturalHeight: 100 });

const logoMengambang = bikin('span', {
    kelas: ['logo-placeholder', 'floating', 'front'],
    attr: { 'data-logo': 'kop', 'data-width': '120px' },
    style: { left: '300px', top: '30px' },
    anak: [gambar(120)],
});
const bungkus = bikin('p', { kelas: ['floating-only'], anak: [logoMengambang] });
const logoSebaris = bikin('span', {
    kelas: ['logo-placeholder'], attr: { 'data-logo': 'sebaris', 'data-width': '90px' }, anak: [gambar(90)],
});
const logoTanpaGambar = bikin('span', { kelas: ['logo-placeholder'], attr: { 'data-logo': 'kosong' } });
const kotakMaterai = bikin('div', { style: { width: '100px', height: '60px' } });
const materai = bikin('div', {
    kelas: ['materai-placeholder'],
    attr: { 'data-materai': 'm1', 'data-width': '100', 'data-height': '60' },
    anak: [kotakMaterai],
});
const ttd = bikin('div', { kelas: ['ttd-placeholder'], attr: { 'data-ttd': 't1' }, anak: [bikin('div')] });
[bungkus, logoSebaris, logoTanpaGambar, materai, ttd].forEach((n) => iframeDoc.body.appendChild(n));

// ── Bayangan halaman ─────────────────────────────────────────────────────
const dragState = { active: false, element: null, startX: 0, startY: 0, startLeft: 0, startTop: 0 };
const configHeader = { logoSizes: {} };
const dipanggil = [];
const antrian = [];
const editor = {
    getDoc: () => iframeDoc,
    getBody: () => iframeDoc.body,
    selection: { select() {}, collapse() {} },
    focus() {},
    setDirty(v) { editor._kotor = v; },
    _kotor: false,
};

const ctx = {
    console, Math, JSON, Date, Object, Array, String, Number, Set, parseInt, parseFloat, isNaN,
    setTimeout: (fn) => { antrian.push(fn); },
    document: bikin('#topdoc'),
};
ctx.window = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'resize.js' });

const setup = ctx.window.buatDelegasi(
    editor, dragState, configHeader,
    () => dipanggil.push('scanLogos'),
    () => dipanggil.push('scanTtd'),
    () => dipanggil.push('scanMaterai'),
    () => dipanggil.push('reorder'),
    () => dipanggil.push('markDirty')
);
setup();

const arahDari = (el) => el.querySelectorAll('.ezdoc-resize-handle').map((h) => h.getAttribute('data-arah')).join(',');

// ── Pegangan terpasang sesuai yang ukurannya memang bisa disimpan ────────
if (arahDari(logoMengambang) !== 'e,se,sw') gagal('pegangan logo mengambang: ' + arahDari(logoMengambang));
if (arahDari(logoSebaris) !== 'e,se') gagal('pegangan logo sebaris: ' + arahDari(logoSebaris));
if (arahDari(materai) !== 'e,s,se') gagal('pegangan materai: ' + arahDari(materai));
if (arahDari(ttd) !== '') gagal('TTD tidak boleh bisa diubah ukurannya — ukurannya dikunci mengikuti hasil cetak');
if (arahDari(logoTanpaGambar) !== '') gagal('logo tanpa gambar tidak punya yang bisa diubah');

// Pegangan hanya alat bantu layar: ia tidak boleh ikut tersimpan ke dokumen.
logoMengambang.querySelectorAll('.ezdoc-resize-handle').forEach((h) => {
    if (h.getAttribute('data-mce-bogus') !== '1') gagal('pegangan bisa ikut tersimpan ke isi dokumen');
});

// Dipanggil dua kali tidak menggandakan pegangan.
iframeDoc._attachAllResizeHandles();
if (arahDari(logoMengambang) !== 'e,se,sw') gagal('pegangan berganda setelah dipasang ulang');

// ── Menarik sisi kanan: lebar ikut mouse, angkanya tersimpan saat dilepas ─
const pegangan = (el, arah) => el.querySelector('.ezdoc-resize-handle[data-arah="' + arah + '"]');
const img = logoMengambang.querySelector('img');

kirimEvent('mousedown', pegangan(logoMengambang, 'e'), { clientX: 500, clientY: 100 });
if (dragState.active) gagal('menarik pegangan malah memulai pemindahan tempat');
if (!logoMengambang.classList.contains('ezdoc-resizing')) gagal('keadaan sedang-diubah tidak ditandai');

kirimEvent('mousemove', iframeDoc.body, { clientX: 540, clientY: 100 });
if (img.offsetWidth !== 160) gagal('lebar tidak ikut mouse saat ditarik: ' + img.offsetWidth);
const label = logoMengambang.querySelector('.ezdoc-resize-badge');
if (!label) gagal('ukuran berjalan tidak ditampilkan');
if (label.textContent !== '160 × 80') gagal('angka ukuran salah: ' + label.textContent);

kirimEvent('mouseup', iframeDoc.body, {});
if (configHeader.logoSizes.kop !== '160px') gagal('lebar tidak tersimpan ke pengaturan logo');
if (logoMengambang.getAttribute('data-width') !== '160px') gagal('lebar tidak tersimpan ke atribut');
if (editor._kotor !== true) gagal('dokumen tidak ditandai berubah');
if (logoMengambang.querySelector('.ezdoc-resize-badge')) gagal('label ukuran tertinggal');
if (logoMengambang.classList.contains('ezdoc-resizing')) gagal('tanda sedang-diubah tertinggal');
antrian.splice(0).forEach((fn) => fn());
if (!dipanggil.includes('scanLogos')) gagal('panel tidak ikut diperbarui');

// ── Menarik sudut kiri: sisi kanannya tetap di tempat ────────────────────
const kananSebelum = (parseInt(logoMengambang.style.left) || 0) + img.offsetWidth;
kirimEvent('mousedown', pegangan(logoMengambang, 'sw'), { clientX: 300, clientY: 200 });
kirimEvent('mousemove', iframeDoc.body, { clientX: 260, clientY: 200 });
if (img.offsetWidth !== 200) gagal('menarik ke kiri tidak melebarkan: ' + img.offsetWidth);
if ((parseInt(logoMengambang.style.left) || 0) + img.offsetWidth !== kananSebelum) {
    gagal('sisi kanan ikut bergeser saat sudut kiri ditarik');
}
kirimEvent('mouseup', iframeDoc.body, {});

// ── Esc membatalkan, ukuran kembali seperti sebelum ditarik ──────────────
const lebarSebelum = img.offsetWidth;
const kiriSebelum = logoMengambang.style.left;
kirimEvent('mousedown', pegangan(logoMengambang, 'sw'), { clientX: 200, clientY: 200 });
kirimEvent('mousemove', iframeDoc.body, { clientX: 120, clientY: 200 });
if (img.offsetWidth === lebarSebelum) gagal('Esc: lebar tidak berubah saat ditarik');
kirimEvent('keydown', iframeDoc.body, { key: 'Escape' });
if (img.offsetWidth !== lebarSebelum) gagal('Esc tidak mengembalikan lebar: ' + img.offsetWidth);
if (logoMengambang.style.left !== kiriSebelum) gagal('Esc tidak mengembalikan posisi');
if (logoMengambang.querySelector('.ezdoc-resize-badge')) gagal('Esc menyisakan label ukuran');
// Tombol yang dilepas sesudah dibatalkan tidak boleh menyimpan apa-apa.
configHeader.logoSizes.kop = 'jangan-berubah';
kirimEvent('mouseup', iframeDoc.body, {});
if (configHeader.logoSizes.kop !== 'jangan-berubah') gagal('yang dibatalkan ikut tersimpan');

// ── Materai: sisi bawah mengubah tinggi saja ─────────────────────────────
kirimEvent('mousedown', pegangan(materai, 's'), { clientX: 50, clientY: 300 });
kirimEvent('mousemove', iframeDoc.body, { clientX: 50, clientY: 330 });
if (kotakMaterai.offsetHeight !== 90) gagal('tinggi materai tidak ikut mouse: ' + kotakMaterai.offsetHeight);
if (kotakMaterai.offsetWidth !== 100) gagal('lebar materai ikut berubah padahal yang ditarik sisi bawah');
kirimEvent('mouseup', iframeDoc.body, {});
if (materai.getAttribute('data-height') !== '90') gagal('tinggi materai tidak tersimpan');
if (materai.getAttribute('data-width') !== '100') gagal('lebar materai ikut tertulis salah');
antrian.splice(0).forEach((fn) => fn());
if (!dipanggil.includes('scanMaterai')) gagal('panel materai tidak ikut diperbarui');

// ── Ukuran tidak pernah menyusut jadi tak terlihat ───────────────────────
kirimEvent('mousedown', pegangan(logoSebaris, 'e'), { clientX: 400, clientY: 400 });
kirimEvent('mousemove', iframeDoc.body, { clientX: 100, clientY: 400 });
if (logoSebaris.querySelector('img').offsetWidth < 20) gagal('lebar boleh menyusut sampai hilang');
kirimEvent('mouseup', iframeDoc.body, {});

console.log('LULUS');
