<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Shared content CSS — single source of truth for document body rendering
 * across 3 contexts: designer editor (TinyMCE iframe), generate view (HTML),
 * and PDF export (dompdf).
 *
 * ## Motivation
 *
 * Historically the 3 contexts had duplicated `.content` CSS rules that drifted
 * over time (paragraph margins, table cell padding, list styles, word-wrap
 * protection). Drift caused text flow accumulation bugs — e.g. designer's
 * page break line rendered ~1 line off from actual print output because
 * `.f` field decorations rendered differently across contexts.
 *
 * This class centralizes the SHARED rules so any content-flow property change
 * updates all 3 contexts atomically. Context-specific rules (paper
 * visualization, edit-mode toggles, placeholder decorations) stay inline
 * in respective view files.
 *
 * ## Precedent
 * - **Notion** — shared content CSS between editor and public/exported view
 * - **Google Docs** — shared content styles between editor and print
 * - **Filament** — shared component CSS between form and view context
 * - **shadcn/ui** — style modules embedded via single import per context
 *
 * ## Usage
 *
 * Designer (TinyMCE content_style):
 * ```php
 * content_style: `
 *     <?= \Ezdoc\UI\ContentCss::render() ?>
 *     // context-specific rules here (paper visualization, placeholders, dst)
 * `
 * ```
 *
 * Generate view + PDF (embed in `<style>`):
 * ```html
 * <style>
 *     <?= \Ezdoc\UI\ContentCss::render() ?>
 *     // context-specific rules
 * </style>
 * ```
 *
 * ## Selector convention
 * All rules scoped under `.content` — designer must set TinyMCE `body_class`
 * to `'content'` supaya `body.content` matches `.content p` etc.
 *
 * spec: docs/CONTENT-CSS.md
 */
final class ContentCss
{
    /**
     * Render shared content CSS as string.
     *
     * @return string CSS rules ready untuk embed di `<style>` tag atau
     *                TinyMCE content_style template literal.
     */
    public static function render(): string
    {
        return <<<'CSS'
/* ─── Shared content CSS (Ezdoc\UI\ContentCss) ─── */

/* Paragraph baseline — 8px vertical margin, min-height 1.2em keeps empty
   paragraphs at consistent height (matches typography rhythm across
   designer/generate/PDF). */
.content p {
    margin: 8px 0;
    min-height: 1.2em;
}

/* Paragraph containing ONLY floating/absolute elements collapses to zero
   height — floating logo/TTD/QR anchored ke wrapper p tidak boleh push
   text flow (visual position = fixed top/left, tidak boleh add height). */
.content p.floating-only {
    min-height: 0;
    margin: 0;
    line-height: 0;
}

/* Print widow/orphan protection disabled — designer's page break visualization
   uses background gradient at pixel boundary (no widow/orphan concept).
   Setting both to 1 makes browser print break at pixel boundary matching
   designer, tidak push paragraph ke next page untuk hindari 1-line orphan. */
.content p, .content li {
    orphans: 1;
    widows: 1;
}

/* ─── Lists (restore browser defaults yang di-strip Tailwind preflight) ─── */
.content ol, .content ul {
    margin: 8px 0;
    padding-left: 2.5em;
}
.content ol { list-style: decimal; }
.content ul { list-style: disc; }
.content ol ol { list-style: lower-alpha; }
.content ol ol ol { list-style: lower-roman; }
.content ul ul { list-style: circle; }
.content ul ul ul { list-style: square; }
.content li { display: list-item; }

/* ─── Tables ─── */
.content table {
    border-collapse: collapse;
    width: 100%;
}
/* Opt-in fixed layout via class="tbl-fixed" — force equal columns, prevents
   auto-layout column width drift when cell content lengths differ between
   designer placeholder text dan generate actual field values. */
.content table.tbl-fixed {
    table-layout: fixed;
}
.content td, .content th {
    border: 1px solid #ccc;
    padding: 6px;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
/* HTML5 border="0" attribute → no visible border (semantic HTML backward-compat) */
.content table[border="0"] td,
.content table[border="0"] th {
    border: none;
}

/* ─── Images ─── */
.content img {
    max-width: 100%;
    height: auto;
}
.content .logo-img { display: inline-block; }

/* ─── Headings — explicit defaults (dompdf tidak apply browser default h1-h6) ─── */
.content h1, .content h2, .content h3,
.content h4, .content h5, .content h6 {
    line-height: 1.25;
    page-break-after: avoid;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
}
.content h1 { font-size: 2em;    font-weight: bold; margin: 0.67em 0; }
.content h2 { font-size: 1.5em;  font-weight: bold; margin: 0.75em 0; }
.content h3 { font-size: 1.17em; font-weight: bold; margin: 0.83em 0; }
.content h4 { font-size: 1em;    font-weight: bold; margin: 1.12em 0; }
.content h5 { font-size: 0.83em; font-weight: bold; margin: 1.5em 0; }
.content h6 { font-size: 0.75em; font-weight: bold; margin: 1.67em 0; }

/* ─── Overflow protection ─── */
.content a {
    word-break: break-all; /* long URLs tidak overflow */
}
.content pre, .content code {
    white-space: pre-wrap;
    word-break: break-word;
}
CSS;
    }
}
