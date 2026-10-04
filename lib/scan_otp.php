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
 * `seconds_left` dan `route` dipakai halaman penanda tangan: yang pertama
 * untuk hitung mundur sebelum menekan setuju, yang kedua untuk membedakan QR di
 * layar petugas (`scan`) dari permintaan yang dikirim ke aplikasinya (`app`).
 *
 * @return array{
 *     status: string, document_name?: string, signer_name?: string,
 *     context_id?: string, subject_id?: string, label?: string,
 *     ttd_label?: string, created_at?: string, seconds_left?: int, route?: string
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
    $meta = json_decode((string) ($challenge['provider_meta'] ?? ''), true);
    $out = [
        'status'       => 'ok',
        'seconds_left' => $service->secondsLeft($challenge),
        'route'        => is_array($meta) && isset($meta['route'])
            ? (string) $meta['route']
            : \Ezdoc\Signature\Otp\ScanOtpService::ROUTE_SCAN,
    ];

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
                // docs/260803-otp-jalur-kedua.md §8h. Yang menjawab "dokumen yang
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
 * `$meta` digabung ke `provider_meta` — kanal yang dipakai (`channel`) dan
 * pengenal penanda tangan di aplikasi pemakai (`signer_ref`), kalau ada. Yang
 * kedua yang membuat {@see ezdoc_scan_history()} bisa menjawab "apa saja yang
 * pernah saya tanda tangani".
 *
 * Tidak pernah melempar, dengan alasan yang sama seperti di atas.
 *
 * @param array<string,mixed> $meta
 * @return array{ok: bool, code?: string, ttl_seconds?: int, reason?: string}
 */
function ezdoc_scan_fulfil(string $scanToken, string $phone, string $consentMessage, array $meta = []): array
{
    try {
        $result = (new \Ezdoc\Signature\Otp\ScanOtpService())
            ->fulfil($scanToken, $phone, $consentMessage, $meta);
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:fulfil] ' . $e->getMessage());

        return ['ok' => false, 'reason' => 'error'];
    }

    if (!empty($result['ok'])) {
        // Nomornya disamarkan di catatan audit. Baris yang menyebut nomor
        // lengkap setiap orang yang pernah memindai akan berubah jadi buku
        // alamat. Pengenal penanda tangan juga tidak ikut, dengan alasan sama.
        $audit = ['masked_phone' => \Ezdoc\Signature\Otp\OtpService::maskPhone($phone)];
        if (isset($meta['channel']) && is_string($meta['channel'])) {
            $audit['channel'] = $meta['channel'];
        }

        ezdoc_audit_log('otp.scan_fulfilled', [
            'target_type' => 'otp_challenge',
            'target_id'   => substr($scanToken, 0, 12),
            'metadata'    => $audit,
        ]);
    }

    return $result;
}

/**
 * Sampai mana sebuah token, untuk layar tunggu penanda tangan.
 *
 * Penanda tangan yang sudah membacakan kodenya perlu tahu apakah petugas sudah
 * memasukkannya — tanpa harus bertanya. Lihat
 * {@see \Ezdoc\Signature\Otp\ScanOtpService::progress()} untuk arti tiap status.
 *
 * Mengetahui token sudah cukup sebagai hak membaca: tokennya sekali pakai,
 * acak, dan yang dikembalikan cuma satu kata.
 *
 * @return array{status: string}
 */
function ezdoc_scan_progress(string $scanToken): array
{
    try {
        return ['status' => (new \Ezdoc\Signature\Otp\ScanOtpService())->progress($scanToken)];
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:progress] ' . $e->getMessage());

        return ['status' => 'error'];
    }
}

/**
 * Riwayat penandatanganan satu penanda tangan, terbaru dulu.
 *
 * `$signerRef` adalah pengenal yang dikirim aplikasi pemakai lewat
 * `ezdoc_scan_fulfil(..., ['signer_ref' => ...])`. Pustaka tidak menafsirkannya;
 * aplikasi pemakai yang menjamin bahwa yang bertanya memang pemilik pengenal itu.
 *
 * Dokumen yang sudah dihapus tidak ikut — catatannya masih ada untuk petugas,
 * tapi bagi penanda tangan dokumen itu memang tidak ada lagi.
 *
 * Tidak pernah melempar: daftar kosong lebih baik daripada halaman yang mati.
 *
 * @return list<array{
 *     document_name: string, ttd_label: string, context_id: string,
 *     scanned_at: string, status: string, channel: string, public_slug: string
 * }>
 */
