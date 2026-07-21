<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Template;

use Ezdoc\Template\FloatingElement;
use Ezdoc\Template\FloatingExtractor;
use Ezdoc\Template\FloatingInjector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Ezdoc\Template\FloatingInjector.
 *
 * Focus:
 * - Each floating type renders correct HTML marker structure
 * - Widget wrapper (`<p class="floating-only" contenteditable="false">`) applied
 * - CSS class list correct per type + z-index
 * - XSS protection via htmlspecialchars
 * - Multiple elements appended in given order
 * - Round-trip preservation with FloatingExtractor
 *
 * PHP 7.4+ compatible.
 */
final class FloatingInjectorTest extends TestCase
{
    // ─── Empty / edge cases ─────────────────────────────────────────────

    public function testEmptyFloatingArrayReturnsUnchangedHtml(): void
    {
        $html = '<p>Content</p>';
        $result = FloatingInjector::inject($html, []);

        $this->assertSame($html, $result);
    }

    public function testEmptyHtmlWithFloatingProducesMarkersOnly(): void
    {
        $el = new FloatingElement('test_logo', 'logo', 100, 200, 'front', '80px');
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringContainsString('class="floating-only"', $result);
        $this->assertStringContainsString('data-logo="test_logo"', $result);
    }

    // ─── Widget wrapper ─────────────────────────────────────────────────

    public function testMarkerWrappedInWidgetPattern(): void
    {
        $el = new FloatingElement('logo1', 'logo', 100, 200, 'front', '80px');
        $result = FloatingInjector::inject('', [$el]);

        // Widget wrapper: <p class="floating-only" contenteditable="false">
        $this->assertStringContainsString('<p class="floating-only" contenteditable="false">', $result);
        $this->assertStringContainsString('</p>', $result);
    }

    public function testInnerElementAlsoContenteditableFalse(): void
    {
        // Both the outer widget wrapper AND the inner span/div must be
        // contenteditable=false (CKEditor 5 non-editable atomic widget pattern).
        $el = new FloatingElement('logo1', 'logo', 100, 200, 'front', '80px');
        $result = FloatingInjector::inject('', [$el]);

        // Should have >= 2 occurrences (wrapper + inner)
        $this->assertGreaterThanOrEqual(2, substr_count($result, 'contenteditable="false"'));
    }

    // ─── LOGO type ──────────────────────────────────────────────────────

    public function testLogoMarkerStructure(): void
    {
        $el = new FloatingElement('hospital', 'logo', 400, 100, 'front', '80px');
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringContainsString('<span', $result);
        $this->assertStringContainsString('data-logo="hospital"', $result);
        $this->assertStringContainsString('data-width="80px"', $result);
        $this->assertStringContainsString('data-pos-mode="front"', $result);
        $this->assertStringContainsString('data-pos-x="400"', $result);
        $this->assertStringContainsString('data-pos-y="100"', $result);
        // CSS position
        $this->assertStringContainsString('top: 100px', $result);
        $this->assertStringContainsString('left: 400px', $result);
        // CSS classes
        $this->assertStringContainsString('logo-placeholder', $result);
        $this->assertStringContainsString('floating', $result);
        $this->assertStringContainsString('front', $result);
        // Human-readable inner text
        $this->assertStringContainsString('[Logo: hospital]', $result);
    }

    public function testLogoBehindClass(): void
    {
        $el = new FloatingElement('watermark', 'logo', 0, 0, 'behind', '80px');
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringContainsString('behind', $result);
        $this->assertStringNotContainsString(' front"', $result); // no front class
    }

    // ─── QR type ────────────────────────────────────────────────────────

    public function testQrMarkerStructure(): void
    {
        $el = new FloatingElement('verify_qr', 'qr', 500, 700, 'front', '60px');
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringContainsString('<span', $result);
        $this->assertStringContainsString('data-qr="verify_qr"', $result);
        $this->assertStringContainsString('data-width="60px"', $result);
        $this->assertStringContainsString('data-pos-x="500"', $result);
        $this->assertStringContainsString('data-pos-y="700"', $result);
        $this->assertStringContainsString('qr-placeholder', $result);
        $this->assertStringContainsString('[QR: verify_qr]', $result);
    }

    // ─── TTD type ───────────────────────────────────────────────────────

    public function testTtdMarkerStructure(): void
    {
        $el = new FloatingElement(
            'dokter',
            'ttd',
            600,
            800,
            'front',
            null,
            ['label' => 'Doctor', 'nama_field' => 'nama_dokter', 'ttd_modes' => 'image']
        );
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('data-ttd="dokter"', $result);
        $this->assertStringContainsString('data-label="Doctor"', $result);
        $this->assertStringContainsString('data-nama-field="nama_dokter"', $result);
        $this->assertStringContainsString('data-ttd-modes="image"', $result);
        $this->assertStringContainsString('data-pos-x="600"', $result);
        $this->assertStringContainsString('data-pos-y="800"', $result);
        $this->assertStringContainsString('ttd-placeholder', $result);
    }

