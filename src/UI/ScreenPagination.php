<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Screen pagination — visual multi-paper cards + JS spacer at page boundaries.
 *
 * ## Motivation
 *
 * Generate view previously rendered content di single tall `.page` container.
 * Content overflowed paperH tanpa visual page break — text bleed continuously
 * dari "paper 1" area ke "paper 2" area tanpa gap. User request: match Google
 * Docs / Word Online visual (paper cards with gap between).
 *
 * ## Design decisions (why no DOM restructure)
 *
 * Prior attempts at true multi-page split (v0.9.13 phases 1-5) broke:
 * - Cursor jumps during typing (DOM restructure interrupts caret)
 * - Insert table di luar kertas (cursor position lost)
 * - Save + reload = layout kacau
 * - Text + Table not combining on same card
 *
 * Approach here: KEEP single `.page` container. Content stays in one DOM tree.
 * Visual paper cards achieved via CSS mask-image (cutout gap regions from single
 * background). Content properly-broken across paper boundaries via JS-inserted
 * invisible spacer divs.
 *
 * Trade-off: only element-level break points (paragraph, heading, list-item,
 * table-row). Line-level split within a single paragraph tidak supported —
 * paragraph yg terlalu panjang untuk fit paper akan overflow ke gap area. Untuk
 * MOST realistic docs (form-filled templates), paragraphs pendek, jarang isu.
 *
 * ## Precedent
 * - **CKEditor 5 Pagination Premium** — commercial ($1500/yr) plugin dgn same
 *   approach: single container + visual paper cards via CSS + JS spacer.
 * - **Google Docs** — multi-container tapi dgn intense DOM management (their
 *   solution too complex to port; we adopt visual approach only).
 * - **Word Online** — similar single-container-visual-paginated pattern.
 * - **Paged.js chunker** — inspiration untuk overflow detection algorithm
 *   (traverse block children, measure position, decide break point).
 *
 * ## Usage
 *
 * Include CSS + JS in generate view AFTER shared ContentCss:
 * ```html
 * <style>
 *     <?= \Ezdoc\UI\ContentCss::render() ?>
 *     <?= \Ezdoc\UI\ScreenPagination::renderCss($paperW, $paperH, $padTop, $padRight, $padBottom, $padLeft, $gap) ?>
 * </style>
 * <script>
 *     <?= \Ezdoc\UI\ScreenPagination::renderJs($paperH, $padTop, $padBottom, $gap) ?>
 * </script>
 * ```
 *
 * spec: docs/SCREEN-PAGINATION.md
 */
