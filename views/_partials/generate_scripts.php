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
                    page.querySelectorAll('input[type="text"], input[type="date"], input[type="time"], input[type="number"], input.qr-field, input.ttd-qr-content-input, textarea').forEach(el => {
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
                    const ctrl = wrap.querySelector('input[type="date"], input[type="time"], input[type="text"], input[type="number"], select, input[type="hidden"]');
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

                    // Sign tokens are single-use and the server has now spent
                    // them. Holding on would leave the modal showing a green
                    // "verified" badge for a token that no longer works, and
                    // re-post dead tokens on the next save.
                    _otpState.signTokens = {};
                    _otpState.currentChallengeId = null;
                    document.querySelectorAll('input[id^="_sign_token_"]').forEach(function (el) {
                        el.remove();
                    });
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
                    btn.textContent = origText;
                    btn.disabled = false;
                    await handleSaveRejection(data);
                }
            } catch (e) {
                showToast(t('toast.save_failed', {error: e.message}, 'Failed to save: {error}'), 'error');
                btn.textContent = origText;
                btn.disabled = false;
            }
        }

        // Penolakan simpan yang menyebut dokumen lain harus menawarkan jalan ke
        // dokumen itu.
        //
        // Sebuah dokumen dialamati oleh slot-nya — (template, NORM, Nopen, label).
        // Mengisi NORM/Nopen yang sama dengan dokumen sebelumnya berarti menuju
        // dokumen yang SAMA, walau layarnya bilang "baru". Kalau yang di sana
        // terkunci, simpannya ditolak untuk dokumen yang petugas tidak merasa
        // sedang disentuh.
        //
        // Toast tidak memberi jalan keluar: pesannya hilang sendiri dan tidak ada
        // yang bisa diklik. Padahal server sudah mengirim slot yang cocok, jadi
        // tautan ke dokumennya bisa dibangun tepat.
        async function handleSaveRejection(data) {
            // Penolakan §7b: isi dokumen beku karena sudah ada tanda tangan.
            // Remedinya bukan "buka dokumen lain" — dokumennya sudah terbuka —
            // tapi membuat versi baru. Toast tidak bisa menawarkan itu, dan tanpa
            // tawaran itu petugas kehabisan jalan: field yang berubah tidak akan
            // pernah bisa disimpan di versi ini.
            if (data.remedy === 'new_version') {
                const changed = Array.isArray(data.changed_fields) ? data.changed_fields : [];
                const detail = changed.length
                    ? t('alert.frozen_changed_detail', {fields: changed.join(', ')},
                        'Frozen fields that changed: {fields}')
                    : '';

                const go = await ezdocConfirm(
                    data.message + (detail ? '\n\n' + detail : ''),
                    {
                        title: t('alert.frozen_title', {}, 'Document already signed'),
                        variant: 'warning',
                        confirmText: t('modal.new_version.title', {}, 'Create New Version'),
                        cancelText: t('actions.close', {}, 'Close')
                    }
                );
                if (go && typeof showNewVersionModal === 'function') {
                    // Versi baru menyalin isi versi ini, jadi apa yang diketik
                    // petugas tidak hilang — ia pindah ke versi yang belum
                    // ditandatangani, tempat ia boleh berubah.
                    await showNewVersionModal();
                }
                return;
            }

            const slot = data.matched_slot;
            const lockedId = data.locked_doc_id;

            if (!lockedId || !slot) {
                showToast(data.message, 'error');
                return;
            }

            const p = _preservedParams();
            p.set('template_id', templateId);
            if (slot.norm) p.set('norm', slot.norm);
            if (slot.nopen) p.set('nopen', slot.nopen);
            if (slot.label && slot.label !== '-') p.set('label', slot.label);
            const target = '?' + p.toString();

            const detail = t('alert.locked_slot_detail', {
                id: lockedId,
                version: data.locked_version || 1,
                norm: slot.norm || '-',
                nopen: slot.nopen || '-',
                label: slot.label || '-'
            }, 'Document #{id} (version {version}) already occupies this slot — MR {norm}, Reg {nopen}, label "{label}".');

            const go = await ezdocConfirm(data.message + '\n\n' + detail, {
                title: t('alert.locked_slot_title', {}, 'Slot already taken'),
                variant: 'warning',
                confirmText: t('actions.view_document', {}, 'View that document'),
                cancelText: t('actions.close', {}, 'Close')
            });

            // Membuka dokumennya bukan tindakan merusak, tapi apa pun yang belum
            // tersimpan di form ini akan hilang. Karena simpannya baru saja
            // ditolak, tidak ada yang tersimpan — jadi peringatan navigasi biasa
            // yang justru menyesatkan di sini.
            if (go) {
                if (typeof markClean === 'function') markClean();
                window.location.href = target;
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
            //
            // Adanya URL verifikasi tingkat dokumen dipakai sebagai penanda
            // "dokumen sudah punya slug", bukan sebagai isi QR — tiap slot punya
            // pointer-nya sendiri. Tanpa slug, tidak ada satu pun pointer yang
            // bisa dibangun, jadi dokumennya harus disimpan lebih dulu.
            const vu = document.getElementById('verifyUrlInput')?.value || '';
            const warnBox = document.getElementById('verifyQrModalWarn');
            const confirmBtn = document.getElementById('btnVerifyQrConfirm');
            const mainBox = document.getElementById('verifyQrModalMain');
            const savingBox = document.getElementById('verifyQrModalSaving');

            if (mainBox) mainBox.style.display = '';
            if (savingBox) savingBox.style.display = 'none';

            if (vu) {
                warnBox.style.display = 'none';
                confirmBtn.textContent = t('modal.verify_qr.confirm_activate', {}, 'OK, Activate');
                confirmBtn.disabled = false;
                confirmBtn.dataset.needSave = '0';
            } else {
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

        // Dulu di sini ada generateVerifyQr() + initVerifyQrOnLoad(): jalur render
        // ketiga yang mengisi area .ttd-area-verify-qr dengan URL verifikasi
        // dokumen — nilai yang sama untuk setiap slot. Areanya sudah tidak
        // dirender lagi; mode ini sekarang cuma memaksa semua slot ke mode QR, dan
        // isinya diurus generateTtdQr() lewat jalur per-tanda-tangan yang sama.
        // Tidak ada fitur yang hilang: yang berkurang hanya duplikasinya.

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
                    // Jalur penolakan yang sama seperti submitForm — kalau yang
                    // menghalangi adalah dokumen lain, tawarkan jalan ke sana.
                    await handleSaveRejection(data);
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
                return;
            }

            // Versi terakhir: menghapusnya sama artinya dengan menghapus dokumennya,
            // dan `delete_version` memang tidak melakukan itu — cakupannya satu versi.
            // Memisahkan keduanya adalah pola biasa (Google Docs, WordPress, Git:
            // entitas dan versinya dihapus dengan perintah berbeda).
            //
            // Yang tidak biasa adalah menyebut jalan keluar lalu menyembunyikannya.
            // `delete_slot` tidak pernah punya tombol, jadi dokumen bervesi tunggal
            // tidak bisa dihapus sama sekali. Ditawarkan di sini, dengan konfirmasi
            // yang menyebut akibatnya — bukan diam-diam dieskalasi.
            if (data.remedy === 'delete_slot' && data.slot) {
                await deleteWholeDocument(data.slot);
                return;
            }

            ezdocAlert(t('alert.generic_failed', {reason: data.message || 'error'}, 'Failed: {reason}'), { title: 'Error', variant: 'error' });
        }

        /**
         * Hapus dokumen beserta SELURUH versinya.
         *
         * Identitas slot datang dari server, bukan disusun ulang dari keadaan
         * halaman: halaman bisa sudah berbeda dari dokumen yang barusan ditolak.
         */
        async function deleteWholeDocument(slot) {
            const confirmed = await ezdocConfirm(
                t('confirm.delete_whole_document', {
                    norm: slot.norm || '-',
                    nopen: slot.nopen || '-'
                }, 'This is the only version, so deleting it removes the whole document (MR {norm}, Reg {nopen}) along with every version. Continue?'),
                {
                    title: t('confirm.delete_whole_document_title', {}, 'Delete the whole document'),
                    variant: 'danger',
                    confirmText: t('confirm.delete_whole_document_yes', {}, 'Delete document'),
                    cancelText: t('actions.cancel', {}, 'Cancel')
                }
            );
            if (!confirmed) return;

            const fd = new FormData();
            fd.append('_doc_action', 'delete_slot');
            fd.append('template_id', slot.template_id);
            fd.append('norm', slot.norm);
            fd.append('nopen', slot.nopen);
            fd.append('label', slot.label || '-');

            const resp = await fetch(_ezdocEndpoint('docAction'), { method: 'POST', body: fd });
            const data = await resp.json();

            if (!data.success) {
                ezdocAlert(t('alert.generic_failed', {reason: data.message || 'error'}, 'Failed: {reason}'), { title: 'Error', variant: 'error' });
                return;
            }

            // Dokumennya sudah tidak ada — memuat ulang slot yang sama akan
            // menampilkan form kosong seolah tidak terjadi apa-apa. Kembali ke
            // daftar, tempat penghapusannya terlihat.
            const params = _preservedParams();
            params.set('template_id', templateId);
            window._ezdocSuppressUnload = true;
            window.location.href = '?' + params.toString();
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

        const configTtdMap = <?= json_encode($configTtd ?? [], JSON_UNESCAPED_UNICODE) ?>;
        let _otpState = {
            // Challenge currently awaiting a code. Scoped to whichever slot the
            // modal has open — see the reset in openSign().
            currentChallengeId: null,
            signTokens: {},
            timerInterval: null
        };

        function openSign(id, label) {
            if (!editMode) {
                ezdocAlert(t('ttd.locked_no_sign', {}, 'This document is locked. Cannot sign. Create a new version to revise.'), { title: 'Locked', variant: 'warning' });
                return;
            }
            // Drop any challenge left over from another slot. Without this,
            // sending a code for slot A then opening slot B and typing a code
            // would verify against A's challenge and file the resulting token —
            // which the server bound to A — under B.
            if (currentTtdId !== id) {
                _otpState.currentChallengeId = null;
            }
            currentTtdId = id;
            document.getElementById('signTitle').textContent = label || t('fallback.signature', {}, 'Signature');
            modal.classList.add('show');
            clearSign();

            // Mode QR memakai modal yang sama dengan isi tengah yang ditukar.
            // Alasannya bukan hemat markup: OTP, RBAC, cek dokumen terkunci, dan
            // pengelolaan sign token semuanya hidup di sini. Alur kedua akan
            // berarti empat hal itu harus dijaga sejajar, dan yang paling mudah
            // tertinggal justru yang paling penting.
            const isQrMode = (document.getElementById('ttd_mode_' + id)?.value === 'qr');
            const canvasWrap = document.getElementById('signCanvas');
            const canvasHint = document.getElementById('signCanvasHint');
            const qrConfirm = document.getElementById('signQrConfirmArea');
            const btnClear = document.getElementById('btnClearSign');
            const btnSave = document.getElementById('btnSaveSign');

            if (canvasWrap) canvasWrap.classList.toggle('hidden', isQrMode);
            if (canvasHint) canvasHint.classList.toggle('hidden', isQrMode);
            if (qrConfirm) qrConfirm.classList.toggle('hidden', !isQrMode);
            // Tidak ada yang bisa dihapus dari sesuatu yang belum digambar.
            if (btnClear) btnClear.classList.toggle('hidden', isQrMode);
            if (btnSave) {
                btnSave.textContent = isQrMode
                    ? t('modal.sign_qr.confirm_btn', {}, 'Sign')
                    : t('actions.save', {}, 'Save');
            }

            // Nama penanda tangan diminta di sini, sekali, sebelum tanda tangan
            // diambil. Setelah slot ditandatangani, nama masuk ke hash dan tidak
            // bisa berubah — jadi satu-satunya tempat yang masuk akal untuk
            // mengisinya adalah sebelum menandatangani, bukan sesudahnya.
            //
            // Slot yang sudah ditandatangani tidak menampilkan field ini: nilainya
            // sudah beku, dan menampilkan kotak yang bisa diketik akan menawarkan
            // sesuatu yang tidak bisa dipenuhi.
            const nameRow = document.getElementById('signNameRow');
            const nameInput = document.getElementById('signNameInput');
            const nameHidden = document.getElementById('ttd_nama_' + id);
            const alreadySigned = !!(document.getElementById('ttd_' + id)?.value || '').trim();

            if (nameRow && nameInput) {
                if (alreadySigned || !nameHidden) {
                    nameRow.classList.add('hidden');
                } else {
                    nameRow.classList.remove('hidden');
                    nameInput.value = nameHidden.value || '';
                }
            }

            if (isQrMode) {
                const signer = window.EZDOC_SIGNER || {};
                const roles = (signer.roles || []).join(', ');
                const asEl = document.getElementById('signQrAs');
                const slotEl = document.getElementById('signQrSlot');
                // Ditampilkan apa adanya, termasuk saat identitasnya tidak ada.
                // Menandatangani sebagai "0" adalah masalah yang harus terlihat
                // sebelum ditandatangani, bukan sesudahnya di halaman verify.
                if (asEl) {
                    asEl.textContent = (signer.id && signer.id !== '0')
                        ? (roles ? `#${signer.id} · ${roles}` : `#${signer.id}`)
                        : t('modal.sign_qr.no_identity', {}, 'no identity — check access configuration');
                }
                if (slotEl) slotEl.textContent = label || id;
            }

            // Find TTD config
            const ttdInfo = configTtdMap.find(item => item.id === id) || {};
            const requireOtp = !!(ttdInfo.require_otp);

            const otpContainer = document.getElementById('signOtpContainer');
            const otpBadge = document.getElementById('signOtpBadge');
            const overlay = document.getElementById('signCanvasOverlay');
            const btnSaveSign = document.getElementById('btnSaveSign');

            if (requireOtp) {
                otpContainer.classList.remove('hidden');
                // Check if sign token already verified for this ttd
                const existingToken = _otpState.signTokens[id];
                if (existingToken) {
                    // OTP Verified! Show canvas
                    otpBadge.classList.remove('hidden');
                    overlay.classList.add('hidden');
                    if (btnSaveSign) btnSaveSign.disabled = false;
                    document.getElementById('signOtpStep1').classList.add('hidden');
                    document.getElementById('signOtpStep2').classList.add('hidden');
                } else {
                    // Requires OTP verification first
                    otpBadge.classList.add('hidden');
                    overlay.classList.remove('hidden');
                    if (btnSaveSign) btnSaveSign.disabled = true;

                    // Show Step 1 (Kirim OTP) with editable phone number
                    document.getElementById('signOtpStep1').classList.remove('hidden');
                    document.getElementById('signOtpStep2').classList.add('hidden');

                    // Resolve phone number, most specific source first.
                    //
                    // The DOM is checked before the server value because the
                    // operator may have just typed a correction that has not
                    // been saved yet. The server value is the fallback, and it
                    // is what makes a number passed via GET/POST work at all —
                    // without a matching field in the template there is nothing
                    // in the DOM to read, which is why the input used to come
                    // up empty despite the number being in the URL.
                    const phoneFieldSrc = ttdInfo.otp_phone_source || 'nomor_hp_pasien';
                    let patientPhone = '';
                    const phoneCtrl = document.querySelector(`input[name="${phoneFieldSrc}"], input[name="no_hp"], input[name="nomor_hp"]`);
                    if (phoneCtrl) patientPhone = phoneCtrl.value || '';
                    if (!patientPhone) {
                        const editable = document.querySelector(`.f[data-field="${phoneFieldSrc}"]`);
                        if (editable) patientPhone = editable.textContent || '';
                    }
                    if (!patientPhone) patientPhone = ttdInfo.otp_phone_value || '';
                    patientPhone = patientPhone.trim();

                    const phoneInput = document.getElementById('signOtpPhoneInput');
                    if (phoneInput) phoneInput.value = patientPhone;
                }
            } else {
                // No OTP required
                otpContainer.classList.add('hidden');
                otpBadge.classList.add('hidden');
                overlay.classList.add('hidden');
                if (btnSaveSign) btnSaveSign.disabled = false;
            }

            // Nilai slot yang bukan data URI tidak bisa digambar ke canvas —
            // penanda mode QR akan berakhir sebagai src dan memicu error load.
            const existing = document.getElementById('ttd_' + id)?.value;
            if (existing && existing.startsWith('data:')) {
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

        async function sendTtdOtp() {
            if (!currentTtdId) return;
            const phoneInput = document.getElementById('signOtpPhoneInput');
            const phone = phoneInput ? phoneInput.value.trim() : '';

            if (!phone) {
                showToast(t('modal.otp.phone_required', {}, 'A phone number is required.'), 'error');
                return;
            }

            const btn = document.getElementById('btnSendOtp');
            const btnText = document.getElementById('btnSendOtpText');
            btn.disabled = true;
            btnText.textContent = t('modal.otp.sending', {}, 'Mengirim OTP...');

            try {
                const fd = new FormData();
                fd.append('_doc_action', 'send_ttd_otp');
                fd.append('template_id', templateId);
                fd.append('ttd_id', currentTtdId);
                fd.append('phone', phone);
                if (CURRENT_DOC_ID) fd.append('doc_id', CURRENT_DOC_ID);

                // Get patient name if available
                const nameCtrl = document.querySelector('input[name="nama_pasien"], input[name="nama"]');
                if (nameCtrl && nameCtrl.value) fd.append('nama_pasien', nameCtrl.value);

                const endpoint = typeof _ezdocEndpoint === 'function' ? _ezdocEndpoint('docAction') : 'actions/document/send_ttd_otp.php';
                const resp = await fetch(endpoint, { method: 'POST', body: fd });
                const data = await resp.json();

                if (data.success) {
                    _otpState.currentChallengeId = data.challenge_id;
                    showToast(data.message || t('modal.otp.send_success', {}, 'Code sent.'), 'success');

                    // Switch to Step 2
                    document.getElementById('signOtpStep1').classList.add('hidden');
                    document.getElementById('signOtpStep2').classList.remove('hidden');

                    const descEl = document.getElementById('signOtpStep2Desc');
                    if (descEl) descEl.textContent = t('modal.otp.step2_desc', {phone: data.masked_phone}, `Kode OTP 6 digit telah dikirimkan ke WhatsApp ${data.masked_phone}. Silakan minta kode tersebut dari pasien dan masukkan di bawah ini.`);

                    const codeInput = document.getElementById('signOtpCodeInput');
                    if (codeInput) { codeInput.value = ''; codeInput.focus(); }

                    startOtpResendTimer(60);
                } else {
                    showToast(data.message || 'Gagal mengirim OTP.', 'error');
                }
            } catch (e) {
                showToast('Gagal mengirim OTP: ' + e.message, 'error');
            } finally {
                btn.disabled = false;
                btnText.textContent = t('modal.otp.send_btn', [], 'Kirim Kode OTP via WA');
            }
        }

        async function verifyTtdOtp() {
            if (!_otpState.currentChallengeId) {
                showToast('Sesi OTP tidak valid. Silakan kirim ulang OTP.', 'error');
                return;
            }

            const codeInput = document.getElementById('signOtpCodeInput');
            const code = codeInput ? codeInput.value.trim() : '';

            if (code.length !== 6) {
                showToast('Kode OTP harus 6 digit angka!', 'error');
                return;
            }

            const btn = document.getElementById('btnVerifyOtp');
            const btnText = document.getElementById('btnVerifyOtpText');
            btn.disabled = true;
            btnText.textContent = t('modal.otp.verifying', {}, 'Memverifikasi...');

            try {
                const fd = new FormData();
                fd.append('_doc_action', 'verify_ttd_otp');
                fd.append('challenge_id', _otpState.currentChallengeId);
                fd.append('otp_code', code);

                const endpoint = typeof _ezdocEndpoint === 'function' ? _ezdocEndpoint('docAction') : 'actions/document/verify_ttd_otp.php';
                const resp = await fetch(endpoint, { method: 'POST', body: fd });
                const data = await resp.json();

                if (data.success) {
                    _otpState.signTokens[currentTtdId] = data.sign_token;

                    // Create/update hidden input on main form
                    let tokenInput = document.getElementById('_sign_token_' + currentTtdId);
                    if (!tokenInput) {
                        tokenInput = document.createElement('input');
                        tokenInput.type = 'hidden';
                        tokenInput.id = '_sign_token_' + currentTtdId;
                        tokenInput.name = '_sign_token_' + currentTtdId;
                        document.getElementById('mainForm').appendChild(tokenInput);
                    }
                    tokenInput.value = data.sign_token;

                    showToast(data.message || t('modal.otp.verify_success', {}, 'Verified.'), 'success');

                    // Unlock canvas & badge
                    document.getElementById('signOtpBadge').classList.remove('hidden');
                    document.getElementById('signCanvasOverlay').classList.add('hidden');
                    document.getElementById('btnSaveSign').disabled = false;

                    document.getElementById('signOtpStep1').classList.add('hidden');
                    document.getElementById('signOtpStep2').classList.add('hidden');
                } else {
                    showToast(data.message || 'Verifikasi gagal.', 'error');
                    if (data.attempts_left !== undefined) {
                        const attemptsEl = document.getElementById('signOtpAttemptsLeft');
                        if (attemptsEl) attemptsEl.textContent = t('modal.otp.attempts_left', {attempts: data.attempts_left}, `Sisa percobaan: ${data.attempts_left} kali`);
                    }
                }
            } catch (e) {
                showToast('Gagal memverifikasi OTP: ' + e.message, 'error');
            } finally {
                btn.disabled = false;
                btnText.textContent = t('modal.otp.verify_btn', [], 'Verifikasi OTP');
            }
        }

        function resendTtdOtp() {
            document.getElementById('signOtpStep2').classList.add('hidden');
            document.getElementById('signOtpStep1').classList.remove('hidden');
        }

        function startOtpResendTimer(seconds) {
            if (_otpState.timerInterval) clearInterval(_otpState.timerInterval);
            const btnResend = document.getElementById('btnResendOtp');
            const btnResendText = document.getElementById('btnResendOtpText');
            btnResend.disabled = true;

            let remaining = seconds;
            btnResendText.textContent = t('modal.otp.resend_countdown', {seconds: remaining}, `Kirim ulang (${remaining}s)`);

            _otpState.timerInterval = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(_otpState.timerInterval);
                    btnResend.disabled = false;
                    btnResendText.textContent = t('modal.otp.resend_btn', [], 'Kirim Ulang');
                } else {
                    btnResendText.textContent = t('modal.otp.resend_countdown', {seconds: remaining}, `Kirim ulang (${remaining}s)`);
                }
            }, 1000);
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

        // Penanda "slot ini ditandatangani lewat mode QR".
        // Harus sama dengan Ezdoc\Signature\SignatureValue::QR_ACT — kalau berbeda,
        // dokumennya tersimpan tapi tidak ada record tanda tangan yang tertulis.
        const EZDOC_QR_ACT = 'qr:v1';

        // ═══════════════════════════════════════════════════════════════════════
        // Normalisasi gambar tanda tangan sebelum disimpan
        // ═══════════════════════════════════════════════════════════════════════
        //
        // Canvas-nya 850×400 dan diekspor apa adanya: 340.000 piksel RGBA yang
        // mayoritasnya kosong. Terukur 16,5 KB per dokumen, dan `signature_values`
        // jadi 97,3% dari seluruh data dokumen.
        //
        // Ditampilkan, gambar itu maksimal 120×60 px (.ttd-signature). Jadi yang
        // disimpan tujuh kali lebih besar dari yang pernah dilihat siapa pun.
        //
        // Dua langkah, keduanya di sisi klien — jadi hemat di jaringan juga, bukan
        // cuma di disk:
        //
        //   1. Pangkas ke kotak tinta. Sisa kanvas tidak membawa informasi.
        //   2. Turunkan resolusi sampai sisi terpanjang <= MAX_SIDE.
        //
        // Tetap PNG. Lossy (WebP/JPEG) ditolak: kompresi lossy meninggalkan
        // artefak pada garis tipis, dan ini artefak yang orang anggap bukti.
        // Penghematannya tidak sebanding dengan pertanyaan "kenapa tanda tangan
        // saya kelihatan beda".
        const EZDOC_SIG_MAX_SIDE = 400;   // ~3,3x ukuran tampil — cukup untuk cetak
        const EZDOC_SIG_PAD = 4;          // > lineWidth/2, supaya ujung bulat tidak terpotong

        /**
         * Kotak tinta dari data piksel, bukan dari pelacakan goresan.
         *
         * Alasannya penting: modal ini juga memuat tanda tangan yang sudah ada ke
         * kanvas untuk diedit. Pelacakan goresan tidak melihat piksel yang datang
         * dari gambar itu, jadi menyimpan tanpa menggambar apa pun akan memangkas
         * habis tanda tangan yang sudah ada. Memindai alpha melihat semuanya —
         * goresan baru, gambar yang dimuat, dan hasil penghapusan.
         *
         * Latar putih kanvas berasal dari CSS, bukan dicat ke bitmap-nya, jadi
         * area kosong benar-benar transparan dan alpha bisa dipercaya.
         *
         * @returns {{x:number,y:number,w:number,h:number}|null} null kalau tidak ada tinta
         */
        function _ezdocInkBounds(sourceCanvas) {
            const w = sourceCanvas.width;
            const h = sourceCanvas.height;
            const data = sourceCanvas.getContext('2d').getImageData(0, 0, w, h).data;

            let minX = w, minY = h, maxX = -1, maxY = -1;
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    // Alpha saja. Warna goresan tidak diasumsikan — template boleh
                    // memakai tinta biru, dan memeriksa "bukan putih" akan salah
                    // menghitung piksel transparan sebagai tinta.
                    if (data[(y * w + x) * 4 + 3] !== 0) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }

            if (maxX < 0) return null; // kanvas kosong

            const x = Math.max(0, minX - EZDOC_SIG_PAD);
            const y = Math.max(0, minY - EZDOC_SIG_PAD);

            return {
                x: x,
                y: y,
                w: Math.min(w, maxX + EZDOC_SIG_PAD + 1) - x,
                h: Math.min(h, maxY + EZDOC_SIG_PAD + 1) - y
            };
        }

        /**
         * PNG yang sudah dipangkas dan diturunkan resolusinya.
         *
         * Mengembalikan null kalau kanvasnya kosong — pemanggil yang memutuskan
         * apa artinya, dan di sini artinya "tidak ada yang ditandatangani".
         *
         * Kegagalan apa pun jatuh kembali ke ekspor utuh. Ini optimasi
         * penyimpanan; kehilangan tanda tangan karenanya akan jadi pertukaran yang
         * bodoh. `getImageData` bisa melempar kalau kanvasnya ternoda oleh gambar
         * lintas-asal — tidak terjadi sekarang (semua data URI), tapi menaruh
         * jaring di bawahnya jauh lebih murah daripada mengetahuinya nanti.
         */
        function normaliseSignaturePng(sourceCanvas) {
            let bounds = null;
            try {
                bounds = _ezdocInkBounds(sourceCanvas);
            } catch (e) {
                console.warn('[ezdoc:signature] pemindaian kotak tinta gagal, pakai ekspor utuh', e);
                return sourceCanvas.toDataURL('image/png');
            }

            if (bounds === null) return null;

            // Jangan pernah memperbesar. Tanda tangan kecil yang di-upscale cuma
            // menambah byte dan blur, tanpa satu pun detail baru.
            const scale = Math.min(1, EZDOC_SIG_MAX_SIDE / Math.max(bounds.w, bounds.h));
            const outW = Math.max(1, Math.round(bounds.w * scale));
            const outH = Math.max(1, Math.round(bounds.h * scale));

            try {
                const out = document.createElement('canvas');
                out.width = outW;
                out.height = outH;
                const octx = out.getContext('2d');
                octx.imageSmoothingEnabled = true;
                octx.imageSmoothingQuality = 'high';
                octx.drawImage(sourceCanvas, bounds.x, bounds.y, bounds.w, bounds.h, 0, 0, outW, outH);

                return out.toDataURL('image/png');
            } catch (e) {
                console.warn('[ezdoc:signature] normalisasi gagal, pakai ekspor utuh', e);
                return sourceCanvas.toDataURL('image/png');
            }
        }

        // Tuliskan nama penanda tangan SEBELUM tanda tangannya diambil.
        //
        // Urutannya penting: hash tanda tangan dihitung server dari field yang
        // dikirim pada simpan yang sama. Kalau namanya menyusul, ia jadi perubahan
        // isi setelah penandatanganan — ditolak, dan tidak ada nama yang bisa
        // masuk lagi selamanya.
        //
        // Mengembalikan false kalau petugas memilih tidak melanjutkan.
        function commitSignerName(ttdId) {
            const nameRow = document.getElementById('signNameRow');
            const nameInput = document.getElementById('signNameInput');
            const nameHidden = document.getElementById('ttd_nama_' + ttdId);

            if (!nameRow || nameRow.classList.contains('hidden') || !nameInput || !nameHidden) {
                return true;
            }

            const value = nameInput.value.trim();
            nameHidden.value = value;

            // Span contenteditable-nya yang terlihat di dokumen; keduanya harus
            // sama, kalau tidak yang tercetak berbeda dari yang ditandatangani.
            const span = document.querySelector('.f[data-field="' + nameHidden.name + '"]');
            if (span) span.textContent = value;

            return true;
        }

        async function saveSign() {
            if (!currentTtdId) { closeSign(); return; }

            const isQrMode = (document.getElementById('ttd_mode_' + currentTtdId)?.value === 'qr');
            const hiddenInput = document.getElementById('ttd_' + currentTtdId);
            if (!hiddenInput) { closeSign(); return; }

            // Nama kosong tidak diblokir — sebagian slot memang tidak mencetak nama
            // (cap, saksi tanpa identitas). Tapi ia dikonfirmasi, karena sesudah ini
            // tidak ada kesempatan kedua.
            const nameRow = document.getElementById('signNameRow');
            const nameInput = document.getElementById('signNameInput');
            if (nameRow && !nameRow.classList.contains('hidden') && nameInput && nameInput.value.trim() === '') {
                const proceed = await ezdocConfirm(
                    t('confirm.sign_without_name', {}, 'The name under this signature is empty. It cannot be filled in after signing — only by creating a new version. Sign anyway?'),
                    {
                        title: t('modal.sign.name_missing_title', {}, 'Name is empty'),
                        variant: 'warning',
                        confirmText: t('confirm.sign_anyway', {}, 'Sign anyway'),
                        cancelText: t('confirm.fill_name_first', {}, 'Fill the name first')
                    }
                );
                if (!proceed) { nameInput.focus(); return; }
            }

            commitSignerName(currentTtdId);

            if (isQrMode) {
                // Yang disimpan penanda tindakan, bukan gambar dan bukan URL QR-nya.
                // URL itu turunan dari slug + ttd_id: menyimpannya akan membekukan
                // base URL dan MAC saat ini ke dalam dokumen, sehingga rotasi kunci
                // atau pindah domain meninggalkan URL basi yang tercetak.
                hiddenInput.value = EZDOC_QR_ACT;

                const area = document.getElementById('ttd_area_qr_' + currentTtdId);
                if (area) {
                    area.dataset.ttdSigned = '1';
                    // QR-nya baru muncul setelah ini: pointer-nya sudah menunjuk
                    // sesuatu yang akan ada begitu dokumennya tersimpan.
                    generateTtdQr(currentTtdId);
                }
                if (typeof triggerDirty === 'function') triggerDirty();
                closeSign();

                // Menandatangani adalah tindakan commit — tanda tangan tidak punya
                // keadaan draft. Recordnya baru tertulis saat dokumen disimpan, jadi
                // menunggu petugas menekan Simpan berarti QR sudah terlihat di layar
                // sementara record yang ditunjuknya belum ada.
                //
                // submitForm() dipakai apa adanya: ia yang memvalidasi field,
                // menjalankan hook simpan, dan mengurus sign token sekali-pakai.
                // Menulis simpan sendiri di sini akan melewati ketiganya.
                if (typeof submitForm === 'function') submitForm();
                return;
            }

            // Dipangkas ke kotak tinta dan diturunkan resolusinya — lihat
            // normaliseSignaturePng(). Tetap PNG lossless.
            const data = normaliseSignaturePng(canvas);

            // Kanvas kosong. Dulu ini tersimpan sebagai data URI 850×400 penuh
            // transparansi: sebuah "tanda tangan" yang tidak menggambarkan apa pun,
            // yang menandai slotnya sudah ditandatangani dan — sejak Fase 1 —
            // menuliskan record tanda tangan untuk goresan yang tidak ada.
            if (data === null) {
                showToast(t('toast.signature_empty', {}, 'Nothing was drawn — the signature was not saved.'), 'error');
                return;
            }

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
                const labelEl = preview.parentElement.querySelector('.ttd-label');
                const label = labelEl ? labelEl.textContent : t('fallback.signature', {}, 'Signature');
                const imgArea = preview.querySelector('.ttd-area-image');
                const target = imgArea || preview;
                target.innerHTML = `<div class="ttd-canvas-placeholder" onclick="openSign('${ttdId}', '${label}')"></div>`;

                // Slot ini tidak lagi ditandatangani, jadi pointer QR-nya berhenti
                // menunjuk apa pun. Tanpa ini QR tetap terlihat di layar setelah
                // tanda tangannya dihapus, dan yang memindai akan mendapat
                // "tanda tangan tidak dikenal" tanpa ada yang tahu kenapa.
                const qrArea = document.getElementById('ttd_area_qr_' + ttdId);
                if (qrArea) {
                    qrArea.dataset.ttdSigned = '0';
                    const qrPreview = document.getElementById('ttd_qr_preview_' + ttdId);
                    const qrContent = (document.getElementById('ttd_qr_content_' + ttdId)?.value || '').trim();
                    const qrTpl = document.getElementById('ttd_qr_data_' + ttdId)?.value || '';
                    if (qrPreview && !qrContent && !qrTpl) {
                        qrPreview.innerHTML = `<button type="button" class="ttd-qr-sign-cta" onclick="openSign('${ttdId}', '${label}')">`
                            + `<span class="ttd-qr-sign-cta-icon">&#10003;</span>`
                            + `<span>${t('ttd.sign_with_qr', {}, 'Sign with QR')}</span></button>`;
                    } else {
                        generateTtdQr(ttdId);
                    }
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

            // Urutan resolusi — harus sama dengan sisi server (generate.php),
            // kalau tidak QR yang dilihat petugas berbeda dari yang tercetak:
            //   1. isi tersimpan  2. pattern template  3. pointer per tanda tangan

            // Priority 1: user-typed per-document content
            let qrData = (document.getElementById('ttd_qr_content_' + ttdId)?.value || '').trim();

            // Priority 3 disiapkan dulu supaya cabang di bawah bisa memilih.
            // Nilainya dihitung server dari slug + ttd_id, jadi tidak berubah saat
            // form diedit — tidak perlu ikut regenerasi per-field.
            const area = document.getElementById('ttd_area_qr_' + ttdId);
            const ttdVerifyUrl = (area?.dataset.ttdVerifyUrl || '').trim();
            const qrDataTpl = document.getElementById('ttd_qr_data_' + ttdId)?.value || '';

            // Slot belum ditandatangani dan tidak ada apa pun yang dikonfigurasi:
            // server merender tombol tanda tangan di sini. Jangan ditimpa dengan
            // pesan status — tombol itu satu-satunya jalan maju yang ada.
            const isSigned = area?.dataset.ttdSigned === '1';
            if (!qrData && !qrDataTpl && !isSigned) return;

            // Priority 2: resolve template pattern with current form field values
            if (!qrData && qrDataTpl) {
                qrData = qrDataTpl.replace(/\{([^}]+)\}/g, (_, fieldName) => {
                    const input = document.querySelector(`input[name="${fieldName}"], select[name="${fieldName}"]`);
                    if (input) return input.value || '';
                    const editable = document.querySelector(`.f[data-field="${fieldName}"]`);
                    if (editable) return editable.textContent || '';
                    return '';
                }).trim();

                // Pattern disetel template tapi field-nya masih kosong. Jangan
                // diganti diam-diam dengan pointer verifikasi — itu menampilkan
                // sesuatu yang artinya lain dari yang template minta.
                if (!qrData) {
                    preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.fill_field_first', {}, 'Fill in the field first') + '</small>';
                    return;
                }
            }

            // Priority 3: tidak ada isi tersimpan, tidak ada pattern → pointer ke
            // tanda tangan slot ini. Ini yang membuat mode QR punya arti: yang
            // memindai mendarat di record tanda tangan ini, bukan di teks yang
            // siapa pun bisa mengarang.
            //
            // Syaratnya slot sudah ditandatangani. Pointer ke record yang belum
            // ada adalah klaim palsu — kertasnya terlihat bertanda tangan, tapi
            // yang memindai dapat "tanda tangan tidak dikenal".
            if (!qrData && isSigned) qrData = ttdVerifyUrl;

            if (!qrData) {
                preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.save_first', {}, 'Save document first') + '</small>';
                return;
            }

            preview.innerHTML = '<small class="text-gray-400 text-[10px]">' + t('ttd.generating', {}, 'Generating...') + '</small>';

            fetch(_ezdocQrUrl(qrData))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Keterangan hanya kalau isinya benar-benar pointer
                        // verifikasi. QR berisi pattern template bisa berisi apa
                        // saja, dan "pindai untuk verifikasi" di bawahnya akan
                        // jadi janji yang tidak dipenuhi.
                        const caption = (ttdVerifyUrl && qrData === ttdVerifyUrl)
                            ? `<div class="text-[9px] text-gray-500 mt-0.5">${t('ttd.scan_to_verify', {}, 'Scan to verify')}</div>`
                            : '';
                        preview.innerHTML = `<img src="${data.qr}" class="w-[100px] h-[100px] max-w-[none] max-h-[none] object-contain block" alt="${t('ttd.qr_alt', {}, 'Signature QR')}" title="${qrData.replace(/"/g, '&quot;')}">` + caption;
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

            // Jalan pulang. Nilai yang pernah diketik menang atas apa pun, jadi
            // tanpa keterangan ini sebuah "123" yang diisi sekali akan bertahan
            // selamanya tanpa cara terlihat untuk mengembalikannya ke otomatis.
            //
            // Keterangannya menyebut apa yang sebenarnya kembali kalau dikosongkan
            // — pattern template kalau template menyetelnya, kalau tidak pointer
            // ke tanda tangan ini — supaya tidak menjanjikan hal yang salah.
            let hint = '';
            if (resolvedPattern) {
                hint = t('ttd.edit_qr_prompt_default_hint', {pattern: resolvedPattern},
                    '\n\nDefault template: {pattern}\n(Leave empty to use the default template)');
            } else {
                const area = document.getElementById('ttd_area_qr_' + ttdId);
                const ttdVerifyUrl = (area?.dataset.ttdVerifyUrl || '').trim();
                if (ttdVerifyUrl) {
                    hint = t('ttd.edit_qr_prompt_verify_hint', {url: ttdVerifyUrl},
                        '\n\nAutomatic: {url}\n(Leave empty to go back to this verification link)');
                }
            }

            const newVal = prompt(t('ttd.edit_qr_prompt_label', {}, 'QR content for this signature:') + hint, current);
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
