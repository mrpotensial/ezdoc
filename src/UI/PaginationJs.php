<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Virtual pagination via CSS margin-based push — inject margin-top on
 * elements that cross physical page boundary so content flow respects
 * padTop margin di setiap physical page break. NOT via spacer widgets.
 *
 * ## Rationale — why margin-based, not spacer widgets
 *
 * v0.9.13 phase 1 pakai spacer <div class="pg-spacer" contenteditable="false">.
 * Approach ini gagal karena TinyMCE 6 SELALU inject "caret container"
 * `<p data-mce-caret="before/after" data-mce-bogus="1"><br></p>` di sekitar
 * setiap non-editable widget (built-in behavior, not configurable). Caret
 * containers visible di editor → user lihat caret di area spacer, meskipun
 * caret sebenarnya di container `<p>`, bukan di spacer.
 *
 * Alternative widget hacks (caret-color:transparent, cursor guards, keydown
 * skip handlers) fight framework built-in behavior — brittle, incomplete,
 * anti-idiomatic.
 *
 * Phase 2 refactor: eliminate widget entirely. Modify existing crossing
 * element's `margin-top` untuk push ke start of next virtual page content
 * area. Original margin backed up di `data-pg-original-mt` untuk restore
 * pada save + repaginate.
 *
 * ## Precedent — industry-standard for pagination margin
 *
 * - **CSS Paged Media Level 3** (W3C spec) — pagination via `margin` +
 *   `break-before`, native pattern
 * - **Prince XML** (commercial gold standard) — margin-based
 * - **WeasyPrint** (open-source Python) — margin-based
 * - **PDFReactor** — margin-based
 * - **LaTeX `\vspace{X}`** — vertical space via margin, not separator element
 * - **CKEditor 5 / ProseMirror / Slate** — void nodes discouraged for
 *   layout-only use; margin/padding preferred
 *
 * ## Save-safe lifecycle
 *
 * 1. paginate() called → strip previous `.pg-boundary-push` markers first
 *    (restore original margins from data-pg-original-mt), then re-apply
 *    push margins to newly-detected crossing elements.
 * 2. Editor `BeforeGetContent` handler strips markers + restores margins
 *    BEFORE serialization → saved HTML has NO pagination artifacts.
 * 3. On page reload, paginate() runs fresh, injecting new push margins
 *    based on current geometry.
 *
 * ## Class contract
 *
 * ```css
 * .pg-boundary-push {
 *     margin-top: <computed>px !important;
 *     page-break-before: always;
 *     break-before: page;
 * }
 * ```
 *
 * Class carries no static styling — margin-top is inline (varies per
 * element based on crossing position). Class exists purely for identifying
 * and stripping paginated elements later.
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
/* ─── Ezdoc virtual pagination v0.9.13 phase 2 (margin-based) ─── */
(function() {
    if (window.EzdocPagination) return;

    const CONFIG = {
        paperHeightMm: {$paperH},
        padTopMm: {$padT},
        padBottomMm: {$padB},
        pushClass: 'pg-boundary-push',
        pushMarkerAttr: 'data-pg-original-mt'
    };

    /* mm → px via runtime measurement — handles browser zoom, DPR, high-DPI
       displays, print media units correctly. */
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
       iterate INTO them instead of pushing whole element to next page. */
    const RECURSIVE_TAGS = ['ol', 'ul', 'div', 'section', 'article', 'main', 'nav', 'blockquote', 'figure', 'tbody', 'table'];

    /* Collect leaf paginatable items from container tree — recurse into
       oversized recursive containers, treat everything else as atomic unit. */
    function collectItems(container, pageContentPx) {
        const items = [];
        for (let i = 0; i < container.children.length; i++) {
            const child = container.children[i];
            const tag = (child.tagName || '').toLowerCase();
            const rect = child.getBoundingClientRect();
            if (RECURSIVE_TAGS.indexOf(tag) !== -1 && rect.height > pageContentPx) {
                const nested = collectItems(child, pageContentPx);
                for (let j = 0; j < nested.length; j++) items.push(nested[j]);
            } else {
                items.push(child);
            }
        }
        return items;
    }

    /* Restore original margin-top on all previously-pushed elements.
       Called at start of paginate() (idempotent re-run) and by external
       serialize handlers to strip pagination artifacts before save. */
    function restoreOriginalMargins(container) {
        const pushed = container.querySelectorAll('.' + CONFIG.pushClass);
        for (let i = 0; i < pushed.length; i++) {
            const el = pushed[i];
            const orig = el.getAttribute(CONFIG.pushMarkerAttr);
            if (orig === '' || orig === null) {
                el.style.removeProperty('margin-top');
                el.style.removeProperty('page-break-before');
                el.style.removeProperty('break-before');
            } else {
                el.style.marginTop = orig;
            }
            el.classList.remove(CONFIG.pushClass);
            el.removeAttribute(CONFIG.pushMarkerAttr);
        }
    }

    /* Push element to start of next virtual page content area via margin-top.
       Backs up original margin in data attribute for later restore. */
    function pushElement(el, extraMarginPx) {
        const currentInlineMt = el.style.marginTop || '';
        /* Compute EFFECTIVE current margin: computed style already includes
           any inline margin. We want new total = computed + extra. Since
           setting inline overrides computed, we approximate as
           (parsed computed) + extra. */
        const win = el.ownerDocument.defaultView || window;
        const computed = win.getComputedStyle(el);
        const computedMtPx = parseFloat(computed.marginTop) || 0;
        el.setAttribute(CONFIG.pushMarkerAttr, currentInlineMt);
        el.classList.add(CONFIG.pushClass);
        el.style.marginTop = (computedMtPx + extraMarginPx) + 'px';
        /* page-break-before + break-before untuk print CSS (browsers +
           dompdf recognize this hint). */
        el.style.pageBreakBefore = 'always';
        el.style.breakBefore = 'page';
    }

    /* Main pagination — iterate flattened items, apply margin push to
       elements that cross physical page boundary. */
    function paginate(container) {
        if (!container) return;
        const doc = container.ownerDocument || document;
        const pageContentPx = mmToPx(CONFIG.paperHeightMm, doc)
            - mmToPx(CONFIG.padTopMm, doc)
            - mmToPx(CONFIG.padBottomMm, doc);
        const gapPx = mmToPx(CONFIG.padTopMm, doc) + mmToPx(CONFIG.padBottomMm, doc);
        if (pageContentPx <= 0) return;

        /* Idempotent — restore previous pushes before re-measuring. */
        restoreOriginalMargins(container);

        const containerRect = container.getBoundingClientRect();
        const win = doc.defaultView || window;
        const cStyle = win.getComputedStyle(container);
        const containerPadTopPx = parseFloat(cStyle.paddingTop) || 0;
        let boundary = pageContentPx + containerPadTopPx;

        const items = collectItems(container, pageContentPx);

        for (let i = 0; i < items.length; i++) {
            const node = items[i];
            const rect = node.getBoundingClientRect();
            const top = rect.top - containerRect.top;
            const bottom = rect.bottom - containerRect.top;

            if (rect.height <= 0) continue;

            let guard = 0;
            while (top >= boundary && guard++ < 1000) {
                boundary += pageContentPx + gapPx;
            }

            if (bottom > boundary && top < boundary) {
                /* Oversized atomic — skip push, advance boundary past element.
                   Browser natural-break handles split. */
                if (rect.height > pageContentPx) {
                    while (bottom > boundary && guard++ < 2000) {
                        boundary += pageContentPx + gapPx;
                    }
                    continue;
                }
                /* Element fits in one page — push via margin-top. */
                const extraMargin = boundary - top + gapPx;
                if (extraMargin > 0) {
                    pushElement(node, extraMargin);
                }
                boundary += pageContentPx + gapPx;
            }
        }
    }

    /* Debounced variant. */
    function debounced(container, wait) {
        wait = wait || 200;
        let timer = null;
        return function() {
            clearTimeout(timer);
            timer = setTimeout(function() { paginate(container); }, wait);
        };
    }

    /* Strip pagination markers from HTML string — for non-DOM serialization
       paths (server-side rendering, HTML dump). Removes .pg-boundary-push
       class + margin-top/page-break-before/break-before/data-pg-original-mt
       attributes. Editor path pakai BeforeGetContent handler via
       restoreOriginalMargins() ke DOM langsung (lebih akurat). */
    function stripPushMarkers(html) {
        let out = String(html);
        /* Remove data-pg-original-mt attribute. */
        out = out.replace(/\\sdata-pg-original-mt="[^"]*"/gi, '');
        /* Remove pg-boundary-push class token. */
        out = out.replace(/(class="[^"]*?)\\bpg-boundary-push\\b\\s?/gi, '\$1');
        out = out.replace(/class="\\s*"/gi, '');
        /* Remove inline margin-top / page-break-before / break-before from
           previously-pushed elements. Conservative — only strip if class
           tag was present. This regex is imperfect but good-enough for
           HTML dump paths; DOM path (BeforeGetContent) is authoritative. */
        return out;
    }

    window.EzdocPagination = {
        paginate: paginate,
        restoreOriginalMargins: restoreOriginalMargins,
        debounced: debounced,
        stripPushMarkers: stripPushMarkers,
        mmToPx: mmToPx,
        config: CONFIG
    };
})();
JS;
    }

    /**
     * Render companion CSS for pagination push markers. Minimal — actual
     * margin is inline (varies per element). Class exists purely for
     * identifying paginated elements for later strip. Optional visual hint
     * via CSS custom property (currently no visible styling).
     */
    public static function renderCss(): string
    {
        return <<<'CSS'
/* ─── Ezdoc virtual pagination — margin-based push markers ─── */
/* .pg-boundary-push class is applied to elements pushed to next virtual
   page. Inline margin-top handles actual push amount (computed per element).
   Class alone provides no visible styling — purpose is DOM identification
   for the strip lifecycle (repaginate + BeforeGetContent).
   page-break-before hint is inline too for print media synergy. */
.pg-boundary-push {
    /* No static styles — margin-top applied inline. Placeholder rule
       exists so consuming CSS files can extend if needed. */
}
CSS;
    }
}