final class ScreenPagination
{
    /**
     * Render CSS — multi-paper visual via mask-image cutouts + drop-shadow.
     *
     * @param float $paperW  Paper width in mm (e.g. 210 for A4).
     * @param float $paperH  Paper height in mm (e.g. 297 for A4).
     * @param float $padTop
     * @param float $padRight
     * @param float $padBottom
     * @param float $padLeft
     * @param float $gap     Visual gap between papers in mm (default 12mm).
     */
    public static function renderCss(
        float $paperW,
        float $paperH,
        float $padTop,
        float $padRight,
        float $padBottom,
        float $padLeft,
        float $gap = 12.0,
        string $mode = 'paged'
    ): string {
        // Continuous mode — no mask, no page cards, no gap. Body just single
        // scroll container. Keep existing shadow + white paper look, tapi tanpa
        // multi-page cutout.
        if ($mode === 'continuous') {
            return <<<CSS
/* ─── Screen Pagination (Continuous mode) ─── */
.page {
    background-color: #ffffff;
    box-shadow: 0 4px 20px rgba(0,0,0,0.25);
    background-image: none !important; /* remove dashed page break preview */
    -webkit-mask-image: none;
    mask-image: none;
    filter: none;
}
.ezdoc-page-spacer { display: none !important; }
CSS;
        }
        $tileH = $paperH + $gap;
        return <<<CSS
/* ─── Screen Pagination — visual multi-paper cards ─── */

/* .page = single container spanning all "papers". No box-shadow di sini —
   shadow di-apply via drop-shadow filter yg respect mask cutouts (per-paper
   shadow effect). */
.page {
    background-color: #ffffff;
    box-shadow: none;
    /* Mask cutout: alternating opaque (paper area, 0..paperH) + transparent
       (gap area, paperH..paperH+gap). Repeating vertically. */
    -webkit-mask-image: linear-gradient(
        to bottom,
        black 0mm,
        black {$paperH}mm,
        transparent {$paperH}mm,
        transparent {$tileH}mm
    );
    mask-image: linear-gradient(
        to bottom,
        black 0mm,
        black {$paperH}mm,
        transparent {$paperH}mm,
        transparent {$tileH}mm
    );
    -webkit-mask-size: 100% {$tileH}mm;
    mask-size: 100% {$tileH}mm;
    -webkit-mask-repeat: repeat-y;
    mask-repeat: repeat-y;
    /* Drop-shadow filter follows mask, giving per-paper shadow effect. */
    filter: drop-shadow(0 4px 12px rgba(0,0,0,0.25));
    /* Remove existing dashed line pattern — replaced by real gap. */
    background-image: none;
}

/* Marker class on body: enable pagination mode. Consumer sets this to opt-in. */
.ezdoc-paginated .page {
    background-color: #ffffff;
}

/* Spacer inserted by JS at page boundary — invisible, cannot be caret target. */
.ezdoc-page-spacer {
    display: block;
    width: 100%;
    user-select: none;
    -webkit-user-select: none;
    /* contenteditable=false blocks caret entry (industri standar TinyMCE
       widget pattern). */
    pointer-events: none;
    /* Height set inline by JS (padB + gap + padT of next paper). */
}

/* Print — hide spacers + turn off mask (printer handles page break via @page). */
@media print {
    .page {
        -webkit-mask-image: none;
        mask-image: none;
        filter: none;
    }
    .ezdoc-page-spacer { display: none !important; }
}
CSS;
    }

