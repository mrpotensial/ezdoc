<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Providers;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\KeyStore;
use Ezdoc\Signature\KeyStore\X509Certificate;
use Ezdoc\Signature\ProviderCapabilities;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\SignResult;
use Ezdoc\Signature\SignatureProvider;
use Ezdoc\Signature\Verdict;
use Ezdoc\Signature\VerifyContext;

/**
 * Ezdoc\Signature\Providers\LocalPkiProvider — level-2 signer memakai
 * private key + X.509 cert dari KeyStore.
 *
 * ## Sign
 *
 * `openssl_sign(contentBytes, sig, key, OPENSSL_ALGO_SHA256)` menghasilkan
 * RAW BINARY signature (bukan base64, bukan hex). Envelope disimpan apa
 * adanya dengan `envelopeFormat = 'raw'`. Consumer yang butuh transport
 * JSON boleh base64-encode di layer atas — `SignResult::toArray()` sudah
 * otomatis melakukannya.
 *
 * `certificatePem` diisi dari `X509Certificate::getPem()` supaya verifier
 * bisa extract public key + subject CN tanpa perlu akses KeyStore.
 *
 * ## Verify
 *
 * Cert diperoleh dari (prioritas):
 *   1. `$ctx->getMetadata()['certificate_pem']` — kalau consumer bawa
 *      cert dari envelope storage (persist-then-verify use case).
 *   2. `KeyStore::loadCertificate($alias)` — fallback ke cert alias
 *      sekarang. Perhatikan: kalau cert alias di-rotate setelah sign,
 *      verify akan pakai cert baru dan gagal untuk signature lama.
 *      Untuk itulah metadata['certificate_pem'] disediakan.
 *
 * `openssl_verify()` return 1 valid, 0 invalid, -1 error — masing-masing
 * dipetakan ke Verdict.
 *
 * ## Level 2
 *
 * Non-repudiation dengan cert; tidak termasuk chain validation atau TSA
 * timestamping (level 3). Ekstensi tsb ditambahkan di provider terpisah.
 *
 * PHP 7.4+ compatible.
 */
final class LocalPkiProvider implements SignatureProvider
{
    /** @var string */
    const PROVIDER_NAME = 'local_pki';

    /** @var array<string,int> hash algo → OPENSSL_ALGO_* constant */
    private static $ALGO_MAP = [
        'sha256' => OPENSSL_ALGO_SHA256,
        'sha384' => OPENSSL_ALGO_SHA384,
        'sha512' => OPENSSL_ALGO_SHA512,
    ];

    /** @var KeyStore */
    private $keyStore;

    /** @var string */
    private $alias;

    /** @var string */
    private $algo;

    /** @var int OPENSSL_ALGO_* */
    private $algoConst;

    /**
     * @param KeyStore $keyStore
     * @param string   $alias    kunci di keystore ('default', 'billing', dst)
     * @param string   $algo     hash algo — 'sha256' | 'sha384' | 'sha512'
     * @throws ValidationException
     */
    public function __construct(KeyStore $keyStore, string $alias, string $algo = 'sha256')
    {
        if ($alias === '') {
            throw ValidationException::forField('alias', 'must be non-empty');
        }
        $normAlgo = strtolower($algo);
        if (!isset(self::$ALGO_MAP[$normAlgo])) {
            throw ValidationException::forField(
                'algo',
                'unsupported hash algo: ' . $algo . ' (allowed: sha256, sha384, sha512)'
            );
        }
        $this->keyStore = $keyStore;
        $this->alias = $alias;
        $this->algo = $normAlgo;
        $this->algoConst = self::$ALGO_MAP[$normAlgo];
    }

