<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Envelope;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\PrivateKey;
use Ezdoc\Signature\KeyStore\X509Certificate;

/**
 * Ezdoc\Signature\Envelope\PkcsSevenEnvelope — PKCS#7 / CMS SignedData
 * envelope (RFC 5652).
 *
 * ## Kenapa temp file?
 *
 * PHP `openssl_pkcs7_*` API menerima filesystem paths saja — tidak ada
 * variant string-buffer bahkan di PHP 8.4. `php://memory` juga tidak
 * kompatibel (C-level fopen path). Semua operasi wrap dalam
 * `tempnam() + chmod 0600 + try/finally unlink()` — pattern kanonis
 * yang aman dari race condition symlink.
 *
 * ## Format tersimpan: DER
 *
 * `pack()` menghasilkan **DER** — encoding kanonis CMS menurut RFC 5652, dan
 * satu-satunya bentuk yang diterima verifier pihak lain
 * (`openssl cms -verify -inform DER`), PAdES, dan CAdES.
 *
 * Sebelumnya kelas ini menyimpan S/MIME, karena `openssl_pkcs7_sign()` selalu
 * menulis format itu apa pun flag-nya. S/MIME adalah pembungkus *transport
 * email* (RFC 8551), bukan format penyimpanan, dan memilihnya membawa tiga
 * akibat yang semuanya berasal dari satu akar:
 *
 *   1. `unpack()` tidak bisa membaca keluaran `pack()` sendiri.
 *   2. Konten biner tidak selamat — S/MIME transport teks.
 *   3. Konten ber-LF gagal diverifikasi — S/MIME mengkanonikalisasi ke CRLF,
 *      jadi isinya tidak lagi cocok dengan dirinya sendiri.
 *
 * Ketiganya hilang dengan DER. `PKCS7_BINARY` mematikan kanonikalisasi, dan
 * byte CMS-nya diambil keluar dari pembungkus S/MIME yang OpenSSL keluarkan.
 * (`openssl_cms_sign()` bisa menulis DER langsung, tapi baru ada di PHP 8.0
 * sementara lantai dukungan library ini 7.4.)
 *
 * ### Amplop lama tetap terbaca
 *
 * `verify()` dan `unpack()` menerima DER, PEM, maupun S/MIME. Amplop yang
 * sudah tersimpan sebelum perubahan ini tetap bisa diverifikasi apa adanya —
 * tidak ada migrasi data. Yang berubah hanya format tulis.
 *
 * ## Detached vs attached
 *
 * Default: **attached** (isi tertanam di amplop). Tanda tangan yang tidak
 * membawa isinya tidak bisa diikat ke dokumen mana pun oleh API OpenSSL milik
 * PHP — `verify()` akan menolak, bukan mengembalikan "valid" atas dokumen yang
 * tak pernah diperiksa. Lihat catatan di `verify()`.
 *
 * Untuk PAdES — yang memang detached, karena isinya adalah `/ByteRange` di
 * dalam PDF-nya sendiri — pakai `PadesEnvelope` / `OpensslPdfSigner`.
 *
 * ## Cross-runtime OpenSSL
 *
 * `openssl_cms_sign()` menerima cert+key sebagai resource / string (PEM) /
 * OpenSSLCertificate / OpenSSLAsymmetricKey — kita pass PEM string dari
 * `X509Certificate::getPem()` + resource dari `PrivateKey::getResource()`.
 * Konsisten di PHP 7.x resource dan PHP 8+ object.
 *
 * ## Signature vs Envelope
 *
 * Method `pack()` di kelas ini BUKAN pembungkus raw signature yang
 * sudah dihitung provider — PKCS7 melakukan proses sign dari cert+key
 * secara internal. Untuk memenuhi kontrak `Envelope::pack()`, kelas ini
 * WAJIB menerima cert (via param) + private key (via `options['private_key']`).
 * Kalau caller sudah punya raw signature dan ingin repack, itu use case
 * yang berbeda — tidak didukung karena PKCS7 SignedData butuh signer
 * info (issuer, serial, signedAttrs) yang tidak bisa dibangun ulang
 * dari raw bytes saja.
 *
 * PHP 7.4+ compatible.
 */
