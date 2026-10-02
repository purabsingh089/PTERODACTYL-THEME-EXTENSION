/*
 * Primus · theme.js
 * Core engine for the injected client bundle. Responsibilities:
 *   - fetch runtime settings from the extension backend
 *   - apply admin overrides as CSS custom properties
 *   - expose a DOM micro-toolkit + MutationObserver widget lifecycle
 *   - provide skeleton injection, toast system and storage helpers
 * Registered widgets live in widgets.js / ai-fixer.js / ai-optimizer.js.
 */
(function () {
  "use strict";

  var P = window.__primus || (window.__primus = {});
  if (P._booted) return;
  P._booted = true;

  P.util = {
    esc: function (s) {
      var d = document.createElement("div");
      d.textContent = String(s == null ? "" : s);
      return d.innerHTML;
    },
    q: function (sel, root) {
      return (root || document).querySelector(sel);
    },
    qa: function (sel, root) {
      return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    },
    el: function (tag, cls, html) {
      var e = document.createElement(tag);
      if (cls) e.className = cls;
      if (html != null) e.innerHTML = html;
      return e;
    },
    css: function (name, value) {
      document.documentElement.style.setProperty(name, value);
    },
    attr: function (el, key, val) {
      if (val === undefined) return el.getAttribute(key);
      el.setAttribute(key, val);
      return val;
    },
    debounce: function (fn, ms) {
      var t;
      return function () {
        var args = arguments, self = this;
        clearTimeout(t);
        t = setTimeout(function () { fn.apply(self, args); }, ms);
      };
    },
    throttle: function (fn, ms) {
      var last = 0;
      return function () {
        var now = Date.now();
        if (now - last >= ms) {
          last = now;
          fn.apply(this, arguments);
        }
      };
    },
    fmtBytes: function (b) {
      if (b == null || isNaN(b)) return "--";
      var u = ["B", "KiB", "MiB", "GiB", "TiB"], i = 0;
      b = Number(b);
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (i === 0 ? Math.round(b) : b.toFixed(1)) + " " + u[i];
    },
    fmtDate: function (dt) {
      var d = new Date(dt || Date.now());
      var today = new Date();
      var same = d.toDateString() === today.toDateString();
      return same
        ? d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
        : d.toLocaleDateString(undefined, { month: "short", day: "numeric" }) +
          " " + d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    },
    relTime: function (dt) {
      var s = Math.floor((Date.now() - new Date(dt).getTime()) / 1000);
      if (s < 45) return "just now";
      if (s < 3600) return Math.max(1, Math.round(s / 60)) + "m ago";
      if (s < 86400) return Math.round(s / 3600) + "h ago";
      return Math.round(s / 86400) + "d ago";
    },
  };

  P.store = {
    prefix: "primus:",
    get: function (key, def) {
      try {
        var v = localStorage.getItem(P.store.prefix + key);
        return v == null ? def : JSON.parse(v);
      } catch (e) { return def; }
    },
    set: function (key, val) {
      try { localStorage.setItem(P.store.prefix + key, JSON.stringify(val)); } catch (e) { /* full/blocked */ }
      return val;
    },
  };

  /* ── runtime settings ──────────────────────────────────────────────── */
  P.settings = null;
  P.set = function (path, dflt) {
    var s = P.settings;
    if (!s) return dflt;
    var parts = String(path).split("."), v = s;
    for (var i = 0; i < parts.length; i++) {
      if (v == null || typeof v !== "object") return dflt;
      v = v[parts[i]];
    }
    return v == null ? dflt : v;
  };

  P.applyOverrides = function () {
    var o = P.set("overrides", {});
    for (var k in o) {
      if (Object.prototype.hasOwnProperty.call(o, k)) P.util.css(k, o[k]);
    }
    var theme = P.set("appearance.theme", "dark");
    P.util.attr(document.documentElement, "data-primus-theme", theme);
    var vib = P.set("appearance.vibrance", "normal");
    P.util.attr(document.documentElement, "data-primus-vibrance", vib);
    applyAppearanceFlags();
    applyFavicon();
  };

  function cardScaleValue() {
    var raw = parseFloat(P.set("appearance.card_scale", 1));
    if (!(raw > 0)) raw = 1;
    return Math.max(0.85, Math.min(1.2, raw));
  }

  /* Card scale is desktop-only: inline custom properties beat the CSS
     media query, so below 720px the variable is forced back to 1 both at
     boot and on resize. */
  function applyCardScale() {
    P.util.css("--pr-card-scale", window.innerWidth < 720 ? "1" : String(cardScaleValue()));
  }

  function applyAppearanceFlags() {
    var density = P.set("appearance.density", "comfortable");
    P.util.attr(document.documentElement, "data-primus-density",
      density === "compact" ? "compact" : "comfortable");
    P.util.attr(document.documentElement, "data-primus-hide-copyright",
      P.set("appearance.hide_stock_copyright", false) ? "1" : "0");
    applyCardScale();
  }

  function applyFavicon() {
    var url = P.set("appearance.favicon_url", "");
    if (!url) return;
    var link = document.querySelector('link[rel*="icon"]') || document.createElement("link");
    link.rel = "icon";
    link.href = url;
    document.head.appendChild(link);
  }

  function applyLogo() {
    var url = P.set("appearance.logo_url", "");
    if (!url) return;
    var img = document.querySelector(
      'img[src*="/logo"], header img[src^="data:"], a[href="/"] > img'
    );
    if (img) img.src = url;
    var avatars = document.querySelectorAll('img[alt=""][class*="avatar"]');
    avatars.forEach(function (a, i) { if (!i) { a.src = url; } });
  }

  /* ── widget framework ──────────────────────────────────────────────── */
  P.widgets = [];
  P.register = function (widget) {
    if (!widget || typeof widget.observe !== "function") return false;
    widget._bound = new WeakSet();
    P.widgets.push(widget);
    observeExistingDomNodes(widget);
    return true;
  };

  function observeExistingDomNodes(widget) {
    if (widget.each) {
      P.util.qa(widget.each).forEach(function (node) {
        if (!widget._bound.has(node)) { widget._bound.add(node); widget.observe(node); }
      });
    }
  }

  var observer = new MutationObserver(function (mutations) {
    P.widgets.forEach(function (w) {
      if (w.each) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (n) {
            if (n.nodeType !== 1) return;
            if (n.matches && n.matches(w.each) && !w._bound.has(n)) {
              w._bound.add(n);
              w.observe(n);
            }
            if (n.querySelectorAll) {
              P.util.qa(w.each, n).forEach(function (c) {
                if (!w._bound.has(c)) { w._bound.add(c); w.observe(c); }
              });
            }
          });
        });
      }
    });
  });

  function startObserver() {
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  /* ── page transitions ──────────────────────────────────────────────── */
  function pageTransitions() {
    var lastPath = location.pathname + location.search;
    new MutationObserver(function () {
      var now = location.pathname + location.search;
      if (now !== lastPath) {
        lastPath = now;
        var main = P.util.q("main, #root > div");
        if (main) {
          main.classList.remove("pr-page-enter");
          void main.offsetWidth; /* reflow to restart animation */
          main.classList.add("pr-page-enter");
        }
        P.emit("page:view", { path: now });
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  /* ── events bus ────────────────────────────────────────────────────── */
  P.bus = {};
  P.on = function (name, fn) {
    (P.bus[name] = P.bus[name] || []).push(fn);
  };
  P.emit = function (name, data) {
    (P.bus[name] || []).forEach(function (fn) {
      try { fn(data || {}); } catch (e) { console.error("[primus]", e); }
    });
  };

  /* ── toasts ────────────────────────────────────────────────────────── */
  P.toast = function (title, msg, kind) {
    kind = kind || "info";
    var wrap = P.util.q(".pr-toasts");
    if (!wrap) {
      wrap = P.util.el("div", "pr-toasts");
      document.body.appendChild(wrap);
    }
    var colors = {
      success: "var(--pr-success)",
      error: "var(--pr-danger)",
      warning: "var(--pr-warning)",
      info: "var(--pr-accent)",
    };
    var t = P.util.el(
      "div",
      "pr-toast pr-scale-in",
      '<div class="pr-toast__icon"></div>' +
        '<div class="pr-toast__body"><div class="pr-toast__title">' +
        P.util.esc(title) + '</div>' +
        (msg ? '<div class="pr-toast__msg">' + msg + "</div>" : "") +
        "</div>" +
        '<button class="pr-toast__close" aria-label="dismiss">&times;</button>'
    );
    t.style.setProperty("--toast-accent", colors[kind] || colors.info);
    t.querySelector(".pr-toast__close").addEventListener("click", function () { dismiss(); });
    wrap.appendChild(t);
    var timer = setTimeout(dismiss, 5200);
    function dismiss() {
      if (t.dataset.dismissed) return;
      t.dataset.dismissed = "1";
      t.style.animation = "pr-toast-out var(--pr-dur-2) var(--pr-ease) forwards";
      clearTimeout(timer);
      setTimeout(function () { t.remove(); }, 220);
    }
    return t;
  };

  /* ── skeleton helpers ──────────────────────────────────────────────── */
  P.skeleton = function (host, lines) {
    var frag = document.createDocumentFragment();
    for (var i = 0; i < (lines || 3); i++) {
      var s = P.util.el("div", "pr-skeleton pr-skeleton--text");
      s.style.width = (60 + Math.round(Math.random() * 40)) + "%";
      frag.appendChild(s);
    }
    host.appendChild(frag);
    return frag;
  };
  P.clearSkeletons = function (host) {
    Array.prototype.forEach.call(host.querySelectorAll
      ? host.querySelectorAll(".pr-skeleton")
      : [], function (s) { s.remove(); });
  };
  P.clearSkeletons = function (host) {
    P.util.qa(".pr-skeleton", host).forEach(function (s) { s.remove(); });
  };

  /* ── api helper (session cookies ride along automatically) ─────────── */
  P.api = function (path, opts) {
    opts = opts || {};
    var init = {
      method: opts.method || "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };
    var csrf = document.querySelector('meta[name="csrf-token"]')
      || document.querySelector('meta[name="_token"]');
    var csrfVal = csrf && csrf.content;
    if (!csrfVal) {
      var hidden = document.querySelector('input[name="_token"]');
      csrfVal = hidden && hidden.value;
    }
    if (init.method !== "GET" && csrfVal) {
      init.headers["X-CSRF-TOKEN"] = csrfVal;
    }
    if (opts.json) {
      init.headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(opts.json);
    }
    return fetch("/extensions/" + (P.identifier || "primus") + "/" + path, init).then(function (r) {
      if (!r.ok) {
        return r.json().catch(function () { return {}; }).then(function (b) {
          var err = new Error(b.error || ("HTTP " + r.status));
          err.status = r.status;
          err.payload = b;
          throw err;
        });
      }
      var ct = r.headers.get("content-type") || "";
      return ct.indexOf("json") >= 0 ? r.json() : r.text();
    });
  };

  /* ── boot sequence ─────────────────────────────────────────────────── */
  P.ready = new Promise(function (resolve) { P._ready = resolve; });

  function boot() {
    P.applyOverrides();
    P.emit("settings", P.settings);
    P.widgets.forEach(observeExistingDomNodes);
    startObserver();
    pageTransitions();
    setTimeout(applyLogo, 400);
    window.addEventListener("resize", P.util.debounce(applyCardScale, 150));
    P._ready(P.settings);
  }

  document.addEventListener("DOMContentLoaded", function () {
    P.api("settings.json")
      .then(function (s) { P.settings = s || {}; })
      .catch(function () { P.settings = { overrides: {} }; })
      .then(boot);
  });
})();
