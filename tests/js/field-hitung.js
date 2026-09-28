/**
 * Penghitung rumus di sisi peramban harus sepakat dengan sisi server.
 *
 * Fungsinya diambil apa adanya dari generate_scripts.php lalu dijalankan atas
 * kasus yang SAMA dengan tests/Format/CalcFieldTest.php. Dua penghitung untuk
 * satu bahasa adalah utang: yang satu akan berubah lebih dulu, dan bedanya
 * baru ketahuan saat angka di layar tidak sama dengan yang tercetak.
 *
 * Argumen: berkas JS berisi _pecahRumus + _hitungRumus.
 * Dipanggil dari tests/UI/FieldHitungJsTest.php.
 */
const vm = require('vm');
const fs = require('fs');

const gagal = (pesan) => { console.log('GAGAL: ' + pesan); process.exit(1); };

const ctx = { console };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx, { filename: 'hitung.js' });

if (typeof ctx._hitungRumus !== 'function') gagal('_hitungRumus tidak terbentuk');

const hitung = (rumus, nilai) => ctx._hitungRumus(rumus, (nama) => (nama in nilai ? nilai[nama] : 0));

// Kasus yang sama dengan sisi server.
const kasus = [
    ['a + b + c', { a: 3, b: 2, c: 2 }, 7],
    ['(a + b) * 2', { a: 3, b: 2 }, 10],
    ['a / b', { a: 3, b: 2 }, 1.5],
    ['a - b', { a: 3, b: 2 }, 1],
    ['{a} + {b}', { a: 3, b: 2 }, 5],
    ['a + b + c', { a: 3, b: 2 }, 5],            // yang tidak ada dihitung nol
    ['bishop_a + bishop_b + bishop_c + bishop_d + bishop_e', { bishop_a: 2, bishop_b: 2, bishop_c: 1, bishop_d: 2, bishop_e: 1 }, 8],
];

kasus.forEach(([rumus, nilai, harusnya]) => {
    const hasil = hitung(rumus, nilai);
    if (hasil !== harusnya) gagal('"' + rumus + '" → ' + hasil + ', harusnya ' + harusnya);
});

// Yang tidak bisa dihitung mengembalikan null — sama dengan sisi server.
[
    ['a + + *', { a: 3 }],
    ['a / b', { a: 3, b: 0 }],      // pembagian nol
    ['(a + b', { a: 3, b: 2 }],     // kurung tidak tertutup
    ['a $ b', { a: 3, b: 2 }],      // huruf yang tidak dikenal
    ['', {}],
].forEach(([rumus, nilai]) => {
    const hasil = hitung(rumus, nilai);
    if (hasil !== null) gagal('"' + rumus + '" seharusnya null, dapat ' + hasil);
});

// Urutan operasi: kali dan bagi lebih dulu daripada tambah dan kurang.
if (hitung('a + b * c', { a: 2, b: 3, c: 4 }) !== 14) gagal('urutan operasi salah');
if (hitung('(a + b) * c', { a: 2, b: 3, c: 4 }) !== 20) gagal('kurung tidak dihormati');

console.log('LULUS');
