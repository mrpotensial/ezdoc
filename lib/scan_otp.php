<?php

declare(strict_types=1);

/**
 * Sisi masuk jalur "penanda tangan meminta kodenya sendiri" (V6).
 *
 * Dipanggil penangan pesan masuk milik aplikasi pemakai, in-process — bukan
 * lewat HTTP. Server yang memanggil dirinya sendiri lewat loopback butuh kunci
 * rahasia dan mewarisi setiap cara loopback bisa gagal.
 *
 * ## Pembagian tugasnya
 *
 * | | Milik siapa | Kenapa |
 * |---|---|---|
 * | Mencari dokumen yang ditunjuk token | library | Ia yang tahu skemanya |
 * | Merangkai kata-kata persetujuan | **aplikasi pemakai** | Itu keputusan hukum, bukan teknis |
 * | Menyimpan kode + salinan pesannya | library | Ia yang tahu aturannya |
 *
 * Karena itu ada dua fungsi, bukan satu. Yang pertama mengumpulkan fakta, yang
 * kedua menyimpan hasilnya. Kalimatnya dirangkai di antara keduanya, oleh yang
 * berhak memutuskannya.
 *
 * ## Pemakaian
 *
 * ```php
 * $ctx = ezdoc_scan_context($token);
 * if ($ctx === null || $ctx['status'] !== 'ok') { ...tolak dengan sopan... }
 *
 * $pesan = "Dokumen: {$ctx['document_name']}\n"
 *        . "Atas nama: {$ctx['signer_name']}\n"
 *        . "No. Pendaftaran: {$ctx['context_id']}";
 *
 * $hasil = ezdoc_scan_fulfil($token, $nomorPengirim, $pesan);
 * if ($hasil['ok']) reply($pesan . "\n\nKode: {$hasil['code']}");
 * ```
 */

if (defined('EZDOC_SCAN_OTP_LOADED')) {
    return;
}
define('EZDOC_SCAN_OTP_LOADED', true);

/**
 * Fakta tentang dokumen yang ditunjuk sebuah token pindaian.
 *
 * Tidak pernah melempar: ini jalur webhook, dan webhook yang mati akan diulang
 * penyedia chat — menghasilkan beberapa kode untuk satu pindaian.
 *
 * `status` bernilai `ok`, `not_found`, `expired`, `already_scanned`, atau
 * `error`. Yang bukan `ok` tetap mengembalikan array, supaya pemanggilnya bisa
 * menjawab dengan kalimat yang tepat alih-alih diam.
 *
 * Yang TIDAK dikembalikan: apa pun yang bersifat klinis. Nama, jenis dokumen,
 * dan nomor kunjungan sudah cukup untuk mengenali — dan ponsel bisa dilihat
 * orang lain.
 *
 * @return array{
 *     status: string, document_name?: string, signer_name?: string,
 *     context_id?: string, subject_id?: string, label?: string,
 *     ttd_label?: string, created_at?: string
 * }
 */
