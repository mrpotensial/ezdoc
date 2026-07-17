<?php

declare(strict_types=1);

namespace Ezdoc\Template;

/**
 * Extract floating element markers dari HTML content ke sidecar metadata.
 *
 * Regex-based extraction (mirroring generate.php's renderContent() detection
 * patterns). Returns cleaned HTML + array of {@see FloatingElement} instances.
 *
 * ## Round-trip contract
 *
 * ```php
 * $result = FloatingExtractor::extract($htmlWithMarkers);
 * // $result['html'] = HTML dgn floating markers stripped
 * // $result['floating'] = FloatingElement[]
 *
 * $rehydrated = FloatingInjector::inject($result['html'], $result['floating']);
 * // $rehydrated should equal $htmlWithMarkers (module trivial whitespace)
 * ```
 *
 * ## Detected patterns
 *
 * - `<span class="logo-placeholder floating [front|behind]" data-logo="X"
 *   data-width="Y" data-pos-x="Z" data-pos-y="W">...</span>`
 * - `<span class="qr-placeholder floating ...">` (same shape as logo)
 * - `<div class="ttd-placeholder floating ..." data-ttd="X" data-pos-x=""
 *   data-pos-y="" ...>...</div>`
 * - `<div class="materai-placeholder floating ...">` (same shape as ttd)
 *
 * ## Backward-compat
 *
 * Handles both:
 * 1. Legacy: markers embedded langsung di HTML (no wrapper `<p>`)
 * 2. Widget-wrapper (v0.9.12 phase 1): `<p class="floating-only"
 *    contenteditable="false"><marker/></p>` — extractor strips outer wrapper too.
 *
 * spec: docs/FLOATING-ELEMENTS.md
 */
final class FloatingExtractor
{
    /**
     * Extract floating markers dari HTML content.
     *
     * @param string $html Full HTML template content
     * @return array{html: string, floating: FloatingElement[]}
     */
    public static function extract(string $html): array
    {
        $floating = [];

        // Extract logo/qr floating spans
        $html = preg_replace_callback(
            '/<span([^>]*class="[^"]*(?:logo|qr)-placeholder[^"]*floating[^"]*"[^>]*)>.*?<\/span>/is',
            function ($match) use (&$floating) {
                $element = self::parseSpan($match[1]);
                if ($element !== null) {
                    $floating[] = $element;
                    return ''; // strip
                }
                return $match[0]; // keep if parse failed
            },
            $html
        );

        // Extract ttd/materai floating divs
        $html = preg_replace_callback(
            '/<div([^>]*class="[^"]*(?:ttd|materai)-placeholder[^"]*floating[^"]*"[^>]*)>.*?<\/div>\s*(<\/div>|<\/p>)?/is',
            function ($match) use (&$floating) {
                $element = self::parseDiv($match[1]);
                if ($element !== null) {
                    $floating[] = $element;
                    return isset($match[2]) ? $match[2] : ''; // preserve closing tag if present
                }
                return $match[0];
            },
            $html
        );

        // Cleanup: strip empty widget wrappers `<p class="floating-only" contenteditable="false"></p>`
        $html = preg_replace(
            '/<p[^>]*class="[^"]*floating-only[^"]*"[^>]*>\s*<\/p>/i',
            '',
            $html
        );

        return [
            'html' => $html,
            'floating' => $floating,
        ];
    }

    /**
     * Parse floating span (logo/qr) attributes → FloatingElement.
     */
    private static function parseSpan(string $attrs): ?FloatingElement
    {
        $classes = self::extractAttr($attrs, 'class') ?: '';

        // Determine type (logo vs qr)
        if (strpos($classes, 'logo-placeholder') !== false) {
            $type = FloatingElement::TYPE_LOGO;
            $id = self::extractAttr($attrs, 'data-logo');
        } elseif (strpos($classes, 'qr-placeholder') !== false) {
            $type = FloatingElement::TYPE_QR;
            $id = self::extractAttr($attrs, 'data-qr');
        } else {
            return null;
        }

        if ($id === null || $id === '') {
            return null;
        }

        return new FloatingElement(
            $id,
            $type,
            (int) (self::extractAttr($attrs, 'data-pos-x') ?? 0),
            (int) (self::extractAttr($attrs, 'data-pos-y') ?? 0),
            self::zIndexFromClasses($classes),
            self::extractAttr($attrs, 'data-width') ?? '80px',
            [] // spans don't have additional properties for logo/qr
        );
    }

    /**
     * Parse floating div (ttd/materai) attributes → FloatingElement.
     */
    private static function parseDiv(string $attrs): ?FloatingElement
    {
        $classes = self::extractAttr($attrs, 'class') ?: '';

        if (strpos($classes, 'ttd-placeholder') !== false) {
            $type = FloatingElement::TYPE_TTD;
            $id = self::extractAttr($attrs, 'data-ttd');
        } elseif (strpos($classes, 'materai-placeholder') !== false) {
            $type = FloatingElement::TYPE_MATERAI;
            $id = self::extractAttr($attrs, 'data-materai');
        } else {
            return null;
        }

        if ($id === null || $id === '') {
            return null;
        }

        // TTD has additional data attributes (label, nama_field, dst)
        $data = [];
        if ($type === FloatingElement::TYPE_TTD) {
            $data['label'] = self::extractAttr($attrs, 'data-label') ?? '';
            $data['nama_field'] = self::extractAttr($attrs, 'data-nama-field') ?? '';
            $data['ttd_modes'] = self::extractAttr($attrs, 'data-ttd-modes') ?? 'image';
        }

        return new FloatingElement(
            $id,
            $type,
            (int) (self::extractAttr($attrs, 'data-pos-x') ?? 0),
            (int) (self::extractAttr($attrs, 'data-pos-y') ?? 0),
            self::zIndexFromClasses($classes),
            self::extractAttr($attrs, 'data-width') ?? '120px',
            $data
        );
    }

    /**
     * Extract attribute value from HTML tag attributes string.
     */
    private static function extractAttr(string $attrs, string $name): ?string
    {
        if (preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $attrs, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        return null;
    }

    /**
     * Determine z-index from CSS classes list.
     */
    private static function zIndexFromClasses(string $classes): string
    {
        if (strpos($classes, 'behind') !== false) {
            return FloatingElement::Z_BEHIND;
        }
        return FloatingElement::Z_FRONT;
    }

    /**
     * Serialize FloatingElement array ke JSON string.
     *
     * @param FloatingElement[] $floating
     */
    public static function toJson(array $floating): string
    {
        $data = array_map(function (FloatingElement $el) {
            return $el->toArray();
        }, $floating);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Deserialize dari JSON string ke FloatingElement array.
     *
     * @return FloatingElement[]
     */
    public static function fromJson(?string $json): array
    {
        if ($json === null || $json === '' || $json === 'null') {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                try {
                    $out[] = FloatingElement::fromArray($row);
                } catch (\Throwable $e) {
                    // skip malformed rows
                    continue;
                }
            }
        }

        return $out;
    }
}
