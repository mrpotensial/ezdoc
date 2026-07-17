# Shared Content CSS

Single source of truth for document body rendering across 3 contexts: **designer editor** (TinyMCE iframe), **generate view** (HTML browser render), and **PDF export** (dompdf).

## Overview

| Component | Path | Responsibility |
|---|---|---|
| `Ezdoc\UI\ContentCss` | `src/UI/ContentCss.php` | Static utility returning shared CSS string |

## Motivation

Historically these 3 contexts had **duplicated `.content` CSS rules that drifted over time**:

- Paragraph margins
- Table cell padding + border rules
- List styles (`ol`, `ul`, `li`)
- Word-wrap protection
- Heading defaults
- Widow/orphan overrides for print

Drift caused **text flow accumulation bugs** — e.g. designer's page break line rendered ~1 line off from actual print output because paragraph or table cell rules rendered differently across contexts. Fixing these bugs required manually syncing 3 different files.

**`ContentCss::render()` centralizes SHARED rules** so any content-flow property change updates all 3 contexts atomically.

## Precedent (industry-standard shared-style pattern)

- **Notion** — shared content CSS between editor mode and public/exported view
- **Google Docs** — shared content styles between editor and print rendering
- **Filament** — shared component CSS between form input and display context
- **shadcn/ui** — style modules embedded via single import per rendering context
- **Ghost CMS** — shared post CSS between editor preview and public site

## Design

### Static utility (not `.css` file)

`ContentCss` returns CSS as a **PHP string** rather than a physical `.css` file. Rationale:

- **No URL/asset serving complexity** — same string embeds in 3 different contexts (JS template literal, HTML `<style>`, dompdf HTML `<style>`) without needing a public URL
- **Deterministic output** — no cache/CDN concerns
- **dompdf-compatible** — dompdf doesn't reliably resolve `<link>` for local files; inline `<style>` works universally
- **Editor-compatible** — TinyMCE `content_css` accepts URLs, but embedding inline in `content_style` avoids asset routing

If browser caching becomes important later, `ContentCss` can be wrapped by a controller that emits `Content-Type: text/css` for a URL endpoint.

### `.content` selector convention

**All rules scoped under `.content` class**. This unifies selectors across contexts:

- **Designer editor**: TinyMCE `body_class: 'content'` sets `<body class="content">` inside iframe
- **Generate view**: content wrapped in `<div class="content">`
- **PDF export**: same `<div class="content">` wrapper

Rules like `.content p { margin: 8px 0 }` match consistently in all 3.

## Usage

### Designer (TinyMCE `content_style`)

```php
tinymce.init({
 body_class: 'content', // matches .content selectors in shared rules
 content_style: `
 <?= \Ezdoc\UI\ContentCss::render() ?>
 /* context-specific rules: paper visualization, placeholders, dst */
 `,
});
```

### Generate view (`<style>` tag)

```html
<style>
 .content { line-height: 1.6; }
 <?= \Ezdoc\UI\ContentCss::render() ?>
 /* context-specific: edit-on/edit-off, .f field styles, dst */
</style>
```

### PDF export (dompdf HTML)

```php
$pdfHtml .= '
<style>
 .content {
 line-height: 1.6;
 width: ' . ($paperW - $padL - $padR) . 'mm;
 }
 ' . \Ezdoc\UI\ContentCss::render() . '
</style>';
```

## Shared rules (as of v0.9.10)

- Paragraph baseline: `margin: 8px 0; min-height: 1.2em`
- Floating-only paragraph collapse: `min-height: 0; margin: 0; line-height: 0`
- Widow/orphan override for print: `orphans: 1; widows: 1`
- List rendering: `ol/ul/li` with correct `list-style`, `padding-left: 2.5em`, nested variants
- Table: `border-collapse`, `.tbl-fixed` opt-in, cell padding/border/word-wrap
- `table[border="0"]` no-border for semantic HTML5 attribute
- Image: `max-width: 100%; height: auto`
- Heading defaults: h1-h6 sizes/margins + `page-break-after: avoid` + word-wrap
- Overflow: `.content a { word-break: break-all }`, `pre/code { white-space: pre-wrap }`

## Context-specific rules (stay inline)

**Designer editor**:
- Paper visualization (body backgrounds, page break gradients)
- Placeholder decorations (`.field-placeholder`, `.ttd-placeholder`, `.logo-placeholder` outlines)
- Floating placeholder handles (drag `⋮⋮` overlays, hover states)
- Iframe scroll spacer

**Generate view (browser)**:
- `.edit-on` / `.edit-off` toggles
- `.f` field contenteditable styles
- Toolbar, modal, toast, sidebar
- Screen `.page` structure (padding, box-shadow)
- `@media print` overrides

**PDF export (dompdf)**:
- `@page { size, margin }`
- PDF `.page` structure (dompdf-specific width workaround)
- Times font resolution (dompdf font metrics)
- No JavaScript-driven UI

## Adding new shared rules

**When to add to `ContentCss`**:
- Rule affects content-flow rendering (paragraphs, tables, lists, images)
- Rule needs identical output in editor + generate + PDF
- Drift between contexts would cause visible bugs

**When to keep inline**:
- Context-specific behavior (edit mode toggles, drag handles)
- Rule only makes sense in one rendering context (paper visualization, print media)
- Consumer-configurable per-view

## Migration checklist for new rules

When adding a shared content rendering rule:

1. Add to `Ezdoc\UI\ContentCss::render()` under `.content` selector
2. Verify designer body has `content` class via `body_class: 'content'`
3. Test in all 3 contexts (editor, generate view, PDF export)
4. Update this doc if adding a new rule category

## Testing

```php
public function testContentCssRendersConsistentString(): void
{
 $css1 = \Ezdoc\UI\ContentCss::render();
 $css2 = \Ezdoc\UI\ContentCss::render();
 $this->assertSame($css1, $css2, 'CSS output must be deterministic');
 $this->assertStringContainsString('.content p', $css1);
 $this->assertStringContainsString('table-layout: fixed', $css1);
}
```

## See Also

- `src/UI/ContentCss.php` — static class implementation
- `views/document/designer.php` — TinyMCE integration (`body_class` + `content_style`)
- `views/document/generate.php` — HTML view + PDF export integration
- `docs/PDF-RENDERING.md` — dompdf CSS quirks (why explicit `.content { width: X mm }` needed)
