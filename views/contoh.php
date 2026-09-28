<?php

declare(strict_types=1);

/**
 * Halaman contoh konversi — dari gambar dokumen ke template ezdoc.
 *
 * Satu dokumen kertas dibedah: bagian mana jadi fitur apa, ditulis bagaimana,
 * dan disimpan di mana. Semuanya dibaca dari berkas lewat
 * {@see \Ezdoc\Template\Contoh}, tidak ada yang diambil dari database dan tidak
 * ada yang ditulis ke sana.
 *
 * Halaman ini juga ditujukan untuk dibaca MESIN: bagian "Ringkasan untuk AI"
 * berisi seluruh aturan + template dalam satu blok teks, siap disalin ke
 * percakapan dengan model lain yang diminta mengubah dokumen jadi template.
 *
 * @var \Ezdoc\UI\Config $config
 * @var \Ezdoc\UI\Theme  $theme
 */

if (function_exists('ezdoc_can_manage_templates') && !ezdoc_can_manage_templates()) {
    ?>
    <section class="max-w-2xl mx-auto p-6">
        <div class="rounded-lg border-l-4 border-amber-400 bg-amber-50 p-4">
            <p class="text-sm text-amber-900">
                <?= h(t('contoh.denied', [], 'This page is for people who design templates.')) ?>
            </p>
        </div>
    </section>
    <?php
    return;
}

$slugContoh = isset($_GET['contoh']) && is_string($_GET['contoh'])
    ? $_GET['contoh']
    : \Ezdoc\Template\Contoh::BAWAAN;
$contoh = \Ezdoc\Template\Contoh::muat($slugContoh, $config);

if ($contoh === null) {
    ?>
    <section class="max-w-2xl mx-auto p-6">
        <div class="rounded-lg border-l-4 border-red-400 bg-red-50 p-4">
            <p class="text-sm text-red-900">
                Berkas contoh tidak ditemukan di <code>contoh/</code>.
            </p>
        </div>
    </section>
    <?php
    return;
}

$templateHtml = (string) $contoh['template_html'];
$peta = $contoh['peta'];
$nilai = $contoh['nilai'];
$configHeader = json_decode((string) $contoh['config_header'], true);
if (!is_array($configHeader)) {
    $configHeader = [];
}

// ── Pratinjau: dirender perender yang sama dengan yang mencetak dokumen ──
// Bukan gambar hasil tangkapan layar. Kalau contohnya rusak, halaman ini yang
// pertama menunjukkannya — bukan orang yang menyalinnya bulan depan.
$pratinjau = '';
$pratinjauGagal = '';
if (function_exists('ezdoc_document_pdf_html')) {
    try {
        if (function_exists('ezdoc_set_document_locale')) {
            ezdoc_set_document_locale('id');
        }
        // Baris tabelnya dari berkas, bukan dari kueri: contoh yang tabelnya
        // kosong tidak menunjukkan apa pun tentang fitur tabel.
        $GLOBALS['ezdocBarisTabelSiap'] = $contoh['baris_tabel'];
        $pratinjau = ezdoc_document_pdf_html([
            'template' => $contoh,
            'doc_id' => 0,
            'fields' => $nilai,
            'ttd_values' => [],
            // Tanpa sambungan database: baris tabel kueri sengaja kosong.
            'conn' => null,
        ]);
    } catch (\Throwable $e) {
        $pratinjauGagal = $e->getMessage();
    }
} else {
    $pratinjauGagal = 'Perakit dokumen (lib/pdf_compose.php) belum dimuat.';
}

// Kode QR dibuat aplikasi pemakai; tanpa itu hasilnya gambar kosong. Diganti
// kotak berlabel supaya yang melihat tahu sebabnya, bukan ikon gambar rusak.
if ($pratinjau !== '' && strpos($pratinjau, '<img src="" class="qr-img"') !== false) {
    $pratinjau = str_replace(
        '<img src="" class="qr-img"',
        '<img src="data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="86" height="86">'
            . '<rect width="86" height="86" fill="#f1f5f9" stroke="#94a3b8" stroke-dasharray="4 3"/>'
            . '<text x="43" y="40" font-size="9" fill="#64748b" text-anchor="middle">QR dibuat</text>'
            . '<text x="43" y="52" font-size="9" fill="#64748b" text-anchor="middle">aplikasi</text></svg>'
        ) . '" class="qr-img"',
        $pratinjau
    );
}

