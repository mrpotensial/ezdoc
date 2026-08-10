<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;

/**
 * Menyiapkan PDF untuk ditandatangani, dengan menambahkan bagian baru di
 * ujungnya (*incremental update*, PDF 32000-1:2008 §7.5.6 dan §12.8).
 *
 * ## Kenapa ini ada, padahal `OpensslPdfSigner::embedSignature()` sengaja stub
 *
 * Stub itu menolak menangani **PDF sembarangan**: yang mungkin sudah punya tanda
 * tangan lain, terenkripsi, terlinearisasi, atau memakai cross-reference stream.
 * Menulis penambal umum untuk semua itu tanpa kumpulan uji yang layak memang
 * berisiko menghasilkan PDF yang tampak sah tapi ditolak.
 *
 * **Kelas ini bukan penambal umum.** Ia menandatangani PDF yang baru saja dibuat
 * sendiri sedetik sebelumnya, pada saat pembekuan dokumen. Karena itu ia boleh
 * menuntut banyak, dan ia **menolak dengan jelas** apa pun di luar itu — daftar
 * penolakannya ada di {@see assertSignable()}.
 *
 * Ukuran yang dipakai juga berbeda. Untuk tanda tangan sendiri, yang harus
 * menerima hasilnya adalah pembaca PDF mana pun, bukan PSrE. Itu bisa diuji di
 * dalam repo: keluarannya dibongkar ulang oleh {@see PdfBytesRange} dan
 * diperiksa sendiri.
 *
 * Yang diklaim: **PAdES-B-B** — satu tanda tangan, tanpa data pencabutan
 * sertifikat. Bukan B-T maupun B-LT. Untuk itu ada `JSignPdfSigner`.
 *
 * ## Kenapa dua langkah, bukan `embedSignature()`
 *
 * Tanda tangannya menutupi byte PDF-nya sendiri, jadi byte itu harus sudah jadi
 * sebelum ada yang bisa menandatanganinya. Lihat {@see PdfSignaturePlaceholder}.
 *
 * ## Byte asli tidak pernah disentuh
 *
 * Seluruh perubahan ditulis sebagai objek baru **di ujung berkas** — termasuk
 * saat mengubah katalog dan halaman. Begitulah incremental update bekerja: objek
 * dengan nomor sama yang ditulis belakangan menggantikan yang lama, dan yang
 * lama tetap ada. Ini bukan kerapian, melainkan syarat: `/ByteRange` pertama
 * dimulai dari byte 0, jadi menyunting apa pun di depan akan membatalkan tanda
 * tangan lain yang mungkin sudah ada.
 *
 * PHP 7.4+ compatible.
 */
final class IncrementalPdfSigner
{
    /**
     * Ruang yang disediakan untuk CMS, dalam byte.
     *
     * 16 KB cukup lapang untuk sertifikat berantai tiga tingkat. Kelebihannya
     * jadi nol di dalam berkas — biayanya ruang, dan kekurangannya biayanya
     * menyiapkan ulang dari awal.
     */
    public const DEFAULT_RESERVED_SIZE = 16384;

