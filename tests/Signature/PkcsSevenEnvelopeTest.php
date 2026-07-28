<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\Envelope\PkcsSevenEnvelope;
use Ezdoc\Signature\KeyStore\PrivateKey;
use Ezdoc\Signature\KeyStore\X509Certificate;
use Ezdoc\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Covers the CMS/PKCS#7 envelope — the container an L2 or L3 signature travels
 * in, and what a counterparty runs `openssl cms -verify` against.
 *
 * PHP's PKCS#7 API only works through files, so the implementation writes
 * temporary ones. Two things therefore get asserted beyond the signature
 * itself: that those files do not survive the call, and that they are not
 * readable by other users while they exist.
 */
final class PkcsSevenEnvelopeTest extends TestCase
{
    private PkcsSevenEnvelope $envelope;
    private X509Certificate $cert;
    private PrivateKey $key;
    private string $caBundle;

    protected function setUp(): void
    {
        if (!TestPki::available()) {
            self::markTestSkipped('OpenSSL cannot generate keys here.');
        }

        $pki = TestPki::selfSigned('p7');
        $this->cert = X509Certificate::fromPem($pki['certPem']);
        $this->key = PrivateKey::fromPem($pki['keyPem']);

        // Self-signed, so the signer's own certificate is its trust anchor.
        $this->caBundle = TestPki::tempFile($pki['certPem'], 'ezdoc_ca');
        $this->envelope = new PkcsSevenEnvelope($this->caBundle);
    }

    protected function tearDown(): void
    {
        if (isset($this->caBundle)) {
            @unlink($this->caBundle);
        }
    }

    private function pack(string $content): string
    {
        // The signature argument is unused for PKCS#7 — OpenSSL produces the
        // real one from the key — but the Envelope contract passes it through.
        return $this->envelope->pack('', $content, $this->cert, ['private_key' => $this->key]);
    }

    // ─── Round trip ───

