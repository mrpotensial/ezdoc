<?php

declare(strict_types=1);

/**
 * Action: sudahkah QR-nya dipindai?
 *
 * Endpoint: POST _doc_action=scan_code_status
 *
 * Params:
 *   - challenge_id : string (wajib) dari request_scan_code
 *
 * Dipanggil berkala selagi modal terbuka, supaya layar petugas berpindah
 * sendiri dari "menunggu dipindai" ke "masukkan kode" begitu penanda tangan
 * memindai — tanpa petugas menekan apa pun.
 *
 * ## Kenapa ini bukan alat pengintai, tidak seperti pendahulunya
 *
 * Endpoint status pada jalur voucher menerima **nomor telepon** dan menjawab
 * apakah nomor itu punya kode aktif. Dalam jumlah besar itu memetakan siapa
 * yang sedang menandatangani, dan karenanya perlu jeda, pencatatan, dan
 * pembatasan tersendiri.
 *
 * Di sini yang diterima `challenge_id` — nilai acak 32 karakter yang baru saja
 * diterbitkan untuk pemanggilnya sendiri. Tidak ada yang bisa dipetakan dengan
 * menebaknya, jadi tidak ada yang perlu dibatasi.
 *
 * Perbedaan itu bukan kebetulan: ia akibat langsung dari kode yang terikat
 * dokumen alih-alih terikat nomor.
 */

use Ezdoc\Context;
use Ezdoc\Signature\Otp\OtpChallengeRepository;

require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ezdoc_respond_error(t('response.method_not_allowed', [], 'Method not allowed'), 405);
}

ezdoc_require_auth();

$challengeId = trim((string) ($_POST['challenge_id'] ?? ''));
if ($challengeId === '') {
    ezdoc_respond_error(t('response.missing_params', [], 'Missing required parameters'), 400);
}

$repo = new OtpChallengeRepository(Context::default()->db);
$challenge = $repo->findById($challengeId);

if ($challenge === null) {
    // Sengaja tidak membedakan "tidak ada" dari "milik orang lain". Tidak ada
    // yang berguna didapat pemanggil dari perbedaan itu.
    ezdoc_respond_success(['state' => 'unknown']);
}

$state = 'waiting';

if (!empty($challenge['verified_at'])) {
    $state = 'verified';
} elseif (!empty($challenge['scanned_at'])) {
    $state = 'scanned';
} elseif (strtotime((string) $challenge['expires_at']) < time()) {
    $state = 'expired';
}

$attemptsLeft = max(
    0,
    (int) ($challenge['max_attempts'] ?? 3) - (int) ($challenge['attempts'] ?? 0)
);

ezdoc_respond_success([
    'state' => $state,
    // Ditampilkan setelah dipindai, supaya petugas tahu berapa sisa percobaan
    // sebelum kodenya mati.
    'attempts_left' => $state === 'scanned' ? $attemptsLeft : null,
    // Nomor yang memindai, disamarkan. Petugas bisa mencocokkan sekilas dengan
    // nomor pasien tanpa nomornya ikut terbaca orang yang lewat di belakang.
    'masked_phone' => !empty($challenge['phone'])
        ? \Ezdoc\Signature\Otp\OtpService::maskPhone((string) $challenge['phone'])
        : null,
]);
