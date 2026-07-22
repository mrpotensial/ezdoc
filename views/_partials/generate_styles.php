<?php
/**
 * ezdoc/views/_partials/generate_styles.php — main screen CSS block for generate view.
 *
 * Extracted dari `views/document/generate.php` (v1.0-prep line target refactor).
 * Contains full screen CSS: paper card visualization dgn dashed page break
 * preview, form field styles (.f contenteditable), TTD placeholder + canvas,
 * materai upload UI, QR positioning, toolbar, modals, screen pagination via
 * ContentCss + ScreenPagination helpers, print media rules.
 *
 * Expected in-scope vars (from parent generate.php via `include` scope share):
 *   @var array<string,int>    $paperDim   Paper dimensions {width, height} in mm
 *   @var int                  $padTop
 *   @var int                  $padRight
 *   @var int                  $padBottom
 *   @var int                  $padLeft
 *   @var string               $layoutMode  'paged' | 'continuous'
 *
 * Include pattern from generate.php:
 *   <?php include __DIR__ . '/../_partials/generate_styles.php'; ?>
 *
 * spec: docs/VIEWS.md (v1.0)
 */
?>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "Times New Roman", serif; background: #6b7280; margin: 0; padding: 30px; font-size: 12pt; }
        .page {
            width: <?= $paperDim['width'] ?>mm;
            min-height: <?= $paperDim['height'] ?>mm;
            margin: 0 auto;
            padding: <?= $padTop ?>mm <?= $padRight ?>mm <?= $padBottom ?>mm <?= $padLeft ?>mm;
            background-color: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            position: relative;
            /* Page break preview (edit-on only) — dashed horizontal line at
               every paperH mm boundary. Same dual-layer bg masking technique
               dari designer.php (Notion / Google Docs page marker convention):
               - Layer 1 (front): horizontal alternating transparent/white
                 stripes, 12px pattern. White segments invisible over paper.
               - Layer 2 (back): solid horizontal line at Y=paperH-1, tiled
                 vertically every paperH.
               Result: dashed page break line visible di boundary halaman.
               Hidden di edit-off + @media print (rules di bawah). */
            background-image:
                linear-gradient(
                    to right,
                    transparent 0,
                    transparent 6px,
                    #fff 6px,
                    #fff 12px
                ),
                linear-gradient(
                    to bottom,
                    transparent 0,
                    transparent calc(<?= $paperDim['height'] ?>mm - 1px),
                    rgba(100, 116, 139, 0.55) calc(<?= $paperDim['height'] ?>mm - 1px),
                    rgba(100, 116, 139, 0.55) <?= $paperDim['height'] ?>mm
                );
            background-size: 12px 100%, 100% <?= $paperDim['height'] ?>mm;
            background-position: 0 0, 0 0;
            background-repeat: repeat-x, repeat-y;
        }
        /* Hide page break preview di edit-off (locked/final) state + print.
           Preview marker adalah edit-mode visual hint, bukan bagian dokumen final. */
        .edit-off .page {
            background-image: none;
        }
        @media screen and (max-width: 900px) {
            body { padding: 10px; }
            .page { width: 100%; padding: 15px; }
        }

        /* Content baseline — shared across designer editor, generate view, PDF.
           Single source of truth via Ezdoc\UI\ContentCss. Historically these
           rules diverged causing text flow drift ~1 line per page. Centralized
           now — any content rendering property change updates 3 contexts atomically.
           Precedent: Notion/Google Docs shared editor+view CSS. */
        .content { line-height: 1.6; }
        <?= \Ezdoc\UI\ContentCss::render() ?>

        /* Screen pagination — visual multi-paper cards + JS spacer boundary.
           Adopted patterns dari Paged.js chunker (overflow detection algo).
           layoutMode = 'paged' (multi-page cards) atau 'continuous' (single flow). */
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

        <?php if ($layoutMode === 'continuous'): ?>
        /* Continuous mode — remove .page min-height constraint so body flows
           as single container tanpa fixed paper height. */
        .page {
            min-height: 0 !important;
        }
        <?php endif; ?>

        /* Field (contenteditable) - auto-adjusts for 1 or multi line */
        .f-wrap { display: inline; }
        .f {
            font-family: inherit;
            font-size: inherit;
            font-weight: inherit;
            font-style: inherit;
            color: inherit;
            line-height: inherit;
            border-bottom: 1px dotted #333;
            /* background: rgba(219, 234, 254, 0.3); */
            padding: 1px 4px;
            margin: 0;
            outline: none;
            display: inline;
            min-width: 40px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .f:focus {
            background: rgba(219, 234, 254, 0.7);
            border-bottom-color: #3b82f6;
        }
        .f:empty::before {
            content: '........';
            color: #9ca3af;
        }
        .edit-off .f {
            border-bottom: none;
            background: transparent;
            pointer-events: none;
        }
        .edit-off .f:empty::before {
            content: '';
        }

        /* Field types */
        .field-date, .field-select {
            font-family: inherit;
            font-size: inherit;
            border: none;
            border-bottom: 1px dotted #333;
            background: rgba(219, 234, 254, 0.3);
            padding: 2px 6px;
            outline: none;
        }
        .field-number {
            /* Number uses contenteditable like text, just different background */
            background: rgba(254, 243, 199, 0.3);
        }
        .field-date { width: 140px; }
        .field-select {
            padding: 3px 6px;
            border-radius: 3px;
            border: 1px solid #d1d5db;
            background: #fff;
        }
        .field-checkbox-wrap {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }
        .field-checkbox {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .field-radio-wrap {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .field-radio-label {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
            font-size: inherit;
        }
        .field-radio-label input {
            width: 14px;
            height: 14px;
            cursor: pointer;
        }
        .edit-off .field-date,
        .edit-off .field-select {
            border: none;
            background: transparent;
            pointer-events: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .edit-off .field-number {
            background: transparent;
        }
        .edit-off .field-checkbox,
        .edit-off .field-radio-label input {
            pointer-events: none;
        }
        @media print {
            .field-date {
                border: none !important;
                background: transparent !important;
            }
            .field-number {
                background: transparent !important;
            }
            .field-select {
                border: none !important;
                background: transparent !important;
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
            }
        }

        /* Logo */
        .logo-img { height: auto; vertical-align: middle; }
        .logo-floating { position: absolute; }
        .logo-behind { z-index: -1; }
        .logo-front { z-index: 100; }
        .logo-empty {
            display: inline-block; padding: 8px 12px; background: #fef3c7;
            border: 1px dashed #f59e0b; color: #92400e; font-size: 11px; border-radius: 4px;
        }

        /* QR Code */
        .qr-item-inline {
            display: inline-block;
            text-align: center;
            vertical-align: top;
            margin: 5px;
        }
        .qr-item-floating {
            position: absolute;
            text-align: center;
        }
        .qr-behind { z-index: -1; }
        .qr-front { z-index: 100; }
        .qr-preview { min-height: 50px; display: flex; align-items: center; justify-content: center; }
        .qr-img { max-width: 100%; height: auto; }
        .qr-canvas-placeholder {
            width: 80px; height: 80px;
            border: 2px dashed #6366f1;
            background: #eef2ff;
            cursor: pointer;
            margin: 0 auto;
            display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
        }
        .qr-canvas-placeholder::before { content: 'QR'; color: #6366f1; font-size: 14px; font-weight: bold; }
        .qr-canvas-placeholder:hover { background: #e0e7ff; }
        .qr-input { margin-top: 5px; }
        .qr-field {
            width: 100%; max-width: 150px;
            font-size: 10px; padding: 4px 6px;
            border: 1px solid #d1d5db; border-radius: 4px;
            text-align: center;
        }
        .qr-field:focus { outline: none; border-color: #6366f1; }
        .edit-off .qr-input { display: none; }
        .edit-off .qr-canvas-placeholder { display: none; }
        @media print {
            .qr-input { display: none !important; }
            .qr-canvas-placeholder { display: none !important; }
        }

        /* TTD Placeholder Items */
        .ttd-item-inline {
            display: inline-block;
            text-align: center;
            min-width: 120px;
            vertical-align: top;
            margin: 5px;
        }
        .ttd-item-floating {
            position: absolute;
            text-align: center;
            min-width: 120px;
        }
        .ttd-behind { z-index: -1; }
        .ttd-front { z-index: 100; }
        .ttd-label { font-size: 12pt; margin-bottom: 5px; }
        .ttd-img { min-height: 50px; display: flex; align-items: center; justify-content: center; }
        .ttd-signature { max-height: 60px; max-width: 120px; }
        .ttd-canvas-placeholder {
            width: 100px; height: 50px;
            border: 1px dashed #10b981;
            background: #ecfdf5;
            cursor: pointer;
            margin: 0 auto;
        }
        .ttd-canvas-placeholder:hover { background: #d1fae5; }
        .ttd-name { font-size: 11pt; margin-top: 3px; }
        .ttd-name .f { min-width: 80px; }

        /* TTD Hover Actions */
        .ttd-img { position: relative; }
        .ttd-img .ttd-actions {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            display: none;
            gap: 5px;
            background: rgba(0,0,0,0.7);
            padding: 5px 8px;
            border-radius: 6px;
        }
        .ttd-img:hover .ttd-actions { display: flex; }
        .ttd-img .ttd-actions button {
            padding: 4px 8px;
            font-size: 11px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .ttd-img .ttd-actions .btn-edit { background: #3b82f6; color: #fff; }
        .ttd-img .ttd-actions .btn-edit:hover { background: #2563eb; }
        .ttd-img .ttd-actions .btn-delete { background: #ef4444; color: #fff; }
        .ttd-img .ttd-actions .btn-delete:hover { background: #dc2626; }
        .edit-off .ttd-actions { display: none !important; }

        /* TTD Mode Toggle */
        .ttd-mode-toggle { display: flex; gap: 2px; margin-bottom: 4px; justify-content: center; }
        .ttd-mode-btn { font-size: 10px; padding: 1px 8px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; border-radius: 3px; }
        .ttd-mode-btn.active { background: #10b981; color: #fff; border-color: #10b981; }
        .ttd-mode-btn:hover { background: #ecfdf5; }
        .ttd-mode-btn.active:hover { background: #059669; }
        .ttd-area-qr { text-align: center; min-height: 50px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; }
        .ttd-qr-preview {
            width: 110px !important;
            height: 110px !important;
            min-width: 110px !important;
            min-height: 110px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto !important;
            flex-shrink: 0 !important;
        }
        .ttd-qr-preview img {
            width: 100px !important;
            height: 100px !important;
            max-width: 100px !important;
            max-height: 100px !important;
            min-width: 100px !important;
            min-height: 100px !important;
            object-fit: contain !important;
            display: block !important;
            aspect-ratio: 1 / 1 !important;
        }
        /* Verify QR area — reuse dimensi QR biasa, tambah styling khas verify (warna teal) */
        .ttd-area-verify-qr { text-align: center; min-height: 50px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; }
        .ttd-verify-qr-preview {
            width: 110px !important;
            height: 110px !important;
            min-width: 110px !important;
            min-height: 110px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto !important;
            flex-shrink: 0 !important;
        }
        .ttd-verify-qr-preview img {
            width: 100px !important;
            height: 100px !important;
            max-width: 100px !important;
            max-height: 100px !important;
            min-width: 100px !important;
            min-height: 100px !important;
            object-fit: contain !important;
            display: block !important;
            aspect-ratio: 1 / 1 !important;
        }
        /* Toggle Verify QR button — visual indicator saat ON */
        #btnToggleVerifyQr { transition: background 0.2s; }
        .ttd-qr-content-input {
            width: 100%;
            max-width: 160px;
            font-size: 10px;
            padding: 2px 5px;
            border: 1px dashed #9ca3af;
            border-radius: 3px;
            background: rgba(243, 244, 246, 0.5);
            outline: none;
            box-sizing: border-box;
        }
        .ttd-qr-content-input:focus { border-color: #10b981; background: #fff; }
        .edit-off .ttd-qr-content-input { display: none !important; }
        .edit-off .ttd-mode-toggle { display: none !important; }

        /* Materai Section */
        .materai-wrap { display: inline-block; text-align: center; vertical-align: top; }
        .materai-item-floating { position: absolute; }
        .materai-behind { z-index: -1; }
        .materai-front { z-index: 100; }
        .materai-label { font-size: 9pt; margin-bottom: 3px; color: #c2410c; font-weight: bold; }
        /* Size on .materai-img is set inline (per placeholder data-width/height) */
        .materai-img { position: relative; margin: 0 auto; display: flex; align-items: center; justify-content: center; }
        .materai-img .materai-image { max-width: 100%; max-height: 100%; }
        .materai-empty-box { border: 1px dashed #c2410c; background: #fff7ed; }
        .materai-upload-box {
            border: 2px dashed #c2410c; background: #fff7ed; color: #c2410c;
            font-size: 10px; line-height: 1.3;
            display: flex; align-items: center; justify-content: center; text-align: center;
            cursor: pointer;
        }
        .materai-upload-box:hover { background: #fed7aa; }
        .materai-actions {
            position: absolute; bottom: 2px; right: 2px; display: none;
            background: rgba(0,0,0,0.65); border-radius: 4px; padding: 2px;
        }
        .materai-img:hover .materai-actions { display: flex; gap: 2px; }
        .materai-actions button {
            font-size: 9px; padding: 2px 5px; border: none; cursor: pointer; border-radius: 3px;
        }
        .materai-actions .btn-edit { background: #3b82f6; color: #fff; }
        .materai-actions .btn-delete { background: #ef4444; color: #fff; }
        .materai-serial-input {
            display: block; margin: 4px auto 0; width: 95%; max-width: 110px;
            font-size: 9pt; padding: 1px 4px; border: 1px dashed #c2410c;
            background: rgba(255,247,237,0.5); border-radius: 3px; outline: none;
        }
        .materai-serial-input:focus { background: #fff; }
        .edit-off .materai-actions { display: none !important; }
        .edit-off .materai-upload-box { cursor: default; pointer-events: none; }
        .edit-off .materai-serial-input { border: none; background: transparent; }

        /* TTD Section — untuk struktur lama .ttd-wrap > .ttd-item (bukan placeholder-based) */
        .ttd-wrap { margin-top: 40px; display: flex; justify-content: center; flex-wrap: wrap; gap: 25px; }
        .ttd-item { flex: 0 0 calc(33.33% - 25px); min-width: 130px; text-align: center; }
        .ttd-wrap .ttd-img { height: 70px; display: flex; align-items: center; justify-content: center; }
        .ttd-wrap .ttd-img img { max-height: 65px; max-width: 100%; }
        .ttd-wrap .ttd-name { margin-top: 5px; }
        .ttd-wrap .ttd-name .f { text-align: center; }

        /* Dynamic Tables */
        .dyntable-rendered { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        .dyntable-rendered th, .dyntable-rendered td { padding: 4px 8px; vertical-align: top; }
        .dyntable-rendered th { font-weight: bold; }
        .dyntable-rendered thead { display: table-header-group; }
        .dyntable-rendered tr { page-break-inside: avoid; }
        .dyntable-empty { color: #999; font-style: italic; text-align: center; padding: 10px; }
        @media print {
            .dyntable-rendered thead { display: table-header-group; }
            .dyntable-rendered tr { page-break-inside: avoid; break-inside: avoid; }
        }

        /* TOOLBAR - V1 Style (Right Panel) */
        .toolbar {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #222;
            padding: 8px;
            border-radius: 8px;
            z-index: 1000;
            max-height: 90vh;
            overflow-y: auto;
            width: 210px;
            transition: width 0.2s ease, height 0.2s ease, padding 0.2s ease, max-height 0.2s ease, background 0.2s ease;
            box-sizing: border-box;
        }
        .toolbar-toggle {
            display: block;
            width: 100%;
            padding: 4px 8px;
            border: none;
            background: #333;
            color: #bbb;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            text-align: right;
            margin-bottom: 8px;
            height: 22px;
        }
        .toolbar-toggle:hover { background: #444; color: #fff; }
        .toolbar.collapsed {
            width: 44px;
            height: 44px;
            min-height: 0;
            max-height: 44px;
            padding: 0;
            overflow: hidden;
            background: transparent;
        }
        .toolbar.collapsed .toolbar-body { display: none; }
        .toolbar.collapsed .toolbar-toggle {
            display: block;
            width: 44px;
            height: 44px;
            padding: 0;
            margin: 0;
            border-radius: 8px;
            line-height: 44px;
            font-size: 20px;
            text-align: center;
            background: #16a34a;
            color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }
        .toolbar.collapsed .toolbar-toggle:hover { background: #15803d; }
        .toolbar button:not(.toolbar-toggle) {
            display: block;
            width: 100%;
            margin: 5px 0;
            padding: 8px 10px;
            color: #fff;
            background: #444;
            border: none;
            cursor: pointer;
            border-radius: 6px;
            font-size: 13px;
        }
        .toolbar button:not(.toolbar-toggle):hover { background: #555; }
        .toolbar button.btn-success { background: #16a34a; }
        .toolbar button.btn-success:hover { background: #15803d; }
        /* Dirty state — pulse amber to remind user to save */
        .toolbar button.btn-dirty { background: #f59e0b !important; animation: dirtyPulse 1.6s ease-in-out infinite; }
        .toolbar button.btn-dirty:hover { background: #d97706 !important; }
        @keyframes dirtyPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            50%      { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
        }
        /* Conditional Section (#7) — layout-transparent visual hint in edit mode.
           Uses outline+background only (no border/padding/margin) supaya edit-on
           view + editor + print + PDF render identical box. Outline doesn't
           affect layout. Google Docs/Notion pattern. */
        .edit-on .conditional-section {
            outline: 2px dashed #06b6d4;
            outline-offset: -2px;
            background: rgba(236, 254, 255, 0.4);
            border-radius: 4px;
        }
        /* Hide marker in print + locked view (final document) */
        .edit-off .conditional-section,
        .conditional-section { outline: none; background: transparent; }
        @media print {
            .conditional-section { outline: none !important; background: transparent !important; }
            .conditional-section[data-cond-result="0"] { display: none !important; }
        }

        /* Field validation (#6) */
        .field-required-mark { color: #dc2626; font-weight: bold; margin-left: 2px; user-select: none; }
        .f-wrap.field-invalid > .f, .f-wrap.field-invalid > input[type="date"], .f-wrap.field-invalid > select {
            background: #fee2e2 !important;
            border-bottom-color: #dc2626 !important;
        }
        .f-wrap.field-invalid::after {
            content: attr(data-v-error-display);
            display: block;
            font-size: 10px;
            color: #dc2626;
            background: #fef2f2;
            padding: 1px 4px;
            border-radius: 3px;
            margin-top: 2px;
        }
        /* Hide validation marks/errors in print/PDF & locked/edit-off */
        .edit-off .field-required-mark { display: none; }
        @media print {
            .field-required-mark { display: none !important; }
            .f-wrap.field-invalid::after { display: none !important; }
            .f-wrap.field-invalid > * { background: transparent !important; border-color: inherit !important; }
        }

        /* kbd styling for keyboard shortcuts modal */
        kbd {
            display: inline-block;
            padding: 1px 6px;
            font-size: 11px;
            font-family: ui-monospace, Menlo, Consolas, monospace;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            box-shadow: 0 1px 0 #d1d5db;
            color: #111;
        }
        .toolbar .template-name {
            color: #aaa;
            font-size: 11px;
            text-align: center;
            margin-bottom: 5px;
        }
        .toolbar .doc-info {
            color: #4ade80;
            font-size: 11px;
            text-align: center;
            padding-bottom: 8px;
            margin-bottom: 8px;
            border-bottom: 1px solid #444;
        }
        .toolbar .doc-info.new { color: #fbbf24; }
        .toolbar .meta-section {
            background: #2a2a2a;
            padding: 6px 7px;
            border-radius: 6px;
            margin-bottom: 6px;
        }
        .toolbar .meta-section label {
            display: block;
            color: #94a3b8;
            font-size: 9px;
            margin-bottom: 1px;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .toolbar .meta-section label:first-child { margin-top: 0; }
        .toolbar .meta-section label small { display:none; }
        .toolbar .meta-section input, .toolbar .meta-section select {
            width: 100%;
            padding: 3px 6px;
            border: 1px solid #444;
            border-radius: 3px;
            background: #1a1a1a;
            color: #fff;
            font-size: 11px;
            box-sizing: border-box;
            height: 24px;
        }
        .toolbar .meta-section input:focus,
        .toolbar .meta-section select:focus {
            outline: none;
            border-color: #4ade80;
        }
        .toolbar .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 6px;
        }
        .toolbar .meta-grid > div { min-width: 0; }
        /* Compact icon-grid for collapsed sections */
        .toolbar .icon-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
        .toolbar .icon-grid button {
            margin: 0 !important;
            padding: 6px 4px !important;
            font-size: 10px !important;
            line-height: 1.15;
            text-align: center;
        }
        .toolbar .icon-grid button i { display: block; font-size: 14px; margin-bottom: 2px; }
        .toolbar .icon-grid button.wide { grid-column: span 2; }

        @media screen and (max-width: 1024px) {
            .toolbar { top: auto; bottom: 10px; right: 10px; max-height: 50vh; width: 170px; }
            .toolbar button { font-size: 12px; }
        }

        /* Toast */
        .toast {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            padding: 12px 24px; border-radius: 8px; color: #fff; z-index: 2000;
            animation: fadeOut 3s forwards;
        }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }
        @keyframes fadeOut { 0%,80% { opacity: 1; } 100% { opacity: 0; } }

        /* Modal - Fullscreen TTD */
        .modal {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85);
            z-index: 3000; align-items: center; justify-content: center;
        }
        .modal.show { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 12px;
            width: 95vw;
            max-width: 900px;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }
        .modal-body {
            flex: 1;
            padding: 20px;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
        }
        #signCanvas {
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
            cursor: crosshair;
            touch-action: none;
            background: #fff;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            text-align: right;
            flex-shrink: 0;
            background: #f9fafb;
            border-radius: 0 0 12px 12px;
        }
        .modal-footer button {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-left: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .modal-footer button:hover { opacity: 0.9; }
        .sign-hint {
            text-align: center;
            color: #6b7280;
            font-size: 13px;
            margin-top: 10px;
        }

        /* Print — @page margin per-physical-page (CSS Paged Media Level 3).
           Browser applies margin to setiap physical page yg dipaginasi.
           .page fills printable area (width: auto → follows body). */
        @page {
            size: <?= $paperDim['width'] ?>mm <?= $paperDim['height'] ?>mm;
            margin: <?= $padTop ?>mm <?= $padRight ?>mm <?= $padBottom ?>mm <?= $padLeft ?>mm;
        }
        @media print {
            /* Preserve background colors & images (browsers strip them by default on print) */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            html, body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
                width: auto !important;
                max-width: none !important;
                font-size: 12pt;
            }
            .page {
                /* auto = fill body (yg fills @page printable area). No explicit
                   width/mm calc → prevents unit conversion mismatch. */
                width: auto !important;
                max-width: none !important;
                min-height: 0 !important;
                margin: 0 !important;
                /* 3mm right-safety padding — small breathing room supaya text
                   tidak nempel di right edge. Only right side; other sides = 0
                   karena @page margin sudah handle. */
                padding: 0 3mm 0 0 !important;
                box-shadow: none !important;
                position: relative;
                /* Screen pagination artifacts: mask + drop-shadow off untuk print. */
                -webkit-mask-image: none !important;
                mask-image: none !important;
                filter: none !important;
                /* Page break preview edit-mode marker — hide dari printed output. */
                background-image: none !important;
            }
            /* Floating position compensation — @page margin shifts .page origin
               (paper corner → padL,padT offset). Translate back to visual paper
               corner position untuk floating logo/TTD/QR/materai. */
            .logo-floating, .ttd-item-floating, .qr-item-floating,
            .qr-behind, .qr-front, .materai-floating,
            .materai-behind, .materai-front {
                transform: translate(-<?= $padLeft ?>mm, -<?= $padTop ?>mm);
            }
            /* Hide screen-only pagination spacers (they exist untuk screen visual). */
            .ezdoc-page-spacer { display: none !important; }
            .toolbar, .modal, .toast { display: none !important; }
            .f { border-bottom: none !important; background: transparent !important; }
            .f:empty::before { display: none; }
            .ttd-actions { display: none !important; }
            .ttd-mode-toggle { display: none !important; }
            .materai-actions { display: none !important; }
            .materai-upload-box { display: none !important; }
            .materai-serial-input { border: none !important; background: transparent !important; }
            .ttd-qr-content-input { display: none !important; }
            .ttd-canvas-placeholder { display: none !important; }
        }
    </style>
