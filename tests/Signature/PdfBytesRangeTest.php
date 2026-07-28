<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\Pdf\PdfBytesRange;
use PHPUnit\Framework\TestCase;

/**
 * Covers `/ByteRange` handling — the array in a signed PDF that says which
 * spans of the file the signature actually covers.
 *
 * It is a gap by construction: the signature container sits in the hole
 * between the two spans, so anything the ranges fail to include can be
 * rewritten without breaking the signature. Reading it wrong is therefore not
 * a parsing inconvenience; it decides how much of the document is protected.
 *
 * A `/ByteRange [a b c d]` means: hash bytes `a..a+b`, then `c..c+d`.
 */
final class PdfBytesRangeTest extends TestCase
{
    /**
     * Build a PDF-shaped string whose /ByteRange genuinely describes it.
     *
     * @return array{bytes: string, range: PdfBytesRange}
     */
    private function signedPdf(string $before = '%PDF-1.7 header and body ', string $after = ' trailer %%EOF'): array
    {
        $marker = '/ByteRange [';
        $hole = str_repeat('0', 40); // stands in for the signature container

        // Ranges are laid out so the hole is exactly what they skip.
        $prefix = $before . $marker;
        // Placeholder written first, then rewritten once the real offsets are
        // known — the same two-pass dance a real signer performs.
        $rangeText = '';
        for ($pass = 0; $pass < 2; $pass++) {
            $doc = $prefix . $rangeText . ']' . $hole . $after;
            $start2 = strlen($prefix . $rangeText . ']') + strlen($hole);
            $rangeText = sprintf(
                '%d %d %d %d',
                0,
                strlen($prefix . $rangeText . ']'),
                $start2,
                strlen($after)
            );
        }

        $doc = $prefix . $rangeText . ']' . $hole . $after;

        return [
            'bytes' => $doc,
            'range' => PdfBytesRange::fromPdf($doc),
        ];
    }

    // ─── Construction ───

    public function testAcceptsAWellFormedRange(): void
    {
        $range = new PdfBytesRange([0, 100, 500, 200]);

        self::assertSame(0, $range->getStart1());
        self::assertSame(100, $range->getLength1());
        self::assertSame(500, $range->getStart2());
        self::assertSame(200, $range->getLength2());
        self::assertSame([0, 100, 500, 200], $range->toArray());
    }

    public function testRejectsWrongElementCount(): void
    {
        $this->expectException(ValidationException::class);
        new PdfBytesRange([0, 100, 500]);
    }

    public function testRejectsNegativeOffsets(): void
    {
        $this->expectException(ValidationException::class);
        new PdfBytesRange([0, 100, -500, 200]);
    }

    public function testRejectsNonNumericValues(): void
    {
        $this->expectException(ValidationException::class);
        new PdfBytesRange([0, 100, 'lima ratus', 200]);
    }

    /**
     * Overlapping spans would let the same bytes be counted twice while
     * something else escapes coverage entirely.
     */
    public function testRejectsOverlappingSpans(): void
    {
        $this->expectException(ValidationException::class);
        new PdfBytesRange([0, 500, 100, 200]);
    }

    public function testAcceptsAdjacentSpans(): void
    {
        // s2 exactly at s1+l1 — no gap, no overlap. Legal, if unusual.
        $range = new PdfBytesRange([0, 100, 100, 200]);

        self::assertSame(100, $range->getStart2());
    }

    // ─── Signature window ───

    /**
     * The signature container occupies the gap between the two spans. Getting
     * its bounds wrong means reading or writing the wrong bytes.
     */
    public function testSignatureWindowIsTheGapBetweenSpans(): void
    {
        $range = new PdfBytesRange([0, 100, 500, 200]);

        self::assertSame(100, $range->getSignatureOffset());
        self::assertSame(400, $range->getSignatureLength());
    }

    // ─── Coverage ───

    /**
     * Full coverage means the ranges start at byte 0 and run to the last byte.
     * Anything else leaves a region that can be altered after signing.
     */
    public function testFullCoverageRequiresStartingAtZero(): void
    {
        $covering = new PdfBytesRange([0, 100, 500, 200]);
        self::assertTrue($covering->isFullCoverage(700));

        $notFromStart = new PdfBytesRange([10, 90, 500, 200]);
        self::assertFalse($notFromStart->isFullCoverage(700));
    }

    /** Trailing bytes past the second span are outside the signature. */
    public function testFullCoverageRequiresReachingTheEnd(): void
    {
        $range = new PdfBytesRange([0, 100, 500, 200]);

        self::assertTrue($range->isFullCoverage(700));
        self::assertFalse($range->isFullCoverage(800), '100 unsigned bytes at the end');
    }

    // ─── Extraction ───

    public function testExtractsExactlyTheCoveredBytes(): void
    {
        $pdf = 'AAAABBBBCCCCDDDD';
        // Cover 'AAAA' and 'DDDD', skipping the middle.
        $range = new PdfBytesRange([0, 4, 12, 4]);

        self::assertSame('AAAADDDD', $range->computeHashedContent($pdf));
    }

    public function testExtractionRejectsRangesPastEndOfFile(): void
    {
        $range = new PdfBytesRange([0, 100, 500, 200]);

        $this->expectException(EzdocException::class);
        $range->computeHashedContent('terlalu pendek');
    }

