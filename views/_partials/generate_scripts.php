<?php
/**
 * ezdoc/views/_partials/generate_scripts.php — main JS block for generate view.
 *
 * Extracted dari `views/document/generate.php` (v1.0-prep line target refactor).
 * Contains full generate view JavaScript: EZDOC_DEBUG diagnostic bag, form
 * field bindings, TTD signature canvas, materai upload, QR generation,
 * PDF export flow, print handling, floating position sync.
 *
 * Expected in-scope vars (from parent generate.php via `include` scope share):
 *   @var array<string,mixed>  $dbFields         Form field values
 *   @var array<string,mixed>  $configTtd        TTD placeholder config
 *   @var array<string,mixed>  $docMeta          Doc metadata (created_by, timestamps)
 *   @var int                  $author_id        Current user ID
 *   @var int                  $template_id      Template ID from query
 *   @var int                  $doc_id           Document ID from query
 *   @var int                  $param_version    Template version number
 *   @var string               $param_norm       Patient MR number
 *   @var string               $param_nopen      Patient registration number
 *   @var string               $param_label      Slot label (default '-')
 *   @var bool                 $isSuperadmin     Whether current user is admin
 *   @var mixed                $ctx              Ezdoc Context
 *
 * Include pattern from generate.php:
 *   <?php include __DIR__ . '/../_partials/generate_scripts.php'; ?>
 *
 * spec: docs/VIEWS.md (v1.0)
 */
