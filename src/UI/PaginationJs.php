<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Virtual pagination via DOM split — restructures content into multiple
 * `.page` divs based on measured content overflow. Each `.page` renders
 * as separate paper card with own padding and shadow (Google Docs UX).
 *
 * ## Rationale
 *
 * v0.9.13 phase 1-3 tried margin-based push approach. All variants had
 * fundamental problems: variable gap (block-level cascade), TinyMCE widget
 * caret issues, or content splitting through margin band. User's insight:
 * PDF Raw (dompdf) is consistent because it PAGINATES content into separate
 * physical pages via @page margin. No push.
 *
 * Phase 4 refactor: split content into multiple `.page` divs client-side.
 * Same result as PDF Raw but for screen visual. Each `.page` is atomic
 * paper card. Body gray background shows through as gap between cards.
 *
 * ## Precedent
 *
 * - **Google Docs** — pages rendered as separate card elements dgn gray gap
 * - **Notion** — page cards for exported view
 * - **Paged.js polyfill** — same pattern (fragmented pages), full spec-compliance
 * - **Vivliostyle** — spec-compliant page fragmentation
 *
 * ## API
 *
 * ```js
 * window.EzdocPagination.split(pageEl, paperHmm, padTopMm, padBottomMm);
 * ```
 *
 * Splits `pageEl.content` children across multiple cloned `pageEl` divs
 * based on measured overflow. Preserves DOM node identity (event listeners
 * intact when items moved).
 */