    public function testTtdDefaultsWhenDataMissing(): void
    {
        $el = new FloatingElement('sign1', 'ttd', 100, 100, 'front', null, []);
        $result = FloatingInjector::inject('', [$el]);

        // Defaults: label = 'Signature', nama_field = 'nama_<id>', ttd_modes = 'image'
        $this->assertStringContainsString('data-label="Signature"', $result);
        $this->assertStringContainsString('data-nama-field="nama_sign1"', $result);
        $this->assertStringContainsString('data-ttd-modes="image"', $result);
    }

    // ─── MATERAI type ───────────────────────────────────────────────────

    public function testMateraiMarkerStructure(): void
    {
        $el = new FloatingElement('m1', 'materai', 200, 300, 'front');
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('data-materai="m1"', $result);
        $this->assertStringContainsString('data-pos-x="200"', $result);
        $this->assertStringContainsString('data-pos-y="300"', $result);
        $this->assertStringContainsString('materai-placeholder', $result);
        $this->assertStringContainsString('[Materai: m1]', $result);
    }

    // ─── Multiple elements ─────────────────────────────────────────────

    public function testMultipleElementsAppendedInOrder(): void
    {
        $logo = new FloatingElement('logo1', 'logo', 100, 100, 'front', '80px');
        $qr   = new FloatingElement('qr1', 'qr', 500, 500, 'front', '60px');
        $ttd  = new FloatingElement('ttd1', 'ttd', 600, 800, 'front');

        $result = FloatingInjector::inject('<p>Content</p>', [$logo, $qr, $ttd]);

        // Original HTML preserved at start
        $this->assertStringStartsWith('<p>Content</p>', $result);

        // Appended order: logo → qr → ttd
        $logoPos = strpos($result, 'data-logo="logo1"');
        $qrPos   = strpos($result, 'data-qr="qr1"');
        $ttdPos  = strpos($result, 'data-ttd="ttd1"');

        $this->assertNotFalse($logoPos);
        $this->assertNotFalse($qrPos);
        $this->assertNotFalse($ttdPos);
        $this->assertLessThan($qrPos, $logoPos);
        $this->assertLessThan($ttdPos, $qrPos);
    }

    // ─── XSS protection ────────────────────────────────────────────────

    public function testIdWithSpecialCharsEscaped(): void
    {
        // Malicious id attempt — should be escaped, not injected as-is
        $el = new FloatingElement('"><script>alert(1)</script>', 'logo', 0, 0, 'front', '80px');
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testLabelWithSpecialCharsEscaped(): void
    {
        $el = new FloatingElement(
            'ttd1',
            'ttd',
            0,
            0,
            'front',
            null,
            ['label' => 'Dr. <b>House</b> "MD"']
        );
        $result = FloatingInjector::inject('', [$el]);

        $this->assertStringNotContainsString('<b>House</b>', $result);
        $this->assertStringContainsString('&lt;b&gt;', $result);
    }

    // ─── Round-trip with FloatingExtractor ─────────────────────────────

    public function testRoundTripPreservesData(): void
    {
        $original = new FloatingElement('logo1', 'logo', 400, 100, 'front', '80px');
        $html = FloatingInjector::inject('', [$original]);

        $extracted = FloatingExtractor::extract($html);

        $this->assertCount(1, $extracted['floating']);
        $rehydrated = $extracted['floating'][0];

        $this->assertSame($original->id, $rehydrated->id);
        $this->assertSame($original->type, $rehydrated->type);
        $this->assertSame($original->positionX, $rehydrated->positionX);
        $this->assertSame($original->positionY, $rehydrated->positionY);
        $this->assertSame($original->zIndex, $rehydrated->zIndex);
        $this->assertSame($original->width, $rehydrated->width);
    }

    public function testRoundTripMultipleTypes(): void
    {
        $originals = [
            new FloatingElement('logo1', 'logo', 100, 100, 'front', '80px'),
            new FloatingElement('qr1', 'qr', 500, 500, 'behind', '60px'),
            new FloatingElement('mat1', 'materai', 300, 700, 'front'),
        ];

        $html = FloatingInjector::inject('<p>Body</p>', $originals);
        $extracted = FloatingExtractor::extract($html);

        $this->assertCount(3, $extracted['floating']);

        // Index rehydrated by id for stable assertion
        $byId = [];
        foreach ($extracted['floating'] as $el) {
            $byId[$el->id] = $el;
        }

        $this->assertSame('logo', $byId['logo1']->type);
        $this->assertSame('qr', $byId['qr1']->type);
        $this->assertSame('materai', $byId['mat1']->type);
        $this->assertSame('behind', $byId['qr1']->zIndex);
    }
}