    public function testHashCoversOnlyTheIncludedSpans(): void
    {
        $range = new PdfBytesRange([0, 4, 12, 4]);

        $original = 'AAAABBBBCCCCDDDD';
        $middleChanged = 'AAAAXXXXYYYYDDDD'; // only the uncovered gap differs

        self::assertSame(
            PdfBytesRange::computeHash($original, $range),
            PdfBytesRange::computeHash($middleChanged, $range),
            'bytes in the gap are outside the signature by design'
        );
    }

    public function testHashChangesWhenCoveredBytesChange(): void
    {
        $range = new PdfBytesRange([0, 4, 12, 4]);

        self::assertNotSame(
            PdfBytesRange::computeHash('AAAABBBBCCCCDDDD', $range),
            PdfBytesRange::computeHash('ZZZZBBBBCCCCDDDD', $range)
        );
    }

    public function testHashRejectsUnsupportedAlgorithm(): void
    {
        $range = new PdfBytesRange([0, 4, 12, 4]);

        $this->expectException(EzdocException::class);
        PdfBytesRange::computeHash('AAAABBBBCCCCDDDD', $range, 'md5');
    }

    public function testSupportedAlgorithmsProduceExpectedDigestLengths(): void
    {
        $range = new PdfBytesRange([0, 4, 12, 4]);
        $pdf = 'AAAABBBBCCCCDDDD';

        self::assertSame(32, strlen(PdfBytesRange::computeHash($pdf, $range, 'sha256')));
        self::assertSame(48, strlen(PdfBytesRange::computeHash($pdf, $range, 'sha384')));
        self::assertSame(64, strlen(PdfBytesRange::computeHash($pdf, $range, 'sha512')));
    }

    // ─── Parsing ───

    public function testParsesByteRangeFromAPdf(): void
    {
        $signed = $this->signedPdf();

        self::assertSame(0, $signed['range']->getStart1());
        self::assertTrue(
            $signed['range']->isFullCoverage(strlen($signed['bytes'])),
            'the fixture should describe itself completely'
        );
    }

    /**
     * The PDF spec allows arbitrary whitespace inside the array, and real
     * producers use it — a parser that only accepts single spaces would read
     * some files as unsigned.
     */
    public function testToleratesIrregularWhitespace(): void
    {
        foreach ([
            '/ByteRange [0 100 500 200]',
            '/ByteRange[0 100 500 200]',
            "/ByteRange [ 0  100   500    200 ]",
            "/ByteRange [\n0\n100\n500\n200\n]",
            "/ByteRange [\r\n0 100 500 200\r\n]",
        ] as $variant) {
            $range = PdfBytesRange::fromPdf('%PDF-1.7 ' . $variant . ' trailer');

            self::assertSame([0, 100, 500, 200], $range->toArray(), "failed on: {$variant}");
        }
    }

    public function testUnsignedPdfHasNoByteRange(): void
    {
        $this->expectException(EzdocException::class);
        PdfBytesRange::fromPdf('%PDF-1.7 sama sekali tidak ditandatangani %%EOF');
    }

    public function testFindAllReturnsEverySignature(): void
    {
        $pdf = '%PDF-1.7 '
            . '/ByteRange [0 100 500 200] '
            . 'isi lain '
            . '/ByteRange [0 800 1200 300] '
            . '%%EOF';

        $all = PdfBytesRange::findAll($pdf);

        self::assertCount(2, $all, 'a multi-signature document has one range per signature');
        self::assertSame([0, 100, 500, 200], $all[0]->toArray());
        self::assertSame([0, 800, 1200, 300], $all[1]->toArray());
    }

    public function testFindAllSkipsMalformedEntries(): void
    {
        $pdf = '%PDF-1.7 '
            . '/ByteRange [0 500 100 200] '   // overlapping — invalid
            . '/ByteRange [0 100 500 200] '   // valid
            . '%%EOF';

        $all = PdfBytesRange::findAll($pdf);

        self::assertCount(1, $all);
        self::assertSame([0, 100, 500, 200], $all[0]->toArray());
    }

    public function testFindAllReturnsEmptyForUnsignedInput(): void
    {
        self::assertSame([], PdfBytesRange::findAll('%PDF-1.7 polos %%EOF'));
        self::assertSame([], PdfBytesRange::findAll(''));
    }

    /** fromPdf() takes the first signature; findAll() is for the rest. */
    public function testFromPdfReturnsTheFirstSignature(): void
    {
        $pdf = '/ByteRange [0 100 500 200] /ByteRange [0 800 1200 300]';

        self::assertSame([0, 100, 500, 200], PdfBytesRange::fromPdf($pdf)->toArray());
    }

    // ─── Placeholder ───

    /**
     * Signers write an all-zero range first, then patch in real offsets. A
     * document still carrying zeros was never finished, and its signature
     * covers nothing.
     */
    public function testAllZeroRangeIsRecognisedAsAPlaceholder(): void
    {
        self::assertTrue((new PdfBytesRange([0, 0, 0, 0]))->isPlaceholder());
        self::assertFalse((new PdfBytesRange([0, 100, 500, 200]))->isPlaceholder());
    }
}