// Config untuk ditampilkan: gambar logo dipotong supaya terbaca orang.
$configTampil = $configHeader;
if (isset($configTampil['logos']) && is_array($configTampil['logos'])) {
    foreach ($configTampil['logos'] as $nama => $isi) {
        $configTampil['logos'][$nama] = substr((string) $isi, 0, 38)
            . '… (dipotong, aslinya ' . number_format(strlen((string) $isi) / 1024, 0) . ' KB)';
    }
}
$configTeks = json_encode($configTampil, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$daftarContoh = \Ezdoc\Template\Contoh::daftar();
// Tidak semua contoh lahir dari selembar kertas. Yang memperagakan satu fitur
// tidak punya dokumen asal — dan panel "Dokumen asal" yang kosong lebih
// membingungkan daripada tidak ada.
$gambarUri = ($contoh['gambar'] !== '' && is_file($contoh['gambar']))
    ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($contoh['gambar']))
    : '';

// Tautan ke halaman pembuatan dokumen, memakai contoh yang sama.
$qk = (string) $config->get('app.query_key', 'ezdoc_page');
$base = (string) $config->get('app.base_path', '');
$join = (strpos($base, '?') === false) ? '?' : '&';
$urlGenerate = $base . $join . $qk . '=generate&template_id=contoh:' . rawurlencode((string) $contoh['slug']);
$urlDesigner = $base . $join . $qk . '=designer&action=edit&id=contoh:' . rawurlencode((string) $contoh['slug']);
$urlAksi = $base . $join . $qk . '=action';
// Nama yang ditawarkan: nama contohnya tanpa kata "(contoh)" — yang tersimpan
// bukan contoh lagi.
$namaUsul = trim((string) preg_replace('/\s*\(contoh\)\s*$/iu', '', (string) $contoh['nama_template']));

// Kop yang sedang berlaku, untuk mengisi formulir pengaturannya. Yang
// tersimpan lewat halaman ini menang atas nilai di berkas mount; keduanya
// ditampilkan apa adanya supaya yang mengatur tahu ia sedang mengubah apa.
$kopBerlaku = \Ezdoc\Template\Contoh::kopBerlaku($config);
$kopTersimpan = \Ezdoc\Template\Contoh::kopTersimpan();
$kopBisaDiatur = \Ezdoc\Template\Contoh::berkasKop() !== null;
$kopAlamat = is_array($kopBerlaku['address'] ?? '')
    ? implode("\n", $kopBerlaku['address'])
    : (string) ($kopBerlaku['address'] ?? '');
$kopAdaLogo = trim((string) ($kopBerlaku['logo'] ?? '')) !== '';