function ezdoc_scan_history(string $signerRef, int $limit = 30): array
{
    try {
        $repo = new \Ezdoc\Signature\Otp\OtpChallengeRepository(\Ezdoc\Context::default()->connection());
        $rows = $repo->findBySignerRef($signerRef, $limit);
        $service = new \Ezdoc\Signature\Otp\ScanOtpService($repo);

        $out = [];
        foreach (ezdoc_scan_describe($rows) as $item) {
            if ($item['deleted']) {
                continue;
            }

            $row = $item['row'];
            $meta = is_array($row['provider_meta'] ?? null) ? $row['provider_meta'] : [];
            $out[] = [
                'document_name' => $item['document_name'],
                'ttd_label'     => $item['ttd_label'],
                'context_id'    => $item['context_id'],
                'scanned_at'    => (string) ($row['scanned_at'] ?? ''),
                'status'        => $service->progressOf($row),
                'channel'       => isset($meta['channel']) ? (string) $meta['channel'] : '',
                'public_slug'   => $item['public_slug'],
            ];
        }

        return $out;
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:history] ' . $e->getMessage());

        return [];
    }
}

/**
 * Permintaan tanda tangan yang menunggu di aplikasi, untuk dokumen satu subjek.
 *
 * Hanya yang dikirim lewat aplikasi (`route` = `app`). QR di layar petugas tidak
 * pernah muncul di sini — lihat
 * {@see \Ezdoc\Signature\Otp\OtpChallengeRepository::findPendingForSubject()}.
 *
 * Tokennya IKUT dikembalikan: dialah yang dipakai untuk membuka permintaannya.
 * Karena itu aplikasi pemakai wajib memastikan yang bertanya memang tertaut ke
 * subjek itu sebelum memanggil fungsi ini.
 *
 * @return list<array{
 *     scan_token: string, document_name: string, ttd_label: string,
 *     context_id: string, created_at: string, seconds_left: int
 * }>
 */
function ezdoc_scan_pending(string $subjectType, string $subjectId): array
{
    try {
        $repo = new \Ezdoc\Signature\Otp\OtpChallengeRepository(\Ezdoc\Context::default()->connection());
        $service = new \Ezdoc\Signature\Otp\ScanOtpService($repo);
        $rows = $repo->findPendingForSubject(
            $subjectType,
            $subjectId,
            \Ezdoc\Signature\Otp\ScanOtpService::ROUTE_APP
        );

        $out = [];
        foreach (ezdoc_scan_describe($rows) as $item) {
            $row = $item['row'];
            $out[] = [
                'scan_token'    => (string) $row['scan_token'],
                'document_name' => $item['document_name'],
                'ttd_label'     => $item['ttd_label'],
                'context_id'    => $item['context_id'],
                'created_at'    => (string) ($row['created_at'] ?? ''),
                'seconds_left'  => $service->secondsLeft($row),
            ];
        }

        return $out;
    } catch (\Throwable $e) {
        @error_log('[ezdoc:scan:pending] ' . $e->getMessage());

        return [];
    }
}

/**
 * Dokumen milik satu subjek, terbaru dulu.
 *
 * Untuk halaman "dokumen atas nama saya": nama, nomor kunjungan, tanggal, dan
 * slug verifikasi publik bila masih aktif. Isi dokumennya TIDAK ikut —
 * menampilkannya butuh pemeriksaan hak yang lain lagi.
 *
 * @return list<array{document_name: string, context_id: string, created_at: string, public_slug: string}>
 */