// @phpstan-ignore-next-line — vars ARE defined at runtime via include scope share
/** @phpstan-ignore variable.undefined */
?>
    <script>
        // ===== EZDOC_DEBUG — client-side diagnostic bag =====
        // Buka F12 → Console tab → ketik `EZDOC_DEBUG` untuk inspect load state.
        // Save log akan tampil di console tiap kali klik Simpan (via saveDocument()).
        window.EZDOC_DEBUG = window.EZDOC_DEBUG || {};
        window.EZDOC_DEBUG.load = <?= json_encode($__ezdocLoadDebug ?? ['result' => ['found' => false], 'hint' => 'No lookup performed (missing norm/nopen or new doc)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.EZDOC_DEBUG.saves = [];
        console.log('%c[ezdoc:load]', 'color:#0e7490;font-weight:bold', window.EZDOC_DEBUG.load);

        const templateId = <?= $template_id ?>;
        const isEditMode = <?= $isEditMode ? 'true' : 'false' ?>;
        // editMode is driven by the document lock state (inverse of is_locked)
        const editMode = <?= $param_is_locked ? 'false' : 'true' ?>;

        // ===== CONDITIONAL SECTIONS (#7) =====
        // Evaluator for simple expressions like:
        //   jenis_kelamin=P
        //   umur>=17
        //   jenis_kelamin=P AND umur>=17
        //   status!=lajang OR has_anak=1
        // Operators: = != > < >= <=
        // Logical: AND, OR (no parentheses)
        function evalCondExpr(expr) {
            if (!expr) return true;
            // Split by OR first (lower precedence)
            const orParts = expr.split(/\s+OR\s+/i);
            for (const orPart of orParts) {
                // Each OR part is AND-joined
                const andParts = orPart.split(/\s+AND\s+/i);
                let andOk = true;
                for (const cond of andParts) {
                    if (!evalSingleCond(cond.trim())) { andOk = false; break; }
                }
                if (andOk) return true; // any OR branch satisfied → true
            }
            return false;
        }

        function evalSingleCond(cond) {
            // Match operator (longest first)
            const ops = ['>=', '<=', '!=', '=', '>', '<'];
            let op = '', leftRaw = '', rightRaw = '';
            for (const o of ops) {
                const idx = cond.indexOf(o);
                if (idx > 0) {
                    op = o;
                    leftRaw = cond.substring(0, idx).trim();
                    rightRaw = cond.substring(idx + o.length).trim();
                    break;
                }
            }
            if (!op) return false;

            // Resolve left operand from form (field name)
            const fieldName = leftRaw;
            let leftVal = '';
            const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
            if (input) {
                if (input.type === 'checkbox') leftVal = input.checked ? '1' : '0';
                else leftVal = input.value || '';
            } else {
                const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                if (editable) leftVal = (editable.innerText || '').trim();
            }

            // Try numeric comparison if both sides parse as numbers
            const lNum = parseFloat(leftVal);
            const rNum = parseFloat(rightRaw);
            const numeric = !isNaN(lNum) && !isNaN(rNum);

            switch (op) {
                case '=':  return numeric ? (lNum === rNum) : (leftVal === rightRaw);
                case '!=': return numeric ? (lNum !== rNum) : (leftVal !== rightRaw);
                case '>':  return numeric ? (lNum >  rNum) : (leftVal >  rightRaw);
                case '<':  return numeric ? (lNum <  rNum) : (leftVal <  rightRaw);
                case '>=': return numeric ? (lNum >= rNum) : (leftVal >= rightRaw);
                case '<=': return numeric ? (lNum <= rNum) : (leftVal <= rightRaw);
            }
            return false;
        }

        // Apply conditional sections — toggle display
        function applyConditionalSections() {
            document.querySelectorAll('.conditional-section[data-cond]').forEach(el => {
                const expr = el.getAttribute('data-cond') || '';
                const ok = evalCondExpr(expr);
                el.style.display = ok ? '' : 'none';
                el.dataset.condResult = ok ? '1' : '0';
            });
        }

        // Evaluate on load + every input change (debounced)
        document.addEventListener('DOMContentLoaded', function() {
            applyConditionalSections();
            // Hook into existing field changes
            let _condTimer = null;
            const trigger = () => {
                clearTimeout(_condTimer);
                _condTimer = setTimeout(applyConditionalSections, 150);
            };
            document.querySelectorAll('.f[data-field], input[name], select[name]').forEach(el => {
                el.addEventListener('input', trigger);
                el.addEventListener('change', trigger);
            });
        });

        // ===== DIRTY STATE TRACKING (#22 unsaved changes indicator) =====
        let isDirty = false;
        const ORIG_TITLE = document.title;

        function markDirty() {
            if (!editMode) return; // locked docs are not editable
            if (isDirty) return;
            isDirty = true;
            // Title bar prefix asterisk
            if (!document.title.startsWith('* ')) document.title = '* ' + ORIG_TITLE;
            // Save button visual: change to amber/orange to signal pending save
            const btn = document.querySelector('.btn-success');
            if (btn) {
                btn.classList.add('btn-dirty');
                btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
                if (!btn.textContent.startsWith('● ')) btn.textContent = '● ' + btn.textContent;
            }
        }

        function markClean() {
            isDirty = false;
            document.title = ORIG_TITLE;
            const btn = document.querySelector('.btn-success');
            if (btn) {
                btn.classList.remove('btn-dirty');
                if (btn.textContent.startsWith('● ')) {
                    btn.textContent = btn.textContent.substring(2);
                }
            }
        }

        // Bind change tracking to all inputs/textareas/contenteditable in the form
        // Excluded: hidden inputs (auto-updated), file inputs (handled by upload handler manually)
        function bindDirtyTracking() {
            const form = document.getElementById('mainForm');
            if (!form) return;

            // Standard form controls
            form.querySelectorAll('input, textarea, select').forEach(el => {
                if (el.type === 'hidden') return;       // auto-set programmatically
                if (el.id && el.id.startsWith('ttd_'))   return; // hidden TTD inputs
                if (el.id && el.id.startsWith('materai_img_')) return;
                if (el.id && el.id.startsWith('materai_upload_')) return;
                // Navigation-only controls (bukan data edit) — skip dirty tracking.
                // Bugfix: versionSelect dropdown triggers switchVersion() navigation
                // saja, tidak mutate document data. User yg cuma lihat-lihat ganti
                // versi jangan sampai kena beforeunload prompt.
                if (el.id === 'versionSelect') return;
                if (el.hasAttribute('data-no-dirty')) return; // opt-out attr
                el.addEventListener('input', markDirty);
                el.addEventListener('change', markDirty);
            });
            // Contenteditable
            form.querySelectorAll('[contenteditable="true"]').forEach(el => {
                el.addEventListener('input', markDirty);
            });
        }

        // Trigger dirty mark from external events (TTD canvas save, materai upload, mode switch)
        function triggerDirty() { markDirty(); }

        // Beforeunload warning — only prompt if dirty AND bukan intentional
        // internal navigation. Livewire wire:navigate / HTMX hx-boost pattern:
        // handler check flag `_ezdocSuppressUnload` yg di-set oleh caller
        // sebelum navigasi terprogram (switchVersion, doCreateNewVersion, dll).
        //
        // Native browser prompt tidak bisa di-Tailwind-kan (security restriction);
        // hanya bisa dipicu atau tidak dipicu.
        window._ezdocSuppressUnload = false;
        window.addEventListener('beforeunload', function(e) {
            if (window._ezdocSuppressUnload) return; // intentional nav — silent
            if (isDirty) {
                e.preventDefault();
                e.returnValue = ''; // required, but text ignored by modern browsers
                return '';
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            bindDirtyTracking();
        });
        let currentTtdId = null;
        let drawing = false;

        const modal = document.getElementById('signModal');
        const canvas = document.getElementById('signCanvas');
        const ctx = canvas.getContext('2d');

        ctx.strokeStyle = '#000';
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        // When document is locked: force all inputs/contenteditable to readonly state
        if (!editMode) {
            document.addEventListener('DOMContentLoaded', function() {
                // Disable contenteditable spans (.f)
                document.querySelectorAll('.f[contenteditable]').forEach(el => {
                    el.setAttribute('contenteditable', 'false');
                });
                // Set readonly on text/date/number inputs inside the content area + QR inputs
                const page = document.querySelector('.page');
                if (page) {
                    page.querySelectorAll('input[type="text"], input[type="date"], input[type="number"], input.qr-field, input.ttd-qr-content-input, textarea').forEach(el => {
                        el.readOnly = true;
                    });
                    // Disable select, checkbox, radio (readonly doesn't work on these)
                    page.querySelectorAll('select, input[type="checkbox"], input[type="radio"]').forEach(el => {
                        el.disabled = true;
                    });
                }
                // Also guard the toolbar meta inputs (norm, nopen, label) - they already have PHP readonly but double-check
                ['inputNorm', 'inputNopen', 'inputLabel'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.readOnly = true;
                });
            });
        }

        // Update QR preview when input changes
        async function updateQrPreview(fieldName) {
            const input = document.getElementById('qrinput_' + fieldName);
            const preview = document.getElementById('qrpreview_' + fieldName);
            if (!input || !preview) return;

            let data = input.value.trim();
            if (!data) {
                preview.innerHTML = '<div class="qr-canvas-placeholder" onclick="document.getElementById(\'qrinput_' + fieldName + '\').focus()"></div>';
                return;
            }

            // Resolve marker `{verify_url}` client-side ke URL current supaya QR preview
            // akurat. Server-side render tetap resolve marker saat cetak/PDF.
            if (data === '{verify_url}' || data === '{verify}') {
                const vu = document.getElementById('verifyUrlInput')?.value || '';
                if (!vu) {
                    preview.innerHTML = '<div class="p-2.5 text-amber-500 text-[10px]">' + t('ttd.save_first_for_verify_url', {}, 'Save document first to generate the verify URL') + '</div>';
                    return;
                }
                data = vu;
            }

            // Show loading
            preview.innerHTML = '<div class="p-5 text-indigo-500 text-[11px]">' + t('ttd.generating', {}, 'Generating...') + '</div>';

            try {
                const resp = await fetch(_ezdocQrUrl(data));
                let result;
                try {
                    result = await resp.json();
                } catch (jsonErr) {
                    // Response bukan JSON — show HTTP status + partial body untuk debug
                    const txt = await resp.text().catch(() => '');
                    preview.innerHTML = '<div class="p-2.5 text-red-600 text-[10px]">HTTP ' + resp.status + ' — ' + (txt.slice(0, 80) || 'no response') + '</div>';
                    return;
                }
                if (result.success) {
                    preview.innerHTML = '<img src="' + result.qr + '" class="qr-img w-full h-auto" alt="QR">';
                } else {
                    preview.innerHTML = '<div class="p-2.5 text-red-600 text-[10px]">Error: ' + (result.message || 'unknown') + '</div>';
                }
            } catch (e) {
                preview.innerHTML = '<div class="p-2.5 text-red-600 text-[10px]">Network: ' + e.message + '</div>';
            }
        }

        // ===== Numeric field filter — contenteditable doesn't respect type=number =====
        // Allow: digits (0-9), decimal point (single), leading minus.
        // Strip: alphabetic + other symbols.
        function filterNumeric(el) {
            const original = el.textContent;
            // Restore caret position after DOM mutation
            const sel = window.getSelection();
            const range = sel && sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
            const caretOffset = range ? range.startOffset : original.length;

            let filtered = original.replace(/[^\d.\-]/g, '');
            // Only 1 decimal point (keep first)
            const dotIdx = filtered.indexOf('.');
            if (dotIdx !== -1) {
                filtered = filtered.slice(0, dotIdx + 1) + filtered.slice(dotIdx + 1).replace(/\./g, '');
            }
            // Minus only at start
            if (filtered.includes('-')) {
                const hasLeading = filtered.startsWith('-');
                filtered = (hasLeading ? '-' : '') + filtered.replace(/-/g, '');
            }

            if (filtered !== original) {
                el.textContent = filtered;
                // Restore caret
                try {
                    const newRange = document.createRange();
                    const textNode = el.firstChild || el;
                    const pos = Math.min(caretOffset - (original.length - filtered.length), filtered.length);
                    newRange.setStart(textNode, Math.max(0, pos));
                    newRange.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                } catch (e) { /* focus lost, skip */ }
            }

            // Sync hidden input
            const wrap = el.closest('.f-wrap');
            const hidden = wrap && wrap.querySelector('input[type="hidden"]');
            if (hidden) hidden.value = filtered;
        }

        // Block paste of non-numeric content; replace with sanitized text
        function handleNumericPaste(e) {
            e.preventDefault();
            const raw = (e.clipboardData || window.clipboardData).getData('text');
            let cleaned = raw.replace(/[^\d.\-]/g, '');
            const dotIdx = cleaned.indexOf('.');
            if (dotIdx !== -1) {
                cleaned = cleaned.slice(0, dotIdx + 1) + cleaned.slice(dotIdx + 1).replace(/\./g, '');
            }
            if (cleaned.includes('-')) {
                const hasLeading = cleaned.startsWith('-');
                cleaned = (hasLeading ? '-' : '') + cleaned.replace(/-/g, '');
            }
            document.execCommand('insertText', false, cleaned);
        }

        // AJAX Save - no reload
        // ===== Field Validation (#6) =====
        // Validates all .f-wrap elements with data-v-* attributes.
        // Returns true if all valid; otherwise marks invalid + returns false.
        function validateAllFields() {
            // Clear previous invalid markers
            document.querySelectorAll('.f-wrap.field-invalid').forEach(w => {
                w.classList.remove('field-invalid');
                w.removeAttribute('data-v-error-display');
            });

            let firstInvalid = null;
            let allValid = true;

            document.querySelectorAll('.f-wrap[data-v-required], .f-wrap[data-v-min], .f-wrap[data-v-max], .f-wrap[data-v-pattern]').forEach(wrap => {
                const required = wrap.getAttribute('data-v-required') === '1';
                const min = wrap.getAttribute('data-v-min');
                const max = wrap.getAttribute('data-v-max');
                const pattern = wrap.getAttribute('data-v-pattern');
                const customMsg = wrap.getAttribute('data-v-error-msg') || '';

                // Find value (could be in .f contenteditable, input[type=date], select, hidden input)
                let val = '';
                const editable = wrap.querySelector('.f[data-field]');
                if (editable) val = (editable.innerText || '').trim();
                else {
                    const ctrl = wrap.querySelector('input[type="date"], input[type="text"], input[type="number"], select, input[type="hidden"]');
                    if (ctrl) val = (ctrl.value || '').trim();
                }

                let err = '';
                if (required && val === '') {
                    err = customMsg || t('validation.required', {}, 'Required');
                } else if (val !== '') {
                    // Number type vs text length
                    const ctrlNum = wrap.querySelector('.field-number, input[type="number"]');
                    const isNumber = !!ctrlNum;
                    if (min !== null && min !== '' && min !== undefined) {
                        if (isNumber) {
                            if (parseFloat(val) < parseFloat(min)) err = customMsg || t('validation.min_value', {min: min}, 'Minimum value {min}');
                        } else {
                            if (val.length < parseInt(min)) err = customMsg || t('validation.min_length', {min: min}, 'Minimum {min} characters');
                        }
                    }
                    if (!err && max !== null && max !== '' && max !== undefined) {
                        if (isNumber) {
                            if (parseFloat(val) > parseFloat(max)) err = customMsg || t('validation.max_value', {max: max}, 'Maximum value {max}');
                        } else {
                            if (val.length > parseInt(max)) err = customMsg || t('validation.max_length', {max: max}, 'Maximum {max} characters');
                        }
                    }
                    if (!err && pattern) {
                        try {
                            const re = new RegExp(pattern);
                            if (!re.test(val)) err = customMsg || t('validation.pattern_mismatch', {}, 'Invalid format');
                        } catch (e) { /* invalid regex — skip */ }
                    }
                }

                if (err) {
                    wrap.classList.add('field-invalid');
                    wrap.setAttribute('data-v-error-display', err);
                    if (!firstInvalid) firstInvalid = wrap;
                    allValid = false;
                }
            });

            if (!allValid && firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const target = firstInvalid.querySelector('.f, input, select');
                if (target && target.focus) setTimeout(() => target.focus(), 300);
            }
            return allValid;
        }

        async function submitForm() {
            const norm = document.getElementById('inputNorm').value.trim();
            const nopen = document.getElementById('inputNopen').value.trim();
            let label = (document.getElementById('inputLabel')?.value || '').trim();
            if (label === '') label = '-';
            if (!norm || !nopen) {
                ezdocAlert(t('alert.identity_required', {}, 'MR No. and Registration No. are required!'), { title: 'Required Fields', variant: 'warning' });
                return;
            }

            // Validate fields with data-v-* rules (#6)
            if (!validateAllFields()) {
                showToast(t('toast.invalid_fields', {}, 'Some fields are invalid. Check the red highlights.'), 'error');
                return;
            }

            const btn = document.querySelector('.btn-success');
            const origText = btn.textContent;
            btn.textContent = t('toolbar.saving', {}, 'Saving...');
            btn.disabled = true;

            const formData = new FormData(document.getElementById('mainForm'));

            // spec: ezdoc-spec/slots/generate.md#save-hook-pre — dispatch cancelable pre-save event.
            // Consumers may listen on window 'ezcetak:before-save' and call event.preventDefault()
            // to abort the save, or mutate formData via event.detail.formData in place.
            try {
                var _pre = new CustomEvent('ezcetak:before-save', {
                    cancelable: true,
                    detail: { formData: formData, docId: (document.getElementById('docIdInput') || {}).value || '' }
                });
                var _proceed = window.dispatchEvent(_pre);
                if (!_proceed) {
                    btn.textContent = origText;
                    btn.disabled = false;
                    return;
                }
            } catch (e) { /* CustomEvent unsupported — skip hook */ }

            try {
                // Snapshot FormData sent (client-side diagnostic)
                const _postSnapshot = {};
                for (const [k, v] of formData.entries()) {
                    // Truncate long values (signature base64 dll) untuk keep console readable
                    _postSnapshot[k] = (typeof v === 'string' && v.length > 200) ? v.slice(0, 100) + '…(' + v.length + ' chars)' : v;
                }

                const resp = await fetch(_ezdocEndpoint('save'), { method: 'POST', body: formData });
                const data = await resp.json();

                // Push diagnostic ke debug bag + console.log — F12 inspect
                const _dbg = { post: _postSnapshot, response: data };
                (window.EZDOC_DEBUG = window.EZDOC_DEBUG || {}).saves = (window.EZDOC_DEBUG.saves || []);
                window.EZDOC_DEBUG.saves.push(_dbg);
                console.log('%c[ezdoc:save]', 'color:#059669;font-weight:bold', _dbg);

                if (data.success) {
                    showToast(data.message, 'success');
                    markClean(); // reset dirty state after successful save
                    // spec: ezdoc-spec/slots/generate.md#save-hook-post — dispatch after-save event
                    // for analytics, external sync (audit trail, notification, webhook), or auto-print.
                    try {
                        window.dispatchEvent(new CustomEvent('ezcetak:after-save', {
                            detail: {
                                doc_id:     data.doc_id,
                                verify_url: data.verify_url,
                                isEdit:     !!data.isEdit,
                                message:    data.message
                            }
                        }));
                    } catch (e) { /* skip */ }
                    // Build URL with label if non-default. Preserve routing prefix
                    // (ezdoc_page=generate dll) supaya tidak fallback ke default_page.
                    const savedLabel = data.label || label;
                    const _tParams = _preservedParams();
                    _tParams.set('template_id', templateId);
                    _tParams.set('norm', norm);
                    _tParams.set('nopen', nopen);
                    if (savedLabel && savedLabel !== '-') {
                        _tParams.set('label', savedLabel);
                    }
                    let targetUrl = '?' + _tParams.toString();

                    // Update hidden verify_url supaya QR yang pakai {verify_url} bisa auto-regenerate
                    if (data.verify_url) {
                        const vu = document.getElementById('verifyUrlInput');
                        if (vu) vu.value = data.verify_url;
                        // Trigger regenerate untuk semua QR yang lagi aktif
                        document.querySelectorAll('.ttd-qr-content-input').forEach(inp => {
                            const idMatch = (inp.id || '').match(/^ttd_qr_content_(.+)$/);
                            if (idMatch && typeof generateTtdQr === 'function') generateTtdQr(idMatch[1]);
                        });
                    }

                    if (data.doc_id && !data.isEdit) {
                        // New doc: redirect so page reloads with correct URL (triggers load by id/label)
                        setTimeout(() => { window.location.href = targetUrl; }, 500);
                    } else {
                        // Existing doc: update UI + URL (history.replaceState so reload uses correct label)
                        const docIdInput = document.getElementById('docIdInput');
                        if (docIdInput) docIdInput.value = data.doc_id;
                        // Defensive: .doc-info bisa null di layout yg beda (mis. deleted-mode
                        // header). Skip DOM update kalau tidak ada — data sudah masuk DB.
                        const docInfoEl = document.querySelector('.doc-info');
                        if (docInfoEl) {
                            docInfoEl.textContent = t('toolbar.doc_info_id_edit', {id: data.doc_id}, 'ID: {id} (Edit)');
                            docInfoEl.classList.remove('new');
                        }
                        btn.textContent = t('toolbar.update', {}, 'Update');
                        btn.disabled = false;
                        try { history.replaceState(null, '', targetUrl); } catch (e) {}
                    }
                } else {
                    showToast(data.message, 'error');
                    btn.textContent = origText;
                    btn.disabled = false;
                }
            } catch (e) {
                showToast(t('toast.save_failed', {error: e.message}, 'Failed to save: {error}'), 'error');
                btn.textContent = origText;
                btn.disabled = false;
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // Verify QR Mode — toggle global untuk ganti TTD gambar dengan QR verifikasi
        // ═══════════════════════════════════════════════════════════════════════
        //
        // Approach: save state via submit form, lalu reload halaman dengan flag baru.
        // Server-render langsung apply mode ini. Lebih reliable dari DOM manipulation.
        //
        // Flow:
        //   OFF → ON: modal → OK → save form (persist ke DB) → reload
        //             (kalau doc belum save & butuh NORM/Nopen → warn di modal)
        //   ON → OFF: save form dengan flag=0 → reload
        //
        // State: `_show_verify_qr` (0/1) di data_fields JSON. Support juga GET/POST override.

        function toggleVerifyQrMode() {
            const stateInput = document.getElementById('inputShowVerifyQr');
            if (!stateInput) return;
            const currentOn = stateInput.value === '1';

            if (currentOn) {
                // ON → OFF: langsung apply + save + reload
                stateInput.value = '0';
                saveAndReloadForVerifyQr(false);
                return;
            }

            // OFF → ON: buka modal konfirmasi
            const vu = document.getElementById('verifyUrlInput')?.value || '';
            const urlDisplay = document.getElementById('verifyQrModalUrl');
            const warnBox = document.getElementById('verifyQrModalWarn');
            const confirmBtn = document.getElementById('btnVerifyQrConfirm');
            const mainBox = document.getElementById('verifyQrModalMain');
            const savingBox = document.getElementById('verifyQrModalSaving');

            if (mainBox) mainBox.style.display = '';
            if (savingBox) savingBox.style.display = 'none';

            if (vu) {
                urlDisplay.value = vu;
                warnBox.style.display = 'none';
                confirmBtn.textContent = t('modal.verify_qr.confirm_activate', {}, 'OK, Activate');
                confirmBtn.disabled = false;
                confirmBtn.dataset.needSave = '0';
            } else {
                urlDisplay.value = t('modal.verify_qr.pending_url', {}, '(will be generated after the document is saved)');
                warnBox.style.display = '';
                confirmBtn.textContent = t('modal.verify_qr.save_and_activate', {}, 'Save & Activate');
                confirmBtn.dataset.needSave = '1';
                const norm = (document.getElementById('inputNorm')?.value || '').trim();
                const nopen = (document.getElementById('inputNopen')?.value || '').trim();
                const isGeneral = <?= (isset($isGeneralDoc) && $isGeneralDoc) ? 'true' : 'false' ?>;
                if (!isGeneral && (!norm || !nopen)) {
                    document.getElementById('verifyQrModalWarnMsg').textContent =
                        t('modal.verify_qr.identity_required_warning', {}, 'NORM & Nopen must be filled in first. Close this modal → fill them in the right sidebar → click toggle again.');
                    confirmBtn.disabled = true;
                } else {
                    confirmBtn.disabled = false;
                }
            }

            const modal = document.getElementById('verifyQrConfirmModal');
            // Tailwind `hidden` !important — must remove class BEFORE inline display works.
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeVerifyQrModal() {
            const modal = document.getElementById('verifyQrConfirmModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
        }

        async function confirmVerifyQrMode() {
            document.getElementById('inputShowVerifyQr').value = '1';
            const mainBox = document.getElementById('verifyQrModalMain');
            const savingBox = document.getElementById('verifyQrModalSaving');
            if (mainBox) mainBox.style.display = 'none';
            if (savingBox) savingBox.style.display = '';
            const confirmBtn = document.getElementById('btnVerifyQrConfirm');
            if (confirmBtn) confirmBtn.disabled = true;
            await saveAndReloadForVerifyQr(true);
        }

        // Generate QR verifikasi untuk TTD tertentu.
        // Server render area .ttd-area-verify-qr dengan preview "Loading QR..." — fungsi ini
        // replace dengan img QR yang di-generate dari verifyUrl (sama untuk semua TTD).
        function generateVerifyQr(ttdId) {
            const preview = document.getElementById('ttd_verify_qr_preview_' + ttdId);
            if (!preview) return;
            const verifyUrl = document.getElementById('verifyUrlInput')?.value || '';
            if (!verifyUrl) {
                preview.innerHTML = '<small class="text-amber-500 text-[10px]">' + t('ttd.save_first', {}, 'Save document first') + '</small>';
                return;
            }
            preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.loading_qr', {}, 'Loading QR...') + '</small>';
            fetch(_ezdocQrUrl(verifyUrl))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        preview.innerHTML = `<img src="${data.qr}" class="w-[100px] h-[100px] max-w-[none] max-h-[none] object-contain block my-0 mx-auto" alt="${t('ttd.verify_qr_alt', {}, 'Verification QR')}" title="${verifyUrl.replace(/"/g, '&quot;')}">`;
                    } else {
                        preview.innerHTML = `<small class="text-red-700 text-[10px]">${t('ttd.qr_failed', {error: data.message || 'error'}, 'QR failed: {error}')}</small>`;
                    }
                })
                .catch(err => {
                    preview.innerHTML = `<small class="text-red-700 text-[10px]">${t('ttd.qr_failed', {error: err.message}, 'QR failed: {error}')}</small>`;
                });
        }

        // Auto-generate verify QR saat page load kalau mode ON.
        // Server-render sudah include "Loading QR..." placeholder di area yang visible.
        // Wrap di DOMContentLoaded biar dijalankan setelah DOM siap.
        function initVerifyQrOnLoad() {
            const stateInput = document.getElementById('inputShowVerifyQr');
            if (!stateInput || stateInput.value !== '1') return;
            document.querySelectorAll('[id^="ttd_area_verify_qr_"]').forEach(el => {
                if (el.style.display !== 'none') {
                    const ttdId = el.id.replace('ttd_area_verify_qr_', '');
                    generateVerifyQr(ttdId);
                }
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initVerifyQrOnLoad);
        } else {
            initVerifyQrOnLoad();
        }

        // Save form via fetch (persist state), lalu reload URL dengan `_show_verify_qr=X`.
        // Server-render akan handle semua visual change (TTD → verify QR atau balik).
        async function saveAndReloadForVerifyQr(targetOn) {
            const norm = (document.getElementById('inputNorm')?.value || '').trim();
            const nopen = (document.getElementById('inputNopen')?.value || '').trim();
            const isGeneral = <?= (isset($isGeneralDoc) && $isGeneralDoc) ? 'true' : 'false' ?>;
            if (!isGeneral && (!norm || !nopen)) {
                closeVerifyQrModal();
                showToast(t('toast.identity_required_for_save', {}, 'NORM & Nopen must be filled in before saving'), 'error');
                return;
            }
            try {
                const formData = new FormData(document.getElementById('mainForm'));
                const resp = await fetch(_ezdocEndpoint('save'), { method: 'POST', body: formData });
                const data = await resp.json();
                if (!data.success) {
                    closeVerifyQrModal();
                    showToast(t('toast.save_failed', {error: data.message || 'error'}, 'Failed to save: {error}'), 'error');
                    return;
                }
                // Build URL untuk reload — pastikan doc_id + _show_verify_qr masuk
                const url = new URL(window.location.href);
                url.searchParams.set('_show_verify_qr', targetOn ? '1' : '0');
                if (data.doc_id) {
                    // Pastikan URL punya template_id, norm, nopen, label seperti flow submitForm biasa
                    url.searchParams.set('template_id', String(templateId));
                    if (!isGeneral) {
                        url.searchParams.set('norm', norm);
                        url.searchParams.set('nopen', nopen);
                    }
                    const label = data.label || (document.getElementById('inputLabel')?.value || '').trim();
                    if (label && label !== '-') url.searchParams.set('label', label);
                }
                window.location.href = url.toString();
            } catch (e) {
                closeVerifyQrModal();
                showToast(t('toast.save_failed', {error: e.message}, 'Failed to save: {error}'), 'error');
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // QR Field Modal — untuk standalone QR (klik area QR → modal → OK → isi)
        // ═══════════════════════════════════════════════════════════════════════

        let _qrFieldModalCurrentField = null;

        function openQrFieldModal(fieldName) {
            _qrFieldModalCurrentField = fieldName;
            const modal = document.getElementById('qrFieldModal');
            const nameEl = document.getElementById('qrFieldModalName');
            const verifyBox = document.getElementById('qrFieldVerifyBox');
            const verifyWarn = document.getElementById('qrFieldVerifyWarn');
            const customInput = document.getElementById('qrFieldCustomInput');
            const radioVerify = document.getElementById('qrFieldSourceVerify');
            const radioCustom = document.getElementById('qrFieldSourceCustom');
            const confirmBtn = document.getElementById('btnQrFieldConfirm');
            if (!modal) return;

            if (nameEl) nameEl.textContent = fieldName;

            // Isi current value (kalau sudah ada) — untuk custom input
            const currentVal = (document.getElementById('qrinput_' + fieldName)?.value || '').trim();
            if (customInput) customInput.value = currentVal;

            // Verify URL preview
            const vu = document.getElementById('verifyUrlInput')?.value || '';
            if (vu) {
                if (verifyBox) verifyBox.textContent = vu;
                if (verifyWarn) verifyWarn.style.display = 'none';
                if (radioVerify) radioVerify.disabled = false;
                if (confirmBtn) confirmBtn.disabled = false;
            } else {
                if (verifyBox) verifyBox.textContent = t('modal.qr_field.not_saved_placeholder', {}, '(document not saved yet)');
                if (verifyWarn) verifyWarn.style.display = '';
                if (radioVerify) radioVerify.disabled = true;
                // Force pilih custom mode kalau verify URL belum ada
                if (radioCustom) radioCustom.checked = true;
                if (confirmBtn) confirmBtn.disabled = false;
            }

            // Kalau current val cocok dengan verify_url, radio verify checked. Else custom.
            if (vu && currentVal === vu) {
                if (radioVerify) radioVerify.checked = true;
            } else if (currentVal !== '') {
                if (radioCustom) radioCustom.checked = true;
            } else {
                // Default: verify kalau ada, custom kalau tidak
                if (vu && radioVerify) radioVerify.checked = true;
                else if (radioCustom) radioCustom.checked = true;
            }

            // Tailwind `hidden` utility uses !important, jadi harus di-remove
            // via classList (inline style.display='flex' tidak menang lawan !important).
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
        }

        function closeQrFieldModal() {
            const modal = document.getElementById('qrFieldModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
            }
            _qrFieldModalCurrentField = null;
        }

        function confirmQrField() {
            const fieldName = _qrFieldModalCurrentField;
            if (!fieldName) { closeQrFieldModal(); return; }
            const radioVerify = document.getElementById('qrFieldSourceVerify');
            const customInput = document.getElementById('qrFieldCustomInput');
            let newValue = '';
            if (radioVerify && radioVerify.checked) {
                // Simpan MARKER `{verify_url}` supaya kalau base URL berubah (mis. dev → production),
                // server-side render auto-resolve ke URL current. Jangan simpan URL resolved
                // (yang bisa jadi stale kalau config berubah).
                if (!(document.getElementById('verifyUrlInput')?.value || '')) {
                    showToast(t('toast.verify_url_not_ready', {}, 'Verification URL not available yet. Save the document first.'), 'error');
                    return;
                }
                newValue = '{verify_url}';
            } else {
                newValue = (customInput?.value || '').trim();
                if (!newValue) {
                    showToast(t('toast.qr_content_required', {}, 'QR content cannot be empty'), 'error');
                    return;
                }
            }
            const input = document.getElementById('qrinput_' + fieldName);
            if (input) {
                input.value = newValue;
                if (typeof markDirty === 'function') markDirty();
                if (typeof updateQrPreview === 'function') updateQrPreview(fieldName);
            }
            closeQrFieldModal();
            showToast(t('toast.qr_filled', {}, 'QR filled in. Click Save to persist.'), 'success');
        }

        function showToast(message, type) {
            // Remove existing toast
            document.querySelectorAll('.toast').forEach(t => t.remove());

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // Build URLSearchParams yang preserve routing prefix (mis. ezdoc_page=generate).
        // Kalau library dipakai standalone (tanpa App orchestrator), current URL tidak
        // punya ezdoc_page → params tetap kosong = backward compat.
        function _preservedParams() {
            const cur = new URLSearchParams(window.location.search);
            const p = new URLSearchParams();
            // Preserve routing prefix keys — jangan bawa param dokumen supaya tidak
            // stale saat pindah context.
            ['ezdoc_page', 'ezdoc_asset'].forEach(k => {
                if (cur.has(k)) p.set(k, cur.get(k));
            });
            return p;
        }

        function createNew() {
            const params = _preservedParams();
            params.set('template_id', templateId);
            // Intentional programmatic nav — suppress dirty prompt (Livewire pattern)
            window._ezdocSuppressUnload = true;
            window.location.href = '?' + params.toString();
        }

        // Open PDF view in new tab (superadmin only)
        function viewPdfRaw() {
            const params = _preservedParams();
            params.set('template_id', templateId);
            if (CURRENT_DOC_ID) params.set('doc_id', CURRENT_DOC_ID);
            else {
                if (CURRENT_NORM) params.set('norm', CURRENT_NORM);
                if (CURRENT_NOPEN) params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL && CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                if (CURRENT_VERSION) params.set('version', CURRENT_VERSION);
            }
            params.set('view', 'pdf');
            window.open('?' + params.toString(), '_blank');
        }

        // Show document info modal
        function showDocInfo() {
            const meta = <?= json_encode($docMeta, JSON_UNESCAPED_UNICODE) ?>;
            if (!meta.id) return;

            const fmtDate = (s) => {
                if (!s) return '-';
                const d = new Date(s.replace(' ', 'T'));
                if (isNaN(d)) return s;
                const pad = (n) => String(n).padStart(2, '0');
                return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
            };

            const createdBy = meta.created_by_name
                ? `${meta.created_by_name} (ID: ${meta.created_by})`
                : (meta.created_by ? `ID: ${meta.created_by}` : '-');

            // Build materai info rows from current rendered placeholders + hidden inputs
            let materaiRows = '';
            const materaiWraps = document.querySelectorAll('.materai-wrap');
            if (materaiWraps.length > 0) {
                materaiRows = '<tr><td colspan="2" class="py-2.5 px-0"><hr class="m-0 border-0" style="border-top:1px solid #e5e7eb;"></td></tr>' +
                    `<tr><td colspan="2" class="py-1.5 px-0 text-orange-700 font-bold"><i class="bi bi-stamp"></i> ${t('modal.doc_info.materai_heading', {}, 'Materai')}</td></tr>`;
                materaiWraps.forEach(wrap => {
                    const mid = wrap.getAttribute('data-materai-id') || '';
                    const mode = wrap.getAttribute('data-materai-mode') || 'upload';
                    const lblEl = wrap.querySelector('.materai-label');
                    const lbl = lblEl ? lblEl.textContent.trim() : mid;
                    const imgVal = (document.getElementById('materai_img_' + mid) || {}).value || '';
                    const serialVal = (document.getElementById('materai_serial_' + mid) || {}).value || '';
                    const uploadAt = (document.getElementById('materai_upload_' + mid) || {}).value || '';
                    let statusBadge;
                    if (mode === 'kosong') {
                        statusBadge = `<span class="text-gray-500 text-[11px]">${t('modal.doc_info.materai_manual_status', {}, 'Manual stamp')}</span>`;
                    } else {
                        statusBadge = imgVal
                            ? `<span class="text-green-600 text-[11px]">✓ ${t('modal.doc_info.materai_filled_status', {}, 'Filled')}</span>`
                            : `<span class="text-red-600 text-[11px]">${t('modal.doc_info.materai_missing_status', {}, 'Not uploaded')}</span>`;
                    }
                    materaiRows += `<tr>
                        <td class="py-1 px-0 text-gray-500 text-xs">${escapeHtmlSimple(lbl)}</td>
                        <td class="py-1 px-0 text-xs">
                            ${statusBadge}
                            ${serialVal ? `<div class="text-[10px] text-gray-500">${t('modal.doc_info.materai_serial_label', {serial: escapeHtmlSimple(serialVal)}, 'Serial No.: {serial}')}</div>` : ''}
                            ${uploadAt ? `<div class="text-[10px] text-gray-400">${t('modal.doc_info.materai_upload_label', {date: fmtDate(uploadAt)}, 'Uploaded: {date}')}</div>` : ''}
                        </td>
                    </tr>`;
                });
            }

            let modal = document.getElementById('docInfoModal');
            if (modal) modal.remove();
            modal = document.createElement('div');
            modal.id = 'docInfoModal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:4000;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
            modal.innerHTML = `
                <div class="bg-white rounded-[10px] w-[90%] max-w-[420px] p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h5 class="m-0"><i class="bi bi-info-circle"></i> ${t('modal.doc_info.title', {}, 'Document Details')}</h5>
                        <button onclick="document.getElementById('docInfoModal').remove()" class="border-0 bg-transparent text-xl cursor-pointer text-gray-500">&times;</button>
                    </div>
                    <table class="w-full text-[13px]" style="border-collapse:collapse;">
                        <tr><td class="py-1.5 px-0 text-gray-500 w-[40%]">${t('modal.doc_info.document_id_label', {}, 'Document ID')}</td><td class="py-1.5 px-0"><code>${meta.id}</code></td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.version_row_label', {}, 'Version')}</td><td class="py-1.5 px-0">v${CURRENT_VERSION} ${<?= $param_is_locked ? 'true' : 'false' ?> ? t('toolbar.locked', {}, 'Locked') : t('modal.doc_info.editable_suffix', {}, '(Editable)')}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.norm_row_label', {}, 'MR No.')}</td><td class="py-1.5 px-0">${escapeHtmlSimple(CURRENT_NORM || '-')}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.nopen_row_label', {}, 'Registration No.')}</td><td class="py-1.5 px-0">${escapeHtmlSimple(CURRENT_NOPEN || '-')}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.label_row_label', {}, 'Label')}</td><td class="py-1.5 px-0">${escapeHtmlSimple(CURRENT_LABEL || '-')}</td></tr>
                        <tr><td colspan="2" class="py-2.5 px-0"><hr class="m-0 border-0" style="border-top:1px solid #e5e7eb;"></td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.created_by_label', {}, 'Created by')}</td><td class="py-1.5 px-0">${escapeHtmlSimple(createdBy)}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.created_at_label', {}, 'Created at')}</td><td class="py-1.5 px-0">${fmtDate(meta.created_at)}</td></tr>
                        <tr><td class="py-1.5 px-0 text-gray-500">${t('modal.doc_info.updated_at_label', {}, 'Last updated')}</td><td class="py-1.5 px-0">${fmtDate(meta.updated_at)}</td></tr>
                        ${materaiRows}
                    </table>
                    <div class="text-right mt-4">
                        <button onclick="document.getElementById('docInfoModal').remove()" class="py-1.5 px-4 border-0 bg-blue-500 text-white rounded-md cursor-pointer">${t('actions.close', {}, 'Close')}</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) modal.remove();
            });
        }

        function escapeHtmlSimple(s) {
            return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        // ===== Document versioning =====
        const CURRENT_DOC_ID = <?= (int)$doc_id ?>;
        const CURRENT_VERSION = <?= (int)$param_version ?>;
        const CURRENT_NORM = <?= json_encode($param_norm) ?>;
        const CURRENT_NOPEN = <?= json_encode($param_nopen) ?>;
        const CURRENT_LABEL = <?= json_encode($param_label) ?>;

        // Load all versions for the current slot & populate dropdown
        async function refreshVersionList() {
            if (!CURRENT_NORM || !CURRENT_NOPEN) return;
            const fd = new FormData();
            fd.append('_doc_action', 'list_versions');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            const sel = document.getElementById('versionSelect');
            if (!sel || !data.success) return;
            sel.innerHTML = '';
            data.versions.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.version;
                const lockIcon = v.is_locked ? ' [Locked]' : '';
                const currMark = v.version === CURRENT_VERSION ? ' ' + t('toolbar.version_current_suffix', {}, '(current)') : '';
                opt.textContent = `v${v.version}${lockIcon}${currMark}`;
                if (v.version === CURRENT_VERSION) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        function switchVersion(version) {
            if (parseInt(version) === CURRENT_VERSION) return;
            // Preserve ezdoc_page=generate supaya App router tidak fallback ke default_page.
            const params = _preservedParams();
            params.set('template_id', templateId);
            params.set('norm', CURRENT_NORM);
            params.set('nopen', CURRENT_NOPEN);
            if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
            params.set('version', version);
            // Intentional programmatic nav — suppress dirty prompt (Livewire pattern)
            window._ezdocSuppressUnload = true;
            window.location.href = '?' + params.toString();
        }

        async function toggleDocLock() {
            if (!CURRENT_DOC_ID) return;
            const currentLocked = <?= (int)$param_is_locked ?>;
            const newLocked = currentLocked ? 0 : 1;

            // Pre-lock check: warn if any 'upload' mode materai is empty
            if (newLocked) {
                const missingLabels = [];
                document.querySelectorAll('.materai-wrap[data-materai-mode="upload"]').forEach(wrap => {
                    const mid = wrap.getAttribute('data-materai-id') || '';
                    const imgInput = document.getElementById('materai_img_' + mid);
                    if (!imgInput || !imgInput.value || imgInput.value.length < 30) {
                        const lblEl = wrap.querySelector('.materai-label');
                        missingLabels.push(lblEl ? lblEl.textContent.trim() : mid);
                    }
                });
                if (missingLabels.length > 0) {
                    const warn = t('materai.lock_missing_warning', {list: missingLabels.join('\n  - ')}, 'The following materai have not been uploaded:\n  - {list}\n\nLock this version anyway?');
                    if (!(await ezdocConfirm(warn, { title: t('materai.warning_title', [], 'Warning'), variant: 'warning' }))) return;
                }
            }

            const msg = newLocked
                ? t('confirm.lock_final', {}, 'Lock this version as FINAL?\n\nOnce locked, this version cannot be edited. Only a superadmin can unlock it. Create a new version to revise.')
                : t('confirm.unlock_version', {}, 'Unlock this version? (superadmin access)');
            if (!(await ezdocConfirm(msg, { title: newLocked ? 'Lock Version' : 'Unlock Version', variant: newLocked ? 'danger' : 'warning' }))) return;
            const fd = new FormData();
            fd.append('_doc_action', 'toggle_doc_lock');
            fd.append('doc_id', CURRENT_DOC_ID);
            fd.append('locked', newLocked);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                location.reload();
            } else {
                ezdocAlert(t('alert.generic_failed', {reason: data.message || 'error'}, 'Failed: {reason}'), { title: 'Error', variant: 'error' });
            }
        }

        async function deleteThisVersion() {
            if (!CURRENT_DOC_ID) return;
            if (!(await ezdocConfirm(
                t('confirm.delete_version', {version: CURRENT_VERSION}, 'Delete version v{version}? This cannot be undone.'),
                { title: 'Delete Version', variant: 'danger', confirmText: 'Delete' }
            ))) return;
            const fd = new FormData();
            fd.append('_doc_action', 'delete_version');
            fd.append('doc_id', CURRENT_DOC_ID);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                // Reload latest version after delete — preserve ezdoc_page prefix
                const params = _preservedParams();
                params.set('template_id', templateId);
                params.set('norm', CURRENT_NORM);
                params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                // Intentional programmatic nav — suppress dirty prompt (Livewire pattern)
            window._ezdocSuppressUnload = true;
            window.location.href = '?' + params.toString();
            } else {
                ezdocAlert(t('alert.generic_failed', {reason: data.message || 'error'}, 'Failed: {reason}'), { title: 'Error', variant: 'error' });
            }
        }

        // Restore soft-deleted slot from preview mode (superadmin only)
        async function restoreDeletedSlot() {
            if (!(await ezdocConfirm(
                t('confirm.restore_slot', {}, 'Restore this entire document slot? All soft-deleted versions will become active again.'),
                { title: 'Restore Slot', variant: 'warning', confirmText: 'Restore' }
            ))) return;
            const fd = new FormData();
            fd.append('_doc_action', 'restore_slot');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                ezdocAlert(t('alert.restore_success', {count: data.affected || 0}, 'Restored successfully ({count} version(s))'), { title: 'Restored', variant: 'success' });
                // Reload sebagai dokumen aktif — preserve ezdoc_page prefix
                const params = _preservedParams();
                params.set('template_id', templateId);
                params.set('norm', CURRENT_NORM);
                params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                // Intentional programmatic nav — suppress dirty prompt (Livewire pattern)
            window._ezdocSuppressUnload = true;
            window.location.href = '?' + params.toString();
            } else {
                ezdocAlert(t('alert.generic_failed', {reason: data.message || 'error'}, 'Failed: {reason}'), { title: 'Error', variant: 'error' });
            }
        }

        // Modal: buat versi baru (prompt copy-from-version / blank)
        async function showNewVersionModal() {
            // Load all versions for picker
            const fd = new FormData();
            fd.append('_doc_action', 'list_versions');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            const versions = data.success ? data.versions : [];

            // Build modal dynamically
            let modal = document.getElementById('newVersionModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'newVersionModal';
                modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:4000;display:flex;align-items:center;justify-content:center;';
                document.body.appendChild(modal);
            }
            const optsHtml = versions.map(v =>
                `<option value="${v.version}">v${v.version}${v.is_locked ? ' [Locked]' : ''}</option>`
            ).join('');

            modal.innerHTML = `
                <div class="bg-white rounded-[10px] w-[90%] max-w-[400px] p-5" style="font-family:sans-serif;">
                    <h5 class="mt-0 mr-0 mb-3 ml-0">${t('modal.new_version.title', {}, 'Create New Version')}</h5>
                    <p class="text-gray-500 text-[13px] mb-3">${t('modal.new_version.description', {}, 'Choose the data source for the new version:')}</p>

                    <label class="block mb-2 cursor-pointer">
                        <input type="radio" name="newVerSource" value="blank" checked>
                        ${t('modal.new_version.source_blank', {}, 'Blank (start from scratch)')}
                    </label>
                    <label class="block mb-2 cursor-pointer">
                        <input type="radio" name="newVerSource" value="copy">
                        ${t('modal.new_version.source_copy', {}, 'Copy from version:')}
                        <select id="copyVerSelect" class="ml-1.5 py-0.5 px-1.5">
                            ${optsHtml}
                        </select>
                    </label>

                    <div class="flex gap-2 mt-4 justify-end">
                        <button onclick="document.getElementById('newVersionModal').remove()" class="py-1.5 px-4 border border-gray-300 bg-white rounded-md cursor-pointer">${t('actions.cancel', {}, 'Cancel')}</button>
                        <button onclick="doCreateNewVersion()" class="py-1.5 px-4 border-0 bg-violet-500 text-white rounded-md cursor-pointer">${t('modal.new_version.title', {}, 'Create New Version')}</button>
                    </div>
                </div>
            `;
        }

        async function doCreateNewVersion() {
            const source = document.querySelector('input[name="newVerSource"]:checked')?.value || 'blank';
            const sourceVersion = source === 'copy' ? parseInt(document.getElementById('copyVerSelect').value || '0') : 0;

            const fd = new FormData();
            fd.append('_doc_action', 'new_version');
            fd.append('template_id', templateId);
            fd.append('norm', CURRENT_NORM);
            fd.append('nopen', CURRENT_NOPEN);
            fd.append('label', CURRENT_LABEL);
            fd.append('source_version', sourceVersion);

            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('newVersionModal')?.remove();
                // Redirect to new version — preserve ezdoc_page=generate supaya
                // App router tidak fallback ke default_page (list).
                const params = _preservedParams();
                params.set('template_id', templateId);
                params.set('norm', CURRENT_NORM);
                params.set('nopen', CURRENT_NOPEN);
                if (CURRENT_LABEL !== '-') params.set('label', CURRENT_LABEL);
                params.set('version', data.version);
                // Intentional programmatic nav — suppress dirty prompt (Livewire pattern)
            window._ezdocSuppressUnload = true;
            window.location.href = '?' + params.toString();
            } else {
                ezdocAlert(t('alert.generic_failed', {reason: data.message || 'error'}, 'Failed: {reason}'), { title: 'Error', variant: 'error' });
            }
        }

        // Auto-populate version dropdown on load
        if (CURRENT_DOC_ID) {
            refreshVersionList();
        }

        // Toolbar show/hide
        function toggleToolbar() {
            const tb = document.getElementById('toolbarPanel');
            const btn = document.getElementById('toolbarToggleBtn');
            const isCollapsed = tb.classList.toggle('collapsed');
            btn.innerHTML = isCollapsed ? '&#9776;' : '&minus; ' + t('toolbar.hide_label', {}, 'Hide');
            btn.title = isCollapsed ? t('title.show_toolbar', {}, 'Show Toolbar') : t('title.hide_toolbar', {}, 'Hide Toolbar');
            try { localStorage.setItem('surat_toolbar_collapsed', isCollapsed ? '1' : '0'); } catch (e) {}
        }

        // Apply saved toolbar state on load (default: collapsed on narrow screens)
        (function() {
            let saved = null;
            try { saved = localStorage.getItem('surat_toolbar_collapsed'); } catch (e) {}
            const shouldCollapse = saved === null ? (window.innerWidth < 1024) : saved === '1';
            const tb = document.getElementById('toolbarPanel');
            const btn = document.getElementById('toolbarToggleBtn');
            if (shouldCollapse) {
                if (tb) tb.classList.add('collapsed');
                if (btn) { btn.innerHTML = '&#9776;'; btn.title = t('title.show_toolbar', {}, 'Show Toolbar'); }
            } else {
                if (btn) { btn.innerHTML = '&minus; ' + t('toolbar.hide_label', {}, 'Hide'); btn.title = t('title.hide_toolbar', {}, 'Hide Toolbar'); }
            }
        })();

        function openSign(id, label) {
            if (!editMode) {
                ezdocAlert(t('ttd.locked_no_sign', {}, 'This document is locked. Cannot sign. Create a new version to revise.'), { title: 'Locked', variant: 'warning' });
                return;
            }
            currentTtdId = id;
            document.getElementById('signTitle').textContent = label || t('fallback.signature', {}, 'Signature');
            modal.classList.add('show');
            clearSign();
            const existing = document.getElementById('ttd_' + id)?.value;
            if (existing) {
                const img = new Image();
                img.onload = () => {
                    // Scale and center existing signature on larger canvas
                    const scale = Math.min(canvas.width / img.width, canvas.height / img.height, 2);
                    const w = img.width * scale;
                    const h = img.height * scale;
                    const x = (canvas.width - w) / 2;
                    const y = (canvas.height - h) / 2;
                    ctx.drawImage(img, x, y, w, h);
                };
                img.src = existing;
            }
        }

        function closeSign() { modal.classList.remove('show'); }
        function clearSign() { ctx.clearRect(0, 0, canvas.width, canvas.height); }

        // Generate action buttons HTML
        function getActionsHtml(ttdId, label) {
            return `<div class="ttd-actions">
                <button type="button" class="btn-edit" onclick="openSign('${ttdId}', '${label}')">&#9998; ${t('actions.edit', {}, 'Edit')}</button>
                <button type="button" class="btn-delete" onclick="clearTtd('${ttdId}')">&#10005; ${t('actions.delete', {}, 'Delete')}</button>
            </div>`;
        }

        function saveSign() {
            if (currentTtdId) {
                const data = canvas.toDataURL('image/png');
                const hiddenInput = document.getElementById('ttd_' + currentTtdId);
                hiddenInput.value = data;

                const preview = document.getElementById('preview_' + currentTtdId);
                if (preview) {
                    const labelEl = preview.parentElement.querySelector('.ttd-label');
                    const label = labelEl ? labelEl.textContent : t('fallback.signature', {}, 'Signature');
                    // Check if using new area structure
                    const imgArea = preview.querySelector('.ttd-area-image');
                    const target = imgArea || preview;
                    target.innerHTML = `<img src="${data}" class="ttd-signature" alt="TTD">${getActionsHtml(currentTtdId, label)}`;
                }
                if (typeof triggerDirty === 'function') triggerDirty();
            }
            closeSign();
        }

        // Clear/delete TTD signature
        // ===== Materai handlers =====
        function handleMateraiUpload(input, materaiId) {
            if (!editMode) { ezdocAlert(t('materai.locked_no_upload', {}, 'Document is locked, cannot upload.'), { title: 'Locked', variant: 'warning' }); input.value = ''; return; }
            const file = input.files && input.files[0];
            if (!file) return;

            // Validate type & size (max 2MB)
            if (!/^image\/(png|jpe?g|gif)$/i.test(file.type)) {
                ezdocAlert(t('materai.invalid_format', {}, 'Format must be PNG / JPG.'), { title: 'Invalid Format', variant: 'error' }); input.value = ''; return;
            }
            if (file.size > 2 * 1024 * 1024) {
                ezdocAlert(t('materai.max_size', {}, 'Maximum file size is 2MB.'), { title: 'File Too Large', variant: 'error' }); input.value = ''; return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const dataUrl = e.target.result;
                if (!/^data:image\/(png|jpe?g|gif);base64,/.test(dataUrl)) {
                    ezdocAlert(t('materai.invalid_file', {}, 'Invalid file.'), { title: 'Invalid File', variant: 'error' }); return;
                }
                const hiddenImg = document.getElementById('materai_img_' + materaiId);
                if (hiddenImg) hiddenImg.value = dataUrl;
                const hiddenUpload = document.getElementById('materai_upload_' + materaiId);
                if (hiddenUpload) {
                    const now = new Date();
                    const pad = n => String(n).padStart(2, '0');
                    hiddenUpload.value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
                }
                // Update preview
                const preview = document.getElementById('materai_preview_' + materaiId);
                if (preview) {
                    preview.innerHTML = `<img src="${dataUrl}" class="materai-image" alt="Materai">
                        <div class="materai-actions">
                            <button type="button" class="btn-edit" onclick="document.getElementById('materai_file_${materaiId}').click()">&#9998; ${t('materai.replace', {}, 'Replace')}</button>
                            <button type="button" class="btn-delete" onclick="clearMaterai('${materaiId}')">&#10005; ${t('actions.delete', {}, 'Delete')}</button>
                        </div>`;
                }
                if (typeof triggerDirty === 'function') triggerDirty();
            };
            reader.readAsDataURL(file);
            input.value = ''; // allow same-file re-upload later
        }

        async function clearMaterai(materaiId) {
            if (!editMode) return;
            if (!(await ezdocConfirm(
                t('materai.confirm_delete', {}, 'Delete this e-Materai?'),
                { title: 'Delete Materai', variant: 'danger', confirmText: 'Delete' }
            ))) return;
            const hiddenImg = document.getElementById('materai_img_' + materaiId);
            if (hiddenImg) hiddenImg.value = '';
            const hiddenUpload = document.getElementById('materai_upload_' + materaiId);
            if (hiddenUpload) hiddenUpload.value = '';
            if (typeof triggerDirty === 'function') triggerDirty();
            const preview = document.getElementById('materai_preview_' + materaiId);
            if (preview) {
                preview.innerHTML = `<div class="materai-upload-box" onclick="document.getElementById('materai_file_${materaiId}').click()">
                    <strong>${t('materai.upload_prompt', {}, 'UPLOAD<br>e-MATERAI')}</strong>
                </div>`;
            }
        }

        async function clearTtd(ttdId) {
            if (!(await ezdocConfirm(
                t('ttd.confirm_delete', {}, 'Delete this signature?'),
                { title: 'Delete Signature', variant: 'danger', confirmText: 'Delete' }
            ))) return;

            const hiddenInput = document.getElementById('ttd_' + ttdId);
            if (hiddenInput) hiddenInput.value = '';

            const preview = document.getElementById('preview_' + ttdId);
            if (preview) {
                const imgArea = preview.querySelector('.ttd-area-image');
                if (imgArea) {
                    const labelEl = preview.parentElement.querySelector('.ttd-label');
                    const label = labelEl ? labelEl.textContent : t('fallback.signature', {}, 'Signature');
                    imgArea.innerHTML = `<div class="ttd-canvas-placeholder" onclick="openSign('${ttdId}', '${label}')"></div>`;
                } else {
                    const labelEl = preview.parentElement.querySelector('.ttd-label');
                    const label = labelEl ? labelEl.textContent : t('fallback.signature', {}, 'Signature');
                    preview.innerHTML = `<div class="ttd-canvas-placeholder" onclick="openSign('${ttdId}', '${label}')"></div>`;
                }
            }
            if (typeof triggerDirty === 'function') triggerDirty();
        }

        // Switch TTD mode between image and QR
        function switchTtdMode(ttdId, mode) {
            const modeInput = document.getElementById('ttd_mode_' + ttdId);
            if (modeInput) modeInput.value = mode;
            if (typeof triggerDirty === 'function') triggerDirty();

            const imgArea = document.getElementById('ttd_area_image_' + ttdId);
            const qrArea = document.getElementById('ttd_area_qr_' + ttdId);

            if (imgArea) imgArea.style.display = mode === 'image' ? '' : 'none';
            if (qrArea) {
                qrArea.style.display = mode === 'qr' ? '' : 'none';
                if (mode === 'qr') generateTtdQr(ttdId);
            }

            // Update toggle buttons
            const container = imgArea?.closest('.ttd-item-inline, .ttd-item-floating');
            if (container) {
                container.querySelectorAll('.ttd-mode-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.textContent.trim() === (mode === 'image' ? t('ttd.mode_image', {}, 'Image') : t('ttd.mode_qr', {}, 'QR')));
                });
            }
        }

        // Generate QR code for TTD
        function generateTtdQr(ttdId) {
            const preview = document.getElementById('ttd_qr_preview_' + ttdId);
            if (!preview) return;

            // Priority 1: user-typed per-document content
            let qrData = (document.getElementById('ttd_qr_content_' + ttdId)?.value || '').trim();

            // Priority 2: resolve template pattern with current form field values
            if (!qrData) {
                const qrDataTpl = document.getElementById('ttd_qr_data_' + ttdId)?.value || '';
                if (!qrDataTpl) {
                    preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.fill_or_set_pattern', {}, 'Fill in QR content or set a pattern in the template') + '</small>';
                    return;
                }
                qrData = qrDataTpl.replace(/\{([^}]+)\}/g, (_, fieldName) => {
                    const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
                    if (input) return input.value || '';
                    const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                    if (editable) return editable.textContent || '';
                    return '';
                }).trim();
            }

            if (!qrData) {
                preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.fill_field_first', {}, 'Fill in the field first') + '</small>';
                return;
            }

            preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.generating', {}, 'Generating...') + '</small>';

            fetch(_ezdocQrUrl(qrData))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        preview.innerHTML = `<img src="${data.qr}" class="w-[100px] h-[100px] max-w-[none] max-h-[none] object-contain block" alt="${t('ttd.qr_alt', {}, 'Signature QR')}" title="${qrData.replace(/"/g, '&quot;')}">`;
                    } else {
                        preview.innerHTML = `<small class="text-red-700 text-[10px]">${t('ttd.qr_failed', {error: data.message || 'error'}, 'QR failed: {error}')}</small>`;
                    }
                })
                .catch(err => {
                    preview.innerHTML = `<small class="text-red-700 text-[10px]">${t('ttd.qr_failed', {error: err.message}, 'QR failed: {error}')}</small>`;
                });
        }

        // Buka prompt untuk edit isi QR (input-nya disembunyikan supaya tidak keliatan mengganggu)
        function editQrContent(ttdId, resolvedPattern) {
            const input = document.getElementById('ttd_qr_content_' + ttdId);
            if (!input) return;
            const current = input.value || '';
            const patternHint = resolvedPattern ? t('ttd.edit_qr_prompt_default_hint', {pattern: resolvedPattern}, '\n\nDefault template: {pattern}\n(Leave empty to use the default template)') : '';
            const newVal = prompt(t('ttd.edit_qr_prompt_label', {}, 'QR content for this signature:') + patternHint, current);
            if (newVal === null) return; // user batal
            input.value = newVal.trim();
            markDirty && markDirty();
            generateTtdQr(ttdId);
        }

        // Auto-generate QR for TTD on page load + bind field changes
        (function initTtdQr() {
            const areas = document.querySelectorAll('[id^="ttd_area_qr_"]');
            if (!areas.length) return;

            // Generate QR for all visible QR areas
            areas.forEach(el => {
                if (el.style.display !== 'none') {
                    const ttdId = el.id.replace('ttd_area_qr_', '');
                    generateTtdQr(ttdId);
                }
            });

            // Collect all field names referenced across all QR templates
            const referencedFields = new Set();
            const ttdToFields = {};
            document.querySelectorAll('[id^="ttd_qr_data_"]').forEach(el => {
                const ttdId = el.id.replace('ttd_qr_data_', '');
                const tpl = el.value || '';
                const fields = [...tpl.matchAll(/\{([^}]+)\}/g)].map(m => m[1]);
                ttdToFields[ttdId] = fields;
                fields.forEach(f => referencedFields.add(f));
            });

            // Debounce regeneration
            let regenTimer = null;
            const scheduleRegen = () => {
                clearTimeout(regenTimer);
                regenTimer = setTimeout(() => {
                    Object.keys(ttdToFields).forEach(ttdId => {
                        const area = document.getElementById('ttd_area_qr_' + ttdId);
                        if (area && area.style.display !== 'none') generateTtdQr(ttdId);
                    });
                }, 400);
            };

            // Bind change events to all referenced fields
            referencedFields.forEach(fieldName => {
                const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
                if (input) {
                    input.addEventListener('input', scheduleRegen);
                    input.addEventListener('change', scheduleRegen);
                }
                const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                if (editable) editable.addEventListener('input', scheduleRegen);
            });
        })();

        function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
            const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
            return [x * (canvas.width / rect.width), y * (canvas.height / rect.height)];
        }

        canvas.addEventListener('mousedown', e => { drawing = true; ctx.beginPath(); ctx.moveTo(...getPos(e)); });
        canvas.addEventListener('mousemove', e => { if (drawing) { ctx.lineTo(...getPos(e)); ctx.stroke(); } });
        canvas.addEventListener('mouseup', () => drawing = false);
        canvas.addEventListener('mouseleave', () => drawing = false);
        canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; ctx.beginPath(); ctx.moveTo(...getPos(e)); });
        canvas.addEventListener('touchmove', e => { e.preventDefault(); if (drawing) { ctx.lineTo(...getPos(e)); ctx.stroke(); } });
        canvas.addEventListener('touchend', () => drawing = false);

        // ===== Keyboard shortcuts (#21) =====
        // Admin capability flag — resolved server-side via RoleProvider (see $adminRoleSlug config).
        // spec: ezdoc-spec/context/role_provider.md#capabilities
        const IS_ADMIN_JS      = <?= $isSuperadmin ? 'true' : 'false' ?>;
        // Legacy alias — kept for backward compat with existing JS references (do not use in new code)
        const IS_SUPERADMIN_JS = IS_ADMIN_JS;

        function showShortcutsHelp() {
            // Toggle if already open
            const existing = document.getElementById('shortcutsHelpModal');
            if (existing) { existing.remove(); return; }
            const modal = document.createElement('div');
            modal.id = 'shortcutsHelpModal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:5000;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
            modal.innerHTML = `
                <div class="bg-white rounded-[10px] w-[90%] max-w-[480px] p-5">
                    <div class="flex justify-between items-center mb-3.5">
                        <h5 class="m-0"><i class="bi bi-keyboard"></i> ${t('modal.shortcuts.title', {}, 'Keyboard Shortcuts')}</h5>
                        <button onclick="document.getElementById('shortcutsHelpModal').remove()" class="border-0 bg-transparent text-xl cursor-pointer text-gray-500">&times;</button>
                    </div>
                    <table class="w-full text-[13px]" style="border-collapse:collapse;">
                        <tr><td class="py-[5px] px-0 text-gray-500 w-[40%]"><kbd>Ctrl</kbd> + <kbd>S</kbd></td><td class="py-[5px] px-0">${t('modal.shortcuts.save_doc', {}, 'Save document')}</td></tr>
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>P</kbd></td><td class="py-[5px] px-0">${t('modal.shortcuts.print_browser', {}, 'Print (browser)')}</td></tr>
                        ${IS_SUPERADMIN_JS ? `<tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>P</kbd></td><td class="py-[5px] px-0">${t('modal.shortcuts.view_pdf_admin', {}, 'View PDF (admin)')}</td></tr>` : ''}
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>L</kbd></td><td class="py-[5px] px-0">${t('modal.shortcuts.focus_label', {}, 'Focus the Label input')}</td></tr>
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Esc</kbd></td><td class="py-[5px] px-0">${t('modal.shortcuts.close_modal', {}, 'Close active modal / signature canvas')}</td></tr>
                        <tr><td class="py-[5px] px-0 text-gray-500"><kbd>Ctrl</kbd> + <kbd>/</kbd></td><td class="py-[5px] px-0">${t('modal.shortcuts.show_help', {}, 'Show this help')}</td></tr>
                    </table>
                    <div class="text-right mt-4">
                        <button onclick="document.getElementById('shortcutsHelpModal').remove()" class="py-1.5 px-4 border-0 bg-blue-500 text-white rounded-md cursor-pointer">${t('actions.close', {}, 'Close')}</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
        }

        document.addEventListener('keydown', function(e) {
            const mod = e.ctrlKey || e.metaKey;

            // Ctrl+S — Save
            if (mod && !e.shiftKey && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                if (typeof submitForm === 'function') submitForm();
                return;
            }

            // Ctrl+Shift+P — View PDF (superadmin only)
            if (mod && e.shiftKey && (e.key === 'p' || e.key === 'P')) {
                if (IS_SUPERADMIN_JS && typeof viewPdfRaw === 'function') {
                    e.preventDefault();
                    viewPdfRaw();
                }
                return;
            }

            // Ctrl+P — Print (let default also work, but ensure)
            if (mod && !e.shiftKey && (e.key === 'p' || e.key === 'P')) {
                // Don't preventDefault — let browser print dialog open natively
                return;
            }

            // Ctrl+L — focus label input
            if (mod && (e.key === 'l' || e.key === 'L')) {
                const lbl = document.getElementById('inputLabel');
                if (lbl) {
                    e.preventDefault();
                    lbl.focus();
                    lbl.select && lbl.select();
                }
                return;
            }

            // Ctrl+/ — show shortcuts help
            if (mod && e.key === '/') {
                e.preventDefault();
                showShortcutsHelp();
                return;
            }

            // Esc — close active modal / sign canvas
            if (e.key === 'Escape') {
                // Sign canvas modal
                const signModal = document.getElementById('signModal');
                if (signModal && signModal.classList.contains('show')) {
                    if (typeof closeSign === 'function') closeSign();
                    return;
                }
                // Doc info modal
                const docInfo = document.getElementById('docInfoModal');
                if (docInfo) { docInfo.remove(); return; }
                // New version modal
                const newVer = document.getElementById('newVersionModal');
                if (newVer) { newVer.remove(); return; }
                // Shortcuts help
                const sc = document.getElementById('shortcutsHelpModal');
                if (sc) { sc.remove(); return; }
            }
        });

        // Sync contenteditable fields to hidden inputs
        function syncFields() {
            document.querySelectorAll('.f[data-field]').forEach(el => {
                const hiddenInput = el.nextElementSibling;
                if (hiddenInput && hiddenInput.type === 'hidden') {
                    hiddenInput.value = el.innerText || '';
                }
            });
        }

        // Propagate a value to ALL elements sharing the same field name.
        // Handles contenteditable (.f), hidden inputs, text/date/select, checkbox, radio, qr-content-input.
        // `source` is excluded (to avoid cursor jump / infinite loop).
        function propagateFieldValue(fieldName, value, source) {
            if (!fieldName) return;
            // Contenteditable spans with matching data-field
            document.querySelectorAll(`.f[data-field="${CSS.escape(fieldName)}"]`).forEach(el => {
                if (el === source) return;
                if ((el.innerText || '') !== value) el.innerText = value;
            });
            // All inputs/selects with matching name (hidden, text, date, select, number, qr content)
            document.querySelectorAll(`[name="${CSS.escape(fieldName)}"]`).forEach(el => {
                if (el === source) return;
                if (el.type === 'checkbox') {
                    el.checked = (value === '1' || value === 'true' || value === 'yes');
                } else if (el.type === 'radio') {
                    el.checked = (el.value === value);
                } else if (el.value !== value) {
                    el.value = value;
                }
            });
        }

        // Bind events to all contenteditable fields
        document.querySelectorAll('.f[data-field]').forEach(el => {
            // Sync on input - update hidden sibling + all duplicates
            el.addEventListener('input', function() {
                const val = this.innerText || '';
                const hiddenInput = this.nextElementSibling;
                if (hiddenInput && hiddenInput.type === 'hidden') {
                    hiddenInput.value = val;
                }
                propagateFieldValue(this.dataset.field, val, this);
            });

            // Handle paste - clean up formatting (plain text only)
            el.addEventListener('paste', function(e) {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text/plain');
                document.execCommand('insertText', false, text);
            });

            // Enter to move to next field or blur, Shift+Enter for line break
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    // Find next field
                    const allFields = Array.from(document.querySelectorAll('.f[data-field]'));
                    const idx = allFields.indexOf(this);
                    if (idx < allFields.length - 1) {
                        allFields[idx + 1].focus();
                    } else {
                        this.blur();
                    }
                }
            });
        });

        // Live-sync for non-contenteditable inputs (date, select, checkbox, radio, qr-content-input)
        document.querySelectorAll('input[name], select[name]').forEach(el => {
            // Skip hidden inputs (they're updated via contenteditable propagation) and TTD image data input
            if (el.type === 'hidden') return;
            if (el.id && el.id.startsWith('ttd_')) return; // ttd_* hidden inputs (signature data)

            const handler = function() {
                let val;
                if (el.type === 'checkbox') {
                    val = el.checked ? (el.value || '1') : '';
                } else {
                    val = el.value || '';
                }
                propagateFieldValue(el.name, val, el);
            };
            el.addEventListener('input', handler);
            el.addEventListener('change', handler);
        });

        // Sync before form submit
        const originalSubmitForm = submitForm;
        submitForm = async function() {
            syncFields();
            return originalSubmitForm();
        };
    </script>
