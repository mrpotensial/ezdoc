<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

use Ezdoc\Context;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Support\Clock;
use Ezdoc\Support\SystemClock;

/**
 * Kode sekali pakai yang diminta penanda tangan sendiri, dan terikat ke satu
 * dokumen sejak lahir.
 *
 * Lihat docs/PLAN-OTP-JALUR-KEDUA.md §8h.
 *
 * ## Kenapa ini menggantikan voucher
 *
 * Voucher lahir hanya tahu nomor pengirimnya, karena kodenya diminta sebelum ada
 * yang membuka dokumen. Itu memaksa tabel sendiri — dan membuat pasien menerima
 * enam angka tanpa tahu untuk apa.
 *
 * Di sini QR ada di layar petugas, di dokumen yang sedang dibuka. Jadi
 * challenge-nya sudah tahu template, slot, dan dokumennya sebelum siapa pun
 * memindai. Tabel terpisah tidak diperlukan lagi.
 *
 * Bonusnya bukan cuma kesederhanaan: **persetujuannya jadi sadar.** Balasan yang
 * diterima penanda tangan menyebutkan dokumen apa yang akan ditandatangani, dan
 * pesan itu tersimpan di ponselnya sendiri sebagai bukti.
 *
 * ## Dua tenggat, bukan satu
 *
 * | | Berlaku | Kenapa |
 * |---|---|---|
 * | Token QR | pendek | Ia menganga di layar yang bisa ditinggal petugas |
 * | Kode | lebih panjang | Penanda tangan harus membacanya dan menyebutkannya |
 *
 * Tenggatnya berpindah pemilik saat pindaian masuk — lihat
 * {@see OtpChallengeRepository::fulfilScan()}.
 */
final class ScanOtpService
{
    /** Berapa lama QR di layar masih bisa dipindai. */
    public const SCAN_TTL_SECONDS = 300;

    /** Berapa lama kode berlaku setelah dipindai. */
    public const CODE_TTL_SECONDS = 600;

    /** Tebakan salah yang diperbolehkan sebelum kodenya mati. */
    public const MAX_ATTEMPTS = 3;

    /** Kenapa sebuah token ditolak. Kode, bukan kalimat — tampilan yang menerjemahkan. */
    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const EXPIRED = 'expired';
    public const ALREADY_SCANNED = 'already_scanned';

    private OtpChallengeRepository $repo;
    private Clock $clock;

    public function __construct(?OtpChallengeRepository $repo = null, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->repo = $repo ?? new OtpChallengeRepository(Context::default()->db, $this->clock);
    }

    /**
     * Terbitkan token untuk QR di layar petugas.
     *
     * Tokennya acak dan **tidak memuat identitas apa pun**. Teks yang dikirim
     * penanda tangan tersimpan di ponselnya dan di server penyedia chat; nama
     * dan nomor dokumen tempatnya di balasan, bukan di kiriman.
     *
     * @return array{scan_token: string, challenge_id: string, expires_at: string}
     */
    public function issue(int $templateId, string $ttdId, ?int $docId = null): array
    {
        if ($templateId <= 0 || $ttdId === '') {
            throw new EzdocException('Issuing a scan token requires both template_id and ttd_id.');
        }

        $now = $this->clock->now()->getTimestamp();

        $challengeId = 'otp_' . bin2hex(random_bytes(16));
        $scanToken = bin2hex(random_bytes(16));
        $expiresAt = $this->fmt($now + self::SCAN_TTL_SECONDS);

        $this->repo->createAwaitingScan([
            'challenge_id'  => $challengeId,
            'doc_id'        => $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'scan_token'    => $scanToken,
            'max_attempts'  => self::MAX_ATTEMPTS,
            'expires_at'    => $expiresAt,
            'created_at'    => $this->fmt($now),
            'provider_meta' => ['route' => 'scan'],
        ]);

        return [
            'scan_token'   => $scanToken,
            'challenge_id' => $challengeId,
            'expires_at'   => $expiresAt,
        ];
    }

    /**
     * Apa yang ditunjuk sebuah token, dan apakah masih bisa dipakai.
     *
     * Dipanggil bot begitu pesan masuk. Yang dikembalikan bukan cuma boleh/tidak
     * — juga identitas dokumennya, karena itulah yang harus disebut bot di
     * balasannya supaya penanda tangan tahu apa yang disetujuinya.
     *
     * @return array{status: string, challenge: array<string,mixed>|null}
     */
    public function resolve(string $scanToken): array
    {
        $row = $this->repo->findByScanToken($scanToken);

        if ($row === null) {
            return ['status' => self::NOT_FOUND, 'challenge' => null];
        }

        // Urutannya disengaja: yang sudah dipindai dilaporkan apa adanya walau
        // sudah lewat tenggat. "Sudah dipakai" dan "kedaluwarsa" menuntun ke
        // tindakan berbeda — yang satu berarti sudah ada yang memindai duluan,
        // yang satu berarti minta petugas menampilkan QR baru.
        if (!empty($row['scanned_at'])) {
            return ['status' => self::ALREADY_SCANNED, 'challenge' => $row];
        }

        if ($this->secondsUntil((string) $row['expires_at']) <= 0) {
            return ['status' => self::EXPIRED, 'challenge' => $row];
        }

        return ['status' => self::OK, 'challenge' => $row];
    }

    /**
     * Ubah pindaian jadi kode hidup.
     *
     * `$consentMessage` adalah teks yang **benar-benar dikirim** ke penanda
     * tangan, apa adanya. Ia disimpan karena pertanyaan saat sengketa bukan "apa
     * isi dokumennya" tapi "apa yang diberitahukan kepadanya saat itu" —
     * merangkainya ulang nanti tidak menjawab itu, sebab nama template bisa
     * berubah.
     *
     * Pemanggilnya yang merangkai teks itu, bukan library. Kata-kata persetujuan
     * adalah keputusan hukum milik organisasi yang memakainya.
     *
     * @return array{ok: bool, code?: string, reason?: string}
     */
    public function fulfil(string $scanToken, string $phone, string $consentMessage): array
    {
        $resolved = $this->resolve($scanToken);
        if ($resolved['status'] !== self::OK) {
            return ['ok' => false, 'reason' => $resolved['status']];
        }

        $clean = OtpService::normalizePhone($phone);
        if (strlen($clean) < 8) {
            return ['ok' => false, 'reason' => 'bad_phone'];
        }

        $now = $this->clock->now()->getTimestamp();
        $code = (string) random_int(100000, 999999);

        // Tenggatnya berpindah pemilik di sini: dari QR ke kodenya.
        $won = $this->repo->fulfilScan(
            $scanToken,
            $clean,
            password_hash($code, PASSWORD_DEFAULT),
            $this->fmt($now + self::CODE_TTL_SECONDS),
            $this->fmt($now),
            $consentMessage
        );

        // Kalah balapan: ada yang memindai QR yang sama lebih dulu. Kode di sini
        // tidak pernah tersimpan, jadi ia tidak boleh dikirim ke siapa pun.
        if (!$won) {
            return ['ok' => false, 'reason' => self::ALREADY_SCANNED];
        }

        return [
            'ok'          => true,
            'code'        => $code,
            'ttl_seconds' => self::CODE_TTL_SECONDS,
        ];
    }

    private function secondsUntil(string $stamp): int
    {
        $ts = strtotime($stamp);

        return $ts === false ? 0 : $ts - $this->clock->now()->getTimestamp();
    }

    private function fmt(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
