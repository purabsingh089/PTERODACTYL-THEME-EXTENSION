/*
 * Primus · command-palette.js
 * Ctrl/Cmd+K command palette: fuzzy search across servers (live from the DOM
 * + persisted cache) and static actions (theme toggle, notifications, tour,
 * favorites view, help). Arrow keys navigate, Enter executes.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  var KEY = "palette:last-visited";

  function serversFromDom() {
    return U.qa('a[href^="/server/"]')
      .map(function (a) {
        var href = a.getAttribute("href") || "";
        if (/\/(console|files|databases|schedules|users|backups|network|startup|settings)\b/.test(href)) return null;
        var id = (href.split("/")[2] || "").split("?")[0];
        if (!id) return null;
        var title = (a.querySelector("p") || a.querySelector("div") || a).textContent.trim();
        return {
          id: id,
          title: title.slice(0, 80) || "Server " + id,
          href: "/server/" + id,
        };
      })
      .filter(Boolean);
  }

  function cacheServers() {
    var found = [];
    var seen = {};
    serversFromDom().forEach(function (s) {
      if (seen[s.id]) return;
      seen[s.id] = 1;
      found.push(s);
    });
    if (found.length) {
      var known = P.store.get(KEY, {});
      found.forEach(function (s) { known[s.id] = s; });
      var keys = Object.keys(known);
      while (keys.length > 40) delete known[keys.shift()];
      P.store.set(KEY, known);
    }
  }

  function allServers() {
    var known = P.store.get(KEY, {});
    var list = [];
    for (var id in known) {
      if (Object.prototype.hasOwnProperty.call(known, id)) list.push(known[id]);
    }
    var dom = serversFromDom();
    dom.forEach(function (s) {
      var exists = list.some(function (k) { return k.id === s.id; });
      if (!exists) list.push(s);
    });
    return list;
  }

  function staticActions() {
    var acts = [
      { title: "Toggle dark / light theme", icon: "contrast", run: function () {
        var cur = P.util.attr(document.documentElement, "data-primus-theme") || "dark";
        var next = cur === "dark" ? "light" : "dark";
        P.util.attr(document.documentElement, "data-primus-theme", next);
        P.store.set("appearance:theme-override", next);
        P.toast("Theme switched", "Now using the " + next + " theme.", "info");
      } },
      { title: "Search servers", icon: "search", run: function () { if (P.focusServerSearch) P.focusServerSearch(); } },
      { title: "Show keyboard shortcuts", icon: "keyboard", run: function () { P.showShortcuts && P.showShortcuts(); } },
      { title: "Replay onboarding tour", icon: "sparkle", run: function () { P.store.set("tour:done", false); P.toast("Tour", "Visit a server console to replay the tour.", "info"); } },
      { title: "Open account settings", icon: "cog", run: function () { location.href = "/account"; } },
      { title: "Open API credentials", icon: "key", run: function () { location.href = "/account/api"; } },
      { title: "Open SSH keys", icon: "key", run: function () { location.href = "/account/ssh"; } },
    ];
    if (P.isAdmin) {
      acts.push(
        { title: "Open admin panel", icon: "shield", run: function () { location.href = "/admin"; } },
        { title: "Open Primus settings (admin)", icon: "shield", run: function () { location.href = "/admin/extensions/primus"; } }
      );
    }
    return acts;
  }

  /* fuzzy match: subsequence with word-boundary bonus */
  function fuzzyScore(query, text) {
    query = query.toLowerCase();
    text = text.toLowerCase();
    if (!query) return 1;
    var qi = 0, score = 0, wordBonus = 0;
    for (var ti = 0; ti < text.length && qi < query.length; ti++) {
      if (text[ti] === query[qi]) {
        qi++;
        score += 1;
        if (ti === 0 || text[ti - 1] === " " || text[ti - 1] === "-" || text[ti - 1] === "_") {
          score += 3;
          wordBonus++;
        }
      }
    }
    if (qi < query.length) return 0;
    if (text.indexOf(query) >= 0) score += 10;
    return score + wordBonus;
  }

  function icon(name) {
    var set = {
      server: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>',
      search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
      contrast: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 3v18a9 9 0 0 0 0-18Z" fill="currentColor"/></svg>',
      keyboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/></svg>',
      sparkle: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.1 6.2L20 10l-5.9 1.8L12 18l-2.1-6.2L4 10l5.9-1.8L12 2z"/></svg>',
      cog: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.9 2.9l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.9-2.9l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.9-2.9l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.9 2.9l-.1.1a1.7 1.7 0 0 0-.3 1.9V9c.3.6.9 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>',
      key: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="15" r="4"/><path d="m10.9 12.1 8.1-8.1M18 5l2 2M15 8l2 2"/></svg>',
      shield: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 3v6c0 5-3.4 8.6-8 11-4.6-2.4-8-6-8-11V5l8-3Z"/></svg>',
      arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    };
    return set[name] || set.arrow;
  }

  function commandItems(query) {
    var items = [];
    var servers = allServers().map(function (s) {
      return {
        kind: "server",
        title: s.title,
        sub: "/server/" + s.id,
        icon: "server",
        score: fuzzyScore(query, s.title) + (P.store.get("favorites", []).indexOf(s.id) >= 0 ? 6 : 0),
        run: function () { location.href = s.href; },
      };
    });
    var acts = staticActions().map(function (a) {
      return {
        kind: "action",
        title: a.title,
        sub: "Action",
        icon: a.icon,
        score: fuzzyScore(query, a.title),
        run: a.run,
      };
    });
    items = servers.concat(acts).filter(function (i) { return i.score > 0; });
    items.sort(function (a, b) { return b.score - a.score; });
    return items.slice(0, 12);
  }

  /* ── palette UI state ─────────────────────────────────────────────── */
  var overlay = null;
  var input = null;
  var listEl = null;
  var selected = 0;
  var current = [];

  function open() {
    if (overlay) return;
    cacheServers();
    overlay = U.el(
      "div",
      "pr-palette-mask pr-fade-in",
      '<div class="pr-palette pr-scale-in">' +
        '<div class="pr-palette__inputwrap">' +
          icon("search") +
          '<input type="text" placeholder="Search servers or type a command…" autocomplete="off" spellcheck="false">' +
          "<span>Esc</span>" +
        "</div>" +
        '<div class="pr-palette__list"></div>' +
        '<div class="pr-palette__footer"><span>' +
          "\u2191\u2193 navigate</span><span>\u21B5 select</span><span>esc close</span>" +
        "</div>" +
      "</div>"
    );
    document.body.appendChild(overlay);
    input = overlay.querySelector("input");
    listEl = overlay.querySelector(".pr-palette__list");
    input.value = "";
    selected = 0;
    renderList("");
    input.addEventListener("input", function () {
      selected = 0;
      renderList(input.value.trim());
    });
    input.addEventListener("keydown", onKeydown);
    overlay.addEventListener("mousedown", function (e) {
      if (e.target === overlay) close();
    });
    overlay.addEventListener("click", function (e) {
      var item = e.target.closest("[data-pr-idx]");
      if (item) execItem(Number(item.dataset.prIdx));
    });
    input.focus();
  }

  function close() {
    if (overlay) { overlay.remove(); overlay = null; input = null; listEl = null; }
  }

  function renderList(query) {
    current = commandItems(query);
    listEl.innerHTML = "";
    if (!current.length) {
      listEl.innerHTML = '<div class="pr-palette__empty">No matching servers or commands.</div>';
      return;
    }
    var lastKind = null;
    current.forEach(function (item, i) {
      if (item.kind !== lastKind) {
        lastKind = item.kind;
        var group = U.el("div", "pr-palette__group", item.kind === "server" ? "Servers" : "Actions");
        listEl.appendChild(group);
      }
      var row = U.el(
        "div",
        "pr-palette__item" + (i === selected ? " is-selected" : ""),
        '<span class="pr-palette__icon">' + icon(item.icon) + "</span>" +
          '<span class="pr-palette__labels">' +
            '<span class="pr-palette__title">' + U.esc(item.title) + "</span>" +
            '<span class="pr-palette__sub">' + U.esc(item.sub || "") + "</span>" +
          "</span>" +
          '<span class="pr-palette__enter">\u21B5</span>'
      );
      row.dataset.prIdx = i;
      row.addEventListener("mouseenter", function () {
        selected = i;
        paintSelected();
      });
      listEl.appendChild(row);
    });
    paintSelected();
  }

  function paintSelected() {
    U.qa(".pr-palette__item", listEl).forEach(function (el, i) {
      el.classList.toggle("is-selected", i === selected);
    });
    var sel = listEl.querySelector(".is-selected");
    if (sel) sel.scrollIntoView({ block: "nearest" });
  }

  function execItem(idx) {
    var item = current[idx];
    if (!item) return;
    close();
    try { item.run(); } catch (e) { console.error("[primus]", e); }
  }

  function onKeydown(e) {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      selected = Math.min(current.length - 1, selected + 1);
      paintSelected();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      selected = Math.max(0, selected - 1);
      paintSelected();
    } else if (e.key === "Enter") {
      e.preventDefault();
      execItem(selected);
    } else if (e.key === "Escape") {
      close();
    }
  }

  P.openPalette = open;

  document.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
      e.preventDefault();
      open();
    }
  });

  setInterval(function () { if (!overlay) cacheServers(); }, 15000);
})();
