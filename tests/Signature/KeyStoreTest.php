<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\KeyStore\EnvKeyStore;
use Ezdoc\Signature\KeyStore\FileKeyStore;
use Ezdoc\Signature\KeyStore\X509Certificate;
use Ezdoc\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Covers how signing material is loaded.
 *
 * A key store sits between an alias supplied by the application and a file or
 * environment variable, so the load path is also an injection surface: the
 * alias must never be able to name something outside the configured location.
 */
final class KeyStoreTest extends TestCase
{
    private string $dir;

    /** @var array<int, string> Env vars to clear afterwards. */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        if (!TestPki::available()) {
            self::markTestSkipped('OpenSSL cannot generate keys here.');
        }

        $this->dir = sys_get_temp_dir() . '/ezdoc_ks_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $name) {
            putenv($name);
        }
        $this->touchedEnv = [];

        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->touchedEnv[] = $name;
    }

    /**
     * @param array{keyPem: string, certPem: string} $pki
     */
    private function writeFiles(string $alias, array $pki): void
    {
        file_put_contents($this->dir . '/' . $alias . '.key', $pki['keyPem']);
        file_put_contents($this->dir . '/' . $alias . '.crt', $pki['certPem']);
    }

    // ─── FileKeyStore ───

    public function testFileStoreLoadsKeyAndCertificate(): void
    {
        $pki = TestPki::selfSigned('fks');
        $this->writeFiles('signer', $pki);

        $store = new FileKeyStore($this->dir);

        self::assertTrue($store->hasKey('signer'));
        self::assertNotNull($store->loadPrivateKey('signer'));
        self::assertSame(
            trim($pki['certPem']),
            trim($store->loadCertificate('signer')->getPem())
        );
    }

    public function testFileStoreReportsMissingAlias(): void
    {
        $store = new FileKeyStore($this->dir);

        self::assertFalse($store->hasKey('tidak_ada'));
        $this->expectException(NotFoundException::class);
        $store->loadPrivateKey('tidak_ada');
    }

    /**
     * The alias becomes part of a path, so `../` must not walk out of the key
     * directory and load something that happens to sit beside it.
     */
    public function testFileStoreRejectsTraversalInAlias(): void
    {
        $store = new FileKeyStore($this->dir);

        foreach (['../secret', '..\\secret', 'sub/dir', 'a/../../b'] as $alias) {
            $rejected = false;
            try {
                $store->loadPrivateKey($alias);
            } catch (\Throwable $e) {
                $rejected = true;
            }
            self::assertTrue($rejected, "alias '{$alias}' must be rejected");
        }
    }

    public function testFileStoreRejectsAliasWithNullByte(): void
    {
        $store = new FileKeyStore($this->dir);

        $this->expectException(\Throwable::class);
        $store->loadPrivateKey("signer\0.txt");
    }

    /** The chain file is optional; its absence is not an error. */
    public function testFileStoreTreatsChainAsOptional(): void
    {
        $this->writeFiles('signer', TestPki::selfSigned('fks'));

        $store = new FileKeyStore($this->dir);

        self::assertSame([], $store->loadChain('signer'));
    }

    public function testFileStoreSplitsAChainIntoCertificates(): void
    {
        $pki = TestPki::selfSigned('fks');
        $this->writeFiles('signer', $pki);

        $intermediate = TestPki::selfSigned('chain-int', 365, 'intermediate');
        file_put_contents(
            $this->dir . '/signer.chain',
            $intermediate['certPem'] . "\n" . $pki['certPem']
        );

        $chain = (new FileKeyStore($this->dir))->loadChain('signer');

        self::assertCount(2, $chain);
        self::assertInstanceOf(X509Certificate::class, $chain[0]);
    }

    public function testFileStoreRejectsMalformedKeyMaterial(): void
    {
        file_put_contents($this->dir . '/broken.key', 'ini jelas bukan PEM');
        file_put_contents($this->dir . '/broken.crt', 'ini juga bukan');

        $store = new FileKeyStore($this->dir);

        $this->expectException(\Throwable::class);
        $store->loadPrivateKey('broken');
    }

    // ─── EnvKeyStore ───

    public function testEnvStoreLoadsBase64Material(): void
    {
        $pki = TestPki::selfSigned('envks');
        $this->setEnv('EZDOC_KEY_SIGNER_PRIVATE', base64_encode($pki['keyPem']));
        $this->setEnv('EZDOC_KEY_SIGNER_CERT', base64_encode($pki['certPem']));

        $store = new EnvKeyStore();

        self::assertTrue($store->hasKey('signer'));
        self::assertNotNull($store->loadPrivateKey('signer'));
        self::assertSame(
            trim($pki['certPem']),
            trim($store->loadCertificate('signer')->getPem())
        );
    }

    /** Aliases are lowercase in code, env vars uppercase by convention. */
    public function testEnvStoreUppercasesTheAlias(): void
    {
        $pki = TestPki::selfSigned('envks');
        $this->setEnv('EZDOC_KEY_MY_SIGNER_PRIVATE', base64_encode($pki['keyPem']));
        $this->setEnv('EZDOC_KEY_MY_SIGNER_CERT', base64_encode($pki['certPem']));

        // Dashes become underscores, since shells reject dashes in var names.
        self::assertTrue((new EnvKeyStore())->hasKey('my-signer'));
    }

    public function testEnvStoreReportsMissingVariable(): void
    {
        $store = new EnvKeyStore();

        self::assertFalse($store->hasKey('belum_diset'));
        $this->expectException(NotFoundException::class);
        $store->loadPrivateKey('belum_diset');
    }

    /** Material that is not valid base64 must be refused, not half-decoded. */
    public function testEnvStoreRejectsNonBase64(): void
    {
        $this->setEnv('EZDOC_KEY_BAD_PRIVATE', 'bukan base64 !!!');

        $this->expectException(ValidationException::class);
        (new EnvKeyStore())->loadPrivateKey('bad');
    }

    public function testEnvStoreRejectsInvalidAlias(): void
    {
        $this->expectException(ValidationException::class);
        (new EnvKeyStore())->loadPrivateKey('bukan alias!');
    }

    public function testEnvStoreHonoursACustomPrefix(): void
    {
        $pki = TestPki::selfSigned('envks');
        $this->setEnv('RSIA_SIGNER_PRIVATE', base64_encode($pki['keyPem']));
        $this->setEnv('RSIA_SIGNER_CERT', base64_encode($pki['certPem']));

        self::assertTrue((new EnvKeyStore('RSIA_'))->hasKey('signer'));
    }

    // ─── X509Certificate ───

    public function testCertificateExposesItsIdentity(): void
    {
        $pki = TestPki::selfSigned('x509', 365, 'dr-budi');
        $cert = X509Certificate::fromPem($pki['certPem']);

        self::assertSame('dr-budi', $cert->getSubjectCN());
        self::assertNotEmpty($cert->getSerialNumber());
        self::assertFalse($cert->isExpired());
    }

    public function testCertificateReportsItsValidityWindow(): void
    {
        $cert = X509Certificate::fromPem(TestPki::selfSigned('x509')['certPem']);

        self::assertLessThan($cert->getNotAfter(), $cert->getNotBefore());
        self::assertTrue($cert->isValidAt(time()));
        self::assertFalse($cert->isValidAt($cert->getNotAfter() + 3600));
        self::assertFalse($cert->isValidAt($cert->getNotBefore() - 3600));
    }

    public function testExpiredCertificateSaysSo(): void
    {
        $cert = X509Certificate::fromPem(TestPki::selfSigned('x509-exp', -1, 'lama')['certPem']);

        self::assertTrue($cert->isExpired());
        self::assertFalse($cert->isValidAt(time()));
    }

    public function testMalformedCertificateIsRejected(): void
    {
        $this->expectException(\Throwable::class);
        X509Certificate::fromPem('-----BEGIN CERTIFICATE-----\nbukan sertifikat\n-----END CERTIFICATE-----');
    }

    public function testEmptyCertificateIsRejected(): void
    {
        $this->expectException(\Throwable::class);
        X509Certificate::fromPem('');
    }
}