function ezdoc_subject_documents(string $subjectType, string $subjectId, int $limit = 30): array
{
    if ($subjectType === '' || $subjectId === '' || $limit <= 0) {
        return [];
    }

    try {
        $db = \Ezdoc\Context::default()->connection();
        // LIMIT ditulis langsung sebagai angka: tidak semua penghubung mengikat
        // parameter LIMIT sebagai bilangan, dan '30' bertanda kutip ditolak MySQL.
        $docs = $db->fetchAll(
            'SELECT `id`, `template_id`, `context_id`, `created_at`, `public_slug`, `public_slug_active`
               FROM `ezdoc_documents`
              WHERE `subject_type` = ? AND `subject_id` = ? AND `deleted_at` IS NULL
              ORDER BY `created_at` DESC
              LIMIT ' . (int) $limit,
            [$subjectType, $subjectId]
        );

        $names = ezdoc_scan_template_names(array_column($docs, 'template_id'));

        $out = [];
        foreach ($docs as $doc) {
            $out[] = [
                'document_name' => $names[(int) $doc['template_id']]['name'] ?? '',
                'context_id'    => (string) ($doc['context_id'] ?? ''),
                'created_at'    => (string) ($doc['created_at'] ?? ''),
                'public_slug'   => !empty($doc['public_slug_active']) ? (string) ($doc['public_slug'] ?? '') : '',
            ];
        }

        return $out;
    } catch (\Throwable $e) {
        @error_log('[ezdoc:subject:documents] ' . $e->getMessage());

        return [];
    }
}

/**
 * Lengkapi baris tantangan dengan nama dokumen, label slot, dan nomor kunjungan.
 *
 * Template dan dokumen dibaca sekali untuk semua baris, bukan per baris —
 * riwayat tiga puluh baris tidak boleh berarti enam puluh kueri.
 *
 * @internal
 * @param list<array<string,mixed>> $rows
 * @return list<array{row: array<string,mixed>, document_name: string, ttd_label: string,
 *     context_id: string, public_slug: string, deleted: bool}>
 */
function ezdoc_scan_describe(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $db = \Ezdoc\Context::default()->connection();
    $templates = ezdoc_scan_template_names(array_column($rows, 'template_id'), true);

    $docIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'doc_id')))));
    $docs = [];
    if ($docIds !== []) {
        $tanda = implode(',', array_fill(0, count($docIds), '?'));
        foreach ($db->fetchAll(
            "SELECT `id`, `context_id`, `public_slug`, `public_slug_active`, `deleted_at`
               FROM `ezdoc_documents` WHERE `id` IN ($tanda)",
            $docIds
        ) as $doc) {
            $docs[(int) $doc['id']] = $doc;
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $tpl = $templates[(int) ($row['template_id'] ?? 0)] ?? ['name' => '', 'content' => ''];
        $doc = $docs[(int) ($row['doc_id'] ?? 0)] ?? null;

        $ttdConfig = \Ezdoc\Signature\Otp\OtpService::findTtdConfig(
            (string) $tpl['content'],
            (string) ($row['ttd_id'] ?? '')
        );

        $out[] = [
            'row'           => $row,
            'document_name' => (string) $tpl['name'],
            'ttd_label'     => (string) ($ttdConfig['label'] ?? ''),
            'context_id'    => $doc !== null ? (string) ($doc['context_id'] ?? '') : '',
            'public_slug'   => $doc !== null && !empty($doc['public_slug_active'])
                ? (string) ($doc['public_slug'] ?? '')
                : '',
            'deleted'       => $doc !== null && !empty($doc['deleted_at']),
        ];
    }

    return $out;
}

/**
 * Nama (dan bila diminta, isi) template untuk sekumpulan id, sekali kueri.
 *
 * @internal
 * @param list<mixed> $ids
 * @return array<int, array{name: string, content: string}>
 */
function ezdoc_scan_template_names(array $ids, bool $withContent = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) {
        return [];
    }

    $tanda = implode(',', array_fill(0, count($ids), '?'));
    $kolom = $withContent ? '`id`, `name`, `content`' : '`id`, `name`';

    $out = [];
    foreach (\Ezdoc\Context::default()->connection()->fetchAll(
        "SELECT $kolom FROM `ezdoc_templates` WHERE `id` IN ($tanda)",
        $ids
    ) as $tpl) {
        $out[(int) $tpl['id']] = [
            'name'    => (string) ($tpl['name'] ?? ''),
            'content' => (string) ($tpl['content'] ?? ''),
        ];
    }

    return $out;
}