    /**
     * Siapkan PDF: tambahkan medan tanda tangan beserta lubang kosongnya.
     *
     * Pilihan yang dikenali:
     *   - `field_name`     nama medan, bawaan `Signature1`
     *   - `reason`         `/Reason`
     *   - `location`       `/Location`
     *   - `contact_info`   `/ContactInfo`
     *   - `name`           `/Name` — nama penanda tangan yang tampil
     *   - `signing_time`   unix timestamp untuk `/M`, bawaan waktu sekarang
     *   - `sub_filter`     bawaan `adbe.pkcs7.detached`
     *   - `reserved_size`  bawaan {@see DEFAULT_RESERVED_SIZE}
     *
     * @param array<string,mixed> $options
     * @throws EzdocException kalau PDF-nya di luar lingkup yang ditangani
     */
    public function prepare(string $pdf, array $options = []): PdfSignaturePlaceholder
    {
        $this->assertSignable($pdf);

        $reserved = isset($options['reserved_size'])
            ? (int) $options['reserved_size']
            : self::DEFAULT_RESERVED_SIZE;
        if ($reserved < 1024) {
            throw new EzdocException('reserved_size terlalu kecil untuk CMS mana pun: ' . $reserved);
        }

        $prevXref = self::lastStartXref($pdf);
        $xref = self::readXref($pdf, $prevXref);

        $rootNum = self::refNumber($xref['trailer'], 'Root');
        if ($rootNum === null) {
            throw new EzdocException('Trailer PDF tidak menyebut /Root.');
        }

        $catalog = self::objectDict($pdf, $xref['offsets'], $rootNum, 'katalog');
        if (self::dictHasKey($catalog, 'AcroForm')) {
            throw new EzdocException(
                'PDF ini sudah punya /AcroForm. IncrementalPdfSigner sengaja hanya menangani '
                . 'PDF yang baru dibuat sendiri; untuk berkas dengan formulir yang sudah ada, '
                . 'pakai JSignPdfSigner atau ExternalPdfSigner.'
            );
        }

        $pageNum = self::firstPageNumber($pdf, $xref['offsets'], $catalog);
        $page = self::objectDict($pdf, $xref['offsets'], $pageNum, 'halaman pertama');

        $size = self::trailerSize($xref['trailer']);
        $sigNum = $size;
        $fieldNum = $size + 1;

        // ─── Susun bagian tambahannya ───
        //
        // Urutannya penting untuk satu alasan saja: offset '<' baru bisa
        // dihitung setelah semua yang mendahuluinya ditulis.
        $body = "\n";
        $offsets = [];

        // 1. Kamus tanda tangan, dengan /ByteRange dan /Contents yang masih
        //    berupa tempat kosong. Keduanya berlebar TETAP — menambalnya nanti
        //    tidak boleh mengubah panjang berkas sedikit pun.
        $offsets[$sigNum] = strlen($pdf) + strlen($body);
        $sigDict = $this->buildSignatureDictionary($sigNum, $reserved, $options);
        // Diukur dari awal kamusnya di dalam berkas jadi, bukan dari awal
        // bagian tambahan: `$body` diawali baris kosong, dan selisih satu byte
        // di sini membuat lubangnya bergeser ke spasi sebelum '<'.
        $sigDictAt = $offsets[$sigNum];
        $body .= $sigDict['text'];
        $contentsAt = $sigDictAt + $sigDict['contents_offset'];
        $rangeAt = $sigDictAt + $sigDict['range_offset'];
        $rangeWidth = $sigDict['range_width'];

        // 2. Medan tanda tangan. Satu objek jadi medan sekaligus anotasi —
        //    bentuk gabungan yang lazim untuk medan tanpa anak.
        $offsets[$fieldNum] = strlen($pdf) + strlen($body);
        $body .= $this->buildSignatureField($fieldNum, $sigNum, $pageNum, $options);

        // 3. Halaman: salinannya dengan /Annots yang memuat medan tadi. Anotasi
        //    yang tidak nempel di halaman mana pun membuat sebagian pembaca PDF
        //    menganggap medannya tidak ada.
        $offsets[$pageNum] = strlen($pdf) + strlen($body);
        $body .= $pageNum . " 0 obj\n" . self::withAnnots($page, $fieldNum) . "\nendobj\n";

        // 4. Katalog: salinannya dengan /AcroForm. /SigFlags 3 = ada tanda tangan
        //    dan medannya tidak boleh diubah lagi.
        $offsets[$rootNum] = strlen($pdf) + strlen($body);
        $body .= $rootNum . " 0 obj\n"
            . self::withAcroForm($catalog, $fieldNum)
            . "\nendobj\n";

        // ─── Tabel xref bagian tambahan + trailer ───
        $xrefOffset = strlen($pdf) + strlen($body);
        $body .= self::buildXrefSection($offsets);
        $body .= "trailer\n<< /Size " . ($size + 2)
            . ' /Root ' . $rootNum . ' 0 R'
            . self::carryTrailerRef($xref['trailer'], 'Info')
            . self::carryTrailerId($xref['trailer'])
            . ' /Prev ' . $prevXref
            . " >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        $out = $pdf . $body;

        // ─── Tambal /ByteRange sekarang setelah semua offset diketahui ───
        $gapLength = 2 + ($reserved * 2);   // '<' + hex + '>'
        $afterGap = $contentsAt + $gapLength;

        $range = new PdfBytesRange([
            0,
            $contentsAt,
            $afterGap,
            strlen($out) - $afterGap,
        ]);

        $rangeText = '[' . implode(' ', $range->toArray()) . ']';
        if (strlen($rangeText) > $rangeWidth) {
            throw new EzdocException(
                'Tempat untuk /ByteRange kurang lebar. Ini bug, bukan masukan yang salah.'
            );
        }
        // Diisi sampai penuh dengan spasi supaya panjangnya tidak berubah.
        $rangeText .= str_repeat(' ', $rangeWidth - strlen($rangeText));

        $out = substr_replace($out, $rangeText, $rangeAt, $rangeWidth);

        // Lubangnya harus benar-benar berada di tempat yang ditunjuk /ByteRange.
        // Meleset satu byte membuat tanda tangan menandatangani sebagian dirinya
        // sendiri, dan kegagalannya baru muncul di pembaca PDF orang lain.
        if ($out[$contentsAt] !== '<' || $out[$afterGap - 1] !== '>') {
            throw new EzdocException(
                '/ByteRange tidak menunjuk lubang /Contents. Ini bug, bukan masukan yang salah.'
            );
        }

        if (strlen($out) !== strlen($pdf) + strlen($body)) {
            throw new EzdocException(
                'Menambal /ByteRange mengubah panjang berkas. Ini bug, bukan masukan yang salah.'
            );
        }

        return new PdfSignaturePlaceholder($out, $range);
    }

