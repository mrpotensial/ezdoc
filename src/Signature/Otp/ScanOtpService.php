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

    /**
     * Berapa lama kode yang dikirim ke alamat email berlaku.
     *
     * Lebih panjang dari kode pindaian karena surat bisa terlambat beberapa
     * menit dan penanda tangan perlu membuka kotak suratnya dulu. Tidak sampai
     * berjam-jam: kodenya dibacakan kepada petugas yang sedang melayaninya.
     */
    public const EMAIL_TTL_SECONDS = 1800;

    /** Jalan lahirnya token, tersimpan di `provider_meta.route`. */
    public const ROUTE_SCAN = 'scan';
    public const ROUTE_APP = 'app';
    public const ROUTE_EMAIL = 'email';

    /** Status {@see openDelivered()} untuk penanya yang bukan alamat tujuannya. */
    public const FORBIDDEN = 'forbidden';

    /** Status {@see openDelivered()} untuk token yang tidak dikirim ke alamat. */
    public const NOT_DELIVERED = 'not_delivered';

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

    /** Kunci kode turunan; null = dibaca dari {@see SigningKey} saat pertama dibutuhkan. */
    private ?string $codeKey;

    public function __construct(
        ?OtpChallengeRepository $repo = null,
        ?Clock $clock = null,
        ?string $codeKey = null
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->repo = $repo ?? new OtpChallengeRepository(Context::default()->db, $this->clock);
        $this->codeKey = $codeKey;
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
     * Terbitkan kode yang dikirim ke alamat email penanda tangan.
     *
     * Bedanya dengan {@see issue()}: kodenya lahir SEKARANG, bukan saat
     * pindaian masuk — tidak ada yang memindai. Barisnya langsung berstatus
     * "sudah dipindai", jadi layar petugas yang menunggu pindaian langsung
     * beralih ke isian kode tanpa jalur baru.
     *
     * Kodenya tidak disimpan polos. Ia diturunkan dari `challenge_id` dengan
     * kunci rahasia (HOTP, RFC 4226 §5.3), sehingga aplikasi penanda tangan bisa
     * menampilkan kode yang sama dengan isi suratnya lewat {@see openDelivered()}
     * tanpa ada salinan kode yang tersimpan. Yang tersimpan hanya hash-nya, sama
     * seperti jalur lain — verifikasinya tidak berubah.
     *
     * Alamatnya di `provider_meta.recipient`, bukan di kolom `phone`. Kolom itu
     * VARCHAR(30) dan alamat email sering lebih panjang; kolomnya diisi
     * {@see recipientKey()}.
     *
     * Kalimat persetujuan belum ada di sini: aplikasi pemakai yang merangkainya
     * saat menyusun suratnya, lalu menyimpannya lewat {@see recordConsent()}.
     *
     * @return array{scan_token: string, challenge_id: string, code: string, expires_at: string}
     * @throws EzdocException Alamat tidak sah, atau kunci rahasia belum dipasang.
     */
    public function issueToRecipient(int $templateId, string $ttdId, ?int $docId, string $email): array
    {
        $recipient = OtpService::normalizeRecipient($email);
        if (!OtpService::isEmail($recipient)) {
            throw new EzdocException('A delivered code needs a valid email address.');
        }

        $key = $this->codeKey();
        if ($key === null) {
            throw new EzdocException('A delivered code needs EZDOC_HMAC_SECRET.');
        }

        $issued = $this->issue(
            $templateId,
            $ttdId,
            $docId,
            self::EMAIL_TTL_SECONDS,
            ['route' => self::ROUTE_EMAIL, 'recipient' => $recipient]
        );

        $code = self::deriveCode($key, $issued['challenge_id']);
        $now = $this->clock->now()->getTimestamp();

        $this->repo->fulfilScan(
            $issued['scan_token'],
            self::recipientKey($recipient),
            password_hash($code, PASSWORD_DEFAULT),
            $issued['expires_at'],
            $this->fmt($now),
            ''
        );

        return $issued + ['code' => $code];
    }

    /**
     * Simpan kalimat persetujuan yang benar-benar dikirim, untuk kode lewat email.
     *
     * Terpisah dari {@see issueToRecipient()} karena kalimatnya baru ada setelah
     * aplikasi pemakai menyusun suratnya — dan baru boleh disimpan setelah surat
     * itu terkirim.
     */
    public function recordConsent(string $challengeId, string $consentMessage): void
    {
        $this->repo->recordConsent($challengeId, $consentMessage);
    }

    /**
     * Buka kode yang dikirim ke alamat email, untuk aplikasi penanda tangan.
     *
     * `$recipient` adalah alamat yang SUDAH dibuktikan aplikasi pemakai milik
     * penanya (misalnya alamat terverifikasi akun loginnya). Pustaka hanya
     * mencocokkannya dengan alamat tujuan.
     *
     * | Status | Artinya |
     * |---|---|
     * | `not_found` | Token tidak dikenal |
     * | `not_delivered` | Token ini bukan kiriman email — pakai jalur pindaian |
     * | `forbidden` | Penanya bukan alamat tujuan; `masked_recipient` menyebut tujuannya |
     * | `waiting` | Kode masih berlaku; `code` dan `seconds_left` ikut |
     * | `verified`, `signed`, `expired` | Seperti {@see progress()}, tanpa kode |
     *
     * @return array{status: string, challenge: array<string,mixed>|null,
     *     code?: string, seconds_left?: int, masked_recipient?: string}
     */
    public function openDelivered(string $scanToken, string $recipient): array
    {
        $row = $scanToken === '' ? null : $this->repo->findByScanToken($scanToken);
        if ($row === null) {
            return ['status' => self::NOT_FOUND, 'challenge' => null];
        }

        $meta = json_decode((string) ($row['provider_meta'] ?? ''), true);
        if (!is_array($meta) || ($meta['route'] ?? null) !== self::ROUTE_EMAIL) {
            return ['status' => self::NOT_DELIVERED, 'challenge' => null];
        }

        $to = (string) ($meta['recipient'] ?? '');
        $asker = $recipient === '' ? '' : OtpService::normalizeRecipient($recipient);
        if ($to === '' || $asker === '' || !hash_equals($to, $asker)) {
            return [
                'status'           => self::FORBIDDEN,
                'challenge'        => null,
                'masked_recipient' => $to === '' ? '' : OtpService::maskRecipient($to),
            ];
        }

        $status = $this->progressOf($row);
        $out = ['status' => $status, 'challenge' => $row];

        if ($status === self::WAITING) {
            $code = $this->deliveredCode($row);
            if ($code === null) {
                // Kunci hilang atau berganti sejak kode dikirim: kode di surat
                // masih sah, tapi aplikasi tidak bisa ikut menampilkannya.
                return ['status' => self::EXPIRED, 'challenge' => $row];
            }
            $out['code'] = $code;
            $out['seconds_left'] = $this->secondsLeft($row);
        }

        return $out;
    }

    /**
     * Kode turunan sebuah tantangan lewat email, atau null kalau tidak bisa.
     *
     * Dicocokkan dengan hash yang tersimpan sebelum dikembalikan: kunci yang
     * berganti sejak kode dikirim akan menghasilkan angka lain, dan angka yang
     * keliru lebih buruk daripada tidak ada angka sama sekali.
     *
     * @param array<string,mixed> $row
     */
    public function deliveredCode(array $row): ?string
    {
        $key = $this->codeKey();
        $challengeId = (string) ($row['challenge_id'] ?? '');
        $hash = (string) ($row['otp_hash'] ?? '');
        if ($key === null || $challengeId === '' || $hash === '') {
            return null;
        }

        $code = self::deriveCode($key, $challengeId);

        return password_verify($code, $hash) ? $code : null;
    }

    /**
     * Isi kolom `phone` untuk kode lewat email: 30 karakter, tidak bisa dibalik.
     *
     * Kolomnya VARCHAR(30) — alamat utuh sering tidak muat dan mode ketat MySQL
     * menolaknya. Isinya tetap stabil per alamat, jadi pengelompokan per
     * penerima masih bisa dilakukan.
     */
    public static function recipientKey(string $email): string
    {
        return 'em:' . substr(hash('sha256', OtpService::normalizeRecipient($email)), 0, 27);
    }

    /**
     * Enam angka dari HMAC, dengan pemotongan dinamis HOTP (RFC 4226 §5.3).
     *
     * SHA-256, bukan SHA-1: RFC 6238 §1.2 mengizinkannya, dan kodenya tidak
     * perlu cocok dengan aplikasi autentikator mana pun.
     */
    private static function deriveCode(string $key, string $challengeId): string
    {
        $mac = hash_hmac('sha256', $challengeId, $key, true);
        $offset = ord($mac[strlen($mac) - 1]) & 0x0f;
        $bin = ((ord($mac[$offset]) & 0x7f) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);

        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function codeKey(): ?string
    {
        if ($this->codeKey === null) {
            $this->codeKey = \Ezdoc\Signature\SigningKey::resolve(\Ezdoc\Signature\SigningKey::PURPOSE_DELIVERED_CODE);
        }

        return $this->codeKey;
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