    /**
     * Render JS — insert spacer divs at natural break points sebelum content
     * yg akan cross paper boundary.
     *
     * Algorithm (adopted from Paged.js chunker):
     * 1. Traverse .page's direct block children (p, h1-h6, ul/ol, table, div).
     * 2. For each child, compute top + bottom position relative to .page.
     * 3. If child bottom > current paper's content-area bottom:
     *    - If child top < current paper's content-area bottom (crosses boundary):
     *      → check page-break-inside: avoid (respect CSS)
     *      → insert spacer BEFORE this child (push to next paper)
     *    - Else (child entirely in gap area): also insert spacer to push down
     * 4. Recompute after each insertion (subsequent children shift down).
     *
     * @param float $paperH Paper height in mm.
     * @param float $padTop Top padding in mm.
     * @param float $padBottom Bottom padding in mm.
     * @param float $gap Gap between papers in mm.
     */
    public static function renderJs(
        float $paperH,
        float $padTop,
        float $padBottom,
        float $gap = 12.0,
        string $mode = 'paged'
    ): string {
        // Continuous mode — no JS needed (no spacer, no split). Return no-op.
        if ($mode === 'continuous') {
            return "/* ScreenPagination continuous mode — no-op */";
        }
        $paperHJs = json_encode($paperH);
        $padTopJs = json_encode($padTop);
        $padBottomJs = json_encode($padBottom);
        $gapJs = json_encode($gap);
        return <<<JS
/* ─── Screen Pagination — spacer insertion ─── */
(function() {
    'use strict';

    var PAPER_H_MM = {$paperHJs};
    var PAD_TOP_MM = {$padTopJs};
    var PAD_BOTTOM_MM = {$padBottomJs};
    var GAP_MM = {$gapJs};

    // Convert mm to px using 1 CSS mm = 96 / 25.4 px (browser standard).
    var MM_TO_PX = 96 / 25.4;
    var PAPER_H_PX = PAPER_H_MM * MM_TO_PX;
    var PAD_TOP_PX = PAD_TOP_MM * MM_TO_PX;
    var PAD_BOTTOM_PX = PAD_BOTTOM_MM * MM_TO_PX;
    var GAP_PX = GAP_MM * MM_TO_PX;

    // Spacer height = padB (fill bottom of current paper) + gap + padT (top
    // of next paper). Content after spacer resumes at correct position on
    // next paper's content area.
    var SPACER_H_PX = PAD_BOTTOM_PX + GAP_PX + PAD_TOP_PX;

    /**
     * Compensate floating element top positions untuk paged mode.
     *
     * Floating (position:absolute anchored ke .page) stored dgn continuous-
     * flow Y (from designer drag). Paged mode inserts spacers yg push text
     * content down, tapi floating tetap di absolute top:Ypx → mismatch: text
     * yg dulu adjacent sekarang di paper berbeda.
     *
     * ## Dynamic algorithm (spacer-driven)
     *
     * Instead of assuming each paper consumes exactly contentH (fixed-formula
     * approach which fails when paragraph boundaries don't align w/ content-
     * area edge), we READ actual `.ezdoc-page-spacer` positions + heights and
     * accumulate shifts based on which spacers are ABOVE each floating's
     * continuous position.
     *
     * Steps:
     * 1. Enumerate spacers top-to-bottom → { boundary, height }
     *    - boundary = spacer's insertion point in continuous coord
     *              = spacer.border_top - padT - (cumulative shift above it)
     *    - height = spacer's rendered height
     * 2. For each floating:
     *    - contY = data-ezdoc-cont-top (original continuous value)
     *    - compensation = Σ spacer.height where contY >= spacer.boundary
     *    - style.top = contY + compensation
     *
     * ## Precedent industri
     * - MS Word .docx <w:drawing> position resolved per-anchor-paragraph
     * - CKEditor 5 image-inline widget tracks anchor paragraph
     * - Google Docs anchored positioning relative to nearest text run
     *
     * ## Idempotency
     * data-ezdoc-cont-top preserves original continuous top value. Re-runs
     * read from that + reset style.top fresh each pass, so pagination re-runs
     * (e.g. TTD signature injected, content changed) tidak double-compensate.
     */
    function compensateFloatings(pageEl) {
        var SELECTOR = '.logo-floating, .ttd-item-floating, .qr-item-floating, ' +
                       '.materai-floating, .materai-item-floating, ' +
                       '.qr-behind, .qr-front, .logo-behind, .logo-front, ' +
                       '.ttd-behind, .ttd-front, .materai-behind, .materai-front';
        var floatings = pageEl.querySelectorAll(SELECTOR);
        if (floatings.length === 0) return;

        var pageRect = pageEl.getBoundingClientRect();
        var pageTop = pageRect.top + window.scrollY;

        // ─── Enumerate spacers → boundary + height ───
        // querySelectorAll returns DOM order which matches top-to-bottom
        // rendering (spacers can be div or tr — both handled uniformly via
        // getBoundingClientRect).
        var spacers = pageEl.querySelectorAll('.ezdoc-page-spacer');
        var spacerBoundaries = []; // { boundary, height }
        var cumulativeShift = 0;
        for (var i = 0; i < spacers.length; i++) {
            var s = spacers[i];
            var sRect = s.getBoundingClientRect();
            if (sRect.height === 0) continue; // hidden spacer, skip
            var sBorderTop = sRect.top + window.scrollY - pageTop;
            // Boundary = continuous-coord position where this spacer's shift
            // begins to apply. Content ABOVE this boundary stays put, content
            // AT-OR-BELOW gets shifted by spacer.height.
            var boundary = sBorderTop - PAD_TOP_PX - cumulativeShift;
            spacerBoundaries.push({ boundary: boundary, height: sRect.height });
            cumulativeShift += sRect.height;
        }

        // ─── Apply compensation ke each floating ───
        for (var j = 0; j < floatings.length; j++) {
            var el = floatings[j];
            var origTop = el.getAttribute('data-ezdoc-cont-top');
            if (origTop === null) {
                var currentTop = parseFloat(el.style.top) || 0;
                origTop = String(currentTop);
                el.setAttribute('data-ezdoc-cont-top', origTop);
            }
            var contY = parseFloat(origTop);
            if (!isFinite(contY) || contY < 0) continue;

            // Sum spacer heights whose boundary contY has crossed.
            var compensation = 0;
            for (var k = 0; k < spacerBoundaries.length; k++) {
                if (contY >= spacerBoundaries[k].boundary) {
                    compensation += spacerBoundaries[k].height;
                } else {
                    break; // boundaries sorted top-down; rest are below contY
                }
            }
            // Always set (kalau compensation=0, reset ke original — handles
            // case where mode/content changed dan floating perlu re-align).
            el.style.top = (contY + compensation) + 'px';
        }
    }

    /**
     * Compute how many complete "papers" a given position (from .page top) is
     * past. Position 0 = top of paper 1. Position paperH = start of gap after
     * paper 1. Position paperH+gap = top of paper 2.
     */
    function paperIndexAt(y) {
        var tileH = PAPER_H_PX + GAP_PX;
        return Math.floor(y / tileH);
    }

    /**
     * Compute the y-position of paper N's content-area bottom (where padding
     * bottom starts). N=0 → paper 1's content bottom.
     */
    function contentBottomOfPaper(n) {
        var tileH = PAPER_H_PX + GAP_PX;
        return n * tileH + (PAPER_H_PX - PAD_BOTTOM_PX);
    }

    /**
     * Create invisible spacer element. contenteditable=false + pointer-events:
     * none blocks caret entry (browser tidak masuk cursor ke element ini).
     */
    function createSpacer(heightPx) {
        var s = document.createElement('div');
        s.className = 'ezdoc-page-spacer';
        s.setAttribute('contenteditable', 'false');
        s.style.height = heightPx + 'px';
        return s;
    }

    /**
     * Create `<tr>` spacer untuk inject di dalam table. Valid HTML: tr dgn
     * single td colspan yg cover semua columns. Height set inline.
     *
     * Pattern: instead of splitting table into multiple tables (which needs
     * thead cloning + complex bookkeeping), insert spacer TR inside table.
     * Content flow across papers, rows tetap in original order.
     */
    function createRowSpacer(colspan, heightPx) {
        var tr = document.createElement('tr');
        tr.className = 'ezdoc-page-spacer';
        tr.setAttribute('contenteditable', 'false');
        var td = document.createElement('td');
        td.setAttribute('colspan', String(Math.max(1, colspan)));
        td.style.cssText = 'padding: 0 !important; border: 0 !important; height: ' + heightPx + 'px;';
        tr.appendChild(td);
        return tr;
    }

    /**
     * Untuk tall tables: iterate rows, insert `<tr>` spacer sebelum row yg akan
     * cross paper boundary. Pushes row to next paper's content-area top.
     *
     * Advantage vs splitTableAt:
     * - No thead cloning overhead (no chain of continuations)
     * - Table structure preserved (single `<table>` in DOM)
     * - Rows in original order, semua visible on correct paper
     * - Form submission tidak terganggu (no ID duplication)
     */
    function insertTableRowSpacers(tableEl, pageTop) {
        var tbody = tableEl.querySelector('tbody') || tableEl;
        var rows = Array.from(tbody.children);
        // Determine colspan dari first non-spacer row (assumes uniform columns).
        var colspan = 1;
        for (var k = 0; k < rows.length; k++) {
            if (rows[k].classList && rows[k].classList.contains('ezdoc-page-spacer')) continue;
            colspan = rows[k].children.length || 1;
            break;
        }
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (row.classList && row.classList.contains('ezdoc-page-spacer')) continue;
            var rect = row.getBoundingClientRect();
            if (rect.height === 0) continue;
            var rowTop = rect.top + window.scrollY - pageTop;
            var rowBottom = rowTop + rect.height;
            var paperIdx = paperIndexAt(rowTop);
            var contentBottom = contentBottomOfPaper(paperIdx);
            if (rowBottom <= contentBottom) continue;
            // Row crosses paper boundary. Push to next paper's content-area top.
            var nextPaperTop = (paperIdx + 1) * (PAPER_H_PX + GAP_PX) + PAD_TOP_PX;
            var pushBy = nextPaperTop - rowTop;
            if (pushBy < 5) continue; // negligible
            if (pushBy > (PAPER_H_PX + GAP_PX) * 1.5) continue; // sanity
            // Insert `<tr>` spacer BEFORE this row.
            var spacer = createRowSpacer(colspan, pushBy);
            tbody.insertBefore(spacer, row);
            return true; // one insert, caller should restart loop
        }
        return false;
    }

    // Shared MutationObserver reference — disconnect during spacer insertion
    // supaya mutation events tidak fire kembali dan re-trigger schedule.
    var _observer = null;
    var _isRunning = false;
    var _splitIdCounter = 0;

    // Debug mode via ?ezdoc_debug_pagination=1 — logs each spacer/split action.
    var _debug = /[?&]ezdoc_debug_pagination=1/.test(window.location.search);
    function dbg() {
        if (!_debug) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[ScreenPagination]');
        console.log.apply(console, args);
    }

    /**
     * Merge split continuations back into originals. Called at cleanup phase
     * supaya re-pagination bekerja dari state HTML original (no accumulated
     * splits from previous runs).
     */
    function mergeSplitContinuations(pageEl) {
        // Iterate continuations in document order — nested splits handled by
        // repeated iteration (should be rare).
        var continuations = pageEl.querySelectorAll('[data-ezdoc-split-continues]');
        continuations.forEach(function(cont) {
            var origId = cont.getAttribute('data-ezdoc-split-continues');
            var orig = pageEl.querySelector('[data-ezdoc-split-id="' + origId + '"]');
            if (!orig) { cont.remove(); return; }
            // For tables — merge tbody rows.
            if (orig.tagName === 'TABLE') {
                var origTbody = orig.querySelector('tbody') || orig;
                var contTbody = cont.querySelector('tbody') || cont;
                while (contTbody.firstChild) {
                    origTbody.appendChild(contTbody.firstChild);
                }
            } else {
                // For lists — merge li elements directly.
                while (cont.firstChild) {
                    orig.appendChild(cont.firstChild);
                }
            }
            cont.remove();
        });
        // Clean up markers.
        pageEl.querySelectorAll('[data-ezdoc-split-id]').forEach(function(el) {
            el.removeAttribute('data-ezdoc-split-id');
        });
    }

    /**
     * Split a table into original (rows 0..rowIndex-1) + continuation (rest).
     * Preserves thead (cloned into continuation for repeated header) AND
     * colgroup (column widths). Caption stays in original only.
     */
    function splitTableAt(tableEl, rowIndex) {
        var tbody = tableEl.querySelector('tbody') || tableEl;
        var thead = tableEl.querySelector('thead');
        var colgroup = tableEl.querySelector('colgroup');
        var rows = Array.from(tbody.children);
        if (rowIndex <= 0 || rowIndex >= rows.length) return null;

        var splitId = 'split-' + (++_splitIdCounter);
        tableEl.setAttribute('data-ezdoc-split-id', splitId);

        var newTable = tableEl.cloneNode(false);
        newTable.removeAttribute('id'); // avoid duplicate IDs in DOM
        newTable.setAttribute('data-ezdoc-split-continues', splitId);
        // Clone colgroup (preserves column widths across split parts).
        if (colgroup) newTable.appendChild(colgroup.cloneNode(true));
        // Repeat thead di continuation (industri standar Word/Google Docs).
        if (thead) newTable.appendChild(thead.cloneNode(true));
        var newTbody = document.createElement('tbody');
        for (var i = rowIndex; i < rows.length; i++) {
            newTbody.appendChild(rows[i]);
        }
        newTable.appendChild(newTbody);
        return newTable;
    }

    /**
     * Split OL/UL into original (items 0..itemIndex-1) + continuation.
     * For OL, sets `start` attribute pada continuation supaya numbering lanjut.
     */
    function splitListAt(listEl, itemIndex) {
        var items = Array.from(listEl.children);
        if (itemIndex <= 0 || itemIndex >= items.length) return null;

        var splitId = 'split-' + (++_splitIdCounter);
        listEl.setAttribute('data-ezdoc-split-id', splitId);

        var newList = listEl.cloneNode(false);
        newList.removeAttribute('id'); // avoid duplicate IDs in DOM
        newList.setAttribute('data-ezdoc-split-continues', splitId);

        // OL numbering — preserve via `start` attribute.
        if (listEl.tagName === 'OL') {
            var startAttr = listEl.getAttribute('start');
            var startVal = startAttr ? parseInt(startAttr, 10) : 1;
            newList.setAttribute('start', String(startVal + itemIndex));
        }

        for (var i = itemIndex; i < items.length; i++) {
            newList.appendChild(items[i]);
        }
        return newList;
    }

    /**
     * Attempt to split a container element (table/list) at first sub-child yg
     * cross paper boundary. Returns TRUE kalau split performed.
     *
     * Note: contentBottom + nextPaperTop di-RECOMPUTE per sub-child (not from
     * caller's outer child paper). Reason: container spans multiple papers,
     * different sub-children hit different paper boundaries. Using outer values
     * would give wrong spacerH for late rows.
     */
    function tryContainerSplit(child, contentEl, _outerContentBottom, _outerNextPaperTop, pageTop) {
        var tag = child.tagName;
        var subChildren = null;
        var splitFn = null;

        if (tag === 'TABLE') {
            var tbody = child.querySelector('tbody') || child;
            subChildren = Array.from(tbody.children);
            splitFn = splitTableAt;
        } else if (tag === 'UL' || tag === 'OL') {
            subChildren = Array.from(child.children);
            splitFn = splitListAt;
        } else {
            return false; // not splittable
        }

        for (var i = 0; i < subChildren.length; i++) {
            var sub = subChildren[i];
            if (sub.classList && sub.classList.contains('ezdoc-page-spacer')) continue;
            var subRect = sub.getBoundingClientRect();
            if (subRect.height === 0) continue;

            var subTop = subRect.top + window.scrollY - pageTop;
            var subBottom = subTop + subRect.height;

            // Recompute paper boundary for THIS sub-child's paper.
            var subPaperIdx = paperIndexAt(subTop);
            var subContentBottom = contentBottomOfPaper(subPaperIdx);

            if (subBottom <= subContentBottom) continue; // sub fits its paper

            // Sub-child crosses ITS OWN paper's boundary at position i.
            if (i === 0) {
                // First sub-child already crosses → can't split at row 0.
                // Caller will fallback to push-whole (Case B fallback).
                return false;
            }

            var newContainer = splitFn(child, i);
            if (!newContainer) return false;

            // Compute spacer height BASED ON sub-child's OWN paper's next-top.
            // subNextPaperTop = start of paper AFTER sub-child's current paper.
            var subNextPaperTop = (subPaperIdx + 1) * (PAPER_H_PX + GAP_PX) + PAD_TOP_PX;
            var spacerH = subNextPaperTop - subTop;
            if (spacerH < 5) return false;
            if (spacerH > (PAPER_H_PX + GAP_PX) * 1.5) {
                console.warn('[ScreenPagination] Split spacerH too big=' + spacerH + 'px', child);
                return false;
            }

            // Insert order: [original container] [div spacer] [continuation container]
            var divSpacer = createSpacer(spacerH);
            child.parentNode.insertBefore(divSpacer, child.nextSibling);
            child.parentNode.insertBefore(newContainer, divSpacer.nextSibling);
            return true;
        }
        return false;
    }

    /**
     * Do ONE iteration of pagination work — find first crossing element,
     * insert appropriate spacer. Returns true kalau something inserted.
     * Extracted supaya main insertSpacers bisa chunk work async.
     */
    function paginateOnePass(pageEl, contentEl, paperCapacityPx, pushedOnce) {
        var pageRect = pageEl.getBoundingClientRect();
        var pageTop = pageRect.top + window.scrollY;
        var children = Array.from(contentEl.children);

        // Selectors untuk skip — element yg positioned:absolute atau tidak
        // affect content flow. Kalau tidak di-skip, algo might process wrapper
        // paragraphs containing floating elements, causing weird spacer insertion.
        var CHILD_SKIP_SELECTOR = '.ezdoc-page-spacer, .floating-only, ' +
            '.logo-floating, .ttd-item-floating, .qr-item-floating, .materai-floating, ' +
            '.logo-placeholder, .ttd-placeholder, .qr-placeholder, .materai-placeholder';

        for (var i = 0; i < children.length; i++) {
            var child = children[i];
            if (child.matches && child.matches(CHILD_SKIP_SELECTOR)) continue;
            // Also skip kalau child CONTAINS only floating (e.g., <p><span class="logo-placeholder"></span></p>)
            if (child.firstElementChild && child.firstElementChild.matches && child.firstElementChild.matches(CHILD_SKIP_SELECTOR) && !child.textContent.trim()) continue;

            var rect = child.getBoundingClientRect();
            if (rect.height === 0) continue;

            var childTop = rect.top + window.scrollY - pageTop;
            var childBottom = childTop + rect.height;
            var paperIdx = paperIndexAt(childTop);
            var contentBottom = contentBottomOfPaper(paperIdx);

            if (childBottom <= contentBottom) continue;

            var nextPaperTop = (paperIdx + 1) * (PAPER_H_PX + GAP_PX) + PAD_TOP_PX;
            var pushBy = nextPaperTop - childTop;
            if (pushBy < 5) continue;

            // Case A: element fits in one paper — push whole to next.
            if (rect.height <= paperCapacityPx * 0.98) {
                if (pushBy > (PAPER_H_PX + GAP_PX) * 1.5) {
                    console.warn('[ScreenPagination] Skip huge pushBy=' + pushBy + 'px', child);
                    continue;
                }
                dbg('Case A push-whole', child.tagName, 'top=' + Math.round(childTop), 'h=' + Math.round(rect.height), 'pushBy=' + Math.round(pushBy));
                var spacer = createSpacer(pushBy);
                contentEl.insertBefore(spacer, child);
                return true;
            }

            // Case B: element too big — try container handling.
            if (child.tagName === 'UL' || child.tagName === 'OL') {
                if (tryContainerSplit(child, contentEl, contentBottom, nextPaperTop, pageTop)) {
                    dbg('Case B split', child.tagName, 'top=' + Math.round(childTop), 'h=' + Math.round(rect.height));
                    return true;
                }
            } else if (child.tagName === 'TABLE') {
                if (insertTableRowSpacers(child, pageTop)) {
                    dbg('Case B row-spacer', child.tagName, 'top=' + Math.round(childTop), 'h=' + Math.round(rect.height));
                    return true;
                }
            }

            // Case B fallback: unsplittable table/list — accept overflow.
            if (child.tagName === 'TABLE' || child.tagName === 'UL' || child.tagName === 'OL') {
                dbg('Case C accept overflow (too big, unsplittable)', child.tagName, 'top=' + Math.round(childTop), 'h=' + Math.round(rect.height));
                pushedOnce.add(child);
                continue;
            }

            // Case D: nested table/list inside wrapper div.
            var nested = child.querySelectorAll('table, ol, ul');
            for (var n = 0; n < nested.length; n++) {
                var nest = nested[n];
                if (nest.hasAttribute('data-ezdoc-split-continues')) continue;
                var nRect = nest.getBoundingClientRect();
                if (nRect.height === 0) continue;
                if (nRect.height <= paperCapacityPx * 0.98) continue;

                var nTop = nRect.top + window.scrollY - pageTop;
                var nBottom = nTop + nRect.height;
                var nPaperIdx = paperIndexAt(nTop);
                var nContentBottom = contentBottomOfPaper(nPaperIdx);
                if (nBottom <= nContentBottom) continue;

                var nNextPaperTop = (nPaperIdx + 1) * (PAPER_H_PX + GAP_PX) + PAD_TOP_PX;
                var handled = false;
                if (nest.tagName === 'TABLE') {
                    handled = insertTableRowSpacers(nest, pageTop);
                } else {
                    handled = tryContainerSplit(nest, nest.parentNode, nContentBottom, nNextPaperTop, pageTop);
                }
                if (handled) return true;
            }
        }
        return false;
    }

    /**
     * Main algorithm — CHUNKED async supaya tidak block browser.
     * Each iteration yields ke browser via setTimeout(0) before next iteration.
     * Browser bisa render + handle input events between chunks.
     */
    function insertSpacers(pageEl) {
        if (_isRunning) return;
        _isRunning = true;
        if (_observer) _observer.disconnect();

        // Cleanup previous state — remove spacers, merge split continuations.
        pageEl.querySelectorAll('.ezdoc-page-spacer').forEach(function(s) { s.remove(); });
        mergeSplitContinuations(pageEl);

        var contentEl = pageEl.querySelector('.content') || pageEl;
        var paperCapacityPx = PAPER_H_PX - PAD_TOP_PX - PAD_BOTTOM_PX;
        var maxIterations = 100;
        var startTime = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
        var timeoutMs = 3000;
        var pushedOnce = new WeakSet();
        var iteration = 0;

        function finish(reason) {
            _isRunning = false;
            if (reason) dbg('Pagination finished:', reason, 'iterations=' + iteration);
            // Compensate floating element positions AFTER spacers settled.
            // Wrapped in try — kalau error, tidak affect main pagination.
            try {
                compensateFloatings(pageEl);
            } catch (e) {
                console.warn('[ScreenPagination] compensateFloatings failed:', e);
            }
            // NO observer reconnect — one-shot pagination only (v0.9.13 hang fix).
        }

        function step() {
            // Wall-clock safety abort.
            var now = (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
            if ((now - startTime) > timeoutMs) {
                console.warn('[ScreenPagination] Timeout (' + timeoutMs + 'ms) hit — aborting.');
                finish('timeout');
                return;
            }
            if (iteration++ >= maxIterations) {
                console.warn('[ScreenPagination] Max iterations hit');
                finish('max-iter');
                return;
            }

            var inserted;
            try {
                inserted = paginateOnePass(pageEl, contentEl, paperCapacityPx, pushedOnce);
            } catch (e) {
                console.error('[ScreenPagination] Error in pass:', e);
                finish('error');
                return;
            }

            if (!inserted) {
                finish('converged');
                return;
            }

            // Yield to browser via setTimeout(0) — CRITICAL untuk prevent hang.
            // Browser handles input + rendering between iterations.
            setTimeout(step, 0);
        }

        // Start first iteration
        step();
    }

    /**
     * Get observer target — .content jika ada, else .page. Extracted supaya
     * finish() bisa re-observe same target.
     */
    function observeTargetForPage(pageEl) {
        return pageEl.querySelector('.content') || pageEl;
    }

    /**
     * Debounced trigger — recompute spacers after content changes settle.
     * Uses requestIdleCallback (fallback setTimeout) supaya pagination run
     * di idle time, tidak block user interactions. Prevents browser hang on
     * complex docs.
     */
    var debounceTimer = null;
    function schedule(pageEl, delay) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            // Defer ke idle time. Browser prioritizes user input + rendering
            // dulu, run pagination when spare CPU. Timeout 3000ms = force-run
            // kalau browser never idle (heavy JS activity).
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(function() {
                    insertSpacers(pageEl);
                }, { timeout: 3000 });
            } else {
                // Safari doesn't support requestIdleCallback yet.
                setTimeout(function() { insertSpacers(pageEl); }, 0);
            }
        }, delay || 300);
    }

    // ─── Boot ───
    document.addEventListener('DOMContentLoaded', function() {
        // KILL SWITCH — disable via URL param `?ezdoc_pagination=off` (safety
        // valve untuk emergency). Add ke bookmark kalau perf issue on specific doc.
        if (/[?&]ezdoc_pagination=off/.test(window.location.search)) {
            console.log('[ScreenPagination] disabled via URL kill switch');
            return;
        }
        var pageEl = document.querySelector('.page');
        if (!pageEl) return;

        // Add marker class so CSS knows pagination is active.
        document.body.classList.add('ezdoc-paginated');

        // Initial run — wait for fonts, then defer to idle time.
        // Ensures browser finishes rendering + Alpine hydration + TinyMCE
        // init sebelum pagination starts. Prevents initial-load hang.
        var runInitial = function() {
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(function() {
                    insertSpacers(pageEl);
                }, { timeout: 3000 });
            } else {
                setTimeout(function() { insertSpacers(pageEl); }, 100);
            }
        };
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(runInitial);
        } else {
            setTimeout(runInitial, 100);
        }

        // NO MutationObserver — proven to cause infinite loops with Alpine.js
        // reactive bindings + TTD/materai updates + TinyMCE etc. User must
        // reload page kalau content structure changes and pagination needs
        // re-run. Trade-off worth it — no more browser hang.
        //
        // Manual re-paginate trigger via console: `EzdocPagination.repaginate()`
        // exposed globally untuk debug/manual use.
        window.EzdocPagination = {
            repaginate: function() {
                _isRunning = false; // force allow re-run
                insertSpacers(pageEl);
            }
        };
    });
})();
JS;
    }
}
