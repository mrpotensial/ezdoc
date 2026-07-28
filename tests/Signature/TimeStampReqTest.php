<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Signature\Timestamp\HttpTimestampClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the hand-written DER encoder that builds an RFC 3161 TimeStampReq.
 *
 * Emitting ASN.1 by hand is unusual and worth guarding: a timestamp authority
 * rejects a malformed request outright, and the failure surfaces as an opaque
 * HTTP error far from its cause.
 *
 * Correctness is judged by OpenSSL rather than by this suite re-deriving the
 * same bytes. Asserting against a hardcoded expectation would only prove the
 * encoder still does what it did before — including anything it got wrong.
 * `openssl asn1parse` is an independent implementation of the same standard.
 *
 * Length encoding gets its own tests. The short/long-form boundary at 128 is
 * where hand-rolled encoders classically break, and nothing in the happy path
 * reaches it: a SHA-256 request is 69 bytes.
 */
final class TimeStampReqTest extends TestCase
{
    private static ?bool $opensslCli = null;

    /**
     * @param mixed ...$args
     * @return mixed
     */
    private static function callPrivate(string $method, ...$args)
    {
        $m = new ReflectionMethod(HttpTimestampClient::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    private static function hasOpensslCli(): bool
    {
        if (self::$opensslCli !== null) {
            return self::$opensslCli;
        }
        $out = @shell_exec('openssl version 2>&1');
        return self::$opensslCli = (is_string($out) && stripos($out, 'openssl') === 0);
    }

    /**
     * Run DER through `openssl asn1parse` and return its rendering.
     */
    private function asn1Parse(string $der): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ezdoc_tsq');
        file_put_contents($path, $der);
        try {
            return (string) @shell_exec('openssl asn1parse -inform DER -in ' . escapeshellarg($path) . ' 2>&1');
        } finally {
            @unlink($path);
        }
    }

    private function buildRequest(string $algo = 'sha256', string $content = 'dokumen'): string
    {
        return self::callPrivate('buildTimeStampReq', $algo, hash($algo, $content, true));
    }

    // ─── Length encoding ───

    /**
     * DER writes lengths below 128 in a single byte, and anything larger as a
     * count-prefixed big-endian run. Getting the boundary wrong produces bytes
     * that look plausible and parse as something else entirely.
     */
    public function testShortFormLengthsAreASingleByte(): void
    {
        foreach ([0 => '00', 1 => '01', 127 => '7f'] as $len => $expected) {
            self::assertSame(
                $expected,
                bin2hex(self::callPrivate('derLen', $len)),
                "length {$len} should use the short form"
            );
        }
    }

    public function testLongFormLengthsCarryAByteCount(): void
    {
        // 0x81 = "one length byte follows", 0x82 = two, and so on.
        foreach ([
            128   => '8180',
            255   => '81ff',
            256   => '820100',
            65535 => '82ffff',
            65536 => '83010000',
        ] as $len => $expected) {
            self::assertSame(
                $expected,
                bin2hex(self::callPrivate('derLen', $len)),
                "length {$len} should use the long form"
            );
        }
    }

    /** 127 and 128 sit either side of the boundary. */
    public function testTheShortToLongFormBoundaryIsExact(): void
    {
        self::assertSame(1, strlen(self::callPrivate('derLen', 127)));
        self::assertSame(2, strlen(self::callPrivate('derLen', 128)));
    }

    // ─── INTEGER encoding ───

    /**
     * DER integers are signed, so a leading byte with the high bit set has to
     * be padded — otherwise a nonce reads back as negative and the authority
     * rejects the request.
     */
    public function testHighBitIntegersArePaddedToStayPositive(): void
    {
        // tag 02, length 03, content 00 FF 01 — the pad grows the length too.
        $encoded = self::callPrivate('derIntFromBytes', "\xFF\x01");

        self::assertSame('020300ff01', bin2hex($encoded), 'a 0x00 pad should precede 0xFF');
    }

    public function testRedundantLeadingZeroesAreStripped(): void
    {
        // 0x00 0x7F needs no padding: 0x7F is already positive.
        self::assertSame('02017f', bin2hex(self::callPrivate('derIntFromBytes', "\x00\x7F")));
    }

    public function testSmallIntegersEncodeMinimally(): void
    {
        self::assertSame('020100', bin2hex(self::callPrivate('derIntFromInt', 0)));
        self::assertSame('020101', bin2hex(self::callPrivate('derIntFromInt', 1)));
        self::assertSame('02017f', bin2hex(self::callPrivate('derIntFromInt', 127)));
        // 128 has its high bit set, so it gains a pad byte.
        self::assertSame('02020080', bin2hex(self::callPrivate('derIntFromInt', 128)));
    }

    // ─── Request shape ───

    public function testRequestStartsWithASequence(): void
    {
        $der = $this->buildRequest();

        self::assertSame("\x30", $der[0], 'a TimeStampReq is a SEQUENCE');
        self::assertGreaterThan(40, strlen($der));
    }

    /** The nonce exists so a replayed response can be spotted. */
    public function testEachRequestCarriesAFreshNonce(): void
    {
        self::assertNotSame(
            $this->buildRequest(),
            $this->buildRequest(),
            'two requests for the same document must differ by their nonce'
        );
    }

    public function testDifferentDocumentsProduceDifferentImprints(): void
    {
        $a = $this->buildRequest('sha256', 'dokumen A');
        $b = $this->buildRequest('sha256', 'dokumen B');

        self::assertNotSame($a, $b);
    }

    // ─── Verified against OpenSSL ───

    public function testOpensslParsesTheRequest(): void
    {
        if (!self::hasOpensslCli()) {
            self::markTestSkipped('openssl CLI not available.');
        }

        $parsed = $this->asn1Parse($this->buildRequest());

        self::assertStringNotContainsString('Error', $parsed, $parsed);
        self::assertStringContainsString('SEQUENCE', $parsed);
    }

    /**
     * RFC 3161 §2.4.1:
     *   TimeStampReq ::= SEQUENCE {
     *     version        INTEGER { v1(1) },
     *     messageImprint MessageImprint,
     *     nonce          INTEGER  OPTIONAL,
     *     certReq        BOOLEAN  DEFAULT FALSE }
     */
    public function testRequestMatchesTheRfcStructure(): void
    {
        if (!self::hasOpensslCli()) {
            self::markTestSkipped('openssl CLI not available.');
        }

        $parsed = $this->asn1Parse($this->buildRequest());

        self::assertStringContainsString('INTEGER           :01', $parsed, 'version must be 1');
        self::assertStringContainsString('OBJECT            :sha256', $parsed, 'digest algorithm OID');
        self::assertStringContainsString('OCTET STRING', $parsed, 'the message imprint');
        self::assertStringContainsString('BOOLEAN', $parsed, 'certReq, so the TSA returns its certificate');
    }

    public function testImprintCarriesTheDocumentDigest(): void
    {
        if (!self::hasOpensslCli()) {
            self::markTestSkipped('openssl CLI not available.');
        }

        $digest = strtoupper(hash('sha256', 'dokumen'));
        $parsed = $this->asn1Parse($this->buildRequest('sha256', 'dokumen'));

        self::assertStringContainsString(
            $digest,
            $parsed,
            'the imprint must be the digest of the document, not of something else'
        );
    }

    public function testStrongerDigestsAreEncodedCorrectly(): void
    {
        if (!self::hasOpensslCli()) {
            self::markTestSkipped('openssl CLI not available.');
        }

        foreach (['sha384', 'sha512'] as $algo) {
            $parsed = $this->asn1Parse($this->buildRequest($algo, 'dokumen'));

            self::assertStringNotContainsString('Error', $parsed, "{$algo}: {$parsed}");
            self::assertStringContainsString(
                'OBJECT            :' . $algo,
                $parsed,
                "the OID for {$algo} should resolve by name"
            );
            self::assertStringContainsString(strtoupper(hash($algo, 'dokumen')), $parsed);
        }
    }

    // ─── Input handling ───

    /**
     * Callers hold digests as hex about as often as raw bytes, so both are
     * accepted — but a hex string must not be imprinted literally.
     */
    public function testHexAndBinaryDigestsAreEquivalent(): void
    {
        $binary = self::callPrivate('normalizeToBinary', hash('sha256', 'dokumen', true));
        $hex = self::callPrivate('normalizeToBinary', hash('sha256', 'dokumen'));

        self::assertSame(bin2hex($binary), bin2hex($hex));
        self::assertSame(32, strlen($binary));
    }
}
