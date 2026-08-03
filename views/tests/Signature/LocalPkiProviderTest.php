<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Signature\KeyStore\FileKeyStore;
use Ezdoc\Signature\Providers\LocalPkiProvider;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\Verdict;
use Ezdoc\Signature\VerifyContext;
use Ezdoc\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Covers level-2 signing end to end against real OpenSSL.
 *
 * The point of a signature is that it fails when it should. A test that only
 * checks the happy path would pass just as well against a provider that
 * returned `valid` unconditionally — so most of these change something and
 * assert the verdict turns.
 *
 * Keys and certificates are generated per run rather than committed, so the
 * same code paths production uses get exercised and nothing expires in the
 * repository.
 */
final class LocalPkiProviderTest extends TestCase
{
    private string $dir;
    private LocalPkiProvider $provider;

    protected function setUp(): void
    {
        if (!TestPki::available()) {
            self::markTestSkipped('OpenSSL cannot generate keys here (check OPENSSL_CONF).');
        }

        $this->dir = sys_get_temp_dir() . '/ezdoc_pki_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $this->writeIdentity('signer', TestPki::selfSigned('signer'));
        $this->provider = new LocalPkiProvider(new FileKeyStore($this->dir), 'signer');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    /**
     * @param array{keyPem: string, certPem: string} $pki
     */
    private function writeIdentity(string $alias, array $pki): void
    {
        file_put_contents($this->dir . '/' . $alias . '.key', $pki['keyPem']);
        file_put_contents($this->dir . '/' . $alias . '.crt', $pki['certPem']);
    }

    private function request(string $content, string $signerId = 'user:42'): SignRequest
    {
        return new SignRequest([
            'contentBytes' => $content,
            'signerId'     => $signerId,
        ]);
    }

    private function context(string $content, ?string $expectedSigner = null): VerifyContext
    {
        $data = ['contentBytes' => $content];
        if ($expectedSigner !== null) {
            $data['expectedSignerId'] = $expectedSigner;
        }
        return new VerifyContext($data);
    }

    // ─── Round trip ───

    public function testSignedContentVerifies(): void
    {
        $content = 'Surat keterangan sehat — pasien A';

        $result = $this->provider->sign($this->request($content));
        $verdict = $this->provider->verify($result->getEnvelope(), $this->context($content));

        self::assertSame(Verdict::STATUS_VALID, $verdict->getStatus(), $verdict->getReason());
    }

    public function testSignResultCarriesTheSignerAndLevel(): void
    {
        $result = $this->provider->sign($this->request('isi', 'user:99'));

        self::assertSame('user:99', $result->getSignerId());
        self::assertSame(2, $result->getLevel(), 'local PKI is level 2');
        self::assertNotEmpty($result->getEnvelope());
        self::assertNotEmpty($result->getCertificatePem());
    }

    public function testSignatureIsNotDeterministicallyEmpty(): void
    {
        $a = $this->provider->sign($this->request('isi'));
        $b = $this->provider->sign($this->request('isi lain'));

        self::assertNotSame(
            $a->getEnvelope(),
            $b->getEnvelope(),
            'different content must produce a different signature'
        );
    }

    public function testBinaryContentSurvivesTheRoundTrip(): void
    {
        $content = random_bytes(512);

        $result = $this->provider->sign($this->request($content));
        $verdict = $this->provider->verify($result->getEnvelope(), $this->context($content));

        self::assertSame(Verdict::STATUS_VALID, $verdict->getStatus(), $verdict->getReason());
    }

    // ─── Detection ───

    /** The whole reason the feature exists. */
    public function testAlteredContentIsRejected(): void
    {
        $result = $this->provider->sign($this->request('nominal: 1.000.000'));

        $verdict = $this->provider->verify(
            $result->getEnvelope(),
            $this->context('nominal: 9.000.000')
        );

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    /** Even a one-byte edit must not pass. */
    public function testSingleByteChangeIsRejected(): void
    {
        $content = 'Persetujuan tindakan medis';
        $result = $this->provider->sign($this->request($content));

        $verdict = $this->provider->verify(
            $result->getEnvelope(),
            $this->context($content . ' ')
        );

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    public function testCorruptedSignatureIsRejected(): void
    {
        $content = 'isi dokumen';
        $envelope = $this->provider->sign($this->request($content))->getEnvelope();

        // Flip a byte in the middle of the signature itself.
        $mid = intdiv(strlen($envelope), 2);
        $envelope[$mid] = $envelope[$mid] === 'A' ? 'B' : 'A';

        $verdict = $this->provider->verify($envelope, $this->context($content));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    public function testEmptyEnvelopeIsRejected(): void
    {
        $verdict = $this->provider->verify('', $this->context('isi'));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    public function testGarbageEnvelopeIsRejectedWithoutThrowing(): void
    {
        $verdict = $this->provider->verify('bukan tanda tangan sama sekali', $this->context('isi'));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    /**
     * A signature made with one identity must not verify as another. Without
     * this, holding any key would be enough to sign as anyone.
     */
    public function testSignatureFromAnotherKeyIsRejected(): void
    {
        $content = 'isi dokumen';

        $this->writeIdentity('other', TestPki::selfSigned('other', 365, 'someone-else'));
        $otherProvider = new LocalPkiProvider(new FileKeyStore($this->dir), 'other');

        $foreign = $otherProvider->sign($this->request($content))->getEnvelope();

        // Verified by the first identity, which did not produce it.
        $verdict = $this->provider->verify($foreign, $this->context($content));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    // ─── Certificate validity ───

    /**
     * An expired certificate must be reported as such rather than silently
     * accepted — the signature maths still checks out, so only an explicit
     * date check catches it.
     */
    public function testExpiredCertificateIsNotReportedValid(): void
    {
        $this->writeIdentity('expired', TestPki::selfSigned('expired', -1, 'expired-signer'));
        $provider = new LocalPkiProvider(new FileKeyStore($this->dir), 'expired');

        $content = 'isi dokumen';
        $result = $provider->sign($this->request($content));
        $verdict = $provider->verify($result->getEnvelope(), $this->context($content));

        self::assertNotSame(
            Verdict::STATUS_VALID,
            $verdict->getStatus(),
            'a signature under an expired certificate must not read as valid'
        );
    }

    // ─── Wiring failures ───

    public function testUnknownAliasFailsLoudly(): void
    {
        $provider = new LocalPkiProvider(new FileKeyStore($this->dir), 'tidak_ada');

        $this->expectException(\Throwable::class);
        $provider->sign($this->request('isi'));
    }

    /** A directory holding a cert but no key cannot sign. */
    public function testMissingPrivateKeyFailsLoudly(): void
    {
        $pki = TestPki::selfSigned('certonly', 365, 'cert-only');
        file_put_contents($this->dir . '/certonly.crt', $pki['certPem']);

        $provider = new LocalPkiProvider(new FileKeyStore($this->dir), 'certonly');

        $this->expectException(\Throwable::class);
        $provider->sign($this->request('isi'));
    }

    /**
     * Alias goes into a filesystem path, so traversal must not reach outside
     * the configured directory.
     */
    public function testAliasCannotEscapeTheKeyDirectory(): void
    {
        $provider = new LocalPkiProvider(new FileKeyStore($this->dir), '../../etc/passwd');

        $this->expectException(\Throwable::class);
        $provider->sign($this->request('isi'));
    }

    // ─── Capabilities ───

    public function testCapabilitiesDeclareLevelTwo(): void
    {
        $caps = $this->provider->capabilities();

        self::assertSame(2, $caps->getLevel());
        self::assertNotEmpty($caps->getProviderName());
    }
}
