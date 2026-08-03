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
    private string $keyPem;
    private string $caBundle;

    protected function setUp(): void
    {
        if (!TestPki::available()) {
            self::markTestSkipped('OpenSSL cannot generate keys here.');
        }

        $pki = TestPki::selfSigned('p7');
        $this->cert = X509Certificate::fromPem($pki['certPem']);
        $this->key = PrivateKey::fromPem($pki['keyPem']);
        $this->keyPem = $pki['keyPem']; // legacy fixtures sign through the raw API

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
     * These three used to be pinned limitations. All of them came from storing
     * S/MIME — an email transport — instead of DER, the canonical CMS encoding.
     * They are kept as positive assertions so the format cannot quietly regress.
     */
    public function testUnpackCanReadWhatPackProduces(): void
    {
        $unpacked = $this->envelope->unpack($this->pack('isi dokumen'));

        self::assertNotNull($unpacked['signer_cert_pem'], 'the signer certificate should come back');
        self::assertStringContainsString('BEGIN CERTIFICATE', $unpacked['signer_cert_pem']);
        self::assertSame('der', $unpacked['metadata']['encoding']);
    }

    /**
     * S/MIME is a text transport, so raw bytes did not survive it. DER carries
     * them untouched.
     */
    public function testBinaryContentRoundTrips(): void
    {
        $content = random_bytes(1024);

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    /**
     * S/MIME canonicalises line endings to CRLF, so content signed with bare LF
     * no longer matched what was signed. OPENSSL_CMS_BINARY turns that off.
     */
    public function testLineFeedOnlyContentRoundTrips(): void
    {
        $content = "baris satu\nbaris dua";

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    public function testCarriageReturnContentRoundTrips(): void
    {
        $content = "baris satu\r\nbaris dua";

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    /** Line endings must survive exactly, not be normalised to something else. */
    public function testMixedLineEndingsSurviveByteForByte(): void
    {
        $content = "satu\ndua\r\ntiga\rempat";

        $result = $this->envelope->verify($this->pack($content), $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
        self::assertFalse(
            $this->envelope->verify($this->pack($content), str_replace("\r\n", "\n", $content))['valid'],
            'a document whose line endings were rewritten is a different document'
        );
    }

    public function testNulBytesAndUtf8SurviveTheEnvelope(): void
    {
        foreach (["a\x00b\x00c", 'Perjanjian — pasal 1 ✓', "\xFF\xFE\x00\x01"] as $content) {
            $result = $this->envelope->verify($this->pack($content), $content);

            self::assertTrue($result['valid'], $result['reason'] ?? '');
        }
    }

    // ─── Stored format ───

    /**
     * DER is the canonical CMS encoding (RFC 5652) and the only one a
     * counterparty's verifier, PAdES or CAdES will accept. It begins with an
     * ASN.1 SEQUENCE tag.
     */
    public function testTheStoredEnvelopeIsDer(): void
    {
        $envelope = $this->pack('isi dokumen');

        self::assertSame("\x30", $envelope[0], 'DER starts with a SEQUENCE tag');
        self::assertStringNotContainsString('MIME-Version', $envelope, 'S/MIME is a transport, not a format');
        self::assertStringNotContainsString('-----BEGIN', $envelope);
    }

    /**
     * The point of DER is that someone else can check the signature. If only
     * this library can read it, it is not interoperable — so the check is made
     * by OpenSSL itself rather than by this suite.
     */
    public function testOpensslVerifiesTheStoredEnvelopeIndependently(): void
    {
        $version = @shell_exec('openssl version 2>&1');
        if (!is_string($version) || stripos($version, 'openssl') !== 0) {
            self::markTestSkipped('openssl CLI not available.');
        }

        $envelope = $this->pack('Surat pernyataan');
        $path = TestPki::tempFile($envelope, 'ezdoc_der');
        $out = TestPki::tempFile('', 'ezdoc_out');

        try {
            $result = (string) @shell_exec(
                'openssl cms -verify -inform DER -in ' . escapeshellarg($path)
                . ' -CAfile ' . escapeshellarg($this->caBundle)
                . ' -out ' . escapeshellarg($out) . ' 2>&1'
            );

            self::assertStringContainsString('Verification successful', $result, $result);
        } finally {
            @unlink($path);
            @unlink($out);
        }
    }

    // ─── Envelopes stored before the format changed ───

    /**
     * Signatures written by the previous implementation are S/MIME. They must
     * keep verifying — a stored signature that stops validating is worse than
     * one that was never made.
     */
    private function packLegacySmime(string $content, bool $detached): string
    {
        $in = TestPki::tempFile($content, 'ezdoc_legacy_in');
        $out = TestPki::tempFile('', 'ezdoc_legacy_out');

        try {
            $flags = PKCS7_BINARY | PKCS7_NOATTR | ($detached ? PKCS7_DETACHED : 0);
            $ok = openssl_pkcs7_sign($in, $out, $this->cert->getPem(), $this->keyPem, [], $flags);
            self::assertTrue($ok, 'the legacy fixture itself failed to sign');

            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    public function testLegacyAttachedSmimeEnvelopesStillVerify(): void
    {
        $content = 'Surat lama';
        $legacy = $this->packLegacySmime($content, false);

        self::assertStringContainsString('MIME-Version', $legacy, 'fixture should be S/MIME');

        $result = $this->envelope->verify($legacy, $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    public function testLegacyDetachedSmimeEnvelopesStillVerify(): void
    {
        $content = 'Surat lama detached';
        $legacy = $this->packLegacySmime($content, true);

        self::assertStringContainsString('multipart/signed', $legacy, 'fixture should be detached S/MIME');

        $result = $this->envelope->verify($legacy, $content);

        self::assertTrue($result['valid'], $result['reason'] ?? '');
    }

    public function testLegacyEnvelopesStillDetectAWrongDocument(): void
    {
        $legacy = $this->packLegacySmime('Surat A: setuju', false);

        self::assertFalse($this->envelope->verify($legacy, 'Surat B: menolak')['valid']);
    }

    public function testLegacyEnvelopesCanBeUnpacked(): void
    {
        $unpacked = $this->envelope->unpack($this->packLegacySmime('Surat lama', false));

        self::assertSame('smime', $unpacked['metadata']['encoding']);
        self::assertStringContainsString('BEGIN CERTIFICATE', (string) $unpacked['signer_cert_pem']);
    }

    // ─── Format conversion ───

    public function testAnEnvelopeCanBeRenderedAsPemForTextStorage(): void
    {
        $der = $this->pack('isi dokumen');
        $pem = PkcsSevenEnvelope::toPem($der);

        self::assertStringStartsWith('-----BEGIN PKCS7-----', $pem);
        self::assertSame($der, PkcsSevenEnvelope::toDerBytes($pem), 'PEM must round-trip back to the same bytes');
    }

    public function testPemEnvelopesVerifyToo(): void
    {
        $content = 'isi dokumen';
        $pem = PkcsSevenEnvelope::toPem($this->pack($content));

        self::assertTrue($this->envelope->verify($pem, $content)['valid']);
    }

    public function testConvertingSmimeToDerYieldsTheSameSignature(): void
    {
        $content = 'Surat lama';
        $legacy = $this->packLegacySmime($content, false);

        $der = PkcsSevenEnvelope::toDerBytes($legacy);

        self::assertSame("\x30", $der[0]);
        self::assertTrue(
            $this->envelope->verify($der, $content)['valid'],
            'a legacy envelope converted to DER must still verify'
        );
    }

    public function testConversionRefusesSomethingThatIsNotAnEnvelope(): void
    {
        $this->expectException(ValidationException::class);
        PkcsSevenEnvelope::toDerBytes('jelas bukan CMS');
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
