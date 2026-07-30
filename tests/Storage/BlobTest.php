<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use Ezdoc\Storage\Blob;
use PHPUnit\Framework\TestCase;

/**
 * Covers the reference format, which is load-bearing for signatures.
 *
 * A signature's content hash covers `ttd_value`. Once the image leaves the
 * document row, `ttd_value` becomes one of these references — so the reference
 * has to name the *content*, not a location. If it named a location, the same
 * signature hash could come to describe different bytes, and the hash would stop
 * meaning anything.
 *
 * The parsing side matters for a second reason: these strings arrive from document
 * JSON and POST bodies, and the disk backend turns the key into a filesystem path.
 */
final class BlobTest extends TestCase
{
    public function testTheKeyIsTheSha256OfTheContent(): void
    {
        self::assertSame(hash('sha256', 'tanda tangan'), Blob::keyFor('tanda tangan'));
    }

    public function testTheReferenceCarriesTheKey(): void
    {
        $blob = new Blob(Blob::keyFor('x'), 1, 'image/png', 'database');

        self::assertSame('blob:sha256-' . hash('sha256', 'x'), $blob->reference());
        self::assertSame($blob->key(), Blob::keyFromReference($blob->reference()));
        self::assertTrue(Blob::isReference($blob->reference()));
    }

    /**
     * The prefix has to be unmistakable. A slot value is now one of three things —
     * a legacy data URI, the QR act marker, or a blob reference — and code that
     * inspects a slot must tell them apart without guessing.
     */
    public function testTheReferencePrefixNamesBothTheKindAndTheAlgorithm(): void
    {
        self::assertSame('blob:sha256-', Blob::REF_PREFIX);
    }

    /**
     * @dataProvider notReferences
     */
    public function testNonReferencesAreRejected(string $value): void
    {
        self::assertNull(Blob::keyFromReference($value));
        self::assertFalse(Blob::isReference($value));
    }

    /** @return array<string, array{string}> */
    public static function notReferences(): array
    {
        $hex = str_repeat('a', 64);

        return [
            'kosong'                => [''],
            'data URI'              => ['data:image/png;base64,AAAA'],
            'penanda mode QR'       => ['qr:v1'],
            'teks biasa'            => ['123'],
            'hex tanpa prefix'      => [$hex],
            'prefix tanpa algo'     => ['blob:' . $hex],
            'algoritma lain'        => ['blob:md5-' . $hex],
            'kunci kependekan'      => ['blob:sha256-' . str_repeat('a', 63)],
            'kunci kepanjangan'     => ['blob:sha256-' . str_repeat('a', 65)],
            'hex huruf besar'       => ['blob:sha256-' . strtoupper($hex)],
            'bukan hex'             => ['blob:sha256-' . str_repeat('z', 64)],
            'ada spasi'             => ['blob:sha256-' . $hex . ' '],
            'traversal path'        => ['blob:sha256-../../../etc/passwd'],
            'prefix di tengah'      => ['x blob:sha256-' . $hex],
        ];
    }

    /**
     * Hex must be lower case only. Accepting both would give one set of bytes two
     * valid references — and two different `ttd_value` strings, so two different
     * signature hashes for the same image.
     */
    public function testUpperCaseHexIsNotAnAlternativeSpelling(): void
    {
        $key = Blob::keyFor('x');

        self::assertNotNull(Blob::keyFromReference('blob:sha256-' . $key));
        self::assertNull(Blob::keyFromReference('blob:sha256-' . strtoupper($key)));
    }

    public function testVerifyAcceptsMatchingBytes(): void
    {
        self::assertTrue(Blob::verify(Blob::keyFor('isi'), 'isi'));
    }

    public function testVerifyRejectsAlteredBytes(): void
    {
        self::assertFalse(Blob::verify(Blob::keyFor('isi'), 'isi lain'));
        self::assertFalse(Blob::verify(Blob::keyFor('isi'), ''));
    }

    /**
     * The whole point, stated as a test: the reference is a function of the bytes
     * alone. Not of the row id, not of the backend, not of when it was stored.
     */
    public function testTheReferenceDependsOnNothingButTheBytes(): void
    {
        $a = new Blob(Blob::keyFor('sama'), 4, 'image/png', 'database');
        $b = new Blob(Blob::keyFor('sama'), 4, 'image/jpeg', 'disk');

        self::assertSame($a->reference(), $b->reference());
    }

    public function testContentIsCarriedOnlyWhenSupplied(): void
    {
        self::assertNull((new Blob('k', 1, 'image/png', 'database'))->content());
        self::assertSame('b', (new Blob('k', 1, 'image/png', 'database', 'b'))->content());
    }
}