$aturan = [
    'Nama field huruf kecil dengan garis bawah. Nama yang sama dipakai di {{nama}}, data-nama-field, data-qr, dan data-cond.',
    'Teks yang tidak pernah berubah ditulis sebagai teks biasa. Field hanya untuk yang berbeda tiap dokumen.',
    'Isi TTD WAJIB tiga div berurutan: label, kotak tanda tangan, nama. Perender mencocokkan bentuk itu; satu div tambahan membuat tanda tangannya tidak tercetak.',
    'data-ttd-modes memilih bentuk tanda tangan: image, qr, atau image,qr (penanda tangan yang memilih).',
    'data-require-otp="1" mengirim kode sekali pakai ke nomor pada field yang disebut data-otp-phone-source, berlaku selama data-otp-ttl detik.',
    'Siapa yang boleh menandatangani diatur data-allowed-roles atau data-allowed-users. Kosong berarti siapa pun boleh.',
    'Tiap tanda tangan yang masuk membekukan PDF dokumen saat itu; halaman verifikasi memakai berkas beku itu, bukan merender ulang.',
    'Elemen mengambang dibungkus <p class="floating-only" contenteditable="false">, dan posisinya piksel dari sudut kiri-atas halaman.',
    'Tanda kurang-dari di dalam atribut ditulis sebagai entitas: data-cond="umur&lt;17", bukan data-cond="umur<17".',
    'Untuk tabel kueri cukup satu baris <tr>; baris itu digandakan sebanyak hasil. Baris yang isinya hanya penjumlahan muncul sekali.',
    'Kueri tabel hanya boleh SELECT. Parameternya {norm}, {nopen}, atau {nama_field}.',
    'data-qr menunjuk NAMA FIELD yang berisi isi kodenya, bukan isinya. Pembuat kode QR-nya disediakan aplikasi pemakai.',
    'Gambar logo disimpan di config_header.logos sebagai data URI, bukan <img src> di dalam HTML.',
    'Field bertipe date dicetak d/m/Y. Kalau mau "24 September 2026", pakai nilai bawaan date:d F Y atau field teks.',
    'Materai TIDAK dipakai di contoh ini: fiturnya belum selesai. Jangan menyalinnya dari template lain sampai selesai.',
];

$langkah = [
    'Buka Templates → buat template baru.',
    'Di toolbar editor, pindah ke tampilan kode (Source code).',
    'Tempel isi template.html apa adanya, lalu kembali ke tampilan biasa.',
    'Atur halaman: A4, tegak, tepi 18 mm, mode continuous.',
    'Unggah dua logo dengan nama persis: kop dan stempel.',
    'Buat kueri tabel bernama tindakan, isi SQL-nya, sesuaikan nama tabel dan kolom dengan database Anda.',
    'Sesuaikan peran penanda tangan (data-allowed-roles) dengan peran yang ada di aplikasi Anda.',
    'Simpan. Buat satu dokumen percobaan untuk melihat hasilnya.',
];

// Satu blok teks berisi semuanya — untuk disalin ke percakapan dengan AI lain.
$ringkasan = "# Cara mengubah dokumen (gambar) jadi template ezdoc\n\n"
    . 'Contoh lengkap: ' . (string) $contoh['nama_template'] . "\n\n"
    . "## Langkah\n";
foreach ($langkah as $i => $l) {
    $ringkasan .= ($i + 1) . '. ' . $l . "\n";
}
$ringkasan .= "\n## Peta bagian dokumen → fitur ezdoc\n";
foreach ($peta as $p) {
    $ringkasan .= "\n" . $p['no'] . '. ' . $p['bagian'] . ' → ' . $p['fitur'] . "\n"
        . '   markup : ' . $p['markup'] . "\n"
        . '   simpan : ' . $p['simpan'] . "\n"
        . '   catatan: ' . $p['catatan'] . "\n";
}
$ringkasan .= "\n## Aturan yang sering bikin gagal\n";
foreach ($aturan as $a) {
    $ringkasan .= '- ' . $a . "\n";
}
$ringkasan .= "\n## Template lengkap (isi editor)\n\n```html\n" . $templateHtml . "\n```\n";
$ringkasan .= "\n## Config halaman (config_header)\n\n```json\n" . $configTeks . "\n```\n";
?>

