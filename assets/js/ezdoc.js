/*!
 * ezdoc.js — base client bootstrap.
 *
 * Exposes window.Ezdoc: minimal utilities + slot system for consumer JS
 * extensions. Intentionally framework-agnostic (no jQuery, no React).
 * See docs/UI-CUSTOMIZATION.md.
 */
(function (global) {
    "use strict";

    if (global.Ezdoc && global.Ezdoc.__initialized) {
        return; // idempotent — allow double-inclusion in dev
    }

    var Ezdoc = {
        __initialized: true,
        version: "0.6.6-dev",
        config: {},        // populated by consumer via <script>Ezdoc.config = {...}</script>
        slots: {},         // slot registry (see below)

        /** Escape HTML for safe interpolation. */
        escapeHtml: function (str) {
            if (str === null || str === undefined) return "";
            return String(str)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        },

        /** Format a Date-parseable value to YYYY-MM-DD HH:mm. */
        formatDate: function (input, opts) {
            var d = (input instanceof Date) ? input : new Date(input);
            if (isNaN(d.getTime())) return String(input || "");
            var pad = function (n) { return (n < 10 ? "0" : "") + n; };
            var withTime = !opts || opts.withTime !== false;
            var s = d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
            if (withTime) s += " " + pad(d.getHours()) + ":" + pad(d.getMinutes());
            return s;
        },

        /** POST JSON and return parsed JSON promise. Throws on non-2xx. */
        postJson: function (url, data, opts) {
            opts = opts || {};
            var headers = { "Content-Type": "application/json", "Accept": "application/json" };
            if (opts.csrfToken) headers["X-CSRF-Token"] = opts.csrfToken;
            return fetch(url, {
                method: "POST",
                headers: headers,
                credentials: "same-origin",
                body: JSON.stringify(data || {})
            }).then(function (res) {
                var isJson = (res.headers.get("content-type") || "").indexOf("json") !== -1;
                return (isJson ? res.json() : res.text()).then(function (body) {
                    if (!res.ok) {
                        var err = new Error("HTTP " + res.status);
                        err.status = res.status;
                        err.body = body;
                        throw err;
                    }
                    return body;
                });
            });
        }
    };

    /* ---------- Slot system ------------------------------------------------
     * A slot is a named injection point. Consumer JS registers callbacks;
     * PHP-rendered pages call Ezdoc.slots.render(name, targetEl) to invoke
     * them (typically inside a DOMContentLoaded hook).
     *
     * Callback signature: function (targetEl, contextObj) -> void
     * Priority: lower = earlier. Default 100.
     * ---------------------------------------------------------------------- */
    var _registry = {};

    Ezdoc.slots.register = function (name, callback, priority) {
        if (typeof callback !== "function") return;
        if (!_registry[name]) _registry[name] = [];
        _registry[name].push({
            fn: callback,
            priority: (typeof priority === "number") ? priority : 100
        });
        _registry[name].sort(function (a, b) { return a.priority - b.priority; });
    };

    Ezdoc.slots.render = function (name, target, context) {
        var list = _registry[name] || [];
        for (var i = 0; i < list.length; i++) {
            try {
                list[i].fn(target, context || {});
            } catch (e) {
                if (global.console && console.error) {
                    console.error("[Ezdoc] slot '" + name + "' cb #" + i + " threw:", e);
                }
            }
        }
    };

    Ezdoc.slots.list = function () {
        return Object.keys(_registry);
    };

    global.Ezdoc = Ezdoc;
}(typeof window !== "undefined" ? window : this));
