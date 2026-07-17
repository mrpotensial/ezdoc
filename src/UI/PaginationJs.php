<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Shared virtual pagination JS — inject spacer divs at page boundaries so
 * content flow respects margin at EVERY physical page break, not just first
 * and last. Renders identically across designer editor, generate view, and
 * both print paths (browser Ctrl+P, dompdf PDF).
 *
 * ## Motivation
 *
 * CSS `.page { padding: padT padR padB padL }` applies element padding ONCE
 * (start + end of element). Browser paginator does NOT re-apply padding at
 * physical page breaks. Result: middle pages show content flush against
 * physical page edge (no padT/padB margin band).
 *
 * dompdf works karena its paginator custom re-apply `.page` padding per
 * physical break. Browser print + on-screen preview do not.
 *
 * ## Approach — DOM-injected spacers (industry pattern)
 *
 * Post-render JS measures `.content` children, injects transparent spacer
 * divs at each virtual page boundary. Spacer height = `padBottom + padTop`
 * (== visible gap between physical pages). Content after spacer appears at
 * next page's content area (padTop below physical page top).
 *
 * Because `.page { padding: padT/padB }` still gives correct padding on
 * FIRST page top and LAST page bottom, spacers only need to handle the
 * MIDDLE boundaries.
 *
 * ## Precedent
 * - **Notion print export** — DOM spacer injection at block boundaries
 * - **Confluence page-break macro** — `.page-break-spacer` divs
 * - **Craft.do print view** — spacer div pattern
 * - **LaTeX \pagebreak** — typesetter equivalent (gap insertion)
 * - **Paged.js** — internally uses same pattern with full spec-compliance
 *
 * ## Usage
 *
 * Generate view (embed in `<script>` after DOMContentLoaded):
 * ```php
 * <script>
 * <?= \Ezdoc\UI\PaginationJs::render(
 *     $paperDim['height'],
 *     $padTop,
 *     $padBottom
 * ) ?>
 * document.addEventListener('DOMContentLoaded', () => {
 *   EzdocPagination.paginate(document.querySelector('.content'));
 * });
 * </script>
 * ```
 *
 * Designer editor (TinyMCE `init_instance_callback` + `NodeChange`):
 * ```js
 * setup: (editor) => {
 *   const debounced = debounce(() => {
 *     EzdocPagination.paginate(editor.getBody().querySelector('.content'));
 *   }, 200);
 *   editor.on('NodeChange KeyUp Change', debounced);
 * }
 * ```
 *
 * ## Spacer serialization
 *
 * Spacers carry `data-mce-bogus="1"` → TinyMCE auto-strips on `getContent()`.
 * Non-editor contexts (generate view) can strip via `.pg-spacer` selector
 * before save/serialize.
 *
 * spec: docs/PAGINATION.md
 */
