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
 * Lihat docs/260803-otp-jalur-kedua.md §8h.
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

    /**
     * Berapa lama permintaan yang dikirim ke aplikasi penanda tangan bisa dibuka.
     *
     * Jauh lebih panjang dari QR: tokennya tidak menganga di layar yang bisa
     * ditinggal petugas, tapi menunggu di aplikasi seseorang yang mungkin baru
     * membukanya sore nanti.
     */
    public const APP_TTL_SECONDS = 86400;

    /** Batas masa berlaku yang boleh diminta pemanggil — token tidak boleh hidup selamanya. */
    public const MAX_TTL_SECONDS = 604800;

    /** Jalan lahirnya token, tersimpan di `provider_meta.route`. */
    public const ROUTE_SCAN = 'scan';
    public const ROUTE_APP = 'app';

    /** Status untuk layar tunggu penanda tangan — lihat {@see progress()}. */
    public const OPEN = 'open';
    public const WAITING = 'waiting';
    public const VERIFIED = 'verified';
    public const SIGNED = 'signed';

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
     * `$ttlSeconds` dan `$meta` dipakai permintaan yang dikirim ke aplikasi
     * penanda tangan alih-alih ditampilkan sebagai QR: masa berlakunya lebih
     * panjang dan `route`-nya `app`. Tanpa keduanya, perilakunya persis seperti
     * sebelum parameter itu ada.
     *
     * @param array<string,mixed> $meta Masuk ke `provider_meta`. `route` bawaan `scan`.
     * @return array{scan_token: string, challenge_id: string, expires_at: string}
     */
    public function issue(
        int $templateId,
        string $ttdId,
        ?int $docId = null,
        ?int $ttlSeconds = null,
        array $meta = []
    ): array {
        if ($templateId <= 0 || $ttdId === '') {
            throw new EzdocException('Issuing a scan token requires both template_id and ttd_id.');
        }

        $now = $this->clock->now()->getTimestamp();

        $ttl = $ttlSeconds === null
            ? self::SCAN_TTL_SECONDS
            : max(60, min(self::MAX_TTL_SECONDS, $ttlSeconds));

        $challengeId = 'otp_' . bin2hex(random_bytes(16));
        $scanToken = bin2hex(random_bytes(16));
        $expiresAt = $this->fmt($now + $ttl);

        $this->repo->createAwaitingScan([
            'challenge_id'  => $challengeId,
            'doc_id'        => $docId,
            'template_id'   => $templateId,
            'ttd_id'        => $ttdId,
            'scan_token'    => $scanToken,
            'max_attempts'  => self::MAX_ATTEMPTS,
            'expires_at'    => $expiresAt,
            'created_at'    => $this->fmt($now),
            'provider_meta' => $meta + ['route' => self::ROUTE_SCAN],
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
     * `$meta` digabung ke `provider_meta` — misalnya kanal yang dipakai dan
     * pengenal penanda tangan di aplikasi pemakai. Kunci yang sudah ada
     * (`route`) tidak bisa ditimpa: ia ditulis saat token terbit.
     *
     * @param array<string,mixed> $meta
     * @return array{ok: bool, code?: string, reason?: string}
     */
    public function fulfil(string $scanToken, string $phone, string $consentMessage, array $meta = []): array
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

        // Sesudah menang saja: yang kalah balapan tidak meninggalkan jejak di
        // baris milik orang lain.
        if ($meta !== []) {
            $this->repo->mergeProviderMetaByScanToken($scanToken, $meta);
        }

        return [
            'ok'          => true,
            'code'        => $code,
            'ttl_seconds' => self::CODE_TTL_SECONDS,
        ];
    }

    /**
     * Sisa detik sebuah tantangan sebelum tenggatnya — tidak pernah negatif.
     *
     * @param array<string,mixed> $challenge Baris dari {@see resolve()}.
     */
    public function secondsLeft(array $challenge): int
    {
        return max(0, $this->secondsUntil((string) ($challenge['expires_at'] ?? '')));
    }

    /**
     * Sampai mana sebuah token, dilihat dari sisi penanda tangan.
     *
     * Dipakai layar tunggu sesudah kode tampil: penanda tangan perlu tahu
     * apakah petugas sudah memasukkan kodenya, tanpa bertanya.
     *
     * | Status | Artinya |
     * |---|---|
     * | `open` | Belum dipindai, masih berlaku |
     * | `waiting` | Kode sudah lahir, petugas belum memasukkannya |
     * | `verified` | Petugas memasukkan kode yang benar |
     * | `signed` | Tanda tangannya sudah tersimpan |
     * | `expired` | Lewat tenggat, atau tebakan salahnya habis |
     * | `not_found` | Token tidak dikenal |
     *
     * Urutan pemeriksaannya disengaja: yang sudah tersimpan tetap dilaporkan
     * tersimpan walau tenggat kodenya lewat sesudahnya.
     */
    public function progress(string $scanToken): string
    {
        $row = $this->repo->findByScanToken($scanToken);

        return $row === null ? self::NOT_FOUND : $this->progressOf($row);
    }

    /**
     * Sama dengan {@see progress()}, untuk baris yang sudah dibaca — riwayat
     * membaca puluhan baris sekaligus dan tidak perlu menanyakannya satu-satu.
     *
     * @param array<string,mixed> $row
     */
    public function progressOf(array $row): string
    {
        if (!empty($row['sign_token_used_at'])) {
            return self::SIGNED;
        }
        if (!empty($row['verified_at'])) {
            return self::VERIFIED;
        }
        if ($this->secondsUntil((string) $row['expires_at']) <= 0) {
            return self::EXPIRED;
        }
        if ((int) ($row['attempts'] ?? 0) >= (int) ($row['max_attempts'] ?? self::MAX_ATTEMPTS)) {
            return self::EXPIRED;
        }

        return empty($row['scanned_at']) ? self::OPEN : self::WAITING;
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