<section class="max-w-6xl mx-auto px-4 py-6 md:py-8 space-y-8">

    <header class="space-y-2">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-gray-900">Contoh konversi dokumen</h1>
            <div class="flex flex-wrap items-center gap-2">
                <?php if (count($daftarContoh) > 1): ?>
                    <select onchange="location.href=this.value"
                            class="rounded border border-gray-300 bg-white px-2 py-1.5 text-xs font-medium text-gray-700">
                        <?php foreach ($daftarContoh as $slug => $nama):
                            $u = $base . $join . $qk . '=contoh&contoh=' . rawurlencode($slug); ?>
                            <option value="<?= h($u) ?>" <?= $slug === $contoh['slug'] ? 'selected' : '' ?>><?= h($nama) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <a href="<?= h($urlDesigner) ?>"
                   class="inline-flex items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    Buka di Designer
                    <span class="text-gray-400">→</span>
                </a>
                <a href="<?= h($urlGenerate) ?>"
                   class="inline-flex items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    Coba di halaman Buat dokumen
                    <span class="text-gray-400">→</span>
                </a>
            </div>
        </div>
        <p class="text-sm text-gray-600 max-w-3xl">
            Satu surat kertas dibedah sampai selesai: bagian mana jadi fitur apa, ditulis bagaimana, dan
            disimpan di mana. Dokumennya karangan — nama, alamat, dan angkanya tidak nyata.
        </p>
        <p class="text-sm text-gray-600 max-w-3xl">
            Contoh yang sama bisa dibuka di Designer (untuk melihat apakah tiap elemen memang terbaca
            panelnya) dan di halaman Buat dokumen (untuk mengisi dan menandatangani). Tombol Simpan di
            kedua tempat itu menulis ke peramban Anda saja — database tidak disentuh.
        </p>
        <?php // Satu-satunya tindakan di halaman ini: kalau rancangannya cocok,
        // ia jadi template sungguhan. Sebelum tombol ini ditekan, tidak ada satu
        // baris pun yang masuk database. ?>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div class="flex flex-wrap items-center gap-2">
                <label for="ezNamaTemplate" class="text-xs font-medium text-gray-700">Kalau sudah cocok, simpan jadi template:</label>
                <input id="ezNamaTemplate" type="text" value="<?= h($namaUsul) ?>" maxlength="200"
                       class="min-w-[240px] flex-1 rounded border border-gray-300 px-2 py-1.5 text-xs">
                <button type="button" id="ezSimpanTemplate"
                        class="rounded px-3 py-1.5 text-xs font-medium text-white"
                        style="background: var(--ezdoc-primary, #4f46e5);"
                        data-url="<?= h($urlAksi) ?>" data-contoh="<?= h((string) $contoh['slug']) ?>">
                    Simpan jadi template
                </button>
            </div>
            <p id="ezSimpanKabar" class="mt-1.5 text-[11.5px] text-gray-500" role="status">
                Template baru dibuat baru; tidak ada template lain yang tersentuh.
            </p>
        </div>

        <?php // Kop surat: dari sini, bukan dari berkas config.
        //
        // Diminta pemakai: "maksudku konfigurasi di halaman example, karena
        // tiap consumer kan beda beda". Nilai di berkas mount tetap jadi
        // titik awal; yang diketik di sini menimpanya, dan ikut ke tiap
        // template yang disimpan dari contoh. ?>
        <details class="rounded-lg border border-gray-200 bg-white" id="ezKopPanel"<?= $kopTersimpan !== [] ? ' open' : '' ?>>
            <summary class="cursor-pointer px-3 py-2 text-xs font-medium text-gray-700">
                Kop surat
                <span class="ml-1 font-normal text-gray-500">
                    <?= $kopTersimpan !== [] ? '— diatur dari halaman ini' : '— dari konfigurasi aplikasi' ?>
                </span>
            </summary>
            <div class="border-t border-gray-100 p-3">
                <?php if (!$kopBisaDiatur): ?>
                <p class="text-[11.5px] text-amber-800">
                    Pemasangan ini belum punya folder penyimpanan, jadi kop hanya bisa diatur lewat
                    <code class="font-mono">letterhead.*</code> di berkas mount.
                </p>
                <?php else: ?>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-[11.5px] text-gray-600">Nama</span>
                        <input id="ezKopNama" type="text" maxlength="200" value="<?= h((string) ($kopBerlaku['name'] ?? '')) ?>"
                               class="mt-0.5 w-full rounded border border-gray-300 px-2 py-1.5 text-xs">
                    </label>
                    <label class="block">
                        <span class="text-[11.5px] text-gray-600">Kota (di atas tanda tangan)</span>
                        <input id="ezKopKota" type="text" maxlength="100" value="<?= h((string) ($kopBerlaku['city'] ?? '')) ?>"
                               class="mt-0.5 w-full rounded border border-gray-300 px-2 py-1.5 text-xs">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-[11.5px] text-gray-600">Alamat — satu baris teks jadi satu baris kop (maksimal 4)</span>
                        <textarea id="ezKopAlamat" rows="2"
                                  class="mt-0.5 w-full rounded border border-gray-300 px-2 py-1.5 text-xs font-sans"><?= h($kopAlamat) ?></textarea>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-[11.5px] text-gray-600">
                            Logo <?= $kopAdaLogo ? '— sudah ada; pilih berkas baru untuk menggantinya' : '' ?>
                        </span>
                        <input id="ezKopLogo" type="file" accept="image/*"
                               class="mt-0.5 w-full text-[11.5px] file:mr-2 file:rounded file:border-0 file:bg-gray-100 file:px-2 file:py-1 file:text-[11.5px]">
                    </label>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" id="ezKopSimpan"
                            class="rounded px-3 py-1.5 text-xs font-medium text-white"
                            style="background: var(--ezdoc-primary, #4f46e5);"
                            data-url="<?= h($urlAksi) ?>">Simpan kop</button>
                    <button type="button" id="ezKopReset"
                            class="rounded border border-gray-300 px-2.5 py-1.5 text-xs text-gray-700 hover:bg-gray-50">Kembalikan ke bawaan</button>
                    <?php if ($kopAdaLogo): ?>
                    <label class="text-[11.5px] text-gray-600">
                        <input id="ezKopHapusLogo" type="checkbox" class="align-middle"> hapus logo
                    </label>
                    <?php endif; ?>
                    <span id="ezKopKabar" class="text-[11.5px] text-gray-500" role="status"></span>
                </div>
                <p class="mt-1.5 text-[11.5px] text-gray-500">
                    Berlaku untuk semua contoh, dan ikut ke tiap template yang disimpan dari contoh.
                    Berkas contohnya sendiri tidak berubah.
                </p>
                <?php endif; ?>
            </div>
        </details>

        <p class="text-xs text-gray-500">
            Halaman ini hanya membaca berkas di <code class="font-mono">contoh/<?= h((string) $contoh['slug']) ?>/</code>.
            Tidak ada yang diambil dari database, tidak ada yang ditulis ke sana, dan contoh ini tidak
            terdaftar sebagai template.
        </p>
    </header>

    <!-- ── Dua sisi: kertas dan hasilnya ─────────────────────────────── -->
    <div class="grid gap-5 <?= $gambarUri !== '' ? 'lg:grid-cols-2' : '' ?>">
        <?php if ($gambarUri !== ''): ?>
        <div class="min-w-0 rounded-lg border border-gray-200 bg-white">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold text-gray-900">1 · Dokumen asal</h2>
                <span class="text-[11px] text-gray-500">hasil pindai, sudah terisi tangan</span>
            </div>
            <div class="p-3">
                <div class="relative" id="ezGambar">
                    <?php if ($gambarUri !== ''): ?>
                        <img src="<?= $gambarUri ?>" alt="Surat keterangan tindakan, versi kertas" class="w-full rounded border border-gray-200">
                    <?php endif; ?>
                    <?php foreach ($peta as $p): ?>
                        <button type="button"
                                data-ez-pin="<?= (int) $p['no'] ?>"
                                title="<?= h($p['bagian']) ?>"
                                class="absolute -translate-x-1/2 -translate-y-1/2 h-5 w-5 rounded-full text-[10px] font-semibold text-white shadow ring-2 ring-white transition hover:scale-125"
                                style="left: <?= (float) $p['pin']['x'] ?>%; top: <?= (float) $p['pin']['y'] ?>%; background: var(--ezdoc-primary, #4f46e5);">
                            <?= (int) $p['no'] ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="mt-2 text-[11px] text-gray-500">Klik nomornya untuk melompat ke penjelasan.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="min-w-0 rounded-lg border border-gray-200 bg-white">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold text-gray-900"><?= $gambarUri !== '' ? '2 · Hasil ezdoc' : 'Hasil ezdoc' ?></h2>
                <span class="text-[11px] text-gray-500">dirender perender yang mencetak dokumen</span>
            </div>
            <div class="p-3">
                <?php if ($pratinjau !== ''): ?>
                    <div id="ezPratinjauKotak" class="w-full overflow-hidden rounded border border-gray-200 bg-white" style="height: 620px;">
                        <iframe id="ezPratinjau" title="Pratinjau hasil" class="border-0"
                                style="width: 880px; height: 1215px; transform: scale(0.56); transform-origin: top left;"
                                sandbox="allow-same-origin"
                                srcdoc="<?= h($pratinjau) ?>"></iframe>
                    </div>
                    <ul class="mt-2 space-y-1 text-[11px] text-gray-500">
                        <li>Baris tabelnya dari berkas contoh — kueri di config tidak dijalankan, database tidak disentuh.</li>
                        <li>Tanda tangan masih kosong: ia diisi saat dokumen dibuat, dan yang tampil di sini hasil cetaknya.</li>
                    </ul>
                <?php else: ?>
                    <div class="rounded border-l-4 border-red-400 bg-red-50 p-3 text-xs text-red-900">
                        Pratinjau gagal dirender: <?= h($pratinjauGagal) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Peta fitur ────────────────────────────────────────────────── -->
    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-2.5">
            <h2 class="text-sm font-semibold text-gray-900"><?= $gambarUri !== '' ? '3 · Bagian kertas → fitur ezdoc' : 'Fitur yang dipakai' ?></h2>
        </div>
        <div class="divide-y divide-gray-100">
            <?php foreach ($peta as $p): ?>
                <div id="ezPeta<?= (int) $p['no'] ?>" data-ez-baris="<?= (int) $p['no'] ?>" class="px-4 py-3.5 transition-colors">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold text-white"
                              style="background: var(--ezdoc-primary, #4f46e5);"><?= (int) $p['no'] ?></span>
                        <span class="text-sm font-medium text-gray-900"><?= h($p['bagian']) ?></span>
                        <span class="text-gray-300">→</span>
                        <span class="text-sm text-gray-700"><?= h($p['fitur']) ?></span>
                    </div>
                    <pre class="mt-2 overflow-x-auto rounded bg-gray-50 px-3 py-2 text-[11.5px] leading-relaxed text-gray-800 ring-1 ring-gray-200"><code><?= h($p['markup']) ?></code></pre>
                    <p class="mt-2 text-[12.5px] leading-relaxed text-gray-600"><?= h($p['catatan']) ?></p>
                    <p class="mt-1 text-[11px] text-gray-500">Disimpan di: <span class="font-mono"><?= h($p['simpan']) ?></span></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Aturan + langkah ──────────────────────────────────────────── -->
    <div class="grid gap-5 lg:grid-cols-2">
        <div class="min-w-0 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold text-gray-900">4 · Cara memakai contoh ini</h2>
            </div>
            <ol class="list-decimal space-y-1.5 px-8 py-4 text-[13px] leading-relaxed text-gray-700">
                <?php foreach ($langkah as $l): ?>
                    <li><?= h($l) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <div class="min-w-0 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold text-gray-900">5 · Aturan yang sering bikin gagal</h2>
            </div>
            <ul class="list-disc space-y-1.5 px-8 py-4 text-[13px] leading-relaxed text-gray-700">
                <?php foreach ($aturan as $a): ?>
                    <li><?= h($a) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- ── Berkasnya ─────────────────────────────────────────────────── -->
    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5">
            <h2 class="text-sm font-semibold text-gray-900">6 · Berkas contoh</h2>
            <span class="text-[11px] text-gray-500 font-mono">contoh/<?= h((string) $contoh['slug']) ?>/</span>
        </div>
        <div class="space-y-4 p-4">
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-700 font-mono">template.html — isi editor</span>
                    <?php // Yang ditampilkan isi yang sudah dipasangi kop — itulah yang
                    // disalin orang ke designer, dan itu yang tersimpan kalau tombol
                    // simpan ditekan. Berkas di folder contoh sendiri tetap netral. ?>
                    <?php if ($kopTersimpan !== [] || ($kopBerlaku['name'] ?? '') !== ''): ?>
                    <span class="text-[11px] text-gray-400">kop sudah dari pengaturan</span>
                    <?php endif; ?>
                    <button type="button" data-ez-salin="ezTpl" class="rounded border border-gray-300 px-2 py-1 text-[11px] text-gray-700 hover:bg-gray-50">Salin</button>
                </div>
                <pre id="ezTpl" class="max-h-72 overflow-auto rounded bg-gray-50 p-3 text-[11.5px] leading-relaxed text-gray-800 ring-1 ring-gray-200"><code><?= h($templateHtml) ?></code></pre>
            </div>
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-700 font-mono">contoh.json — config_header</span>
                    <button type="button" data-ez-salin="ezCfg" class="rounded border border-gray-300 px-2 py-1 text-[11px] text-gray-700 hover:bg-gray-50">Salin</button>
                </div>
                <pre id="ezCfg" class="max-h-72 overflow-auto rounded bg-gray-50 p-3 text-[11.5px] leading-relaxed text-gray-800 ring-1 ring-gray-200"><code><?= h((string) $configTeks) ?></code></pre>
            </div>
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-700">Ringkasan untuk AI — satu blok berisi langkah, peta, aturan, template, dan config</span>
                    <button type="button" data-ez-salin="ezRingkas" class="rounded border border-gray-300 px-2 py-1 text-[11px] text-gray-700 hover:bg-gray-50">Salin</button>
                </div>
                <pre id="ezRingkas" class="max-h-44 overflow-auto rounded bg-gray-50 p-3 text-[11.5px] leading-relaxed text-gray-600 ring-1 ring-gray-200"><code><?= h($ringkasan) ?></code></pre>
            </div>
            <p id="ezSalinKabar" class="text-[11px] text-gray-500" role="status"></p>
        </div>
    </div>