final class PaginationJs
{
    /**
     * Render pagination JS as string ready to embed inside `<script>` tag.
     *
     * @param float $paperHeightMm  Paper height (297 for A4 portrait, 210 landscape)
     * @param float $padTopMm       Top margin (typically 20)
     * @param float $padBottomMm    Bottom margin (typically 20)
     * @return string JS body (caller wraps in `<script>...</script>`)
     */
    public static function render(
        float $paperHeightMm,
        float $padTopMm,
        float $padBottomMm
    ): string {
        $paperH = json_encode($paperHeightMm);
        $padT   = json_encode($padTopMm);
        $padB   = json_encode($padBottomMm);

        return <<<JS
/* ─── Ezdoc virtual pagination (Ezdoc\\UI\\PaginationJs) ─── */
(function() {
    if (window.EzdocPagination) return;

    const CONFIG = {
        paperHeightMm: {$paperH},
        padTopMm: {$padT},
        padBottomMm: {$padB},
        spacerClass: 'pg-spacer'
    };

    /* mm → px via runtime measurement — handles browser zoom, DPR, high-DPI
       displays, print media units correctly. Cheaper than JS math with hard-
       coded DPI (which browsers vary). */
    function mmToPx(mm, doc) {
        doc = doc || document;
        const probe = doc.createElement('div');
        probe.style.cssText = 'position:absolute;visibility:hidden;height:' + mm + 'mm;top:-9999px;left:0';
        doc.body.appendChild(probe);
        const px = probe.offsetHeight;
        probe.remove();
        return px;
    }

    /* Tag names treated as recursive containers — if oversized (>1 page tall),
       iterate INTO them instead of pushing whole element to next page. Common
       cases: long ordered/unordered list, large table body, generic wrapper divs. */
    const RECURSIVE_TAGS = ['ol', 'ul', 'div', 'section', 'article', 'main', 'nav', 'blockquote', 'figure', 'tbody', 'table'];

    /* Collect leaf paginatable items from container tree — recurse into
       oversized recursive containers, treat everything else as atomic unit. */
    function collectItems(container, pageContentPx) {
        const items = [];
        for (let i = 0; i < container.children.length; i++) {
            const child = container.children[i];
            if (child.classList && child.classList.contains(CONFIG.spacerClass)) continue;
            const tag = (child.tagName || '').toLowerCase();
            const rect = child.getBoundingClientRect();
            /* Recurse only when: (a) it's a recognized container tag, AND
               (b) it's oversized (won't fit in one virtual page). Small
               containers stay atomic so browsers can keep them on one page. */
            if (RECURSIVE_TAGS.indexOf(tag) !== -1 && rect.height > pageContentPx) {
                const nested = collectItems(child, pageContentPx);
                for (let j = 0; j < nested.length; j++) items.push(nested[j]);
            } else {
                items.push(child);
            }
        }
        return items;
    }

    /* Inject virtual page break spacers into container. Container is typically
       .content div inside .page (generate view) OR TinyMCE editor body
       (designer). Measurement uses getBoundingClientRect for cross-context
       robustness — independent of container position property or offsetParent
       chain complications. */
    function paginate(container) {
        if (!container) return;
        const doc = container.ownerDocument || document;
        const pageContentPx = mmToPx(CONFIG.paperHeightMm, doc)
            - mmToPx(CONFIG.padTopMm, doc)
            - mmToPx(CONFIG.padBottomMm, doc);
        const gapPx = mmToPx(CONFIG.padTopMm, doc) + mmToPx(CONFIG.padBottomMm, doc);
        if (pageContentPx <= 0) return;

        /* Idempotent — strip existing spacers before re-measuring. */
        container.querySelectorAll('.' + CONFIG.spacerClass).forEach(function(s) { s.remove(); });

        const containerRect = container.getBoundingClientRect();
        /* Account for container's own padding-top — when paginate() called
           with a container that has its own top padding (e.g. designer's
           TinyMCE editor body which has padding: padT applied inline),
           children.top is measured from container border-box top (= padTop
           below content area top). Add container padding-top to boundary so
           spacer lands at correct physical page break.
           For generate.php's .content (no own padding), containerPadTopPx=0
           and boundary reduces to pageContentPx (original formula). */
        const cStyle = (doc.defaultView || window).getComputedStyle(container);
        const containerPadTopPx = parseFloat(cStyle.paddingTop) || 0;
        let boundary = pageContentPx + containerPadTopPx;

        /* Flatten tree — recurse into oversized containers so pagination
           happens at LI/TR/nested-block level instead of pushing whole
           oversized parent to next page (that would empty page 1). */
        const items = collectItems(container, pageContentPx);

        for (let i = 0; i < items.length; i++) {
            const node = items[i];
            const rect = node.getBoundingClientRect();
            const top = rect.top - containerRect.top;
            const bottom = rect.bottom - containerRect.top;

            /* Skip zero-height elements (like empty <p> collapsed to 0 by
               .floating-only rule). */
            if (rect.height <= 0) continue;

            /* Element entirely past current boundary — advance boundary(ies).
               Safeguard against infinite loop with max iterations. */
            let guard = 0;
            while (top >= boundary && guard++ < 1000) {
                boundary += pageContentPx + gapPx;
            }

            if (bottom > boundary && top < boundary) {
                /* Oversized atomic (bigger than one page) — cannot be pushed
                   cleanly. Skip spacer, advance boundary past element. Browser
                   natural-break will handle split. Middle-page margin bug
                   persists for this specific element, but no regression
                   (empty page 1) either. */
                if (rect.height > pageContentPx) {
                    while (bottom > boundary && guard++ < 2000) {
                        boundary += pageContentPx + gapPx;
                    }
                    continue;
                }

                /* Element fits in one page — push to next virtual page content
                   area via spacer. Spacer height = remaining space on current
                   page + gap between pages. Spacer inserted as sibling BEFORE
                   node (works for elements at any nesting depth). */
                const spacerH = boundary - top + gapPx;
                if (spacerH > 0 && node.parentNode) {
                    const spacer = doc.createElement('div');
                    /* Dual class:
                       - pg-spacer: our marker (styling + JS query)
                       - mceNonEditable: TinyMCE built-in protected class
                         (matches editor's noneditable_class config). TinyMCE
                         akan block cursor entry, block selection, block delete
                         via Backspace/Delete → MS Word-style protected auto
                         page break. */
                    spacer.className = CONFIG.spacerClass + ' mceNonEditable';
                    spacer.style.cssText = 'display:block;width:100%;background:transparent;pointer-events:none;user-select:none;box-sizing:border-box;height:' + spacerH + 'px';
                    spacer.contentEditable = 'false';
                    /* TinyMCE bogus marker — element stripped on editor.getContent()
                       serialization. Non-editor contexts ignore this attribute.
                       Belt-and-suspenders with mceNonEditable class supaya
                       spacer NEVER makes it into saved HTML content column. */
                    spacer.setAttribute('data-mce-bogus', '1');
                    /* Explicit ARIA hidden — assistive tech skips spacer
                       (bukan konten, cuma visual layout gap). */
                    spacer.setAttribute('aria-hidden', 'true');
                    node.parentNode.insertBefore(spacer, node);
                }
                boundary += pageContentPx + gapPx;
            }
        }
    }

    /* Debounced variant — for editor NodeChange / KeyUp events where
       re-paginate on every keystroke would jank. Default 200ms window. */
    function debounced(container, wait) {
        wait = wait || 200;
        let timer = null;
        return function() {
            clearTimeout(timer);
            timer = setTimeout(function() { paginate(container); }, wait);
        };
    }

    /* Strip spacers from HTML string — for non-editor serialization paths
       (e.g. generate view saving field data via form). Editor path uses
       TinyMCE bogus stripping automatically. */
    function stripSpacers(html) {
        return String(html).replace(
            /<div[^>]*class=(["'])[^"']*\\bpg-spacer\\b[^"']*\\1[^>]*>[\\s\\S]*?<\\/div>/gi,
            ''
        );
    }

    window.EzdocPagination = {
        paginate: paginate,
        debounced: debounced,
        stripSpacers: stripSpacers,
        mmToPx: mmToPx,
        config: CONFIG
    };
})();
JS;
    }

    /**
     * Render companion CSS untuk .pg-spacer element. Kept minimal — actual
     * height set inline by JS. This class ensures no unexpected styling
     * inherited from surrounding content (list-style, borders, backgrounds).
     */
    public static function renderCss(): string
    {
        return <<<'CSS'
/* ─── Ezdoc virtual pagination spacer (Ezdoc\UI\PaginationJs) ─── */
.pg-spacer {
    display: block !important;
    width: 100% !important;
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    margin: 0 !important;
    list-style: none !important;
    pointer-events: none !important;
    user-select: none !important;
    box-sizing: border-box !important;
}
/* Hint browser paginator: prefer breaking AT spacer (both sides ok since
   spacer straddles physical page boundary in our JS logic). */
.pg-spacer {
    break-inside: avoid;
    page-break-inside: avoid;
}
CSS;
    }
}
