# Screen Pagination

**File**: `src/UI/ScreenPagination.php`
**Milestone**: v0.9.13

Visual multi-paper cards di generate view (screen) untuk memberikan Google
Docs / Word Online UX tanpa memecah DOM structure (editing preserved).

## Motivation

Sebelumnya generate view render document content di single tall `.page`
container. Content overflowed paperH tanpa visual page break — text bleeds
continuously dari "paper 1" area ke "paper 2" area tanpa gap yg visible.

User request: match Google Docs / Word Online visual style (multiple paper
cards dgn gap between) tapi **tanpa break editing UX** (`.f` contenteditable
fields tetap editable, TTD signing modal, floating position tetap works).

## Architecture

**Single `.page` DOM container** — content NOT split ke multiple `<div class="page">`
elements. Instead:

- **CSS mask cutout** carves gap regions dari single `.page` background →
  visual paper cards
- **`filter: drop-shadow`** (mask-aware) → per-paper shadow effect
- **JS spacer insertion** — invisible spacer divs/rows di-inject BEFORE content
  yg akan cross paper boundary → content flows naturally at paper edges

Result: editable content di single DOM tree, visually paginated seperti multi
paper cards.

## Layout modes

**`layoutMode`** config (stored di `ezdoc_templates.layout_config` JSON):

| Value | Description | Use case |
|-------|-------------|----------|
| `'paged'` (default) | Multi-paper cards + mask + spacers | Standard documents |
| `'continuous'` | Single flow, no page breaks | Long-form docs, web preview |

Configurable per template via Designer's "Layout Mode" dropdown.

## Case matrix

Main loop iterates `.content` direct children. For each child, algorithm
decides action:

### Case A — Element fits paper capacity

Element `height` ≤ `paperCapacity * 0.98` AND crosses boundary → insert
`<div class="ezdoc-page-spacer">` sebelum element → element pushed ke next
paper's content-area top.

### Case B — Element too big, splittable container

- **`<ol>` / `<ul>`**: `splitListAt(K)` moves items `[K..end]` ke new list.
  For `<ol>`, sets `start="K+1"` on continuation supaya numbering lanjut.
  Insert `<div>` spacer between original + continuation
- **`<table>`**: `insertTableRowSpacers()` — iterate `<tbody>` rows, insert
  `<tr class="ezdoc-page-spacer"><td colspan="N"></td></tr>` sebelum crossing
  row. Preserves single `<table>` structure (no thead cloning chain issue)

### Case C — Element too big, not splittable

Accept overflow at current position. Element bleeds through gap area
(CSS mask hides that portion). Continuation appears on next paper.

Applied to:
- `<table>` yg first-row-already-crosses (marked in `pushedOnce` WeakSet
  untuk prevent infinite loop)
- `<table>` yg single row terlalu tinggi untuk single paper
- Any non-splittable block yg > paper capacity

### Case D — Wrapper div containing nested table/list

`querySelectorAll('table, ol, ul')` di dalam wrapper → handle nested container
in-place. Preserves wrapper structure while paginating inner content.

## Stability guards

- **`_isRunning` flag** — re-entry guard against MutationObserver retrigger
  during spacer insertion
- **`_observer.disconnect()`** — during insertion, prevents mutation events
  yg trigger self-recursive schedule. Reconnect via `setTimeout(..., 0)`
  microtask boundary
- **`pushedOnce` WeakSet** — track elements yg sudah di-push; prevents
  infinite push loop untuk elements yg still overflow after push
- **`maxIterations = 300` cap** — safety limit
- **`mergeSplitContinuations()` at cleanup phase** — idempotent across
  re-runs (multiple `insertSpacers()` calls stack properly)

## Print flow integration

Screen pagination artifacts (mask, drop-shadow, spacers) turned off untuk
print output:

```css
@media print {
    @page {
        size: paperW paperH;
        margin: padT padR padB padL;
    }
    .page {
        mask-image: none !important;
        filter: none !important;
        background-image: none !important;
    }
    .ezdoc-page-spacer { display: none !important; }
}
```

Native `window.print()` uses `@page margin` per-physical-page (CSS Paged
Media Level 3). Consistent dgn dompdf PDF Raw approach.

## Known limitations (accepted trade-offs)

- **Line-level split within paragraph** — not supported. Long paragraph yg
  cross paper boundary akan visually flow ke gap area. Untuk most form-filled
  templates jarang isu.
- **Table single-row too tall** — Case C accept overflow. Proper fix
  (descend into `<td>` + paragraph-level split) deferred.
- **Continuous mode print output** — screen setting only; print always
  paginate karena physical paper needs page boundaries.
- **Firefox older versions** — mungkin ignore `@page margin`. User harus
  set "Default margins" di print dialog.

## Usage

Embed di generate view:

```php
<style>
    <?= \Ezdoc\UI\ContentCss::render() ?>
    <?= \Ezdoc\UI\ScreenPagination::renderCss(
        (float)$paperDim['width'],
        (float)$paperDim['height'],
        (float)$padTop,
        (float)$padRight,
        (float)$padBottom,
        (float)$padLeft,
        12.0,
        $layoutMode
    ) ?>
</style>
<script>
    <?= \Ezdoc\UI\ScreenPagination::renderJs(
        (float)$paperDim['height'],
        (float)$padTop,
        (float)$padBottom,
        12.0,
        $layoutMode
    ) ?>
</script>
```

## Debug mode

Append `?ezdoc_debug_pagination=1` to generate URL → console logs setiap
case decision (`Case A push-whole`, `Case B split`, `Case C accept overflow`,
etc.) untuk troubleshooting.

## Precedent

- **CKEditor 5 Pagination Premium** — commercial plugin ($1500/year) dgn
  same approach: single container + CSS visual paper cards + JS spacer.
  No DOM restructure untuk preserve editing UX.
- **Google Docs** — visual multi-paper pattern (JS-heavy impl too complex
  to port; adopted visual approach only).
- **Word Online** — similar single-container-visual-paginated pattern.
- **Paged.js chunker** — inspiration untuk overflow detection algorithm
  (traverse block children, measure position, decide break point). Study
  only, no vendored code.