final class PaginationJs
{
    /**
     * Render pagination JS as string ready to embed inside `<script>` tag.
     *
     * @param float $paperHeightMm  Paper height (297 for A4 portrait)
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
/* ─── Ezdoc virtual pagination v0.9.13 phase 4 (DOM split) ─── */
(function() {
    if (window.EzdocPagination) return;

    const CONFIG = {
        paperHeightMm: {$paperH},
        padTopMm: {$padT},
        padBottomMm: {$padB},
        wrapperClass: 'ezdoc-paper-wrapper',
        pageMarker: 'data-pg-multi'
    };

    /* mm → px via runtime measurement — handles browser zoom, DPR. */
    function mmToPx(mm, doc) {
        doc = doc || document;
        const probe = doc.createElement('div');
        probe.style.cssText = 'position:absolute;visibility:hidden;height:' + mm + 'mm;top:-9999px;left:0';
        (doc.documentElement || doc.body).appendChild(probe);
        const px = probe.offsetHeight;
        probe.remove();
        return px;
    }

    /* Split content di dalam originalPage.querySelector('.content') across
       multiple cloned page divs. Original .page stays as first page (dgn
       original attributes + floating elements preserved). Subsequent pages
       are shallow clones dgn empty .content.
       Items moved (not copied) via appendChild — DOM listeners intact. */
    function split(originalPage) {
        if (!originalPage) return;
        /* Guard: skip kalau target adalah body/html — wrapper insertion into
           <html> fails (HierarchyRequestError). Multi-page approach requires
           an intermediate parent container (typically div). */
        const originalTag = originalPage.tagName ? originalPage.tagName.toLowerCase() : '';
        if (originalTag === 'body' || originalTag === 'html') return;
        if (!originalPage.parentNode) return;
        const parentTag = originalPage.parentNode.tagName
            ? originalPage.parentNode.tagName.toLowerCase() : '';
        if (parentTag === 'html') return;

        /* Idempotent: kalau sudah pernah di-split, restore dulu. */
        restore(originalPage);

        const doc = originalPage.ownerDocument || document;
        const originalContent = originalPage.querySelector('.content');
        if (!originalContent) return;

        const paperHpx = mmToPx(CONFIG.paperHeightMm, doc);
        const padTpx = mmToPx(CONFIG.padTopMm, doc);
        const padBpx = mmToPx(CONFIG.padBottomMm, doc);
        const contentHpx = paperHpx - padTpx - padBpx;
        if (contentHpx <= 0) return;

        /* Snapshot items sebelum manipulate DOM. */
        const items = [];
        for (let i = 0; i < originalContent.children.length; i++) {
            items.push(originalContent.children[i]);
        }
        if (items.length === 0) return;

        /* Empty originalContent — will be re-populated. */
        while (originalContent.firstChild) originalContent.removeChild(originalContent.firstChild);

        /* Create wrapper OR reuse if parent already has multi-page markers. */
        let wrapper = originalPage.parentNode;
        if (!wrapper || !wrapper.classList || !wrapper.classList.contains(CONFIG.wrapperClass)) {
            wrapper = doc.createElement('div');
            wrapper.className = CONFIG.wrapperClass;
            originalPage.parentNode.insertBefore(wrapper, originalPage);
            wrapper.appendChild(originalPage);
        }

        /* Mark original page as page 1. */
        originalPage.setAttribute(CONFIG.pageMarker, '1');

        let currentPage = originalPage;
        let currentContent = originalContent;
        let pageNumber = 1;

        /* paperHpx sudah dihitung di line ~108 (contentHpx setup) — reuse. */

        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            currentContent.appendChild(item);

            /* Direct overflow via offsetHeight — .page grew past paperH
               (min-height) = content overflowing. Simpler + more reliable
               dari coord math (avoids padTpx precision from mmToPx). */
            if (currentPage.offsetHeight > paperHpx && currentContent.children.length > 1) {
                currentContent.removeChild(item);

                pageNumber++;
                const newPage = originalPage.cloneNode(false);
                newPage.setAttribute(CONFIG.pageMarker, String(pageNumber));
                newPage.style.backgroundImage = 'none';
                const newContent = originalContent.cloneNode(false);
                if (newContent.id) newContent.removeAttribute('id');
                newPage.appendChild(newContent);
                wrapper.appendChild(newPage);

                currentPage = newPage;
                currentContent = newContent;
                currentContent.appendChild(item);
            }
        }

        /* Also hide background-image on original page — di multi-page mode,
           tiap .page adalah atomic card, tidak butuh page-break markers. */
        originalPage.style.backgroundImage = 'none';
    }

    /* Reverse split — collapse all `.page` divs back into original .page.
       Idempotent + safe untuk call before re-split. */
    function restore(originalPage) {
        if (!originalPage) return;
        const wrapper = originalPage.parentNode;
        if (!wrapper || !wrapper.classList || !wrapper.classList.contains(CONFIG.wrapperClass)) {
            return;
        }
        const originalContent = originalPage.querySelector('.content');
        if (!originalContent) return;
        /* Gather all subsequent .page's items into originalContent. */
        let sibling = originalPage.nextElementSibling;
        while (sibling) {
            const nextSibling = sibling.nextElementSibling;
            if (sibling.hasAttribute && sibling.hasAttribute(CONFIG.pageMarker)) {
                const siblingContent = sibling.querySelector('.content');
                if (siblingContent) {
                    while (siblingContent.firstChild) {
                        originalContent.appendChild(siblingContent.firstChild);
                    }
                }
                sibling.remove();
            }
            sibling = nextSibling;
        }
        originalPage.removeAttribute(CONFIG.pageMarker);
        originalPage.style.removeProperty('background-image');
        /* Unwrap: move originalPage back to wrapper's parent, remove wrapper. */
        const wrapperParent = wrapper.parentNode;
        if (wrapperParent) {
            wrapperParent.insertBefore(originalPage, wrapper);
            wrapper.remove();
        }
    }

    /* Debounced split — for content changes (typing, image load, resize). */
    function debounced(originalPage, wait) {
        wait = wait || 200;
        let timer = null;
        return function() {
            clearTimeout(timer);
            timer = setTimeout(function() { split(originalPage); }, wait);
        };
    }

    /* Legacy API — paginate() historically applied push margins. Phase 4
       swap ke split(). Alias for backward compat: paginate(container) calls
       split(container.parentNode) if container is .content, else split(container).
       IMPORTANT: skip kalau container adalah <body> (mis. designer TinyMCE
       iframe body) — can't insert wrapper into <html> (which can only contain
       one <body>). Designer stays continuous view; gray band CSS provides
       visual page marker instead. */
    function paginate(container) {
        if (!container) return;
        const tag = container.tagName ? container.tagName.toLowerCase() : '';
        if (tag === 'body' || tag === 'html') return; // TinyMCE iframe body case — skip multi-page
        if (container.classList && container.classList.contains('content') && container.parentNode) {
            const pageEl = container.parentNode;
            const pageTag = pageEl.tagName ? pageEl.tagName.toLowerCase() : '';
            if (pageTag === 'body' || pageTag === 'html') return; // .content directly inside body
            split(pageEl);
        } else {
            split(container);
        }
    }

    /* Restore for legacy API too. Same guards. */
    function restoreOriginalMargins(container) {
        if (!container) return;
        const tag = container.tagName ? container.tagName.toLowerCase() : '';
        if (tag === 'body' || tag === 'html') return;
        if (container.classList && container.classList.contains('content') && container.parentNode) {
            const pageEl = container.parentNode;
            const pageTag = pageEl.tagName ? pageEl.tagName.toLowerCase() : '';
            if (pageTag === 'body' || pageTag === 'html') return;
            restore(pageEl);
        } else {
            restore(container);
        }
    }

    /* Editor-mode multi-page split — untuk TinyMCE iframe body (yg tidak
       bisa di-split via `split()` karena body/html restrictions).
       Wrap body's direct children ke multiple `.ezdoc-page-view` divs, tiap
       div = 1 visual paper card. Complementary dgn TinyMCE serializer node
       filter yg unwrap `.ezdoc-page-view` on getContent (registered per
       editor instance).
       Precedent: CKEditor 5 Pagination widget pattern, TinyMCE pagebreak
       plugin pattern (wrap DOM + node filter unwrap). */
    function splitEditor(body, paperHmm, padTmm, padBmm) {
        if (!body || !body.ownerDocument) return;
        const doc = body.ownerDocument;
        flattenEditor(body);

        const paperHpx = mmToPx(paperHmm, doc);
        if (paperHpx <= 0) return;

        /* Diagnostic — expose via console untuk verify paperHpx correct.
           User bisa check `EzdocPagination.lastSplitDiag` untuk debug. */
        window.EzdocPagination.lastSplitDiag = {
            paperHmm: paperHmm,
            padTmm: padTmm,
            padBmm: padBmm,
            paperHpx: paperHpx,
            timestamp: (function() { try { return new Date().toISOString(); } catch (e) { return ''; } })()
        };

        const items = [];
        for (let i = 0; i < body.children.length; i++) items.push(body.children[i]);
        if (items.length === 0) return;

        while (body.firstChild) body.removeChild(body.firstChild);

        body.classList.add('ezdoc-paginated');
        /* Force layout recalc — synchronous browser reflow triggered oleh
           membaca offsetHeight. Ensures .ezdoc-paginated CSS rules (min-height
           paperH, padding, etc.) fully computed sebelum measurement checks. */
        void body.offsetHeight;

        let currentPage = null;
        let pageNum = 0;

        function newPage() {
            pageNum++;
            const page = doc.createElement('div');
            page.className = 'ezdoc-page-view';
            page.setAttribute('data-ezdoc-page', String(pageNum));
            body.appendChild(page);
            /* Force layout after adding new page — ensures .ezdoc-page-view
               CSS (min-height, padding) applied before subsequent measurements. */
            void page.offsetHeight;
            currentPage = page;
        }

        /* Copy allowed attributes (id, class, style, start, type) dari
           original element ke clone target. Used untuk cloning OL/UL/TABLE
           saat split preserves list numbering + styling. */
        function copyElementAttrs(from, to) {
            const attrs = ['class', 'style', 'start', 'type', 'reversed', 'border', 'cellpadding', 'cellspacing'];
            for (let i = 0; i < attrs.length; i++) {
                const val = from.getAttribute(attrs[i]);
                if (val !== null) to.setAttribute(attrs[i], val);
            }
        }

        /* Split OL/UL across pages at LI boundaries.
           `list` is currently detached (removed from currentPage). Create
           incremental fresh clone containers, add LIs one-by-one, split
           on overflow. Preserves numbering via `start` attribute on OL. */
        function splitListAcrossPages(list) {
            const tag = list.tagName.toLowerCase();
            const isOl = tag === 'ol';
            const startAttr = isOl ? parseInt(list.getAttribute('start') || '1', 10) : 1;

            /* Snapshot LI children (filter non-LI script-supporting). */
            const lis = [];
            for (let i = 0; i < list.children.length; i++) {
                if (list.children[i].tagName.toLowerCase() === 'li') lis.push(list.children[i]);
            }

            /* Empty original list (will be reused as first-part). */
            while (list.firstChild) list.removeChild(list.firstChild);

            /* Add original list as first-part to currentPage. */
            currentPage.appendChild(list);
            let currentList = list;
            let currentStart = startAttr;
            let liCount = 0;

            for (let i = 0; i < lis.length; i++) {
                const li = lis[i];
                currentList.appendChild(li);
                liCount++;

                if (currentPage.offsetHeight > paperHpx && liCount > 1) {
                    /* Overflow — move this LI to new list in new page. */
                    currentList.removeChild(li);
                    liCount--;

                    newPage();
                    const newList = doc.createElement(tag);
                    copyElementAttrs(list, newList);
                    if (isOl) newList.setAttribute('start', String(currentStart + liCount));
                    currentPage.appendChild(newList);
                    currentList = newList;
                    currentStart = currentStart + liCount;
                    liCount = 0;

                    currentList.appendChild(li);
                    liCount++;
                }
            }
        }

        /* Split TABLE across pages at TR boundaries (TBODY level).
           `table` is currently detached. Preserves THEAD (repeats on each
           split page for readability). */
        function splitTableAcrossPages(table) {
            const doc2 = table.ownerDocument;
            /* Extract THEAD (repeatable) + collect TR from TBODY. */
            const thead = table.querySelector(':scope > thead');
            const tbody = table.querySelector(':scope > tbody');
            const rows = [];
            if (tbody) {
                for (let i = 0; i < tbody.children.length; i++) {
                    if (tbody.children[i].tagName.toLowerCase() === 'tr') rows.push(tbody.children[i]);
                }
                while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
            } else {
                /* Some tables have TR directly under TABLE without TBODY. */
                for (let i = 0; i < table.children.length; i++) {
                    if (table.children[i].tagName.toLowerCase() === 'tr') rows.push(table.children[i]);
                }
                for (let i = rows.length - 1; i >= 0; i--) table.removeChild(rows[i]);
            }

            currentPage.appendChild(table);
            let currentTable = table;
            let currentTbody = tbody || table;
            let rowCount = 0;

            for (let i = 0; i < rows.length; i++) {
                const tr = rows[i];
                currentTbody.appendChild(tr);
                rowCount++;

                if (currentPage.offsetHeight > paperHpx && rowCount > 1) {
                    currentTbody.removeChild(tr);
                    rowCount--;

                    newPage();
                    const newTable = doc2.createElement('table');
                    copyElementAttrs(table, newTable);
                    if (thead) newTable.appendChild(thead.cloneNode(true));
                    const newTbody = doc2.createElement('tbody');
                    newTable.appendChild(newTbody);
                    currentPage.appendChild(newTable);
                    currentTable = newTable;
                    currentTbody = newTbody;
                    rowCount = 0;

                    currentTbody.appendChild(tr);
                    rowCount++;
                }
            }
        }

        /* Add item to currentPage. Handle splittable containers (OL, UL, TABLE)
           by recursing into their children when overflow detected. */
        function addItem(item) {
            const tag = (item.tagName || '').toLowerCase();

            /* For splittable containers, first try adding whole. Kalau fits,
               done. Kalau overflow, remove and split at child boundaries. */
            currentPage.appendChild(item);
            if (currentPage.offsetHeight <= paperHpx) return;

            /* Overflow. */
            if (tag === 'ol' || tag === 'ul') {
                currentPage.removeChild(item);
                if (currentPage.children.length === 0) {
                    /* First on card, list ALONE overflows. Still split. */
                    splitListAcrossPages(item);
                } else {
                    /* Card has previous content. Try moving list to new page. */
                    newPage();
                    currentPage.appendChild(item);
                    if (currentPage.offsetHeight <= paperHpx) return; // fits on new page
                    /* Still overflows on new page. Split it. */
                    currentPage.removeChild(item);
                    splitListAcrossPages(item);
                }
            } else if (tag === 'table') {
                currentPage.removeChild(item);
                if (currentPage.children.length === 0) {
                    splitTableAcrossPages(item);
                } else {
                    newPage();
                    currentPage.appendChild(item);
                    if (currentPage.offsetHeight <= paperHpx) return;
                    currentPage.removeChild(item);
                    splitTableAcrossPages(item);
                }
            } else {
                /* Non-splittable element (paragraph, heading, div, etc.).
                   Move to new page if possible; accept overflow if alone. */
                if (currentPage.children.length > 1) {
                    currentPage.removeChild(item);
                    newPage();
                    currentPage.appendChild(item);
                }
                /* If still overflows on new page (oversized single element),
                   accept — no way to split arbitrary block. */
            }
        }

        newPage();
        for (let i = 0; i < items.length; i++) {
            addItem(items[i]);
        }
    }

    /* Unwrap all `.ezdoc-page-view` divs in body — promote their children
       directly to body, remove wrapper. Idempotent. Used sebelum re-split
       AND sebelum getContent serialize (via BeforeSetContent handler). */
    function flattenEditor(body) {
        if (!body) return;
        const wrappers = body.querySelectorAll('.ezdoc-page-view');
        for (let i = 0; i < wrappers.length; i++) {
            const wrap = wrappers[i];
            while (wrap.firstChild) {
                body.insertBefore(wrap.firstChild, wrap);
            }
            wrap.remove();
        }
        body.classList.remove('ezdoc-paginated');
    }

    window.EzdocPagination = {
        split: split,
        restore: restore,
        splitEditor: splitEditor,
        flattenEditor: flattenEditor,
        paginate: paginate,
        restoreOriginalMargins: restoreOriginalMargins,
        debounced: debounced,
        mmToPx: mmToPx,
        config: CONFIG
    };
})();
JS;
    }

    /**
     * Render CSS untuk multi-page wrapper + separate .page cards.
     *
     * Accepts optional padTopMm parameter untuk backward-compat dgn phase 3
     * callers — parameter is ignored in phase 4 (multi-page divs mode).
     * Per-page margin comes from `.page` element's own padding (set per view),
     * bukan dari shared CSS di sini.
     *
     * @param float $padTopMm Unused (backward-compat, dari phase 3 signature)
     */
    public static function renderCss(float $padTopMm = 20.0): string
    {
        unset($padTopMm); // suppress unused-parameter hint
        return <<<'CSS'
/* ─── Ezdoc virtual pagination — multi-page divs ─── */
/* Wrapper: gray background shows through as gap between .page cards. */
.ezdoc-paper-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
}
/* Each .page (including subsequent split pages) styled identically dgn
   original page CSS (defined per view). No margin — flexbox gap handles
   inter-page spacing. */
.ezdoc-paper-wrapper .page {
    margin: 0;
}
/* Print: each .page = one physical page. page-break-after forces break. */
@media print {
    .ezdoc-paper-wrapper {
        display: block;
        gap: 0;
    }
    .ezdoc-paper-wrapper .page {
        page-break-after: always;
        break-after: page;
        margin: 0 !important;
    }
    .ezdoc-paper-wrapper .page:last-child {
        page-break-after: auto;
        break-after: auto;
    }
}
CSS;
    }
}
