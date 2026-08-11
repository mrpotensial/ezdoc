<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Support;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Covers canonical JSON — the guarantee that identical data yields identical
 * bytes, which is the only thing that makes a hash over it mean anything.
 *
 * The property under test is not "produces this exact string". It is "two
 * inputs that carry the same data cannot produce different output". Pinning
 * literal expected strings would only prove the implementation still does what
 * it did yesterday; these tests build the same data two ways and demand the
 * results match.
 */
final class CanonicalJsonTest extends TestCase
{
    // ─── The property that matters ───

    /**
     * The bug this exists to prevent: two people signing in a different order
     * writes the same signatures under a different key order, and a hash over
     * the raw JSON then reports two identical documents as different.
     */
    public function testKeyOrderDoesNotChangeTheOutput(): void
    {
        $aFirst = ['ttd_a' => 'tanda tangan A', 'ttd_b' => 'tanda tangan B'];
        $bFirst = ['ttd_b' => 'tanda tangan B', 'ttd_a' => 'tanda tangan A'];

        self::assertSame(CanonicalJson::encode($aFirst), CanonicalJson::encode($bFirst));
        self::assertSame(CanonicalJson::hash($aFirst), CanonicalJson::hash($bFirst));
    }

    public function testKeyOrderDoesNotMatterAtAnyDepth(): void
    {
        $one = ['doc' => ['fields' => ['nama' => 'A', 'nik' => 'B'], 'ttd' => ['x' => 1, 'y' => 2]]];
        $two = ['doc' => ['ttd' => ['y' => 2, 'x' => 1], 'fields' => ['nik' => 'B', 'nama' => 'A']]];

        self::assertSame(CanonicalJson::encode($one), CanonicalJson::encode($two));
    }

    /**
     * The other half of the guarantee: different data must not collide. A
     * canonicaliser that returned a constant would pass every test above.
     */
    public function testDifferentDataProducesDifferentOutput(): void
    {
        self::assertNotSame(
            CanonicalJson::hash(['nominal' => '1000000']),
            CanonicalJson::hash(['nominal' => '9000000'])
        );
        self::assertNotSame(
            CanonicalJson::hash(['a' => '1']),
            CanonicalJson::hash(['b' => '1']),
            'the key is part of the data'
        );
    }

    public function testEncodingIsStableAcrossCalls(): void
    {
        $data = ['b' => ['z' => 1, 'a' => 2], 'a' => 'x'];

        self::assertSame(CanonicalJson::encode($data), CanonicalJson::encode($data));
    }

    // ─── Arrays keep their order ───

    /**
     * Objects are unordered in JSON, arrays are not. Sorting a list would
     * change the data, not canonicalise it.
     */
    public function testListOrderIsPreserved(): void
    {
        self::assertSame('["c","a","b"]', CanonicalJson::encode(['c', 'a', 'b']));
        self::assertNotSame(
            CanonicalJson::encode(['a', 'b']),
            CanonicalJson::encode(['b', 'a']),
            'reordering a list is a different document'
        );
    }

    public function testListsInsideMapsKeepTheirOrder(): void
    {
        $encoded = CanonicalJson::encode(['roles' => ['dokter', 'perawat']]);

        self::assertSame('{"roles":["dokter","perawat"]}', $encoded);
    }

    /**
     * An int-keyed map is not a list. Letting json_encode treat it as one would
     * drop the keys and silently change the data being hashed.
     */
    public function testAnIntKeyedMapKeepsItsKeys(): void
    {
        $encoded = CanonicalJson::encode([7 => 'dokter', 3 => 'perawat']);

        self::assertSame('{"3":"perawat","7":"dokter"}', $encoded);
    }

    // ─── Shape of the output ───

    public function testKeysAreSortedAndWhitespaceOmitted(): void
    {
        self::assertSame(
            '{"a":1,"b":2,"c":3}',
            CanonicalJson::encode(['c' => 3, 'a' => 1, 'b' => 2])
        );
    }

    /** RFC 8785 §3.2.2.2 — non-ASCII stays literal, slashes unescaped. */
    public function testNonAsciiAndSlashesAreNotEscaped(): void
    {
        $encoded = CanonicalJson::encode(['url' => 'https://a/b', 'teks' => 'Perjanjian — pasal 1']);

        self::assertStringContainsString('https://a/b', $encoded);
        self::assertStringContainsString('Perjanjian — pasal 1', $encoded);
        self::assertStringNotContainsString('\\/', $encoded);
        self::assertStringNotContainsString('\\u', $encoded);
    }

    /**
     * RFC 8785 §3.2.3 sorts by UTF-16 code units, not code points. Astral
     * characters encode as surrogates in 0xD800..0xDFFF and so sort *before*
     * U+E000..U+FFFF — comparing UTF-8 bytes would put them after.
     */
    public function testKeysSortByUtf16CodeUnitsNotCodePoints(): void
    {
        $astral = "\u{1F600}";   // U+1F600, surrogates D83D DE00 in UTF-16
        $bmp    = "\u{FB00}";    // U+FB00, above the surrogate range in UTF-16

        $encoded = CanonicalJson::encode([$bmp => 1, $astral => 2]);

        self::assertLessThan(
            strpos($encoded, $bmp),
            strpos($encoded, $astral),
            'the astral key sorts first under UTF-16 ordering'
        );
    }

    // ─── Scalars ───

