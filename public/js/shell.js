/*
 * Primus · shell.js
 * Builds the Stark-style shell on top of the stock React chrome.
 *
 * The stock nodes are never replaced. This script only:
 *   - mirrors the configured shell options onto <html> as data-primus-*
 *   - injects text labels into the stable navigation items
 *   - tags the stock power-button wrapper so CSS can pin it
 *   - provides the sidebar collapse toggle and the mobile drawer
 *
 * Everything is defensive: if a lookup fails the attribute is simply not
 * written, which leaves the stock layout fully intact.
 */
(function () {
  "use strict";

  var P = window.__primus || (window.__primus = {});

  var LABELS = {
    NavigationDashboard: "Dashboard",
    NavigationAdmin: "Admin",
    NavigationAccount: "Account",
    NavigationLogout: "Sign Out",
  };

  /* Server + account sub-navigation, resolved by the last path segment. */
  var SUBLABELS = {
    console: "Console",
    files: "Files",
    databases: "Databases",
    schedules: "Schedules",
    users: "Users",
    backups: "Backups",
    network: "Network",
    startup: "Startup",
    settings: "Settings",
    "api-keys": "API Keys",
    ssh: "SSH Keys",
    activity: "Activity",
  };

  var LS_COLLAPSED = "primus:shell:collapsed";
  var LS_DRAWER = "primus:shell:drawer";
  var LS_LAYOUT = "primus:shell:layout";
  var LS_CONTAINER = "primus:shell:container";
  var LS_POWER = "primus:shell:power";
  var LS_SURFACE = "primus:shell:surface";
  var LS_PRESET = "primus:shell:preset";

  function store(key, value) {
    try {
      if (value === undefined) {
        var raw = localStorage.getItem(key);
        return raw == null ? null : JSON.parse(raw);
      }
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { /* blocked / full */ }
    return null;
  }

  function each(sel, root, fn) {
    Array.prototype.slice.call((root || document).querySelectorAll(sel)).forEach(fn);
  }

  /* ── layout attributes ─────────────────────────────────────────────── */
  function setting(path, dflt) {
    if (typeof P.set === "function") return P.set(path, dflt);
    var s = P.settings;
    if (!s) return dflt;
    path.split(".").forEach(function (p) {
      s = s == null ? s : s[p];
    });
    return s == null ? dflt : s;
  }

  function applyLayout() {
    var root = document.documentElement;
    var layout = store(LS_LAYOUT) || setting("appearance.layout", "sidebar");
    if (layout !== "sidebar" && layout !== "topbar") layout = "sidebar";

    var container = store(LS_CONTAINER) || setting("appearance.container", "flush");
    if (container !== "flush" && container !== "boxed") container = "flush";

    var power = store(LS_POWER) || setting("appearance.power_position", "sidebar");
    if (["sidebar", "header", "floating"].indexOf(power) < 0) power = "sidebar";

    var collapsed = store(LS_COLLAPSED);
    if (collapsed === null) collapsed = !!setting("appearance.sidebar_collapsed", false);

    root.setAttribute("data-primus-layout", layout);
    root.setAttribute("data-primus-container", container);
    root.setAttribute("data-primus-power", power);
    root.setAttribute("data-primus-sidebar", collapsed ? "collapsed" : "expanded");
    root.setAttribute("data-primus-drawer", store(LS_DRAWER) === true ? "open" : "closed");
  }

  /* ── label injection ──────────────────────────────────────────────── */
  function labelFor(el) {
    if (!el) return null;
    if (el.id && LABELS[el.id]) return LABELS[el.id];
    if (el.classList && el.classList.contains("navigation-link")) return "Search";
    var href = el.getAttribute && el.getAttribute("href");
    if (href && href.indexOf("/account/") === 0) {
      var seg = href.split("/").filter(Boolean).pop();
      if (SUBLABELS[seg]) return SUBLABELS[seg];
    }
    return null;
  }

  function injectLabel(el) {
    if (!el || el.querySelector(":scope > .pr-navlabel")) return;
    var text = labelFor(el);
    if (!text) return;
    var span = document.createElement("span");
    span.className = "pr-navlabel";
    span.textContent = text;
    el.appendChild(span);
  }

  function injectLabels() {
    var bar = document.getElementById("NavigationBar");
    if (!bar) return;

    Object.keys(LABELS).forEach(function (id) {
      injectLabel(document.getElementById(id));
    });
    each(".navigation-link", bar, injectLabel);

    /* wrap the stock logo text so it can be hidden when collapsed */
    var logoLink = bar.querySelector("#logo > a");
    if (logoLink && !logoLink.querySelector(".pr-logo-text")) {
      var textNodes = Array.prototype.slice.call(logoLink.childNodes).filter(function (n) {
        return n.nodeType === 3 && n.textContent.trim();
      });
      if (textNodes.length) {
        var wrap = document.createElement("span");
        wrap.className = "pr-logo-text";
        textNodes[0].parentNode.insertBefore(wrap, textNodes[0]);
        wrap.appendChild(textNodes[0]);
      }
    }
  }

  /* ── active state fallback ────────────────────────────────────────── */
  function markActive() {
    var path = location.pathname || "/";
    var dashboard = document.getElementById("NavigationDashboard");
    var admin = document.getElementById("NavigationAdmin");
    var account = document.getElementById("NavigationAccount");

    [dashboard, admin, account].forEach(function (el) {
      if (el) el.classList.remove("is-pr-active");
    });

    if (path === "/" && dashboard) dashboard.classList.add("is-pr-active");
    else if (path.indexOf("/admin") === 0 && admin) admin.classList.add("is-pr-active");
    else if (path.indexOf("/account") === 0 && account) account.classList.add("is-pr-active");
  }

  /* ── power bar tagging ────────────────────────────────────────────── */
  function tagPowerBar() {
    if (document.querySelector(".pr-powerbar")) return;
    var buttons = Array.prototype.slice.call(document.querySelectorAll("#app button"));
    var matched = buttons.filter(function (b) {
      return /^(start|restart|stop|kill)$/i.test((b.textContent || "").trim());
    });
    if (matched.length < 2) return;

    var node = matched[0];
    while (node && node !== document.body) {
      var ok = matched.every(function (b) { return node.contains(b); });
      if (ok) break;
      node = node.parentElement;
    }
    if (node && node !== document.body) node.classList.add("pr-powerbar");
  }

  /* ── collapse toggle + mobile drawer ──────────────────────────────── */
  function collapseIcon(collapsed) {
    var d = collapsed
      ? "M4 4h16v16H4z M9 4v16"           /* rail collapsed: show the rail */
      : "M4 4h16v16H4z M15 4v16";         /* expanded: push the rail right */
    return (
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="' +
      (collapsed ? "M9 3v18" : "M15 3v18") +
      '"/></svg>'
    );
  }

  function mountToggle() {
    var bar = document.getElementById("NavigationBar");
    if (!bar || bar.querySelector(".pr-shell-toggle")) return;
    var host = bar.querySelector(":scope > div > div:not(#logo)") || bar.firstElementChild;
    if (!host) return;

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pr-shell-toggle";
    btn.setAttribute("aria-label", "Toggle sidebar");
    var collapsed = document.documentElement.getAttribute("data-primus-sidebar") === "collapsed";
    btn.innerHTML = collapseIcon(collapsed) + '<span class="pr-navlabel" style="flex:none">Collapse</span>';
    btn.addEventListener("click", function () {
      var isCollapsed = document.documentElement.getAttribute("data-primus-sidebar") === "collapsed";
      var next = !isCollapsed;
      store(LS_COLLAPSED, next);
      document.documentElement.setAttribute("data-primus-sidebar", next ? "collapsed" : "expanded");
      btn.innerHTML = collapseIcon(next) + '<span class="pr-navlabel" style="flex:none">Collapse</span>';
    });
    host.appendChild(btn);
  }

  function mountDrawer() {
    if (document.querySelector(".pr-shell-burger")) return;

    var burger = document.createElement("button");
    burger.type = "button";
    burger.className = "pr-shell-burger";
    burger.setAttribute("aria-label", "Open navigation");
    burger.innerHTML =
      '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
      'stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>';
    burger.addEventListener("click", function () {
      var open = document.documentElement.getAttribute("data-primus-drawer") === "open";
      store(LS_DRAWER, !open);
      document.documentElement.setAttribute("data-primus-drawer", open ? "closed" : "open");
    });

    var scrim = document.createElement("div");
    scrim.className = "pr-shell-scrim";
    scrim.addEventListener("click", function () {
      store(LS_DRAWER, false);
      document.documentElement.setAttribute("data-primus-drawer", "closed");
    });

    document.body.appendChild(burger);
    document.body.appendChild(scrim);
  }

  /* ── live layout editor (client-side; persists to localStorage) ───── */
  function option(label, key, values, current) {
    var wrap = document.createElement("div");
    wrap.className = "pr-editor__field";
    var lab = document.createElement("label");
    lab.textContent = label;
    wrap.appendChild(lab);
    var row = document.createElement("div");
    row.className = "pr-editor__pills";
    values.forEach(function (v) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "pr-chip" + (current === v.value ? " is-active" : "");
      b.textContent = v.label;
      b.addEventListener("click", function () {
        store(key, v.value);
        applyLayout();
        Array.prototype.forEach.call(row.children, function (c) { c.classList.remove("is-active"); });
        b.classList.add("is-active");
      });
      row.appendChild(b);
    });
    wrap.appendChild(row);
    return wrap;
  }

  /* ── surface color pickers ────────────────────────────────────── */
  var SURFACES = [
    { key: "--pr-page", label: "Page", ls: "primus:surface:page" },
    { key: "--pr-surface", label: "Surface", ls: "primus:surface:surface" },
    { key: "--pr-surface-raised", label: "Raised", ls: "primus:surface:raised" },
    { key: "--pr-surface-sunken", label: "Sunken", ls: "primus:surface:sunken" },
    { key: "--pr-accent", label: "Accent", ls: "primus:surface:accent" },
    { key: "--pr-text-primary", label: "Text primary", ls: "primus:surface:textprimary" },
    { key: "--pr-text-secondary", label: "Text secondary", ls: "primus:surface:textsecondary" },
  ];

  var PRESETS = {
    midnight: {
      "--pr-page": "#06070a",
      "--pr-surface": "#0b0d12",
      "--pr-surface-raised": "#12151c",
      "--pr-surface-sunken": "#040507",
      "--pr-accent": "#0050b8",
      "--pr-text-primary": "#f2f4f8",
      "--pr-text-secondary": "#a7aebd",
    },
    aurora: {
      "--pr-page": "#06110f",
      "--pr-surface": "#0a1a19",
      "--pr-surface-raised": "#122423",
      "--pr-surface-sunken": "#06110f",
      "--pr-accent": "#2dd4bf",
      "--pr-text-primary": "#f2f4f8",
      "--pr-text-secondary": "#a7aebd",
    },
    slate: {
      "--pr-page": "#101216",
      "--pr-surface": "#181b21",
      "--pr-surface-raised": "#20242c",
      "--pr-surface-sunken": "#101216",
      "--pr-accent": "#38bdf8",
      "--pr-text-primary": "#f2f4f8",
      "--pr-text-secondary": "#a7aebd",
    },
    sunset: {
      "--pr-page": "#14100e",
      "--pr-surface": "#1e1713",
      "--pr-surface-raised": "#2a201a",
      "--pr-surface-sunken": "#14100e",
      "--pr-accent": "#fb923c",
      "--pr-text-primary": "#f2f4f8",
      "--pr-text-secondary": "#a7aebd",
    },
  };

  function rgbToHex(rgb) {
    var m = String(rgb || "").match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!m) return "";
    return "#" + [Number(m[1]), Number(m[2]), Number(m[3])].map(function (c) {
      var h = Number(c).toString(16);
      return h.length === 1 ? "0" + h : h;
    }).join("");
  }

  function setToken(key, value) {
    if (P.util && typeof P.util.css === "function") P.util.css(key, value);
    else document.documentElement.style.setProperty(key, value);
  }

  function tokenHex(key) {
    var probe = document.createElement("span");
    probe.style.cssText = "position:absolute;left:-9999px;color:var(" + key + ")";
    document.body.appendChild(probe);
    var hex = rgbToHex(getComputedStyle(probe).color);
    document.body.removeChild(probe);
    return hex;
  }

  function applyStoredSurfaces() {
    SURFACES.forEach(function (s) {
      var cur = store(s.ls);
      if (cur && /^#[0-9a-fA-F]{6}$/.test(cur)) setToken(s.key, cur);
    });
  }

  function writeSurface(key, value) {
    if (!/^#[0-9a-fA-F]{6}$/.test(value)) return;
    setToken(key, value);
    SURFACES.forEach(function (s) {
      if (s.key === key) store(s.ls, value);
    });
    var inp = document.querySelector('.pr-color-swatch[data-pr-surface="' + key + '"]');
    if (inp) inp.value = value;
  }

  function applyPreset(id) {
    var map = PRESETS[id];
    if (!map) return;
    Object.keys(map).forEach(function (k) { writeSurface(k, map[k]); });
    store(LS_PRESET, id);
    each(".pr-editor__presets .pr-chip", document, function (c) {
      c.classList.toggle("is-active", c.getAttribute("data-pr-preset") === id);
    });
  }

  function resetSurfaces() {
    SURFACES.forEach(function (s) {
      try { localStorage.removeItem(s.ls); } catch (e) { /* blocked */ }
      document.documentElement.style.removeProperty(s.key);
    });
    try { localStorage.removeItem(LS_PRESET); } catch (e) { /* blocked */ }
    SURFACES.forEach(function (s) {
      var hex = tokenHex(s.key);
      var inp = document.querySelector('.pr-color-swatch[data-pr-surface="' + s.key + '"]');
      if (inp && hex) inp.value = hex;
    });
    each(".pr-editor__presets .pr-chip", document, function (c) { c.classList.remove("is-active"); });
  }

  function presetTab() {
    var wrap = document.createElement("div");
    wrap.className = "pr-editor__field";
    var lab = document.createElement("label");
    lab.textContent = "Preset";
    wrap.appendChild(lab);
    var row = document.createElement("div");
    row.className = "pr-editor__pills pr-editor__presets";
    var current = store(LS_PRESET);
    ["midnight", "aurora", "slate", "sunset"].forEach(function (id) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "pr-chip" + (current === id ? " is-active" : "");
      b.setAttribute("data-pr-preset", id);
      b.textContent = id.charAt(0).toUpperCase() + id.slice(1);
      b.addEventListener("click", function () { applyPreset(id); });
      row.appendChild(b);
    });
    wrap.appendChild(row);
    return wrap;
  }

  function surfaceTab() {
    var wrap = document.createElement("div");
    wrap.className = "pr-editor__field";
    var lab = document.createElement("label");
    lab.textContent = "Surfaces";
    wrap.appendChild(lab);
    var grid = document.createElement("div");
    grid.className = "pr-editor__grid";
    SURFACES.forEach(function (s) {
      var row = document.createElement("div");
      row.className = "pr-editor__swatch";
      var inp = document.createElement("input");
      inp.type = "color";
      inp.setAttribute("data-pr-surface", s.key);
      inp.setAttribute("aria-label", s.label);
      inp.className = "pr-color-swatch";
      var cur = store(s.ls);
      if (!cur || !/^#[0-9a-fA-F]{6}$/.test(cur)) cur = tokenHex(s.key);
      if (cur && /^#[0-9a-fA-F]{6}$/.test(cur)) inp.value = cur;
      var lbl = document.createElement("span");
      lbl.textContent = s.label;
      row.appendChild(inp);
      row.appendChild(lbl);
      inp.addEventListener("input", function () {
        writeSurface(s.key, this.value);
        store(LS_PRESET, "custom");
        each(".pr-editor__presets .pr-chip", document, function (c) { c.classList.remove("is-active"); });
      });
      grid.appendChild(row);
    });
    wrap.appendChild(grid);
    var reset = document.createElement("button");
    reset.type = "button";
    reset.className = "pr-btn pr-btn--ghost pr-editor__reset";
    reset.textContent = "Reset surfaces";
    reset.addEventListener("click", resetSurfaces);
    wrap.appendChild(reset);
    return wrap;
  }

  function mountEditor() {
    if (document.querySelector(".pr-editor")) return;
    if (document.body.classList.contains("pr-auth")) return;
    if (/^\/auth(\/|$)/.test(location.pathname || "")) return;
    var fab = document.createElement("button");
    fab.type = "button";
    fab.className = "pr-editor-fab";
    fab.setAttribute("aria-label", "Open layout editor");
    fab.innerHTML =
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ' +
      'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' +
      '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09A1.65 1.65 0 0 0 19.4 15z"/></svg>';

    var panel = document.createElement("div");
    panel.className = "pr-editor";
    panel.setAttribute("hidden", "");
    var head = document.createElement("div");
    head.className = "pr-editor__head";
    head.innerHTML = "<strong>Layout editor</strong><span>Changes apply instantly</span>";
    var close = document.createElement("button");
    close.type = "button";
    close.className = "pr-btn pr-btn--ghost pr-btn--icon";
    close.setAttribute("aria-label", "Close");
    close.textContent = "x";
    head.appendChild(close);
    panel.appendChild(head);

    var body = document.createElement("div");
    body.className = "pr-editor__body";
    body.appendChild(option("Navigation", LS_LAYOUT, [
      { value: "sidebar", label: "Sidebar" },
      { value: "topbar", label: "Top bar" },
    ], document.documentElement.getAttribute("data-primus-layout") || "sidebar"));
    body.appendChild(option("Page container", LS_CONTAINER, [
      { value: "flush", label: "Flush" },
      { value: "boxed", label: "Boxed" },
    ], document.documentElement.getAttribute("data-primus-container") || "flush"));
    body.appendChild(option("Power controls", LS_POWER, [
      { value: "sidebar", label: "Sticky" },
      { value: "header", label: "In page" },
      { value: "floating", label: "Floating" },
    ], document.documentElement.getAttribute("data-primus-power") || "sidebar"));
    body.appendChild(presetTab());
    body.appendChild(surfaceTab());
    panel.appendChild(body);

    function open() { panel.removeAttribute("hidden"); }
    function hide() { panel.setAttribute("hidden", ""); }
    fab.addEventListener("click", function () {
      if (panel.hasAttribute("hidden")) open(); else hide();
    });
    close.addEventListener("click", hide);

    document.body.appendChild(fab);
    document.body.appendChild(panel);
  }

  /* ── orchestration ────────────────────────────────────────────────── */
  function render() {
    [injectLabels, markActive, tagPowerBar, mountToggle].forEach(function (step) {
      try { step(); } catch (e) { /* never let one step break the shell */ }
    });
  }

  function start() {
    applyLayout();
    applyStoredSurfaces();
    render();
    mountDrawer();
    mountEditor();

    /* React rewrites the nav subtree on re-render and on route change, so
       re-apply whenever it does. Debounced to keep it cheap. */
    var pending = null;
    function schedule() {
      if (pending) return;
      pending = setTimeout(function () {
        pending = null;
        render();
      }, 60);
    }

    var app = document.getElementById("app");
    if (app && window.MutationObserver) {
      new MutationObserver(schedule).observe(app, { childList: true, subtree: true });
    }
    window.addEventListener("popstate", schedule);
    window.addEventListener("resize", schedule);
  }

  if (P.ready && typeof P.ready.then === "function") {
    P.ready.then(start, start);
  } else if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
