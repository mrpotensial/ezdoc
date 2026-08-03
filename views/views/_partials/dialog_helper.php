<?php
/**
 * Ezdoc dialog helper — Tailwind-styled alert/confirm modal (Promise-based).
 *
 * Included by both layout.php AND standalone views (designer.php, generate.php)
 * yang render own <html>. Standard PHP partial pattern — DRY without asset
 * routing complexity.
 *
 * Guards:
 *   - Idempotent via `if (window.ezdocAlert && window.ezdocConfirm) return;`
 *     — safe untuk multiple inclusion
 *   - No PHP interpolation di JS body — pure literal, no XSS surface
 *
 * A11y compliance (WAI-ARIA 1.2 dialog spec):
 *   - role="dialog" + aria-modal="true" + aria-labelledby
 *   - Autofocus primary button
 *   - Focus trap (Tab cycle within dialog, Shift+Tab wrap)
 *   - Escape close (alert=resolve true, confirm=resolve false)
 *   - Enter confirm (unless focus di cancel button)
 *   - Restore focus ke previousElement on close
 *
 * API:
 *   await ezdocAlert('Saved', { title, variant, confirmText });
 *   const ok = await ezdocConfirm('Delete?', { title, variant, confirmText, cancelText });
 *
 * Variants: 'info' | 'success' | 'warning' | 'error' | 'danger'
 *
 * Precedent: shadcn/ui AlertDialog + Filament ConfirmationDialog + Radix UI
 * Dialog primitive. Vanilla JS impl (no framework dep).
 */
?>
<script>
(function () {
    if (window.ezdocAlert && window.ezdocConfirm) return; // idempotent

    const ICONS = {
        info:    { color: 'text-blue-600',    bg: 'bg-blue-50',    ring: 'ring-blue-200',
                   svg: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
        success: { color: 'text-emerald-600', bg: 'bg-emerald-50', ring: 'ring-emerald-200',
                   svg: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
        warning: { color: 'text-amber-600',   bg: 'bg-amber-50',   ring: 'ring-amber-200',
                   svg: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>' },
        error:   { color: 'text-red-600',     bg: 'bg-red-50',     ring: 'ring-red-200',
                   svg: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
        danger:  { color: 'text-red-600',     bg: 'bg-red-50',     ring: 'ring-red-200',
                   svg: '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3"/></svg>' },
    };
    const BTN_PRIMARY = {
        info:    'bg-blue-600 hover:bg-blue-500 focus:ring-blue-500',
        success: 'bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500',
        warning: 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500',
        error:   'bg-red-600 hover:bg-red-500 focus:ring-red-500',
        danger:  'bg-red-600 hover:bg-red-500 focus:ring-red-500',
    };
    function esc(s) { const d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
    function ensureRoot() {
        let root = document.getElementById('ezdocDialogRoot');
        if (!root) { root = document.createElement('div'); root.id = 'ezdocDialogRoot'; document.body.appendChild(root); }
        return root;
    }
    function show(opts) {
        const mode = opts.mode || 'alert';
        const variant = opts.variant || 'info';
        const title = opts.title || '';
        const message = opts.message || '';
        const confirmText = opts.confirmText || 'OK';
        const cancelText = opts.cancelText || 'Cancel';
        const iconDef = ICONS[variant] || ICONS.info;
        const btnCls = BTN_PRIMARY[variant] || BTN_PRIMARY.info;
        const isConfirm = mode === 'confirm';
        const previousFocus = document.activeElement;

        return new Promise(function (resolve) {
            const root = ensureRoot();
            root.innerHTML = `
                <div class="fixed inset-0 z-[9999] flex items-center justify-center p-4 opacity-0 transition-opacity duration-150" data-ezdoc-dialog-overlay>
                    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" data-ezdoc-dialog-backdrop></div>
                    <div class="relative w-full max-w-md rounded-xl bg-white shadow-2xl ring-1 ring-black/5 transform transition-transform duration-150 scale-95" role="dialog" aria-modal="true" aria-labelledby="ezdocDialogTitle">
                        <div class="p-5 sm:p-6">
                            <div class="flex items-start gap-4">
                                <div class="shrink-0 inline-flex items-center justify-center w-11 h-11 rounded-full ${iconDef.bg} ${iconDef.color} ring-1 ring-inset ${iconDef.ring}">${iconDef.svg}</div>
                                <div class="flex-1 min-w-0 pt-0.5">
                                    ${title ? `<h3 id="ezdocDialogTitle" class="text-base font-semibold text-gray-900 mb-1">${esc(title)}</h3>` : ''}
                                    <div class="text-sm text-gray-600 whitespace-pre-line leading-relaxed">${esc(message)}</div>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-row-reverse gap-2 rounded-b-xl bg-gray-50 px-5 py-3 sm:px-6">
                            <button type="button" data-ezdoc-dialog-confirm class="inline-flex justify-center rounded-md px-4 py-2 text-sm font-semibold text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 ${btnCls}">${esc(confirmText)}</button>
                            ${isConfirm ? `<button type="button" data-ezdoc-dialog-cancel class="inline-flex justify-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400">${esc(cancelText)}</button>` : ''}
                        </div>
                    </div>
                </div>
            `;
            const overlay = root.querySelector('[data-ezdoc-dialog-overlay]');
            const backdrop = root.querySelector('[data-ezdoc-dialog-backdrop]');
            const panel = root.querySelector('[role="dialog"]');
            const btnConfirm = root.querySelector('[data-ezdoc-dialog-confirm]');
            const btnCancel = root.querySelector('[data-ezdoc-dialog-cancel]');
            requestAnimationFrame(function () {
                overlay.classList.remove('opacity-0'); overlay.classList.add('opacity-100');
                panel.classList.remove('scale-95'); panel.classList.add('scale-100');
                btnConfirm.focus();
            });
            function close(result) {
                overlay.classList.remove('opacity-100'); overlay.classList.add('opacity-0');
                panel.classList.remove('scale-100'); panel.classList.add('scale-95');
                window.removeEventListener('keydown', keyHandler);
                setTimeout(function () {
                    root.innerHTML = '';
                    if (previousFocus && typeof previousFocus.focus === 'function') { try { previousFocus.focus(); } catch (e) {} }
                    resolve(result);
                }, 150);
            }
            btnConfirm.addEventListener('click', function () { close(true); });
            if (btnCancel) btnCancel.addEventListener('click', function () { close(false); });
            backdrop.addEventListener('click', function () { close(isConfirm ? false : true); });
            const FOCUSABLE_SEL = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
            function keyHandler(e) {
                if (e.key === 'Escape') { e.preventDefault(); close(isConfirm ? false : true); return; }
                if (e.key === 'Enter' && document.activeElement !== btnCancel) { e.preventDefault(); close(true); return; }
                if (e.key === 'Tab') {
                    const focusables = panel.querySelectorAll(FOCUSABLE_SEL);
                    if (focusables.length === 0) return;
                    const first = focusables[0];
                    const last = focusables[focusables.length - 1];
                    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }
            window.addEventListener('keydown', keyHandler);
        });
    }
    window.ezdocAlert = function (message, opts) {
        opts = opts || {};
        return show({ mode: 'alert', variant: opts.variant || 'info', title: opts.title || '', message: message, confirmText: opts.confirmText || 'OK' });
    };
    window.ezdocConfirm = function (message, opts) {
        opts = opts || {};
        return show({ mode: 'confirm', variant: opts.variant || 'warning', title: opts.title || '', message: message, confirmText: opts.confirmText || 'Yes', cancelText: opts.cancelText || 'Cancel' });
    };
})();
</script>