</section>

<script>
(function () {
    // Nomor di gambar dan penjelasannya adalah dua tampilan dari satu hal.
    // Menyorot keduanya sekaligus lebih murah daripada memaksa orang mencari
    // baris yang sama dua kali.
    var sorot = null;
    Array.prototype.forEach.call(document.querySelectorAll('[data-ez-pin]'), function (pin) {
        pin.addEventListener('click', function () {
            var no = pin.getAttribute('data-ez-pin');
            var baris = document.getElementById('ezPeta' + no);
            if (!baris) return;
            if (sorot) sorot.style.background = '';
            baris.style.background = 'rgba(99, 102, 241, 0.07)';
            sorot = baris;
            baris.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    // Pratinjau dirender pada ukuran kertas penuh lalu dikecilkan. Skalanya
    // dihitung dari lebar yang tersedia — kalau dipatok angka tetap, di layar
    // sempit dokumennya terpotong dan kartunya melebar melewati layar.
    function skalakan() {
        var kotak = document.getElementById('ezPratinjauKotak');
        var bingkai = document.getElementById('ezPratinjau');
        if (!kotak || !bingkai) return;
        var s = Math.min(1, kotak.clientWidth / 880);
        bingkai.style.transform = 'scale(' + s + ')';
        kotak.style.height = Math.round(1215 * s) + 'px';
    }
    skalakan();
    window.addEventListener('resize', skalakan);

    // ── Simpan jadi template ──
    // Satu permintaan, satu baris baru. Yang menekan memutuskan namanya; kalau
    // namanya sudah dipakai, server menolak dan halaman ini mengatakannya —
    // tidak ada yang ditimpa diam-diam.
    var tombolSimpan = document.getElementById('ezSimpanTemplate');
    if (tombolSimpan) {
        tombolSimpan.addEventListener('click', function () {
            var kotak = document.getElementById('ezNamaTemplate');
            var lapor = document.getElementById('ezSimpanKabar');
            var nama = (kotak && kotak.value || '').trim();
            if (!nama) { lapor.textContent = 'Nama templatenya diisi dulu.'; return; }

            tombolSimpan.disabled = true;
            lapor.textContent = 'Menyimpan…';

            var data = new FormData();
            data.append('ajax', '1');
            data.append('action', 'save_contoh');
            data.append('contoh', tombolSimpan.dataset.contoh);
            data.append('nama_template', nama);

            fetch(tombolSimpan.dataset.url, { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    tombolSimpan.disabled = false;
                    if (!j || !j.success) {
                        lapor.textContent = (j && (j.message || j.error)) || 'Gagal menyimpan.';
                        lapor.className = 'mt-1.5 text-[11.5px] text-red-700';
                        return;
                    }
                    lapor.className = 'mt-1.5 text-[11.5px] text-green-700';
                    lapor.innerHTML = (j.message || 'Tersimpan.') + ' — '
                        + '<a class="underline" href="<?= h($base . $join . $qk) ?>=designer&action=edit&id=' + j.id + '">buka di designer</a>'
                        + ' · <a class="underline" href="<?= h($base . $join . $qk) ?>=generate&template_id=' + j.id + '">buat dokumen</a>';
                })
                .catch(function (e) {
                    tombolSimpan.disabled = false;
                    lapor.className = 'mt-1.5 text-[11.5px] text-red-700';
                    lapor.textContent = 'Gagal menyimpan: ' + e.message;
                });
        });
    }

    // ── Kop surat ──
    // Halaman ini dimuat ulang sesudah tersimpan: pratinjaunya dirender di
    // server, jadi menambal DOM di sini hanya akan menghasilkan dua kebenaran
    // yang bisa berbeda.
    var kopSimpan = document.getElementById('ezKopSimpan');
    if (kopSimpan) {
        var kopKabar = document.getElementById('ezKopKabar');
        var kirimKop = function (data, tombol) {
            tombol.disabled = true;
            kopKabar.textContent = 'Menyimpan…';
            fetch(kopSimpan.dataset.url, { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    tombol.disabled = false;
                    if (j && j.success) {
                        kopKabar.textContent = (j.message || 'Tersimpan.') + ' Memuat ulang…';
                        location.reload();
                        return;
                    }
                    kopKabar.textContent = (j && (j.message || j.error)) || 'Gagal menyimpan.';
                })
                .catch(function (e) { tombol.disabled = false; kopKabar.textContent = 'Gagal menyimpan: ' + e.message; });
        };

        kopSimpan.addEventListener('click', function () {
            var data = new FormData();
            data.append('ajax', '1');
            data.append('action', 'save_kop');
            data.append('nama', document.getElementById('ezKopNama').value);
            data.append('alamat', document.getElementById('ezKopAlamat').value);
            data.append('kota', document.getElementById('ezKopKota').value);
            var hapus = document.getElementById('ezKopHapusLogo');
            if (hapus && hapus.checked) { data.append('hapus_logo', '1'); }
            var berkas = document.getElementById('ezKopLogo');
            if (berkas && berkas.files && berkas.files[0]) { data.append('logo', berkas.files[0]); }
            kirimKop(data, kopSimpan);
        });

        var kopReset = document.getElementById('ezKopReset');
        if (kopReset) {
            kopReset.addEventListener('click', function () {
                var data = new FormData();
                data.append('ajax', '1');
                data.append('action', 'save_kop');
                data.append('reset', '1');
                kirimKop(data, kopReset);
            });
        }
    }

    var kabar = document.getElementById('ezSalinKabar');
    Array.prototype.forEach.call(document.querySelectorAll('[data-ez-salin]'), function (tombol) {
        tombol.addEventListener('click', function () {
            var sumber = document.getElementById(tombol.getAttribute('data-ez-salin'));
            if (!sumber) return;
            var teks = sumber.textContent;
            var selesai = function (pesan) { if (kabar) kabar.textContent = pesan; };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(teks).then(function () { selesai('Tersalin.'); }, function () { pilih(sumber, selesai); });
            } else {
                // Halaman lewat HTTP bukan secure context: clipboard API mati.
                pilih(sumber, selesai);
            }
        });
    });

    function pilih(el, selesai) {
        var r = document.createRange();
        r.selectNodeContents(el);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
        selesai('Clipboard tidak tersedia — teksnya sudah tersorot, tekan Ctrl+C.');
    }
})();
</script>
