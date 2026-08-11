<?php

declare(strict_types=1);

/**
 * Identitas subjek dokumen — tahap EXPAND, penulisan ganda.
 *
 * Lihat docs/260804-skema-subjek.md. Selama masa peralihan setiap penyimpanan
 * menulis ke kolom lama (`norm`, `nopen`) **dan** yang baru
 * (`subject_id`, `context_id`, `subject_type`). Yang dibaca masih yang lama,
 * jadi langkah ini tidak mengubah perilaku apa pun — ia cuma membuat kolom baru
 * ikut mutakhir, supaya pemindahan baca nanti tidak perlu mengejar data.
 *
 * ## Kenapa satu berkas untuk fungsi sekecil ini
 *
 * Ada empat tempat yang menulis dokumen: `save_document.php` (insert dan
 * update), `new_version.php`, dan `DocumentRepository`. Menyalin aturannya ke
 * empat tempat berarti empat tempat yang harus diubah lagi saat sumber
 * `subject_type` berkembang — dan yang satu terlewat akan menulis baris dengan
 * identitas yang berbeda dari tetangganya, diam-diam.
 */

if (defined('EZDOC_SUBJECT_IDENTITY_LOADED')) {
    return;
}
define('EZDOC_SUBJECT_IDENTITY_LOADED', true);

/**
 * Jenis subjek yang berlaku untuk dokumen yang sedang ditulis.
 *
 * Urutannya sekarang pendek, dan itu disengaja:
 *
 *   1. `EZDOC_DEFAULT_SUBJECT_TYPE` kalau aplikasi pemakai mendefinisikannya
 *   2. `''` — belum dinyatakan
 *
 * **Sumber per-template belum ada, dan itu keputusan yang masih terbuka**
 * (260804-skema-subjek §8/§9). Template surat persetujuan pasien dan template
 * SK karyawan bisa hidup di pemasangan yang sama, jadi satu nilai global pada
 * akhirnya tidak cukup — tapi menambahkannya sekarang berarti membaca konten
 * template di setiap penyimpanan, dan `save_document.php` tidak memuatnya.
 * Kandidatnya kolom `subject_type` di `ezdoc_templates`, bukan atribut HTML
 * yang harus diurai tiap kali.
 *
 * Sampai itu diputuskan, `''` adalah jawaban yang jujur: belum dinyatakan.
 * Bukan `'patient'` — menebak satu domain di dalam library persis hal yang
 * sedang dihilangkan rencana ini.
 *
 * String, tidak pernah null: kolomnya `NOT NULL` karena ia akan ikut kunci unik
 * slot, dan NULL di indeks unik dianggap berbeda dari NULL — yang membuat
 * keunikannya runtuh tanpa pesan kesalahan.
 */
function ezdoc_subject_type(): string
{
    if (defined('EZDOC_DEFAULT_SUBJECT_TYPE')) {
        $raw = EZDOC_DEFAULT_SUBJECT_TYPE;
        if (is_string($raw)) {
            return substr(trim($raw), 0, 32);
        }
    }

    return '';
}

/**
 * Baca identitas slot dari permintaan, menerima nama lama maupun baru.
 *
 * Nama baru menang kalau ada. Nama lama tetap diterima tanpa batas waktu yang
 * ditentukan sekarang: tautan `?norm=1&nopen=1` sudah tersimpan di bookmark
 * orang dan di tautan yang dikirim lewat chat, dan mematikannya berarti
 * mematahkan sesuatu yang tidak pernah mereka minta ubah.
 *
 * Awalan dipakai karena `save_document.php` membaca `_norm`/`_nopen`
 * sementara aksi lain membaca `norm`/`nopen` — perbedaan yang sudah ada
 * sebelum ini dan tidak perlu diseragamkan hanya untuk lewat sini.
 *
 * @param array<string,mixed> $source Biasanya $_POST atau $_GET.
 * @return array{0: string, 1: string} [subject_id, context_id]
 */
function ezdoc_slot_identity(array $source, string $prefix = ''): array
{
    $pick = static function (array $src, string ...$keys): string {
        foreach ($keys as $key) {
            if (isset($src[$key]) && is_scalar($src[$key])) {
                $value = trim((string) $src[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    };

    return [
        $pick($source, $prefix . 'subject_id', $prefix . 'norm'),
        $pick($source, $prefix . 'context_id', $prefix . 'nopen'),
    ];
}