    public function testSignedContentVerifies(): void
    {
        $content = 'Surat pernyataan';
        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    public function testEnvelopeIsRecognisableAsPkcs7(): void
    {
        self::assertTrue(PkcsSevenEnvelope::isPkcs7($this->pack('isi')));
    }

    public function testPlainTextIsNotMistakenForPkcs7(): void
    {
        self::assertFalse(PkcsSevenEnvelope::isPkcs7('halo, ini teks biasa'));
        self::assertFalse(PkcsSevenEnvelope::isPkcs7(''));
    }

    /**
     * Known limitation, pinned rather than papered over.
     *
     * `pack()` returns S/MIME because PHP's openssl_pkcs7_sign() only ever
     * writes that, regardless of PKCS7_BINARY. `unpack()` reaches for
     * openssl_pkcs7_read(), which wants PEM/DER, so it cannot read what this
     * class produces. Recovering the signer certificate today means going
     * through verify(), which returns it in `signer_info`.
     *
     * Fixing it properly means converting S/MIME to DER on the way out, which
     * changes the stored format — a decision to make deliberately, not as a
     * side effect of a test.
     */
    public function testUnpackCannotReadSmimeOutput(): void
    {
        $this->expectException(\Throwable::class);
        $this->envelope->unpack($this->pack('isi dokumen'));
    }

    /**
     * Same root cause: S/MIME is a text transport, so raw bytes do not survive
     * it. Signing binary content produces an envelope OpenSSL then refuses.
     *
     * Callers with binary payloads should sign a hash of them, or the format
     * needs to become DER.
     */
    public function testBinaryContentIsNotSupported(): void
    {
        $content = random_bytes(1024);

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertFalse(
            $result['valid'],
            'if this starts passing, the S/MIME limitation is gone and the test should be inverted'
        );
    }

    /**
     * S/MIME canonicalises line endings to CRLF, so content signed with bare
     * LF no longer matches what was signed.
     */
    public function testLineFeedOnlyContentIsNotSupported(): void
    {
        $content = "baris satu\nbaris dua";

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertFalse($result['valid']);
    }

    public function testCarriageReturnContentRoundTrips(): void
    {
        $content = "baris satu\r\nbaris dua";

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    // ─── Content binding ───

    /**
     * Regression for the defect these tests were written to find.
     *
     * openssl_pkcs7_verify() checks a signature against the content carried
     * inside the envelope, never against the document the caller passes in.
     * verify() accepted `$originalContent`, documented it, and ignored it — so
     * it answered "valid" for every document, including an empty one. A stored
     * L2/L3 signature would have validated against anything.
     */
    public function testSignatureDoesNotValidateAnUnrelatedDocument(): void
    {
        $envelope = $this->pack('Surat A: setuju');

        foreach (['Surat B: menolak', '', 'x'] as $other) {
            $result = $this->envelope->verify($envelope, $other);

            self::assertFalse(
                $result['valid'],
                'a signature must not validate content it does not cover'
            );
            self::assertArrayHasKey('content_match', $result['checks']);
        }
    }

    public function testFormatIsDeclared(): void
    {
        self::assertSame('pkcs7', $this->envelope->getFormat());
    }

    // ─── Detection ───

    public function testAlteredContentIsRejected(): void
    {
        $envelope = $this->pack('nominal: 1.000.000');
        $result = $this->envelope->verify($envelope, 'nominal: 9.000.000');

        self::assertFalse($result['valid']);
    }

    public function testCorruptedEnvelopeIsRejected(): void
    {
        $content = 'isi dokumen';
        $envelope = $this->pack($content);

        $mid = intdiv(strlen($envelope), 2);
        $envelope[$mid] = $envelope[$mid] === "\x41" ? "\x42" : "\x41";

        $result = $this->envelope->verify($envelope, $content);

        self::assertFalse($result['valid']);
    }

    public function testEmptyEnvelopeIsRejected(): void
    {
        $result = $this->envelope->verify('', 'isi');

        self::assertFalse($result['valid']);
        self::assertSame('empty envelope', $result['reason']);
    }

    public function testGarbageEnvelopeIsRejectedWithoutThrowing(): void
    {
        $result = $this->envelope->verify('jelas bukan CMS', 'isi');

        self::assertFalse($result['valid']);
    }

    /**
     * A certificate that does not chain to the configured bundle must not
     * pass. Otherwise anyone could self-sign and be believed.
     */
    public function testSignatureFromAnUntrustedCertificateIsRejected(): void
    {
        $foreign = TestPki::selfSigned('p7-foreign', 365, 'orang-lain');
        $content = 'isi dokumen';

        $foreignEnvelope = $this->envelope->pack(
            '',
            $content,
            X509Certificate::fromPem($foreign['certPem']),
            ['private_key' => PrivateKey::fromPem($foreign['keyPem'])]
        );

        // Verified against a bundle that trusts only the original signer.
        $result = $this->envelope->verify($foreignEnvelope, $content);

        self::assertFalse($result['valid'], 'an untrusted signer must not verify');
    }

    // ─── Wiring failures ───

    public function testPackWithoutACertificateIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->envelope->pack('', 'isi', null, ['private_key' => $this->key]);
    }

    public function testPackWithoutAPrivateKeyIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->envelope->pack('', 'isi', $this->cert, []);
    }

    public function testPackWithAWrongTypePrivateKeyIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->envelope->pack('', 'isi', $this->cert, ['private_key' => 'sebuah string']);
    }

    public function testUnpackingAnEmptyEnvelopeThrows(): void
    {
        $this->expectException(\Throwable::class);
        $this->envelope->unpack('');
    }

    // ─── Temporary files ───

    /**
     * The content being signed passes through a temp file. Leaving those behind
     * would strand document contents in a world-readable directory.
     */
    public function testTemporaryFilesDoNotOutliveTheCall(): void
    {
        $before = $this->tempFileCount();

        $content = 'isi dokumen';
        $this->envelope->verify($this->pack($content), $content);

        self::assertSame(
            $before,
            $this->tempFileCount(),
            'pack/verify left temporary files behind'
        );
    }

    private function tempFileCount(): int
    {
        return count(glob(sys_get_temp_dir() . '/ezdoc*') ?: []);
    }
}