    /**
     * Batas lingkup, dinyatakan terang-terangan.
     *
     * Tiap penolakan di sini adalah hal yang **bisa** ditangani penanda tangan
     * yang lebih lengkap, dan yang kalau dipaksakan di sini akan menghasilkan
     * berkas yang tampak benar sampai ada yang membukanya di Adobe.
     *
     * @throws EzdocException
     */
    private function assertSignable(string $pdf): void
    {
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new EzdocException('Bukan berkas PDF: tanda pengenal %PDF- tidak ada di awal.');
        }

        if (PdfBytesRange::findAll($pdf) !== []) {
            throw new EzdocException(
                'PDF ini sudah bertanda tangan. Menumpuk tanda tangan kedua di luar lingkup '
                . 'IncrementalPdfSigner — itu tugas JSignPdfSigner, atau PSrE yang menumpuk sendiri.'
            );
        }

        if (preg_match('/\/Encrypt[\s\/\[<]/', $pdf) === 1) {
            throw new EzdocException(
                'PDF terenkripsi tidak ditangani: setiap objek baru harus ikut dienkripsi '
                . 'dengan kunci berkasnya, dan itu penulis PDF yang utuh, bukan penambal.'
            );
        }

        // Cross-reference stream (PDF 1.5+) menyimpan tabelnya sebagai objek
        // terkompresi, bukan teks. Menambahnya menuntut penulisan stream yang
        // benar — bisa, tapi bukan sekarang, dan gagal diam-diam kalau ditebak.
        if (preg_match('/\bstartxref\b/', $pdf) !== 1 && substr_count($pdf, 'startxref') === 0) {
            throw new EzdocException('PDF tanpa startxref tidak bisa ditambah.');
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array{text: string, contents_offset: int, range_offset: int, range_width: int}
     */
    private function buildSignatureDictionary(int $objNum, int $reserved, array $options): array
    {
        $subFilter = isset($options['sub_filter']) && is_string($options['sub_filter']) && $options['sub_filter'] !== ''
            ? $options['sub_filter']
            : 'adbe.pkcs7.detached';

        $signingTime = isset($options['signing_time']) ? (int) $options['signing_time'] : time();

        // Lebar tetap, sengaja berlebih. Nilai sebenarnya baru diketahui setelah
        // seluruh berkas tersusun, dan yang ditambal belakangan tidak boleh
        // mengubah panjang apa pun.
        $rangeWidth = 64;

        $head = $objNum . " 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /" . $subFilter . "\n"
            . '/ByteRange ';

        $text = $head . str_repeat(' ', $rangeWidth) . "\n" . '/Contents ';

        $rangeOffset = strlen($head);
        $contentsOffset = strlen($text);

        $text .= '<' . str_repeat('0', $reserved * 2) . '>' . "\n"
            . '/M ' . self::pdfDate($signingTime) . "\n";

        foreach ([
            'Name'        => $options['name'] ?? null,
            'Reason'      => $options['reason'] ?? null,
            'Location'    => $options['location'] ?? null,
            'ContactInfo' => $options['contact_info'] ?? null,
        ] as $key => $value) {
            if (is_string($value) && $value !== '') {
                $text .= '/' . $key . ' ' . self::pdfString($value) . "\n";
            }
        }

        $text .= ">>\nendobj\n";

        return [
            'text'            => $text,
            'contents_offset' => $contentsOffset,
            'range_offset'    => $rangeOffset,
            'range_width'     => $rangeWidth,
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function buildSignatureField(int $objNum, int $sigNum, int $pageNum, array $options): string
    {
        $name = isset($options['field_name']) && is_string($options['field_name']) && $options['field_name'] !== ''
            ? $options['field_name']
            : 'Signature1';

        // /Rect kosong + /F 132 (Print 4 + NoView 128) = tanda tangan tak
        // terlihat. Coretan tanda tangan kita sudah tergambar di isi dokumen
        // saat dirender; menggambarnya lagi di sini cuma menampilkannya dua kali.
        return $objNum . " 0 obj\n"
            . '<< /Type /Annot /Subtype /Widget /FT /Sig'
            . ' /T ' . self::pdfString($name)
            . ' /V ' . $sigNum . ' 0 R'
            . ' /P ' . $pageNum . ' 0 R'
            . " /Rect [0 0 0 0] /F 132 >>\nendobj\n";
    }

    // ─── Membaca PDF yang ada ───

    private static function lastStartXref(string $pdf): int
    {
        if (preg_match_all('/startxref\s+(\d+)/', $pdf, $m) !== 1 && empty($m[1])) {
            throw new EzdocException('startxref tidak ditemukan di PDF.');
        }

        $offset = (int) end($m[1]);
        if ($offset <= 0 || $offset >= strlen($pdf)) {
            throw new EzdocException('startxref menunjuk ke luar berkas: ' . $offset);
        }

        return $offset;
    }

    /**
     * Baca tabel xref klasik beserta trailer-nya, ikut menelusuri /Prev.
     *
     * @return array{offsets: array<int,int>, trailer: string}
     */
    private static function readXref(string $pdf, int $offset): array
    {
        $offsets = [];
        $trailer = '';
        $seen = [];

        while ($offset > 0 && $offset < strlen($pdf)) {
            if (isset($seen[$offset])) {
                // Rantai /Prev yang melingkar. Bukan berkas yang kita buat.
                break;
            }
            $seen[$offset] = true;

            if (substr(ltrim(substr($pdf, $offset, 16)), 0, 4) !== 'xref') {
                throw new EzdocException(
                    'Tabel xref klasik tidak ditemukan di offset ' . $offset . '. PDF dengan '
                    . 'cross-reference stream belum ditangani IncrementalPdfSigner.'
                );
            }

            $pos = strpos($pdf, 'xref', $offset) + 4;
            $end = strpos($pdf, 'trailer', $pos);
            if ($end === false) {
                throw new EzdocException('Bagian xref tanpa trailer.');
            }

            $section = substr($pdf, $pos, $end - $pos);
            if (preg_match_all('/(\d+)\s+(\d+)\s*\n((?:\d{10}\s+\d{5}\s+[nf]\s*\n?)+)/', $section, $subs, PREG_SET_ORDER)) {
                foreach ($subs as $sub) {
                    $start = (int) $sub[1];
                    if (preg_match_all('/(\d{10})\s+(\d{5})\s+([nf])/', $sub[3], $entries, PREG_SET_ORDER)) {
                        foreach ($entries as $i => $entry) {
                            $num = $start + $i;
                            // Yang lebih baru menang: bagian pertama yang dibaca
                            // adalah yang terakhir ditulis.
                            if ($entry[3] === 'n' && !isset($offsets[$num])) {
                                $offsets[$num] = (int) $entry[1];
                            }
                        }
                    }
                }
            }

            $trailerEnd = strpos($pdf, 'startxref', $end);
            $thisTrailer = substr($pdf, $end + 7, ($trailerEnd === false ? strlen($pdf) : $trailerEnd) - $end - 7);
            if ($trailer === '') {
                $trailer = $thisTrailer;
            }

            $offset = preg_match('/\/Prev\s+(\d+)/', $thisTrailer, $pm) === 1 ? (int) $pm[1] : 0;
        }

        if ($offsets === []) {
            throw new EzdocException('Tabel xref kosong atau tidak terbaca.');
        }

        return ['offsets' => $offsets, 'trailer' => $trailer];
    }

    private static function trailerSize(string $trailer): int
    {
        if (preg_match('/\/Size\s+(\d+)/', $trailer, $m) !== 1) {
            throw new EzdocException('Trailer PDF tidak menyebut /Size.');
        }

        return (int) $m[1];
    }

    private static function refNumber(string $dict, string $key): ?int
    {
        return preg_match('/\/' . $key . '\s+(\d+)\s+\d+\s+R/', $dict, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Isi kamus sebuah objek, dibaca dari offset yang disebut xref.
     *
     * Lewat offset, bukan dengan mencari `N 0 obj` di seluruh berkas: badan
     * objek bisa memuat stream berisi byte apa saja, termasuk yang menyerupai
     * penanda objek lain.
     *
     * @param array<int,int> $offsets
     */
    private static function objectDict(string $pdf, array $offsets, int $num, string $label): string
    {
        if (!isset($offsets[$num])) {
            throw new EzdocException('Objek ' . $label . ' (#' . $num . ') tidak ada di tabel xref.');
        }

        $at = $offsets[$num];
        if (preg_match('/\G\s*' . $num . '\s+\d+\s+obj/', $pdf, $m, 0, $at) !== 1) {
            throw new EzdocException(
                'Offset xref untuk ' . $label . ' (#' . $num . ') tidak menunjuk ke awal objeknya.'
            );
        }

        $start = strpos($pdf, '<<', $at + strlen($m[0]));
        if ($start === false) {
            throw new EzdocException('Objek ' . $label . ' (#' . $num . ') bukan kamus.');
        }

        return substr($pdf, $start, self::dictLength($pdf, $start));
    }

    /**
     * Panjang kamus `<< ... >>` dengan sarang, string, dan string heks dihormati.
     *
     * Menghitung `>>` dengan pencarian teks biasa akan berhenti di dalam string
     * `(a >> b)` — kesalahan yang menghasilkan kamus terpotong dan PDF rusak.
     */
    private static function dictLength(string $pdf, int $start): int
    {
        $len = strlen($pdf);
        $depth = 0;
        $i = $start;

        while ($i < $len) {
            $c = $pdf[$i];

            if ($c === '(') {
                // String biasa: kurung boleh bersarang, backslash melindungi.
                $nest = 1;
                $i++;
                while ($i < $len && $nest > 0) {
                    if ($pdf[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($pdf[$i] === '(') {
                        $nest++;
                    } elseif ($pdf[$i] === ')') {
                        $nest--;
                    }
                    $i++;
                }
                continue;
            }

            if ($c === '<' && $i + 1 < $len && $pdf[$i + 1] === '<') {
                $depth++;
                $i += 2;
                continue;
            }

            if ($c === '>' && $i + 1 < $len && $pdf[$i + 1] === '>') {
                $depth--;
                $i += 2;
                if ($depth === 0) {
                    return $i - $start;
                }
                continue;
            }

            if ($c === '<') {
                // String heks — lewati sampai '>' supaya tidak terbaca sebagai kamus.
                $close = strpos($pdf, '>', $i);
                $i = $close === false ? $len : $close + 1;
                continue;
            }

            $i++;
        }

        throw new EzdocException('Kamus PDF tidak tertutup — berkasnya terpotong?');
    }

    private static function dictHasKey(string $dict, string $key): bool
    {
        return preg_match('/\/' . $key . '[\s\/\[<]/', $dict) === 1;
    }

    /**
     * Nomor objek halaman pertama.
     *
     * @param array<int,int> $offsets
     */
    private static function firstPageNumber(string $pdf, array $offsets, string $catalog): int
    {
        $pagesNum = self::refNumber($catalog, 'Pages');
        if ($pagesNum === null) {
            throw new EzdocException('Katalog PDF tidak menyebut /Pages.');
        }

        $pages = self::objectDict($pdf, $offsets, $pagesNum, 'pohon halaman');

        if (preg_match('/\/Kids\s*\[(.*?)\]/s', $pages, $m) !== 1) {
            throw new EzdocException('Pohon halaman tanpa /Kids.');
        }
        if (preg_match('/(\d+)\s+\d+\s+R/', $m[1], $k) !== 1) {
            throw new EzdocException('/Kids kosong — PDF tanpa halaman.');
        }

        $first = (int) $k[1];
        $node = self::objectDict($pdf, $offsets, $first, 'simpul halaman');

        // Pohon halaman boleh bertingkat. Turun sampai menemukan /Type /Page.
        $guard = 0;
        while (preg_match('/\/Type\s*\/Pages\b/', $node) === 1 && $guard++ < 32) {
            if (preg_match('/\/Kids\s*\[(.*?)\]/s', $node, $m2) !== 1
                || preg_match('/(\d+)\s+\d+\s+R/', $m2[1], $k2) !== 1) {
                throw new EzdocException('Simpul pohon halaman tanpa anak.');
            }
            $first = (int) $k2[1];
            $node = self::objectDict($pdf, $offsets, $first, 'simpul halaman');
        }

        return $first;
    }

    // ─── Menyusun objek pengganti ───

    private static function withAcroForm(string $catalog, int $fieldNum): string
    {
        $inner = substr($catalog, 2, -2);

        return '<<' . $inner
            . ' /AcroForm << /Fields [' . $fieldNum . ' 0 R] /SigFlags 3 >>'
            . ' >>';
    }

    private static function withAnnots(string $page, int $fieldNum): string
    {
        $ref = $fieldNum . ' 0 R';

        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $m) === 1) {
            // Sudah ada anotasi lain (tautan, misalnya) — medan ini menyusul,
            // tidak menggantikan.
            return str_replace($m[0], '/Annots [' . trim($m[1]) . ' ' . $ref . ']', $page);
        }

        if (self::dictHasKey($page, 'Annots')) {
            // /Annots berupa rujukan ke objek lain. Menggabungnya menuntut
            // menulis ulang objek itu juga; ditolak, bukan ditebak.
            throw new EzdocException(
                'Halaman memakai /Annots sebagai rujukan tak langsung. Di luar lingkup '
                . 'IncrementalPdfSigner — pakai JSignPdfSigner untuk berkas seperti ini.'
            );
        }

        return '<<' . substr($page, 2, -2) . ' /Annots [' . $ref . '] >>';
    }

    /**
     * @param array<int,int> $offsets nomor objek => offset byte
     */
    private static function buildXrefSection(array $offsets): string
    {
        ksort($offsets);

        // Nomor yang berurutan digabung jadi satu sub-bagian; yang meloncat
        // memulai sub-bagian baru. Menulis satu sub-bagian besar berisi objek
        // yang tidak diubah akan menandai mereka bebas — dan menghapusnya.
        $groups = [];
        $current = [];
        $expected = null;

        foreach ($offsets as $num => $off) {
            if ($expected !== null && $num !== $expected) {
                $groups[] = $current;
                $current = [];
            }
            if ($current === []) {
                $current = ['start' => $num, 'entries' => []];
            }
            $current['entries'][] = $off;
            $expected = $num + 1;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        $out = "xref\n";
        foreach ($groups as $g) {
            $out .= $g['start'] . ' ' . count($g['entries']) . "\n";
            foreach ($g['entries'] as $off) {
                // Lebar tetap 20 byte per baris, seperti dituntut spesifikasi.
                $out .= sprintf("%010d %05d n \n", $off, 0);
            }
        }

        return $out;
    }

    private static function carryTrailerRef(string $trailer, string $key): string
    {
        $num = self::refNumber($trailer, $key);

        return $num === null ? '' : ' /' . $key . ' ' . $num . ' 0 R';
    }

    /**
     * /ID dibawa apa adanya kalau ada.
     *
     * Sebagian pembaca PDF memakainya untuk mencocokkan versi berkas; bagian
     * tambahan yang kehilangan /ID terbaca sebagai dokumen yang berbeda.
     */
    private static function carryTrailerId(string $trailer): string
    {
        return preg_match('/\/ID\s*\[[^\]]*\]/s', $trailer, $m) === 1 ? ' ' . $m[0] : '';
    }

    // ─── Bentuk nilai PDF ───

    private static function pdfString(string $value): string
    {
        return '(' . str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value) . ')';
    }

    private static function pdfDate(int $timestamp): string
    {
        $d = date('YmdHis', $timestamp);
        $tz = date('O', $timestamp);   // +0700

        return "(D:" . $d . substr($tz, 0, 3) . "'" . substr($tz, 3, 2) . "')";
    }
}