    /**
     * {@inheritdoc}
     *
     * @throws EzdocException technical failure (openssl_sign gagal, keystore error)
     */
    public function sign(SignRequest $req): SignResult
    {
        $privKey = $this->keyStore->loadPrivateKey($this->alias);
        $cert = $this->keyStore->loadCertificate($this->alias);

        // Drain OpenSSL error queue supaya sisa error dari call sebelumnya
        // tidak nyampur ke diagnostic.
        while (openssl_error_string() !== false) { /* noop */ }

        $signature = '';
        $ok = openssl_sign(
            $req->getContentBytes(),
            $signature,
            $privKey->getResource(),
            $this->algoConst
        );
        if ($ok !== true) {
            $errs = self::drainErrors();
            throw new EzdocException(
                'LocalPkiProvider::sign() openssl_sign failed: '
                . ($errs !== '' ? $errs : 'unknown'),
                ['alias' => $this->alias, 'algo' => $this->algo]
            );
        }
        if ($signature === '') {
            throw new EzdocException(
                'LocalPkiProvider::sign() produced empty signature',
                ['alias' => $this->alias]
            );
        }

        return new SignResult([
            'envelope' => $signature,
            'envelopeFormat' => 'raw',
            'signerId' => $req->getSignerId(),
            'certificatePem' => $cert->getPem(),
            'providerName' => self::PROVIDER_NAME,
            'level' => 2,
            'signedAt' => self::nowIso8601Ms(),
            'metadata' => array_merge(
                $req->getMetadata(),
                [
                    'algo' => $this->algo,
                    'key_alias' => $this->alias,
                    'subject_cn' => $cert->getSubjectCN(),
                    'issuer_cn' => $cert->getIssuerCN(),
                    'serial' => $cert->getSerialNumber(),
                    'content_hash' => $req->getContentHash(),
                ]
            ),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(string $envelope, VerifyContext $ctx): Verdict
    {
        if ($envelope === '') {
            return Verdict::error('empty envelope');
        }

        // Ekstraksi cert: metadata > keystore.
        $cert = $this->resolveCertificate($ctx);
        if ($cert === null) {
            return Verdict::error('no certificate available for verification');
        }

        // Drain OpenSSL error queue.
        while (openssl_error_string() !== false) { /* noop */ }

        $result = openssl_verify(
            $ctx->getContentBytes(),
            $envelope,
            $cert->getResource(),
            $this->algoConst
        );

        if ($result === -1) {
            $errs = self::drainErrors();
            return Verdict::error(
                'OpenSSL error: ' . ($errs !== '' ? $errs : 'unknown'),
                ['algo' => $this->algo]
            );
        }
        if ($result === 0) {
            return Verdict::tampered('Signature does not verify', [
                'algo' => $this->algo,
            ]);
        }
        // result === 1 : signature secara kriptografis cocok.

        $now = time();
        if (!$cert->isValidAt($now)) {
            // Bedakan expired vs not-yet-valid via reason string.
            $reason = ($now < $cert->getNotBefore())
                ? 'Certificate not yet valid'
                : 'Certificate expired';
            return new Verdict(
                Verdict::STATUS_EXPIRED,
                $cert->getSubjectCN(),
                null,
                $reason,
                [
                    'algo' => $this->algo,
                    'not_before' => $cert->getNotBefore(),
                    'not_after' => $cert->getNotAfter(),
                    'now' => $now,
                ]
            );
        }

        return Verdict::valid(
            $cert->getSubjectCN(),
            self::nowIso8601Ms(),
            [
                'algo' => $this->algo,
                'subject_cn' => $cert->getSubjectCN(),
                'issuer_cn' => $cert->getIssuerCN(),
                'serial' => $cert->getSerialNumber(),
                'not_before' => $cert->getNotBefore(),
                'not_after' => $cert->getNotAfter(),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            self::PROVIDER_NAME,
            2,
            ['raw', 'pkcs7'],
            false,
            null,
            'Level 2 detached RSA/EC signature via OpenSSL '
            . strtoupper($this->algo)
            . '. Cert dari KeyStore alias "' . $this->alias
            . '". No chain validation atau TSA timestamp (yet).'
        );
    }

    /**
     * Cari cert untuk verify: metadata > keystore alias.
     */
    private function resolveCertificate(VerifyContext $ctx): ?X509Certificate
    {
        $meta = $ctx->getMetadata();
        if (isset($meta['certificate_pem']) && is_string($meta['certificate_pem']) && $meta['certificate_pem'] !== '') {
            try {
                return X509Certificate::fromPem($meta['certificate_pem']);
            } catch (ValidationException $e) {
                // Cert di metadata corrupt — fall through ke keystore fallback.
            }
        }
        try {
            return $this->keyStore->loadCertificate($this->alias);
        } catch (NotFoundException $e) {
            return null;
        } catch (ValidationException $e) {
            return null;
        }
    }

    /**
     * Drain OpenSSL error queue jadi single "; "-joined string.
     */
    private static function drainErrors(): string
    {
        $parts = [];
        while (($e = openssl_error_string()) !== false) {
            $parts[] = $e;
        }
        return implode('; ', $parts);
    }

    /**
     * ISO 8601 UTC dengan millisecond presisi via DateTime->format('c') +
     * manual ms injection (format 'c' tidak include fractional seconds).
     */
    private static function nowIso8601Ms(): string
    {
        // Ambil microtime string "0.123456 1720579200" — presisi microsecond.
        $mt = microtime(true);
        $sec = (int) floor($mt);
        $ms = (int) round(($mt - $sec) * 1000);
        if ($ms >= 1000) {
            $sec += 1;
            $ms = 0;
        }
        // DateTime dgn UTC — pastikan output selalu Z bukan offset lokal.
        $dt = new \DateTime('@' . $sec);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s')
            . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT)
            . 'Z';
    }
}
