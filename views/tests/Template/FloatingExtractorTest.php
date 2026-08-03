<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Template;

use Ezdoc\Template\FloatingElement;
use Ezdoc\Template\FloatingExtractor;
use Ezdoc\Template\FloatingInjector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Ezdoc\Template\FloatingExtractor + FloatingInjector.
 *
 * Focus:
 * - Extraction dari HTML markers → FloatingElement[]
 * - JSON serialization roundtrip
 * - Extract + Inject roundtrip (data preservation)
 * - Backward-compat: legacy markers + widget-wrapper patterns
 *
 * PHP 7.4+ compatible.
 */
final class FloatingExtractorTest extends TestCase
{
    public function testExtractEmptyHtml(): void
    {
        $result = FloatingExtractor::extract('');

        $this->assertSame('', $result['html']);
        $this->assertSame([], $result['floating']);
    }

    public function testExtractHtmlWithoutFloatingMarkers(): void
    {
        $html = '<p>Just text content</p><p>Another paragraph</p>';
        $result = FloatingExtractor::extract($html);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['floating']);
    }

    public function testExtractLegacyLogoFloatingSpan(): void
    {
        $html = '<p>Before <span class="logo-placeholder floating front" data-logo="hospital" data-width="80px" data-pos-mode="front" data-pos-x="400" data-pos-y="100" style="top: 100px; left: 400px;" contenteditable="false">[Logo: hospital]</span> after</p>';

        $result = FloatingExtractor::extract($html);

        $this->assertCount(1, $result['floating']);
        $el = $result['floating'][0];
        $this->assertSame('hospital', $el->id);
        $this->assertSame('logo', $el->type);
        $this->assertSame(400, $el->positionX);
        $this->assertSame(100, $el->positionY);
        $this->assertSame('front', $el->zIndex);
        $this->assertSame('80px', $el->width);

        // Marker stripped from HTML
        $this->assertStringNotContainsString('logo-placeholder', $result['html']);
        $this->assertStringContainsString('Before', $result['html']);
        $this->assertStringContainsString('after', $result['html']);
    }

    public function testExtractQrFloatingSpan(): void
    {
        $html = '<span class="qr-placeholder floating behind" data-qr="verify" data-width="60px" data-pos-x="700" data-pos-y="900" style="top:900px;left:700px" contenteditable="false">[QR]</span>';

        $result = FloatingExtractor::extract($html);

        $this->assertCount(1, $result['floating']);
        $el = $result['floating'][0];
        $this->assertSame('verify', $el->id);
        $this->assertSame('qr', $el->type);
        $this->assertSame('behind', $el->zIndex);
    }

    public function testExtractTtdFloatingDiv(): void
    {
        $html = '<div class="ttd-placeholder floating front" data-ttd="ttd_dokter" data-label="Doctor" data-nama-field="nama_dokter" data-pos-x="500" data-pos-y="800" style="top:800px;left:500px" contenteditable="false"><div>Content</div></div>';

        $result = FloatingExtractor::extract($html);

        $this->assertCount(1, $result['floating']);
        $el = $result['floating'][0];
        $this->assertSame('ttd_dokter', $el->id);
        $this->assertSame('ttd', $el->type);
        $this->assertSame('Doctor', $el->data['label']);
        $this->assertSame('nama_dokter', $el->data['nama_field']);
    }

    public function testExtractMultipleFloatingElements(): void
    {
        $html = '<p>Text</p>' .
            '<span class="logo-placeholder floating front" data-logo="logo1" data-pos-x="100" data-pos-y="50" contenteditable="false">[L1]</span>' .
            '<span class="qr-placeholder floating front" data-qr="qr1" data-pos-x="200" data-pos-y="60" contenteditable="false">[Q1]</span>' .
            '<div class="ttd-placeholder floating behind" data-ttd="ttd1" data-label="Sig" data-pos-x="300" data-pos-y="70" contenteditable="false"><div>x</div></div>';

        $result = FloatingExtractor::extract($html);

        $this->assertCount(3, $result['floating']);

        // Verify types
        $types = array_map(fn($el) => $el->type, $result['floating']);
        $this->assertContains('logo', $types);
        $this->assertContains('qr', $types);
        $this->assertContains('ttd', $types);
    }

    public function testExtractIgnoresNonFloatingPlaceholders(): void
    {
        // Inline (non-floating) logo should NOT be extracted
        $html = '<span class="logo-placeholder" data-logo="inline_logo">[Logo]</span>';

        $result = FloatingExtractor::extract($html);

        $this->assertSame([], $result['floating']);
        // Original HTML preserved
        $this->assertStringContainsString('logo-placeholder', $result['html']);
    }

    public function testJsonSerializationRoundTrip(): void
    {
        $original = [
            new FloatingElement('logo_a', 'logo', 100, 200, 'front', '80px'),
            new FloatingElement('ttd_a', 'ttd', 500, 800, 'front', '120px', ['label' => 'Dr']),
            new FloatingElement('qr_a', 'qr', 300, 400, 'behind', '60px'),
        ];

        $json = FloatingExtractor::toJson($original);
        $restored = FloatingExtractor::fromJson($json);

        $this->assertCount(3, $restored);
        foreach ($restored as $i => $el) {
            $this->assertSame($original[$i]->id, $el->id);
            $this->assertSame($original[$i]->type, $el->type);
            $this->assertSame($original[$i]->positionX, $el->positionX);
            $this->assertSame($original[$i]->positionY, $el->positionY);
            $this->assertSame($original[$i]->zIndex, $el->zIndex);
            $this->assertSame($original[$i]->data, $el->data);
        }
    }

    public function testFromJsonEmptyStringReturnsEmpty(): void
    {
        $this->assertSame([], FloatingExtractor::fromJson(''));
        $this->assertSame([], FloatingExtractor::fromJson('null'));
        $this->assertSame([], FloatingExtractor::fromJson(null));
    }

    public function testFromJsonMalformedReturnsEmpty(): void
    {
        $this->assertSame([], FloatingExtractor::fromJson('not valid json'));
        $this->assertSame([], FloatingExtractor::fromJson('{'));
    }

    public function testFromJsonSkipsInvalidRows(): void
    {
        // Rows dgn invalid type di-skip; valid rows di-preserve
        $json = json_encode([
            ['id' => 'valid_logo', 'type' => 'logo', 'position_x' => 0, 'position_y' => 0, 'z_index' => 'front', 'width' => '80px'],
            ['id' => 'invalid', 'type' => 'INVALID_TYPE', 'position_x' => 0, 'position_y' => 0, 'z_index' => 'front', 'width' => '80px'],
            ['id' => 'valid_qr', 'type' => 'qr', 'position_x' => 100, 'position_y' => 100, 'z_index' => 'behind', 'width' => '60px'],
        ]);

        $restored = FloatingExtractor::fromJson($json);

        $this->assertCount(2, $restored);
        $this->assertSame('valid_logo', $restored[0]->id);
        $this->assertSame('valid_qr', $restored[1]->id);
    }

    public function testExtractInjectRoundTripPreservesData(): void
    {
        $html = '<span class="logo-placeholder floating front" data-logo="test" data-width="80px" data-pos-x="400" data-pos-y="100" style="top:100px;left:400px" contenteditable="false">[Logo]</span>';

        // Extract
        $extracted = FloatingExtractor::extract($html);
        $this->assertCount(1, $extracted['floating']);
        $originalEl = $extracted['floating'][0];

        // Inject back
        $rehydrated = FloatingInjector::inject($extracted['html'], $extracted['floating']);

        // Re-extract from rehydrated
        $reExtracted = FloatingExtractor::extract($rehydrated);
        $this->assertCount(1, $reExtracted['floating']);
        $rehydratedEl = $reExtracted['floating'][0];

        // Position + type + id preserved
        $this->assertSame($originalEl->id, $rehydratedEl->id);
        $this->assertSame($originalEl->type, $rehydratedEl->type);
        $this->assertSame($originalEl->positionX, $rehydratedEl->positionX);
        $this->assertSame($originalEl->positionY, $rehydratedEl->positionY);
        $this->assertSame($originalEl->zIndex, $rehydratedEl->zIndex);
        $this->assertSame($originalEl->width, $rehydratedEl->width);
    }

    public function testInjectEmptyArrayReturnsUnchangedHtml(): void
    {
        $html = '<p>Content</p>';
        $result = FloatingInjector::inject($html, []);

        $this->assertSame($html, $result);
    }

    public function testInjectWrapsInWidgetPattern(): void
    {
        $el = new FloatingElement('test_logo', 'logo', 100, 200, 'front', '80px');
        $rehydrated = FloatingInjector::inject('', [$el]);

        // Widget wrapper pattern: p.floating-only contenteditable=false
        $this->assertStringContainsString('class="floating-only"', $rehydrated);
        $this->assertStringContainsString('contenteditable="false"', $rehydrated);
        $this->assertStringContainsString('data-logo="test_logo"', $rehydrated);
    }

    // ─── Empty-line remnant bug regression tests ───────────────────────
    //
    // User reported: extractor kadang leaves empty <p> after strip.
    // Root cause: cleanup regex only matched exactly empty wrappers.
    // Following tests cover the previously-broken cases.

    public function testStripsLegacyBareParagraphWrappingFloating(): void
    {
        // Legacy: <p> WITHOUT floating-only class, wrapping ONLY floating marker
        $html = 'Before<p><span class="logo-placeholder floating front" data-logo="h" data-pos-x="0" data-pos-y="0" data-width="80px">[Logo: h]</span></p>After';
        $result = FloatingExtractor::extract($html);

        $this->assertCount(1, $result['floating']);
        // Whole <p> harus stripped (not just span)
        $this->assertStringNotContainsString('<p></p>', $result['html']);
        $this->assertStringNotContainsString('<p>', $result['html']);
        $this->assertSame('BeforeAfter', $result['html']);
    }

    public function testStripsWidgetWrapperContainingNbsp(): void
    {
        // Widget wrapper dgn nbsp inside (editor artifact)
        $html = '<p class="floating-only" contenteditable="false">&nbsp;<span class="logo-placeholder floating front" data-logo="h" data-pos-x="0" data-pos-y="0" data-width="80px">[Logo: h]</span>&nbsp;</p>';
        $result = FloatingExtractor::extract($html);

        $this->assertCount(1, $result['floating']);
        $this->assertSame('', trim($result['html']));
    }

    public function testStripsWidgetWrapperContainingBrTag(): void
    {
        // Widget wrapper dgn <br> inside (browser default in empty p)
        $html = '<p class="floating-only" contenteditable="false"><span class="logo-placeholder floating front" data-logo="h" data-pos-x="0" data-pos-y="0" data-width="80px">[Logo: h]</span><br></p>';
        $result = FloatingExtractor::extract($html);

        $this->assertCount(1, $result['floating']);
        $this->assertSame('', trim($result['html']));
    }

    public function testStripsEmptyWidgetWrapperOnlyBr(): void
    {
        // Phase 3 cleanup: wrapper dgn hanya <br> (no marker inside)
        $html = 'Before<p class="floating-only" contenteditable="false"><br></p>After';
        $result = FloatingExtractor::extract($html);

        $this->assertSame('BeforeAfter', $result['html']);
    }

    public function testStripsEmptyWidgetWrapperOnlyNbsp(): void
    {
        // Phase 3 cleanup: wrapper dgn hanya &nbsp;
        $html = 'Before<p class="floating-only" contenteditable="false">&nbsp;</p>After';
        $result = FloatingExtractor::extract($html);

        $this->assertSame('BeforeAfter', $result['html']);
    }

    public function testPreservesRegularParagraphs(): void
    {
        // Guard: regular paragraphs (not floating-only, not wrapping floating)
        // harus NOT stripped
        $html = '<p>Real text content</p><p>Another paragraph</p>';
        $result = FloatingExtractor::extract($html);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['floating']);
    }

    public function testPreservesIntentionallyEmptyParagraphs(): void
    {
        // Guard: user-intentional empty <p> (spacer) tanpa floating-only class
        // harus preserved
        $html = '<p>Text</p><p></p><p>More text</p>';
        $result = FloatingExtractor::extract($html);

        $this->assertSame($html, $result['html']);
    }

    public function testStripsWholeWrapperInMixedContent(): void
    {
        // Multiple markers + regular content mixed
        $html = '<p>Intro</p>' .
                '<p class="floating-only" contenteditable="false"><span class="logo-placeholder floating front" data-logo="h" data-pos-x="0" data-pos-y="0" data-width="80px">[Logo: h]</span></p>' .
                '<p>Middle text</p>' .
                '<p><div class="ttd-placeholder floating front" data-ttd="t" data-pos-x="0" data-pos-y="0">TTD</div></p>' .
                '<p>Ending</p>';
        $result = FloatingExtractor::extract($html);

        $this->assertCount(2, $result['floating']);
        $this->assertStringContainsString('<p>Intro</p>', $result['html']);
        $this->assertStringContainsString('<p>Middle text</p>', $result['html']);
        $this->assertStringContainsString('<p>Ending</p>', $result['html']);
        $this->assertStringNotContainsString('floating-only', $result['html']);
        $this->assertStringNotContainsString('logo-placeholder', $result['html']);
        $this->assertStringNotContainsString('ttd-placeholder', $result['html']);
    }
}
