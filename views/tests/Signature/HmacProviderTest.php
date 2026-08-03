<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\Providers\HmacProvider;
use Ezdoc\Signature\SignRequest;
use Ezdoc\Signature\Verdict;
use Ezdoc\Signature\VerifyContext;
use PHPUnit\Framework\TestCase;

/**
 * Covers level-1 signing — a shared-secret HMAC over the content hash.
 *
 * L1 proves nothing about identity, only that the bytes have not changed since
 * whoever held the secret signed them. These tests are about that one promise
 * holding: the same content verifies, anything else does not, and a secret too
 * weak to make the promise is refused outright.
 */
final class HmacProviderTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-at-least-32-bytes-long';

    private HmacProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new HmacProvider(self::SECRET);
    }

    private function request(string $content, string $signerId = 'user:1'): SignRequest
    {
        return new SignRequest([
            'contentBytes' => $content,
            'signerId'     => $signerId,
        ]);
    }

    private function context(string $content): VerifyContext
    {
        return new VerifyContext(['contentBytes' => $content]);
    }

    // ─── Round trip ───

    public function testSignedContentVerifies(): void
    {
        $content = 'Berita acara serah terima';

        $result = $this->provider->sign($this->request($content));
        $verdict = $this->provider->verify($result->getEnvelope(), $this->context($content));

        self::assertSame(Verdict::STATUS_VALID, $verdict->getStatus(), $verdict->getReason());
    }

    public function testSignResultDeclaresLevelOne(): void
    {
        $result = $this->provider->sign($this->request('isi', 'user:7'));

        self::assertSame(1, $result->getLevel());
        self::assertSame('user:7', $result->getSignerId());
        self::assertNotEmpty($result->getSignedAt());
    }

    public function testBinaryContentSurvivesTheRoundTrip(): void
    {
        $content = random_bytes(256);

        $result = $this->provider->sign($this->request($content));
        $verdict = $this->provider->verify($result->getEnvelope(), $this->context($content));

        self::assertSame(Verdict::STATUS_VALID, $verdict->getStatus(), $verdict->getReason());
    }

    // ─── Detection ───

    public function testAlteredContentIsRejected(): void
    {
        $result = $this->provider->sign($this->request('jumlah: 10'));

        $verdict = $this->provider->verify($result->getEnvelope(), $this->context('jumlah: 90'));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    /**
     * A different secret must not validate — otherwise the secret is not
     * doing any work.
     */
    public function testSignatureFromAnotherSecretIsRejected(): void
    {
        $content = 'isi dokumen';
        $foreign = (new HmacProvider('a-completely-different-secret-32b!!'))
            ->sign($this->request($content))
            ->getEnvelope();

        $verdict = $this->provider->verify($foreign, $this->context($content));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    public function testTamperedEnvelopeIsRejected(): void
    {
        $content = 'isi dokumen';
        $envelope = $this->provider->sign($this->request($content))->getEnvelope();

        $mid = intdiv(strlen($envelope), 2);
        $envelope[$mid] = $envelope[$mid] === 'a' ? 'b' : 'a';

        $verdict = $this->provider->verify($envelope, $this->context($content));

        self::assertNotSame(Verdict::STATUS_VALID, $verdict->getStatus());
    }

    public function testEmptyEnvelopeIsRejected(): void
    {
        self::assertNotSame(
            Verdict::STATUS_VALID,
            $this->provider->verify('', $this->context('isi'))->getStatus()
        );
    }

    public function testGarbageEnvelopeIsRejectedWithoutThrowing(): void
    {
        self::assertNotSame(
            Verdict::STATUS_VALID,
            $this->provider->verify('{"bukan":"envelope"}', $this->context('isi'))->getStatus()
        );
    }

    // ─── Secret strength ───

    /**
     * A short secret makes the signature guessable, so it is refused at
     * construction rather than producing a signature nobody should trust.
     */
    public function testShortSecretIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        new HmacProvider('terlalu-pendek');
    }

    public function testSecretAtTheBoundaryIsAccepted(): void
    {
        $exact = str_repeat('x', HmacProvider::MIN_SECRET_LEN);

        $provider = new HmacProvider($exact);
        $content = 'isi';

        self::assertSame(
            Verdict::STATUS_VALID,
            $provider->verify(
                $provider->sign($this->request($content))->getEnvelope(),
                $this->context($content)
            )->getStatus()
        );
    }

    public function testSecretOneByteShortIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        new HmacProvider(str_repeat('x', HmacProvider::MIN_SECRET_LEN - 1));
    }

    // ─── Secret handling ───

    /** The secret must never travel inside the envelope it protects. */
    public function testEnvelopeDoesNotContainTheSecret(): void
    {
        $envelope = $this->provider->sign($this->request('isi'))->getEnvelope();

        self::assertStringNotContainsString(self::SECRET, $envelope);
        self::assertStringNotContainsString(base64_encode(self::SECRET), $envelope);
    }

    // ─── Capabilities ───

    public function testCapabilitiesDeclareLevelOne(): void
    {
        $caps = $this->provider->capabilities();

        self::assertSame(1, $caps->getLevel());
        self::assertNotEmpty($caps->getProviderName());
    }
}