final class PkcsSevenEnvelope implements Envelope
{
    /** @var string */
    const FORMAT = 'pkcs7';

    /** @var string prefix tempnam untuk operasi PKCS7 */
    const TMP_PREFIX_IN = 'ezp7in_';

    /** @var string */
    const TMP_PREFIX_OUT = 'ezp7out_';

    /** @var string path ke CA bundle PEM (dipakai verify); kosong = skip chain check */
    private $caBundle;

    /** @var bool default detached mode */
    private $detached;

    /**
     * @param string $caBundle path ke CA bundle PEM (opsional; kosong → PKCS7_NOVERIFY di verify)
     * @param bool   $detached true = amplop tidak membawa isi. Standarnya false:
     *                         amplop detached tidak bisa diikat ke dokumen oleh
     *                         API PHP, jadi `verify()` akan menolaknya kecuali
     *                         caller lewat `skip_content_check`. Untuk PAdES
     *                         pakai PadesEnvelope.
     * @throws ValidationException kalau caBundle non-empty tapi file tidak ada
     */
    public function __construct(string $caBundle = '', bool $detached = false)
    {
        if ($caBundle !== '' && !is_file($caBundle)) {
            throw ValidationException::forField(
                'caBundle',
                'CA bundle file not found: ' . $caBundle
            );
        }
        $this->caBundle = $caBundle;
        $this->detached = $detached;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormat(): string
    {
        return self::FORMAT;
    }

    /**
     * {@inheritdoc}
     *
     * `$signature` parameter di-IGNORE — PKCS7_sign menghitung signature
     * sendiri dari cert+key. Ini keputusan sadar: PKCS7 SignedData tidak
     * bisa dibangun dari raw signature bytes tanpa signer info lengkap
     * (issuer/serial/signedAttrs).
     *
     * Options:
     *   - 'private_key' => PrivateKey (REQUIRED)
     *   - 'untrusted_bundle' => string (path ke PEM intermediates, opsional)
     *   - 'flags' => int (override default flags)
     *
     * @throws EzdocException technical failure (openssl gagal, IO, dsb)
     * @throws ValidationException kalau cert / private key tidak disediakan
     */
    public function pack(string $signature, string $content, X509Certificate $cert = null, array $options = []): string
    {
        if ($cert === null) {
            throw ValidationException::forField('cert', 'X509Certificate required for PKCS7 envelope');
        }
        if (!isset($options['private_key']) || !($options['private_key'] instanceof PrivateKey)) {
            throw ValidationException::forField(
                'private_key',
                'options["private_key"] must be a PrivateKey instance'
            );
        }
        /** @var PrivateKey $privKey */
        $privKey = $options['private_key'];

        $untrusted = null;
        if (isset($options['untrusted_bundle']) && is_string($options['untrusted_bundle']) && $options['untrusted_bundle'] !== '') {
            if (!is_file($options['untrusted_bundle'])) {
                throw ValidationException::forField(
                    'untrusted_bundle',
                    'intermediates bundle not found: ' . $options['untrusted_bundle']
                );
            }
            $untrusted = $options['untrusted_bundle'];
        }

        // PKCS7_BINARY mematikan kanonikalisasi baris. Tanpa ini, isi ber-LF
        // ditandatangani sebagai CRLF dan tidak lagi cocok dengan dirinya
        // sendiri saat diverifikasi.
        $defaultFlags = PKCS7_BINARY | PKCS7_NOATTR;
        if ($this->detached) {
            $defaultFlags |= PKCS7_DETACHED;
        }
        $flags = (isset($options['flags']) && is_int($options['flags']))
            ? $options['flags']
            : $defaultFlags;

        $inPath = self::mkTmp(self::TMP_PREFIX_IN);
        $outPath = self::mkTmp(self::TMP_PREFIX_OUT);
        try {
            if (file_put_contents($inPath, $content) === false) {
                throw new EzdocException('PkcsSevenEnvelope::pack(): failed to write temp input');
            }
            @chmod($inPath, 0600);
            @chmod($outPath, 0600);

            self::drainOpenSslErrors();

            $ok = openssl_pkcs7_sign(
                $inPath,
                $outPath,
                $cert->getPem(),
                $privKey->getResource(),
                [],
                $flags,
                $untrusted
            );
            if ($ok !== true) {
                $errs = self::drainOpenSslErrors();
                throw new EzdocException(
                    'openssl_pkcs7_sign() failed: ' . ($errs !== '' ? $errs : 'unknown')
                );
            }
            $bytes = @file_get_contents($outPath);
            if ($bytes === false || $bytes === '') {
                throw new EzdocException('PkcsSevenEnvelope::pack(): empty output from openssl_pkcs7_sign()');
            }

            // openssl_pkcs7_sign() selalu menulis S/MIME — pembungkus transport
            // email, bukan format penyimpanan. Byte CMS-nya diambil keluar dari
            // situ dan itulah yang disimpan.
            //
            // `openssl_cms_sign()` bisa mengeluarkan DER langsung, tapi baru ada
            // di PHP 8.0 sedangkan lantai dukungan library ini 7.4. Membongkar
            // base64-nya hanya memindahkan byte yang sama — hasilnya identik dan
            // jalan di semua versi.
            $der = self::toDer($bytes);
            if ($der === null) {
                throw new EzdocException(
                    'PkcsSevenEnvelope::pack(): could not extract CMS bytes from the S/MIME openssl produced'
                );
            }
            return $der;
        } finally {
            self::safeUnlink($inPath);
            self::safeUnlink($outPath);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Menerima DER, PEM, maupun S/MIME.
     *
     * Sertifikat penanda tangan diambil lewat `openssl_pkcs7_verify()` dengan
     * `PKCS7_NOVERIFY`, bukan `openssl_pkcs7_read()`. Yang terakhir menolak
     * armor PEM PKCS#7 yang sah — armor yang sama yang dibaca `openssl pkcs7
     * -inform PEM` tanpa keluhan — jadi ia tidak bisa dipakai. `NOVERIFY`
     * melewati pemeriksaan rantai dengan sengaja: memutuskan tepercaya atau
     * tidak adalah pekerjaan `verify()`, sedangkan `unpack()` hanya membongkar.
     *
     * Byte tanda tangan tidak bisa dipisahkan dari kontainer CMS tanpa parsing
     * ASN.1 penuh, jadi 'signature' berisi amplop utuh — caller yang perlu
     * memverifikasi ulang punya sumber lengkap.
     *
     * @throws EzdocException envelope tidak parseable
     */
    public function unpack(string $envelopeBytes): array
    {
        if ($envelopeBytes === '') {
            throw new EzdocException('PkcsSevenEnvelope::unpack(): empty envelope');
        }

        $smime = self::toSmime($envelopeBytes);
        if ($smime === null) {
            throw new EzdocException(
                'PkcsSevenEnvelope::unpack(): unrecognised envelope — expected CMS in DER, PEM or S/MIME form'
            );
        }

        $inPath = self::mkTmp(self::TMP_PREFIX_IN);
        $signerOut = self::mkTmp(self::TMP_PREFIX_OUT);
        $contentOut = self::mkTmp(self::TMP_PREFIX_OUT);
        try {
            if (file_put_contents($inPath, $smime) === false) {
                throw new EzdocException('PkcsSevenEnvelope::unpack(): failed to write temp input');
            }
            @chmod($inPath, 0600);
            @chmod($signerOut, 0600);
            @chmod($contentOut, 0600);

            self::drainOpenSslErrors();

            $ok = openssl_pkcs7_verify(
                $inPath,
                PKCS7_NOVERIFY | PKCS7_BINARY,
                $signerOut,
                [],
                null,
                $contentOut
            );
            if ($ok !== true) {
                $errs = self::drainOpenSslErrors();
                throw new EzdocException(
                    'PkcsSevenEnvelope::unpack(): envelope not parseable: '
                    . ($errs !== '' ? $errs : 'unknown structure')
                );
            }

            $signerPem = @file_get_contents($signerOut);
            if ($signerPem === false || $signerPem === '') {
                $signerPem = null;
            }

            $der = self::toDer($envelopeBytes);

            return [
                'signature' => $envelopeBytes,
                'signer_cert_pem' => $signerPem,
                'timestamp' => null, // signingTime attr tidak di-expose openssl PHP API
                'metadata' => [
                    'format' => self::FORMAT,
                    'encoding' => self::describeEncoding($envelopeBytes),
                    'is_pem' => self::isPemPkcs7($envelopeBytes),
                    'der_length' => $der === null ? 0 : strlen($der),
                    'cert_count' => $signerPem === null ? 0 : substr_count($signerPem, '-----BEGIN CERTIFICATE-----'),
                ],
            ];
        } finally {
            self::safeUnlink($inPath);
            self::safeUnlink($signerOut);
            self::safeUnlink($contentOut);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Options:
     *   - 'ca_bundle'        => string (override caBundle instance)
     *   - 'untrusted_bundle' => string (path PEM intermediates)
     *   - 'flags'            => int    (override default PKCS7_BINARY)
     *   - 'no_verify_chain'  => bool   (paksa PKCS7_NOVERIFY — untuk test)
     */
    public function verify(string $envelopeBytes, string $originalContent, array $options = []): array
    {
        if ($envelopeBytes === '') {
            return [
                'valid' => false,
                'reason' => 'empty envelope',
                'signer_info' => [],
                'checks' => ['non_empty' => false],
            ];
        }

        $caBundle = isset($options['ca_bundle']) && is_string($options['ca_bundle']) && $options['ca_bundle'] !== ''
            ? $options['ca_bundle']
            : $this->caBundle;
        $untrusted = null;
        if (isset($options['untrusted_bundle']) && is_string($options['untrusted_bundle']) && $options['untrusted_bundle'] !== '') {
            $untrusted = $options['untrusted_bundle'];
        }

        $noVerify = (isset($options['no_verify_chain']) && $options['no_verify_chain'] === true) || $caBundle === '';
        $defaultFlags = PKCS7_BINARY;
        if ($noVerify) {
            $defaultFlags |= PKCS7_NOVERIFY;
        }
        $flags = (isset($options['flags']) && is_int($options['flags']))
            ? $options['flags']
            : $defaultFlags;

        // openssl_pkcs7_verify() hanya menerima S/MIME. Amplop DER dibungkus
        // ulang menjadi p7m — base64 murni di sekitar byte yang sama, jadi
        // isinya tidak tersentuh dan tidak ada kanonikalisasi yang terjadi.
        // Amplop S/MIME lama diteruskan apa adanya: OpenSSL sudah menangani
        // multipart/signed maupun p7m secara asli.
        $smime = self::toSmime($envelopeBytes);
        if ($smime === null) {
            return [
                'valid' => false,
                'reason' => 'unrecognised envelope — expected CMS in DER, PEM or S/MIME form',
                'signer_info' => [],
                'checks' => ['non_empty' => true, 'recognised' => false],
            ];
        }

        $inPath = self::mkTmp(self::TMP_PREFIX_IN);
        $signerOut = self::mkTmp(self::TMP_PREFIX_OUT);
        $contentOut = self::mkTmp(self::TMP_PREFIX_OUT);

        try {
            if (file_put_contents($inPath, $smime) === false) {
                throw new EzdocException('PkcsSevenEnvelope::verify(): failed to write temp envelope');
            }
            @chmod($inPath, 0600);
            @chmod($signerOut, 0600);
            @chmod($contentOut, 0600);

            self::drainOpenSslErrors();

            // openssl_pkcs7_verify signature:
            //   openssl_pkcs7_verify($in, $flags, $signers_out=null, $ca_info=[], $untrusted=null, $content=null, $output=null)
            // NOTE: param ke-6 ($content) adalah OUTPUT path — bukan input. PHP
            // menulis ekstraksi content ke sana. Untuk detached S/MIME multipart
            // (default output openssl_pkcs7_sign), content sudah inline di envelope
            // sehingga verify tidak butuh feed content terpisah. Untuk detached
            // DER-only (bare signature), verify tidak akan berhasil via PHP API —
            // caller wajib re-attach content atau shell out ke `openssl smime -verify -content`.
            // Kami extract content ke $contentOut hanya untuk inspection (bisa
            // di-cross-check dengan $originalContent).
            $caArg = ($caBundle !== '' && !$noVerify) ? [$caBundle] : [];
            $result = openssl_pkcs7_verify(
                $inPath,
                $flags,
                $signerOut,
                $caArg,
                $untrusted,
                $contentOut,
                null
            );

            $errs = self::drainOpenSslErrors();

            // Strict === comparison — PHP kembalikan true / false / -1.
            if ($result === -1) {
                return [
                    'valid' => false,
                    'reason' => 'openssl_pkcs7_verify error: ' . ($errs !== '' ? $errs : 'unknown'),
                    'signer_info' => [],
                    'checks' => [
                        'openssl_result' => -1,
                        'chain_check' => !$noVerify,
                        'detached' => $this->detached,
                    ],
                ];
            }
            if ($result === false) {
                return [
                    'valid' => false,
                    'reason' => 'signature verify failed (tampered or wrong content)'
                        . ($errs !== '' ? '; ' . $errs : ''),
                    'signer_info' => [],
                    'checks' => [
                        'openssl_result' => false,
                        'chain_check' => !$noVerify,
                        'detached' => $this->detached,
                    ],
                ];
            }
            // result === true — the signature is internally consistent. That
            // says nothing yet about WHICH document it covers.
            //
            // openssl_pkcs7_verify() checks the signature against the content
            // carried inside the envelope, not against $originalContent. Left
            // there, this method would answer "valid" for any document handed
            // to it — including an empty one — because the caller's content is
            // never consulted. Binding the two is the entire promise of a
            // signature, so it is enforced here.
            $embedded = @file_get_contents($contentOut);

            if ($embedded === false || $embedded === '') {
                // Nothing to compare against: a bare detached envelope carries
                // no content, and PHP's API gives no way to feed one in. Rather
                // than report success on an unchecked document, say so.
                if (empty($options['skip_content_check'])) {
                    return [
                        'valid' => false,
                        'reason' => 'signature is well-formed but its content could not be extracted, '
                            . 'so it cannot be tied to the supplied document',
                        'signer_info' => [],
                        'checks' => [
                            'openssl_result' => true,
                            'content_match' => null,
                            'chain_check' => !$noVerify,
                            'detached' => $this->detached,
                        ],
                    ];
                }
            } elseif (!hash_equals($embedded, $originalContent)) {
                return [
                    'valid' => false,
                    'reason' => 'signature is valid but covers different content than the document supplied',
                    'signer_info' => [],
                    'checks' => [
                        'openssl_result' => true,
                        'content_match' => false,
                        'chain_check' => !$noVerify,
                        'detached' => $this->detached,
                    ],
                ];
            }

            $signerInfo = self::extractSignerInfo($signerOut);

            return [
                'valid' => true,
                'reason' => 'PKCS7 signature valid',
                'signer_info' => $signerInfo,
                'checks' => [
                    'openssl_result' => true,
                    'chain_check' => !$noVerify,
                    'detached' => $this->detached,
                    'ca_bundle' => $caBundle,
                ],
            ];
        } catch (EzdocException $e) {
            return [
                'valid' => false,
                'reason' => $e->getMessage(),
                'signer_info' => [],
                'checks' => ['exception' => true],
            ];
        } finally {
            self::safeUnlink($inPath);
            self::safeUnlink($signerOut);
            self::safeUnlink($contentOut);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canDetached(): bool
    {
        return true;
    }

    /**
     * Sniff apakah bytes berupa PKCS7 (PEM armor 'BEGIN PKCS7' / 'BEGIN CMS'
     * atau DER magic byte SEQUENCE 0x30).
     */
    /**
     * Whether the bytes look like an S/MIME-wrapped PKCS#7 signature.
     *
     * Both shapes openssl_pkcs7_sign() emits are covered: `multipart/signed`
     * when detached, and `application/(x-)pkcs7-mime` when not. Only the
     * header block is inspected, so the check stays cheap on large envelopes.
     */
    private static function isSmimePkcs7(string $bytes): bool
    {
        $head = substr($bytes, 0, 512);
        if (stripos($head, 'MIME-Version:') === false) {
            return false;
        }

        return stripos($head, 'pkcs7-signature') !== false
            || stripos($head, 'pkcs7-mime') !== false
            || stripos($head, 'multipart/signed') !== false;
    }

    public static function isPkcs7(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        if (self::isPemPkcs7($bytes)) {
            return true;
        }
        // S/MIME. This is what pack() actually returns: PHP's
        // openssl_pkcs7_sign() always writes S/MIME, whatever flags it is
        // given, so without this branch the sniffer failed to recognise the
        // very envelopes this class produces.
        if (self::isSmimePkcs7($bytes)) {
            return true;
        }
        // DER: harus mulai dengan SEQUENCE tag (0x30). Cek juga OID contentInfo
        // signedData (1.2.840.113549.1.7.2) di 20 byte pertama — heuristik.
        if ($bytes[0] === "\x30") {
            $head = substr($bytes, 0, 32);
            // OID signedData bytes: 06 09 2A 86 48 86 F7 0D 01 07 02
            $signedDataOid = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02";
            if (strpos($head, $signedDataOid) !== false) {
                return true;
            }
            // Bytes DER pertama tetap SEQUENCE tapi tanpa OID di head — mungkin PKCS7
            // hanya kalau caller tahu format-nya. Return true tetap konservatif:
            // kalau hanya SEQUENCE tanpa signedData OID, kemungkinan false positive.
            return false;
        }
        return false;
    }

    /**
     * Cek armor PEM PKCS7/CMS.
     */
    private static function isPemPkcs7(string $bytes): bool
    {
        return strpos($bytes, '-----BEGIN PKCS7-----') !== false
            || strpos($bytes, '-----BEGIN CMS-----') !== false;
    }

    /**
     * Nama encoding untuk metadata — bukan untuk percabangan logika.
     */
    private static function describeEncoding(string $bytes): string
    {
        if ($bytes === '') {
            return 'empty';
        }
        if (self::isPemPkcs7($bytes)) {
            return 'pem';
        }
        if (self::isSmimePkcs7($bytes)) {
            return 'smime';
        }
        return $bytes[0] === "\x30" ? 'der' : 'unknown';
    }

    /**
     * Ambil byte DER dari amplop apa pun bentuknya.
     *
     * DER dikembalikan apa adanya; PEM dilepas armornya; S/MIME diambil blok
     * base64-nya. Untuk S/MIME, blok dicari dari belakang — pada
     * `multipart/signed` bagian tanda tangan datang setelah isinya, dan sebuah
     * blok baru diterima kalau hasil dekodenya benar-benar dimulai dengan tag
     * SEQUENCE. Isi dokumen yang kebetulan berbentuk base64 karena itu tidak
     * salah dikira tanda tangan.
     *
     * @return string|null null kalau tidak ada CMS yang bisa dikenali
     */
    private static function toDer(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        if ($bytes[0] === "\x30") {
            return $bytes;
        }

        if (strpos($bytes, '-----BEGIN') !== false) {
            if (preg_match('/-----BEGIN [A-Z0-9 #]+-----(.+?)-----END/s', $bytes, $m) !== 1) {
                return null;
            }
            return self::decodeDerBlock($m[1]);
        }

        foreach (array_reverse(preg_split('/\r?\n\r?\n/', $bytes) ?: []) as $part) {
            $der = self::decodeDerBlock($part);
            if ($der !== null) {
                return $der;
            }
        }

        return null;
    }

    /**
     * Dekode satu blok base64, terima hanya kalau hasilnya diawali SEQUENCE.
     */
    private static function decodeDerBlock(string $block): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $block);
        if (!is_string($clean) || strlen($clean) < 64) {
            return null;
        }
        $der = base64_decode($clean, true);
        if ($der === false || $der === '' || $der[0] !== "\x30") {
            return null;
        }
        return $der;
    }

    /**
     * Bentuk amplop menjadi sesuatu yang bisa dibaca `openssl_pkcs7_*`.
     *
     * S/MIME diteruskan utuh — membongkar lalu menyusunnya kembali akan
     * membuang isi yang dibawa `multipart/signed`, dan tanda tangan detached
     * lama justru tergantung pada isi itu. Selain itu, DER dibungkus jadi p7m.
     *
     * @return string|null null kalau bukan CMS yang dikenali
     */
    private static function toSmime(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }
        if (self::isSmimePkcs7($bytes)) {
            return $bytes;
        }

        $der = self::toDer($bytes);
        return $der === null ? null : self::derToSmime($der);
    }

    /**
     * Bungkus DER jadi S/MIME p7m.
     *
     * Hanya base64 di sekeliling byte yang sama — tidak ada isi yang disentuh,
     * jadi kanonikalisasi CRLF yang merusak S/MIME sebagai format penyimpanan
     * tidak pernah terjadi di sini.
     */
    private static function derToSmime(string $der): string
    {
        return "MIME-Version: 1.0\r\n"
            . "Content-Disposition: attachment; filename=\"smime.p7m\"\r\n"
            . "Content-Type: application/x-pkcs7-mime; smime-type=signed-data; name=\"smime.p7m\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split(base64_encode($der), 64, "\r\n");
    }

    /**
     * Bungkus DER jadi armor PEM PKCS#7 — untuk caller yang butuh bentuk teks
     * (kolom DB teks, transport yang tidak binary-safe).
     *
     * @throws ValidationException kalau bytes-nya bukan CMS yang dikenali
     */
    public static function toPem(string $bytes): string
    {
        $der = self::toDer($bytes);
        if ($der === null) {
            throw ValidationException::forField(
                'bytes',
                'not a recognised CMS envelope (expected DER, PEM or S/MIME)'
            );
        }
        return "-----BEGIN PKCS7-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PKCS7-----\n";
    }

    /**
     * Byte DER kanonis dari amplop apa pun — bentuk yang diminta verifier lain
     * (`openssl cms -verify -inform DER`), PAdES, dan CAdES.
     *
     * @throws ValidationException kalau bytes-nya bukan CMS yang dikenali
     */
    public static function toDerBytes(string $bytes): string
    {
        $der = self::toDer($bytes);
        if ($der === null) {
            throw ValidationException::forField(
                'bytes',
                'not a recognised CMS envelope (expected DER, PEM or S/MIME)'
            );
        }
        return $der;
    }

    /**
     * Buat tempnam file — sys temp dir + prefix.
     *
     * @throws EzdocException kalau tempnam gagal
     */
    private static function mkTmp(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new EzdocException('PkcsSevenEnvelope: tempnam() failed');
        }
        return $path;
    }

    /**
     * Unlink diam-diam — dipakai di finally.
     */
    private static function safeUnlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Baca signer cert PEM + parse subject/issuer/serial.
     *
     * @return array<string,mixed>
     */
    private static function extractSignerInfo(string $signerCertPath): array
    {
        if (!is_file($signerCertPath)) {
            return [];
        }
        $pem = @file_get_contents($signerCertPath);
        if ($pem === false || $pem === '') {
            return [];
        }
        try {
            $cert = X509Certificate::fromPem($pem);
        } catch (ValidationException $e) {
            return ['error' => 'signer cert parse failed: ' . $e->getMessage()];
        }
        return [
            'signer_cert_pem' => $cert->getPem(),
            'subject_cn' => $cert->getSubjectCN(),
            'issuer_cn' => $cert->getIssuerCN(),
            'serial' => $cert->getSerialNumber(),
            'not_before' => $cert->getNotBefore(),
            'not_after' => $cert->getNotAfter(),
        ];
    }

    /**
     * Drain OpenSSL error queue jadi single "; "-joined string.
     */
    private static function drainOpenSslErrors(): string
    {
        $parts = [];
        while (($e = openssl_error_string()) !== false) {
            $parts[] = $e;
        }
        return implode('; ', $parts);
    }
}
