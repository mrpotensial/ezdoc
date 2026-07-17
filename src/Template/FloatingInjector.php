<?php

declare(strict_types=1);

namespace Ezdoc\Template;

/**
 * Rehydrate floating element sidecar metadata → HTML markers untuk rendering.
 *
 * Companion service to {@see FloatingExtractor}. Extractor strips markers dari
 * HTML → JSON. Injector reverse: JSON → HTML markers appended ke content.
 *
 * ## Use cases
 *
 * 1. **Legacy consumer backward-compat**: consumer app yang belum migrate ke
 *    sidecar-aware rendering tetap dapat HTML dgn floating markers embedded.
 * 2. **Designer editor round-trip**: editor pakai HTML representation, inject
 *    sidebar → HTML for TinyMCE input.
 * 3. **PDF generation dgn markers**: dompdf renders inline HTML markers.
 *
 * ## Round-trip contract
 *
 * `inject(extract($html)['html'], extract($html)['floating'])` produces HTML
 * yang semantically equivalent dgn original (module minor whitespace normalization).
 *
 * spec: docs/FLOATING-ELEMENTS.md
 */
final class FloatingInjector
{
    /**
     * Inject floating elements back into HTML sebagai markers.
     *
     * Markers wrapped dalam `<p class="floating-only" contenteditable="false">`
     * widget wrapper (CKEditor 5 non-editable atomic block pattern).
     * Appended ke HTML end supaya tidak interfere dgn text flow.
     *
     * @param string            $html     Content HTML (no floating markers)
     * @param FloatingElement[] $floating Sidecar floating elements
     */
    public static function inject(string $html, array $floating): string
    {
        if (empty($floating)) {
            return $html;
        }

        $markers = '';
        foreach ($floating as $el) {
            $markers .= self::renderMarker($el);
        }

        return $html . $markers;
    }

    /**
     * Render single floating element as HTML marker (widget wrapper).
     */
    private static function renderMarker(FloatingElement $el): string
    {
        $classes = self::classesFor($el);
        $style = sprintf('top: %dpx; left: %dpx;', $el->positionY, $el->positionX);
        $posAttrs = sprintf(
            'data-pos-mode="%s" data-pos-x="%d" data-pos-y="%d"',
            $el->zIndex,
            $el->positionX,
            $el->positionY
        );

        switch ($el->type) {
            case FloatingElement::TYPE_LOGO:
                $inner = sprintf(
                    '<span class="%s" data-logo="%s" data-width="%s" %s style="%s" contenteditable="false">[Logo: %s]</span>',
                    self::h($classes),
                    self::h($el->id),
                    self::h($el->width),
                    $posAttrs,
                    self::h($style),
                    self::h($el->id)
                );
                break;

            case FloatingElement::TYPE_QR:
                $inner = sprintf(
                    '<span class="%s" data-qr="%s" data-width="%s" %s style="%s" contenteditable="false">[QR: %s]</span>',
                    self::h($classes),
                    self::h($el->id),
                    self::h($el->width),
                    $posAttrs,
                    self::h($style),
                    self::h($el->id)
                );
                break;

            case FloatingElement::TYPE_TTD:
                $label = $el->data['label'] ?? 'Signature';
                $namaField = $el->data['nama_field'] ?? 'nama_' . $el->id;
                $ttdModes = $el->data['ttd_modes'] ?? 'image';
                $inner = sprintf(
                    '<div class="%s" data-ttd="%s" data-label="%s" data-nama-field="%s" data-ttd-modes="%s" %s style="%s" contenteditable="false">' .
                    '<div style="font-size:12pt;margin-bottom:5px;">%s</div>' .
                    '<div style="width:100px;height:50px;border:1px dashed #10b981;background:#ecfdf5;margin:0 auto;"></div>' .
                    '<div style="font-size:11pt;margin-top:3px;">(..................)</div>' .
                    '</div>',
                    self::h($classes),
                    self::h($el->id),
                    self::h($label),
                    self::h($namaField),
                    self::h($ttdModes),
                    $posAttrs,
                    self::h($style),
                    self::h($label)
                );
                break;

            case FloatingElement::TYPE_MATERAI:
                $inner = sprintf(
                    '<div class="%s" data-materai="%s" %s style="%s" contenteditable="false">[Materai: %s]</div>',
                    self::h($classes),
                    self::h($el->id),
                    $posAttrs,
                    self::h($style),
                    self::h($el->id)
                );
                break;

            default:
                return '';
        }

        // Wrap dalam widget wrapper (CKEditor 5 pattern)
        return sprintf(
            '<p class="floating-only" contenteditable="false">%s</p>',
            $inner
        );
    }

    /**
     * Build CSS classes list for floating element.
     */
    private static function classesFor(FloatingElement $el): string
    {
        $base = $el->type . '-placeholder';
        $zClass = $el->zIndex === FloatingElement::Z_BEHIND ? 'behind' : 'front';
        return sprintf('%s floating %s', $base, $zClass);
    }

    /**
     * htmlspecialchars shortcut.
     */
    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