function ezdoc_scan_context(string $scanToken): array
{
    try {
        $service = new \Ezdoc\Signature\Otp\ScanOtpService();
        $resolved = $service->resolve($scanToken);
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:context] ' . $e->getMessage());

        return ['status' => 'error'];
    }

    if ($resolved['status'] !== \Ezdoc\Signature\Otp\ScanOtpService::OK) {
        return ['status' => $resolved['status']];
    }

    $challenge = $resolved['challenge'];
    $out = ['status' => 'ok'];

    try {
        $db = \Ezdoc\Context::default()->connection();

        $tpl = $db->fetchOne(
            'SELECT `name`, `content` FROM `ezdoc_templates` WHERE `id` = ? LIMIT 1',
            [(int) $challenge['template_id']]
        );
        $out['document_name'] = (string) ($tpl['name'] ?? '');

        // Label slotnya — "Tanda Tangan Pasien" vs "Tanda Tangan Saksi". Penting
        // saat satu dokumen punya beberapa penanda tangan: yang memindai perlu
        // tahu ia sedang diminta menandatangani bagian yang mana.
        $ttdConfig = \Ezdoc\Signature\Otp\OtpService::findTtdConfig(
            (string) ($tpl['content'] ?? ''),
            (string) $challenge['ttd_id']
        );
        $out['ttd_label'] = (string) ($ttdConfig['label'] ?? '');

        $docId = isset($challenge['doc_id']) ? (int) $challenge['doc_id'] : 0;
        if ($docId > 0) {
            $doc = $db->fetchOne(
                'SELECT `subject_id`, `context_id`, `label`, `field_values`, `created_at`
                 FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
                [$docId]
            );

            if ($doc !== null) {
                // Nomor kunjungan ditampilkan, nomor rekam medis TIDAK — lihat
                // docs/PLAN-OTP-JALUR-KEDUA.md §8h. Yang menjawab "dokumen yang
                // mana" adalah nomor kunjungan, dan yang memindai bisa
                // mencocokkannya dengan karcis yang dipegangnya. Nomor rekam
                // medis permanen dan menghubungkan seluruh riwayat.
                $out['context_id'] = (string) ($doc['context_id'] ?? '');
                $out['created_at'] = (string) ($doc['created_at'] ?? '');

                // Label hanya berarti kalau bukan bawaan; kalau '-', ia cuma
                // menambah baris tanpa membedakan apa pun.
                $label = (string) ($doc['label'] ?? '');
                if ($label !== '' && $label !== '-') {
                    $out['label'] = $label;
                }

                $out['signer_name'] = ezdoc_scan_signer_name(
                    (string) ($doc['field_values'] ?? ''),
                    (string) ($ttdConfig['nama_field'] ?? ''),
                    (string) $challenge['ttd_id']
                );
            }
        }
    } catch (\Throwable $e) {
        // Identitasnya tidak lengkap, tapi tokennya sah. Pemanggil memutuskan
        // apakah itu cukup — menolak seluruhnya karena satu nama tidak terbaca
        // akan menghalangi penandatanganan yang sebenarnya sah.
        @error_log('[ezdoc:scan:context] identitas gagal dibaca: ' . $e->getMessage());
    }

    return $out;
}

/**
 * Nama penanda tangan dari `field_values`, kalau bisa ditemukan.
 *
 * Slot mendeklarasikan field namanya lewat `data-nama-field`; kalau tidak ada,
 * konvensi `nama_<ttd_id>` dicoba. Gagal keduanya berarti string kosong —
 * pesannya tetap terkirim, cuma tanpa nama.
 *
 * Aturan itu sengaja sama persis dengan yang dipakai penyimpan dokumen dan
 * halaman generate: satu dokumen bisa punya beberapa slot yang menunjuk field
 * berbeda, jadi menebak nama field dari kata umum seperti `nama` akan menyebut
 * orang lain. Pesan persetujuan ini adalah buktinya — nama kosong masih jujur,
 * nama yang keliru tidak.
 */
function ezdoc_scan_signer_name(string $fieldValuesJson, string $namaField, string $ttdId): string
{
    $values = json_decode($fieldValuesJson, true);
    if (!is_array($values)) {
        return '';
    }

    $keys = [];
    if ($namaField !== '') {
        $keys[] = $namaField;
    }
    if ($ttdId !== '') {
        $keys[] = 'nama_' . $ttdId;
    }

    foreach ($keys as $key) {
        if (isset($values[$key]) && is_scalar($values[$key])) {
            $name = trim((string) $values[$key]);
            if ($name !== '') {
                return $name;
            }
        }
    }

    return '';
}

/**
 * Simpan kode untuk token ini, beserta salinan persis pesan yang dikirim.
 *
 * `$consentMessage` adalah teks yang benar-benar diterima penanda tangan. Ia
 * disimpan karena pertanyaan saat sengketa bukan "apa isi dokumennya" tapi
 * "apa yang diberitahukan kepadanya saat itu" — dan merangkainya ulang nanti
 * tidak menjawab itu.
 *
 * Tidak pernah melempar, dengan alasan yang sama seperti di atas.
 *
 * @return array{ok: bool, code?: string, ttl_seconds?: int, reason?: string}
 */
function ezdoc_scan_fulfil(string $scanToken, string $phone, string $consentMessage): array
{
    try {
        $result = (new \Ezdoc\Signature\Otp\ScanOtpService())
            ->fulfil($scanToken, $phone, $consentMessage);
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:fulfil] ' . $e->getMessage());

        return ['ok' => false, 'reason' => 'error'];
    }

    if (!empty($result['ok'])) {
        // Nomornya disamarkan di catatan audit. Baris yang menyebut nomor
        // lengkap setiap orang yang pernah memindai akan berubah jadi buku
        // alamat.
        ezdoc_audit_log('otp.scan_fulfilled', [
            'target_type' => 'otp_challenge',
            'target_id'   => substr($scanToken, 0, 12),
            'metadata'    => [
                'masked_phone' => \Ezdoc\Signature\Otp\OtpService::maskPhone($phone),
            ],
        ]);
    }

    return $result;
}
