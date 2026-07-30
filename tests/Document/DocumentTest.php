<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use Ezdoc\Document\Document;
use PHPUnit\Framework\TestCase;

/**
 * Covers the one distinction the entity has to keep: a document with no
 * signatures, and a document whose signatures were not loaded.
 *
 * List queries omit `signature_values` on purpose — the base64 images are ~97%
 * of the payload and no list renders them. The saving is real, but it introduces
 * a shape the entity did not have before: a column that is absent rather than
 * empty. Collapsing the two would let a document that *is* signed report that it
 * is not, confidently and without any error.
 */
final class DocumentTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id'               => 7,
            'uuid'             => '0190aaaa-0000-7000-8000-000000000001',
            'template_id'      => 3,
            'template_uuid'    => '0190bbbb-0000-7000-8000-000000000002',
            'template_version' => 2,
            'title'            => 'Surat Pernyataan',
            'field_values'     => '{"nama":"Budi"}',
            'metadata'         => '{}',
            'status'           => 'draft',
            'revision'         => 1,
        ];
    }

    public function testSignaturesAreLoadedWhenTheColumnIsPresent(): void
    {
        $doc = Document::fromRow(self::row([
            'signature_values' => '{"ttd_a":"data:image/png;base64,AAAA"}',
        ]));

        self::assertTrue($doc->hasLoadedSignatureValues());
        self::assertSame(['ttd_a' => 'data:image/png;base64,AAAA'], $doc->getSignatureValues());
    }

    /**
     * A document that genuinely has no signatures: the column is there, and it
     * is empty. Reading it is legitimate and must not throw.
     */
    public function testAnEmptyColumnStillCountsAsLoaded(): void
    {
        $doc = Document::fromRow(self::row(['signature_values' => '{}']));

        self::assertTrue($doc->hasLoadedSignatureValues());
        self::assertSame([], $doc->getSignatureValues());
    }

    /**
     * NULL in the column is also a loaded answer — "this document has none".
     * Only an absent *key* means "not loaded".
     */
    public function testNullInTheColumnCountsAsLoaded(): void
    {
        $doc = Document::fromRow(self::row(['signature_values' => null]));

        self::assertTrue($doc->hasLoadedSignatureValues());
        self::assertSame([], $doc->getSignatureValues());
    }

    public function testAnAbsentColumnIsNotLoaded(): void
    {
        $doc = Document::fromRow(self::row()); // tanpa kunci signature_values

        self::assertFalse($doc->hasLoadedSignatureValues());
    }

    /**
     * The core of it. Returning [] here would be the bug this whole flag exists
     * to prevent: silent, confident, and wrong.
     */
    public function testReadingUnloadedSignaturesThrows(): void
    {
        $doc = Document::fromRow(self::row());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/signature_values was not loaded/');
        $doc->getSignatureValues();
    }

    /**
     * The message has to say what to do, not just what went wrong. Whoever hits
     * this is holding a list result and needs to know findById() is the fix.
     */
    public function testTheErrorNamesTheDocumentAndTheRemedy(): void
    {
        $doc = Document::fromRow(self::row(['id' => 42]));

        try {
            $doc->getSignatureValues();
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('42', $e->getMessage());
            self::assertStringContainsString('findById', $e->getMessage());
        }
    }

    /**
     * toArray() feeds JSON responses and audit entries. `[]` there would be
     * recorded as "document had no signatures" — a wrong audit entry is worse
     * than a missing one, because it is trusted later.
     */
    public function testToArrayReportsNullForUnloadedSignatures(): void
    {
        $doc = Document::fromRow(self::row());

        self::assertArrayHasKey('signature_values', $doc->toArray());
        self::assertNull($doc->toArray()['signature_values']);
    }

    public function testToArrayReportsTheValueWhenLoaded(): void
    {
        $doc = Document::fromRow(self::row(['signature_values' => '{"ttd_a":"x"}']));

        self::assertSame(['ttd_a' => 'x'], $doc->toArray()['signature_values']);
    }

    /**
     * Constructing directly still means "the caller supplied the value", so the
     * flag defaults to loaded. Anything else would break every existing caller
     * by turning a value they passed into an error.
     */
    public function testDirectConstructionDefaultsToLoaded(): void
    {
        $doc = new Document(
            1, 'u', 1, 't', 1, null, null, null, null,
            [], ['ttd_a' => 'x'], [], 'draft', null, null, 1, null, null, null
        );

        self::assertTrue($doc->hasLoadedSignatureValues());
        self::assertSame(['ttd_a' => 'x'], $doc->getSignatureValues());
    }

    public function testDirectConstructionCanDeclareItselfPartial(): void
    {
        $doc = new Document(
            1, 'u', 1, 't', 1, null, null, null, null,
            [], [], [], 'draft', null, null, 1, null, null, null,
            false
        );

        self::assertFalse($doc->hasLoadedSignatureValues());
    }

    /**
     * Omitting signatures must not disturb anything else the row carries — the
     * point of the projection split is that lists lose only the images.
     */
    public function testTheRestOfTheRowIsUnaffected(): void
    {
        $doc = Document::fromRow(self::row(['norm' => '1', 'nopen' => '2']));

        self::assertSame(7, $doc->getId());
        self::assertSame('Surat Pernyataan', $doc->getTitle());
        self::assertSame('Budi', $doc->getFieldValues()['nama']);
        self::assertSame('1', $doc->getFieldValues()['norm']);
        self::assertSame('2', $doc->getFieldValues()['nopen']);
        self::assertSame('draft', $doc->getStatus());
    }
}