    public function testScalarsEncodeAsThemselves(): void
    {
        self::assertSame('null', CanonicalJson::encode(null));
        self::assertSame('true', CanonicalJson::encode(true));
        self::assertSame('false', CanonicalJson::encode(false));
        self::assertSame('42', CanonicalJson::encode(42));
        self::assertSame('"teks"', CanonicalJson::encode('teks'));
    }

    /**
     * A numeric string is not a number. Conflating them would make "1" and 1
     * hash alike, and form input is always the former.
     */
    public function testNumericStringsStayStrings(): void
    {
        self::assertNotSame(CanonicalJson::encode('1'), CanonicalJson::encode(1));
    }

    // ─── Refusals ───

    /**
     * The deliberate deviation from RFC 8785 §3.2.2.3. PHP float output depends
     * on serialize_precision, so the digest would differ between hosts running
     * identical code. Refusing beats a host-specific hash.
     */
    public function testFloatIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        CanonicalJson::encode(['jumlah' => 1.5]);
    }

    public function testTheRejectionNamesTheOffendingPath(): void
    {
        try {
            CanonicalJson::encode(['doc' => ['baris' => [['harga' => 2.5]]]]);
            self::fail('expected a rejection');
        } catch (ValidationException $e) {
            self::assertStringContainsString('doc.baris[0].harga', $e->getMessage());
        }
    }

    public function testNanAndInfinityAreRejected(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            try {
                CanonicalJson::encode(['x' => $value]);
                self::fail('expected a rejection');
            } catch (ValidationException $e) {
                self::assertStringContainsString('float', $e->getMessage());
            }
        }
    }

    public function testResourcesAndObjectsWithoutJsonMeaningAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        CanonicalJson::encode(['h' => new \ArrayObject([1, 2])]);
    }

    // ─── stdClass ───

    /** json_decode without assoc yields stdClass; it must canonicalise the same. */
    public function testStdClassCanonicalisesLikeAnArray(): void
    {
        $asObject = json_decode('{"b":2,"a":1}');
        $asArray = ['a' => 1, 'b' => 2];

        self::assertSame(CanonicalJson::encode($asArray), CanonicalJson::encode($asObject));
    }

    // ─── fromJson ───

    /**
     * The usual entry point: stored JSON carries whatever key order it was
     * written with, and that is exactly what must not reach the hash.
     */
    public function testFromJsonNormalisesStoredKeyOrder(): void
    {
        $storedOneWay = '{"ttd_b":"B","ttd_a":"A"}';
        $storedOther = '{"ttd_a":"A","ttd_b":"B"}';

        self::assertSame(
            CanonicalJson::fromJson($storedOneWay),
            CanonicalJson::fromJson($storedOther)
        );
    }

    public function testFromJsonTreatsEmptyInputAsAnEmptyObject(): void
    {
        self::assertSame('{}', CanonicalJson::fromJson(''));
        self::assertSame('{}', CanonicalJson::fromJson('   '));
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(ValidationException::class);
        CanonicalJson::fromJson('{bukan json}');
    }

    /** Whitespace in the source is insignificant and must not reach the hash. */
    public function testFromJsonIgnoresSourceFormatting()
    {
        self::assertSame(
            CanonicalJson::fromJson('{"a":1,"b":2}'),
            CanonicalJson::fromJson("{\n  \"a\" : 1,\n  \"b\" : 2\n}")
        );
    }

    // ─── hash() ───

    public function testHashIsSha256OfTheCanonicalForm(): void
    {
        $data = ['b' => 2, 'a' => 1];

        self::assertSame(
            hash('sha256', CanonicalJson::encode($data)),
            CanonicalJson::hash($data)
        );
        self::assertSame(64, strlen(CanonicalJson::hash($data)));
    }

    public function testHashAcceptsStrongerAlgorithms(): void
    {
        self::assertSame(128, strlen(CanonicalJson::hash(['a' => 1], 'sha512')));
    }

    public function testHashRejectsAnUnknownAlgorithm(): void
    {
        $this->expectException(ValidationException::class);
        CanonicalJson::hash(['a' => 1], 'bukan-algo');
    }

    // ─── The real shape this will be used on ───

    /**
     * Per-signature hash input from 260730-catatan-tanda-tangan §5. Signing order
     * must not matter, and one slot's value must not affect another's hash.
     */
    public function testSignaturePayloadIsOrderIndependent(): void
    {
        $payload = static function (array $fields, string $ttdId, string $value): array {
            return [
                'document_uuid'    => '0190-aaaa',
                'template_uuid'    => '0190-bbbb',
                'template_version' => 3,
                'fields'           => $fields,
                'ttd_id'           => $ttdId,
                'ttd_value'        => $value,
            ];
        };

        $fieldsOneWay = ['nama' => 'Budi', 'nik' => '123'];
        $fieldsOther = ['nik' => '123', 'nama' => 'Budi'];

        self::assertSame(
            CanonicalJson::hash($payload($fieldsOneWay, 'ttd_1', 'img-a')),
            CanonicalJson::hash($payload($fieldsOther, 'ttd_1', 'img-a')),
            'field order must not change a signature hash'
        );

        self::assertNotSame(
            CanonicalJson::hash($payload($fieldsOneWay, 'ttd_1', 'img-a')),
            CanonicalJson::hash($payload($fieldsOneWay, 'ttd_2', 'img-a')),
            'each slot must hash differently'
        );

        self::assertNotSame(
            CanonicalJson::hash($payload($fieldsOneWay, 'ttd_1', 'img-a')),
            CanonicalJson::hash($payload(['nama' => 'Budi', 'nik' => '999'], 'ttd_1', 'img-a')),
            'editing a field must break the signature'
        );
    }
}
