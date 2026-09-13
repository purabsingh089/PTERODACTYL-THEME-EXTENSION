/*
 * Primus · addons.js
 * Addon framework hub — "Addons" subnav tab opening an overlay with a
 * card grid of enabled addons. The Plugin Manager panel (pilot addon)
 * lists/toggles/deletes/uploads plugin & mod jars through the gated
 * /extensions/primus/addons/plugins endpoints.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  var hubState = null;    // GET /addons payload
  var pluginsState = null; // GET /addons/plugins payload
  var worldsState = null; // {java, worlds, current, perms} for the open panel
  var mask = null;
  var activePanel = null; // null = hub grid, "plugins" = plugin panel

  var ICONS = {
    plugins: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 3v4M14 3v4M8 7h8v4a4 4 0 0 1-4 4 4 4 0 0 1-4-4V7Z"/><path d="M12 15v6"/></svg>',
    worlds: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></svg>',
    mods: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 4h2v6h6v2h-6v6h-2v-6H5v-2h6V4Z"/></svg>',
    players: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.5"/><path d="M3.5 20c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15 14.5c2.8 0 5 1.8 5 4"/></svg>',
    traffic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="7" cy="6" r="1.4" fill="currentColor"/><circle cx="14" cy="12" r="1.4" fill="currentColor"/><circle cx="10" cy="18" r="1.4" fill="currentColor"/></svg>',
    console: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 7 5 5-5 5"/><path d="M12 17h7"/></svg>',
    versions: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M5 21h14"/></svg>',
    icons: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/><path d="M13 3h8v8h-8z" opacity=".4"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/></svg>',
    properties: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/><circle cx="18" cy="18" r="2"/></svg>',
    motd: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16M4 12h16M4 19h10"/></svg>',
    aimotd: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.1 6.2L20 10l-5.9 1.8L12 18l-2.1-6.2L4 10l5.9-1.8L12 2z"/></svg>',
    marketplace: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 8h16l-1.2 11a2 2 0 0 1-2 1.8H7.2a2 2 0 0 1-2-1.8L4 8Z"/><path d="M8.5 8V6.5a3.5 3.5 0 0 1 7 0V8"/></svg>',
  };

  function serverId() {
    var m = location.pathname.match(/\/server\/([a-zA-Z0-9]+)/);
    return m ? m[1] : "";
  }

  function isServerPage() {
    return /\/server\/[a-zA-Z0-9]+/.test(location.pathname);
  }

  function subnavHolder() {
    var sub = U.q("div[class*='SubNavigation']");
    return sub ? (sub.firstElementChild || sub) : null;
  }

  function ensureTab() {
    var holder = subnavHolder();
    if (!holder) return;
    if (U.q(".pr-addons-tab")) return;
    if (!hubState || !hubState.addons || !hubState.addons.length) return;
    var tab = U.el(
      "a",
      "pr-addons-tab",
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
        '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/>' +
        '<rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>' +
      "<span>Addons</span>"
    );
    tab.href = "#";
    tab.setAttribute("role", "button");
    tab.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      openHub();
    });
    /* insert after the MOTD tab when present, else after last server link */
    var motdTab = U.q(".pr-motd-tab");
    var serverLinks = U.qa("a[href*='/server/']", holder);
    var anchor = motdTab || (serverLinks.length ? serverLinks[serverLinks.length - 1] : null);
    if (!anchor) return;
    if (anchor.nextSibling) holder.insertBefore(tab, anchor.nextSibling);
    else holder.appendChild(tab);
  }

  function removeTab() {
    var tab = U.q(".pr-addons-tab");
    if (tab) tab.remove();
  }

  /* ── overlay ─────────────────────────────────────────────────── */
  function openHub() {
    if (mask) return;
    activePanel = null;
    mask = U.el("div", "pr-addons-mask pr-fade-in", "");
    document.body.appendChild(mask);
    renderShell();
    loadHub();
    document.addEventListener("keydown", escHandler);
  }

  function renderShell() {
    var card = U.el(
      "div",
      "pr-addons-card pr-scale-in",
      '<div class="pr-addons-head">' +
        '<span class="pr-addons-title"><span class="pr-addons-dot"></span> Addons</span>' +
        '<button class="pr-addons-back" type="button" style="display:none">&larr; Back</button>' +
        '<button class="pr-addons-close" type="button" aria-label="Close">&times;</button>' +
      "</div>" +
      '<div class="pr-addons-body"><div class="pr-addons-grid"></div></div>'
    );
    mask.innerHTML = "";
    mask.appendChild(card);
    card.querySelector(".pr-addons-close").addEventListener("click", close);
    card.querySelector(".pr-addons-back").addEventListener("click", function () {
      activePanel = null;
      renderGrid();
    });
    mask.addEventListener("click", function (e) { if (e.target === mask) close(); });
  }

  function loadHub() {
    if (!mask) return;
    var body = mask.querySelector(".pr-addons-body");
    if (!body) return;
    body.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
    P.api("addons?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        hubState = payload;
        if (mask) renderGrid();
      })
      .catch(function (err) {
        if (!mask) return;
        body.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load addons.") + "</div>";
      });
  }

  function renderGrid() {
    var body = mask.querySelector(".pr-addons-body");
    var back = mask.querySelector(".pr-addons-back");
    back.style.display = "none";
    body.innerHTML = '<div class="pr-addons-grid"></div>';
    var grid = body.querySelector(".pr-addons-grid");

    var usable = (hubState && hubState.addons) || [];
    usable.forEach(function (a) {
      var locked = a.comingSoon || !a.canUse;
      var card = U.el(
        "div",
        "pr-addons-app" + (locked ? " is-locked" : ""),
        '<div class="pr-addons-app__icon">' + (ICONS[a.id] || ICONS.plugins) + "</div>" +
        '<div class="pr-addons-app__text">' +
          '<div class="pr-addons-app__title">' + U.esc(a.title) + "</div>" +
          '<div class="pr-addons-app__desc">' + U.esc(a.description) + "</div>" +
        "</div>" +
        (a.comingSoon ? '<span class="pr-addons-app__tag">soon</span>' : "")
      );
      if (!locked) {
        card.setAttribute("role", "button");
        card.addEventListener("click", function () { openPanel(a.id); });
      }
      grid.appendChild(card);
    });

    if (!usable.length) {
      grid.innerHTML = '<div class="pr-addons-empty">No addons are enabled on this panel.</div>';
    }
  }

  function openPanel(id) {
    if (id === "marketplace") { openMarketplace(); return; }
    if (id === "worlds") { openWorlds(); return; }
    if (id !== "plugins") return; /* plugins, marketplace + worlds have panels */
    activePanel = "plugins";
    var back = mask.querySelector(".pr-addons-back");
    back.style.display = "";
    var body = mask.querySelector(".pr-addons-body");
    body.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
    P.api("addons/plugins?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        pluginsState = payload;
        renderPlugins();
      })
      .catch(function (err) {
        body.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load plugins.") + "</div>";
      });
  }

  /* ── marketplace panel ───────────────────────────────────────── */
  function openMarketplace() {
    activePanel = "marketplace";
    mask.querySelector(".pr-addons-back").style.display = "";
    var body = mask.querySelector(".pr-addons-body");
    var hasCreate = !!(pluginsState && pluginsState.perms && pluginsState.perms.canCreate);
    body.innerHTML =
      '<div class="pr-mkt-tabs">' +
        '<button class="pr-mkt-tab" data-provider="all">All</button>' +
        '<button class="pr-mkt-tab is-active" data-provider="modrinth">Modrinth</button>' +
        '<button class="pr-mkt-tab" data-provider="curseforge">CurseForge</button>' +
        '<span class="pr-mkt-spacer"></span>' +
        '<button class="pr-mkt-type is-active" data-type="mod">Mods</button>' +
        '<button class="pr-mkt-type" data-type="plugin">Plugins</button>' +
      "</div>" +
      '<input class="pr-mkt-input" placeholder="Search mods and plugins…" aria-label="Search marketplace" />' +
      '<div class="pr-mkt-grid"></div>';
    var input = body.querySelector(".pr-mkt-input");
    var grid = body.querySelector(".pr-mkt-grid");
    var state = { provider: "modrinth", type: "mod", seq: 0 };

    /* perms gate the install buttons; fetch them when the marketplace opens
       straight from the hub (plugins panel was never rendered this session). */
    if (!pluginsState || !pluginsState.perms) {
      P.api("addons/plugins?server=" + encodeURIComponent(serverId()))
        .then(function (payload) {
          if (!mask || activePanel !== "marketplace") return;
          pluginsState = payload;
          var nowCreate = !!(pluginsState && pluginsState.perms && pluginsState.perms.canCreate);
          if (nowCreate !== hasCreate) {
            hasCreate = nowCreate;
            body.querySelectorAll(".pr-mkt-card").forEach(function (c) {
              c.classList.toggle("is-readonly", !hasCreate);
              var btn = c.querySelector(".pr-mkt-card__btn");
              if (btn) btn.textContent = hasCreate ? "Versions" : "View";
            });
          }
        })
        .catch(function () { /* read-only stays the safe default */ });
    }

    body.querySelector(".pr-mkt-tabs").addEventListener("click", function (e) {
      var t = e.target.closest(".pr-mkt-tab");
      if (t) {
        body.querySelectorAll(".pr-mkt-tab").forEach(function (b) { b.classList.remove("is-active"); });
        t.classList.add("is-active");
        state.provider = t.getAttribute("data-provider");
        doSearch();
        return;
      }
      var ty = e.target.closest(".pr-mkt-type");
      if (ty) {
        body.querySelectorAll(".pr-mkt-type").forEach(function (b) { b.classList.remove("is-active"); });
        ty.classList.add("is-active");
        state.type = ty.getAttribute("data-type");
        doSearch();
      }
    });

    var debounce = null;
    input.addEventListener("input", function () {
      clearTimeout(debounce);
      debounce = setTimeout(doSearch, 350);
    });

    function doSearch() {
      var q = input.value.trim();
      if (!q) { grid.innerHTML = ""; return; }
      var seq = ++state.seq;
      grid.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
      P.api("addons/marketplace/search?server=" + encodeURIComponent(serverId()) +
        "&q=" + encodeURIComponent(q) + "&provider=" + state.provider + "&type=" + state.type)
        .then(function (payload) {
          if (seq !== state.seq || !mask) return;
          renderResults(payload.results || []);
        })
        .catch(function (err) {
          if (seq !== state.seq || !mask) return;
          grid.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Search failed.") + "</div>";
        });
    }

    function renderResults(list) {
      if (!list.length) { grid.innerHTML = '<div class="pr-addons-empty">No results.</div>'; return; }
      grid.innerHTML = "";
      list.forEach(function (r) {
        var card = U.el("div", "pr-mkt-card" + (hasCreate ? "" : " is-readonly"),
          '<img class="pr-mkt-card__icon" src="' + U.esc(r.icon) + '" alt="" onerror="this.style.display=\'none\'" />' +
          '<div class="pr-mkt-card__text">' +
            '<div class="pr-mkt-card__title">' + U.esc(r.name) + "</div>" +
            '<div class="pr-mkt-card__meta">' + U.esc(r.author) + " · " + fmtDownloads(r.downloads) + "</div>" +
            '<div class="pr-mkt-card__summary">' + U.esc(r.summary) + "</div>" +
          "</div>" +
          '<button class="pr-mkt-card__btn" data-project="' + U.esc(r.id) + '">' + (hasCreate ? "Versions" : "View") + "</button>");
        card.querySelector(".pr-mkt-card__btn").addEventListener("click", function () {
          if (!hasCreate) { P.toast("Install disabled", "You need file-create permission to install.", "warning"); return; }
          openVersions(r);
        });
        grid.appendChild(card);
      });
    }

    function openVersions(r) {
      var prov = r.provider || state.provider;
      var seq = ++state.seq;
      var modal = U.el("div", "pr-mkt-picker pr-scale-in",
        '<div class="pr-mkt-picker__head">' +
          '<div class="pr-mkt-picker__title">' + U.esc(r.name) + "</div>" +
          '<button class="pr-mkt-picker__close" type="button">&times;</button>' +
        "</div>" +
        '<div class="pr-mkt-picker__body"><div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div></div>');
      body.appendChild(modal);
      modal.querySelector(".pr-mkt-picker__close").addEventListener("click", function () { modal.remove(); });
      P.api("addons/marketplace/versions?server=" + encodeURIComponent(serverId()) +
        "&provider=" + encodeURIComponent(prov) + "&project=" + encodeURIComponent(r.id) + "&type=" + state.type)
        .then(function (payload) {
          if (seq !== state.seq || !mask) { modal.remove(); return; }
          renderVersions(modal, payload.versions || []);
        })
        .catch(function (err) {
          modal.querySelector(".pr-mkt-picker__body").innerHTML =
            '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load versions.") + "</div>";
        });

      function renderVersions(modal, list) {
        var rows = modal.querySelector(".pr-mkt-picker__body");
        rows.innerHTML = "";
        if (!list.length) {
          rows.innerHTML = '<div class="pr-addons-empty">No versions found.</div>';
          return;
        }
        list.slice(0, 20).forEach(function (v) {
          var gvs = (v.game_versions || []).slice(0, 4);
          var extra = (v.game_versions || []).length > gvs.length;
          var row = U.el("div", "pr-mkt-version",
            '<div class="pr-mkt-version__main">' +
              '<div class="pr-mkt-version__name">' + U.esc(v.name) + "</div>" +
              '<div class="pr-mkt-version__meta">' + [U.esc(v.filename), fmtDate(v.date), fmtBytes(v.size)].filter(Boolean).join(" · ") + "</div>" +
              (gvs.length
                ? '<div class="pr-mkt-chips">' + gvs.map(function (gv) { return '<span class="pr-mkt-chip">' + U.esc(gv) + "</span>"; }).join("") +
                    (extra ? '<span class="pr-mkt-chip pr-mkt-chip--more">+' + ((v.game_versions || []).length - gvs.length) + "</span>" : "") + "</div>"
                : "") +
            "</div>" +
            '<button class="pr-mkt-version__install" data-v="' + U.esc(v.id) + '">Install</button>');
          row.querySelector(".pr-mkt-version__install").addEventListener("click", function () {
            install(prov, state.type, r.id, v);
          });
          rows.appendChild(row);
        });
      }
    }

    function install(provider, type, project, v) {
      P.api("addons/marketplace/install", {
        method: "POST",
        json: { server: serverId(), provider: provider, project: project, version: v.id, type: type },
      })
        .then(function (res) {
          P.toast("Installed", U.esc(res.name), "success");
          /* refresh the jar table so the plugins panel shows the new file */
          P.api("addons/plugins?server=" + encodeURIComponent(serverId()))
            .then(function (p) { if (mask) pluginsState = p; })
            .catch(function () {});
        })
        .catch(function (err) {
          P.toast("Install failed", U.esc(err.message || "unknown"), "error");
        });
    }

    function fmtDownloads(n) { return n >= 1e6 ? (n / 1e6).toFixed(1) + "M" : n >= 1e3 ? (n / 1e3).toFixed(1) + "k" : String(n); }
    function fmtBytes(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + " MiB" : Math.max(1, Math.round(n / 1024)) + " KiB"; }
    function fmtDate(s) { var d = new Date(s); return isNaN(d.getTime()) ? "" : d.toISOString().slice(0, 10); }
  }

  /* ── worlds panel ────────────────────────────────────────────── */
  function openWorlds() {
    activePanel = "worlds";
    mask.querySelector(".pr-addons-back").style.display = "";
    var body = mask.querySelector(".pr-addons-body");
    body.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
    P.api("addons/worlds?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        worldsState = payload;
        renderWorlds();
      })
      .catch(function (err) {
        body.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load worlds.") + "</div>";
      });
  }

  function renderWorlds() {
    var body = mask.querySelector(".pr-addons-body");
    var payload = worldsState || {};
    var perms = payload.perms || {};

    if (!payload.java) {
      body.innerHTML =
        '<div class="pr-plugins-panel">' +
          '<div class="pr-addons-empty">World Manager is for Java Minecraft servers.</div>' +
        "</div>";
      return;
    }

    var rows = (payload.worlds || []).map(function (w) {
      var dims = (w.dims || []).map(function (d) {
        return '<span class="pr-worlds-dim">' + U.esc(d) + "</span>";
      }).join("");
      var badge = '<span class="pr-worlds-badge" data-active="' + (w.active ? "true" : "false") + '">' +
        (w.active ? "Active" : "Inactive") + "</span>";
      var switchBtn = perms.canUpdate && !w.active
        ? '<button type="button" class="pr-addons-act" data-switch="' + U.esc(w.name) + '">Switch</button>'
        : "";
      var backupBtn = perms.canCreate
        ? '<button type="button" class="pr-addons-act" data-backup="' + U.esc(w.name) + '">Backup</button>'
        : "";
      var deleteBtn = perms.canDelete && !w.active
        ? '<button type="button" class="pr-addons-act pr-addons-act--danger" data-wdelete="' + U.esc(w.name) + '">Delete</button>'
        : "";
      return '<tr class="pr-worlds-row" data-name="' + U.esc(w.name) + '">' +
        '<td class="pr-addons-row__name">' + U.esc(w.name) + "</td>" +
        "<td>" + fmtBytes(w.size) + "</td>" +
        '<td class="pr-worlds-dims">' + dims + "</td>" +
        "<td>" + badge + "</td>" +
        '<td class="pr-addons-row__acts">' + switchBtn + backupBtn + deleteBtn + "</td>" +
      "</tr>";
    }).join("");

    body.innerHTML =
      '<div class="pr-plugins-panel">' +
        '<div class="pr-plugins-panel__head">' +
          '<span class="pr-plugins-panel__hint">World folders with a level.dat, grouped with their dimension folders</span>' +
        "</div>" +
        '<table class="pr-worlds-table"><thead><tr><th>World</th><th>Size</th><th>Dimensions</th><th>State</th><th></th></tr></thead>' +
        "<tbody>" + (rows || '<tr><td colspan="5" class="pr-addons-empty">No worlds found on this server.</td></tr>') + "</tbody></table>" +
      "</div>";

    bindWorldsEvents(body);
  }

  function bindWorldsEvents(body) {
    if (!body.getAttribute("data-pr-worlds-bound")) {
      body.setAttribute("data-pr-worlds-bound", "1");
      body.addEventListener("click", function (e) {
        var s = e.target.closest("[data-switch]");
        if (s) { worldSwitch(s.dataset.switch); return; }
        var b = e.target.closest("[data-backup]");
        if (b) { worldBackup(b.dataset.backup); return; }
        var d = e.target.closest("[data-wdelete]");
        if (d) { worldDeletePrompt(d.dataset.wdelete); return; }
      });
    }
  }

  function worldSwitch(name) {
    P.api("addons/worlds/switch", {
      method: "POST",
      json: { server: serverId(), name: name },
    })
      .then(function (res) {
        P.toast("Active world set", "Restart the server to load " + U.esc(res.current || name), "success");
        openWorlds();
      })
      .catch(function (err) { P.toast("Switch failed", U.esc(err.message || "unknown"), "error"); });
  }

  function worldBackup(name) {
    P.toast("Backing up", "Archiving " + U.esc(name) + " — this can take a while…", "info");
    P.api("addons/worlds/backup", {
      method: "POST",
      json: { server: serverId(), name: name },
    })
      .then(function (res) {
        P.toast("World archived", U.esc(res.archive || name), "success");
      })
      .catch(function (err) { P.toast("Backup failed", U.esc(err.message || "unknown"), "error"); });
  }

  function worldDeletePrompt(name) {
    var modal = U.el(
      "div",
      "pr-addons-confirm pr-scale-in",
      '<div class="pr-addons-confirm__title">Delete world</div>' +
      '<p>Type <code>' + U.esc(name) + '</code> to confirm deletion. The world folder and its dimension folders will be removed. This cannot be undone.</p>' +
      '<input class="pr-addons-confirm__input" type="text" placeholder="' + U.esc(name) + '">' +
      '<div class="pr-addons-confirm__acts">' +
        '<button type="button" class="pr-addons-confirm__no">Cancel</button>' +
        '<button type="button" class="pr-addons-confirm__yes" disabled>Delete</button>' +
      "</div>"
    );
    var backdrop = U.el("div", "pr-addons-confirm-mask", "");
    backdrop.appendChild(modal);
    mask.appendChild(backdrop);

    var input = modal.querySelector(".pr-addons-confirm__input");
    var yes = modal.querySelector(".pr-addons-confirm__yes");
    input.addEventListener("input", function () {
      yes.disabled = input.value !== name;
    });
    modal.querySelector(".pr-addons-confirm__no").addEventListener("click", function () { backdrop.remove(); });
    backdrop.addEventListener("click", function (e) { if (e.target === backdrop) backdrop.remove(); });
    yes.addEventListener("click", function () {
      backdrop.remove();
      P.api("addons/worlds/delete", {
        method: "POST",
        json: { server: serverId(), name: name, confirm: name },
      })
        .then(function () {
          P.toast("World deleted", U.esc(name), "success");
          openWorlds();
        })
        .catch(function (err) { P.toast("Delete failed", U.esc(err.message || "unknown"), "error"); });
    });
    setTimeout(function () { input.focus(); }, 30);
  }

  function renderPlugins() {
    var body = mask.querySelector(".pr-addons-body");
    var jars = (pluginsState && pluginsState.jars) || [];
    var perms = (pluginsState && pluginsState.perms) || {};

    var rows = jars.map(function (j) {
      var toggleBtn = perms.canUpdate
        ? '<button type="button" class="pr-addons-act" data-toggle="' + U.esc(j.name) + '" data-dir="' + U.esc(j.dir) + '">' + (j.enabled ? "Disable" : "Enable") + "</button>"
        : "";
      var deleteBtn = perms.canDelete
        ? '<button type="button" class="pr-addons-act pr-addons-act--danger" data-delete="' + U.esc(j.name) + '" data-dir="' + U.esc(j.dir) + '">Delete</button>'
        : "";
      return '<tr class="pr-addons-row" data-name="' + U.esc(j.name) + '">' +
        '<td class="pr-addons-row__name">' + U.esc(j.name) + "</td>" +
        '<td>' + (j.dir === "mods" ? "mods" : "plugins") + "</td>" +
        '<td class="pr-addons-row__size">' + fmtBytes(j.size) + "</td>" +
        '<td><span class="pr-addons-pill" data-state="' + (j.enabled ? "on" : "off") + '">' + (j.enabled ? "enabled" : "disabled") + "</span></td>" +
        '<td class="pr-addons-row__acts">' + toggleBtn + deleteBtn + "</td>" +
      "</tr>";
    }).join("");

    var upload = perms.canCreate
      ? '<div class="pr-addons-upload" id="pr-addons-dropzone">' +
          '<input type="file" accept=".jar" class="pr-addons-file" hidden>' +
          '<button type="button" class="pr-addons-upload__btn">Upload .jar</button>' +
          '<span class="pr-addons-upload__hint">or drop it here</span>' +
        "</div>"
      : "";

    body.innerHTML =
      '<div class="pr-plugins-panel">' +
        '<div class="pr-plugins-panel__head">' +
          '<span class="pr-plugins-panel__hint">Plugin &amp; mod jars in plugins/ and mods/</span>' +
          (perms.canCreate ? '<button class="pr-plugins-mkt" type="button">Install from marketplace</button>' : "") +
        "</div>" +
        '<table class="pr-addons-table"><thead><tr><th>Plugin</th><th>Folder</th><th>Size</th><th>State</th><th></th></tr></thead>' +
        "<tbody>" + (rows || '<tr><td colspan="5" class="pr-addons-empty">No plugin jars found.</td></tr>') + "</tbody></table>" +
        upload +
      "</div>";

    bindPluginEvents(body);
  }

  function bindPluginEvents(body) {
    /* body survives innerHTML re-renders — bind its delegate click once */
    if (!body.getAttribute("data-pr-bound")) {
      body.setAttribute("data-pr-bound", "1");
      body.addEventListener("click", function (e) {
        var t = e.target.closest("[data-toggle]");
        if (t) { pluginToggle(t.dataset.toggle, t.dataset.dir); return; }
        var d = e.target.closest("[data-delete]");
        if (d) { pluginDeletePrompt(d.dataset.delete, d.dataset.dir); return; }
        var up = e.target.closest(".pr-addons-upload__btn");
        if (up) { body.querySelector(".pr-addons-file").click(); return; }
        var mkt = e.target.closest(".pr-plugins-mkt");
        if (mkt) { openMarketplace(); return; }
      });
    }

    var fileInput = body.querySelector(".pr-addons-file");
    var zone = body.querySelector("#pr-addons-dropzone");
    if (fileInput) {
      fileInput.addEventListener("change", function () {
        if (fileInput.files && fileInput.files[0]) pluginUpload(fileInput.files[0]);
      });
    }
    if (zone) {
      zone.addEventListener("dragover", function (e) { e.preventDefault(); zone.classList.add("is-drag"); });
      zone.addEventListener("dragleave", function () { zone.classList.remove("is-drag"); });
      zone.addEventListener("drop", function (e) {
        e.preventDefault();
        zone.classList.remove("is-drag");
        if (e.dataTransfer.files && e.dataTransfer.files[0]) pluginUpload(e.dataTransfer.files[0]);
      });
    }
  }

  function pluginToggle(name, dir) {
    P.api("addons/plugins/toggle", {
      method: "POST",
      json: { server: serverId(), name: name.replace(/\.jar(\.disabled)?$/, ""), dir: dir },
    })
      .then(function (res) {
        P.toast(res.enabled ? "Plugin enabled" : "Plugin disabled", U.esc(name), "success");
        openPanel("plugins");
      })
      .catch(function (err) { P.toast("Toggle failed", U.esc(err.message || "unknown"), "error"); });
  }

  function pluginDeletePrompt(name, dir) {
    var modal = U.el(
      "div",
      "pr-addons-confirm pr-scale-in",
      '<div class="pr-addons-confirm__title">Delete plugin</div>' +
      '<p>Type <code>' + U.esc(name) + '</code> to confirm deletion. This cannot be undone.</p>' +
      '<input class="pr-addons-confirm__input" type="text" placeholder="' + U.esc(name) + '">' +
      '<div class="pr-addons-confirm__acts">' +
        '<button type="button" class="pr-addons-confirm__no">Cancel</button>' +
        '<button type="button" class="pr-addons-confirm__yes" disabled>Delete</button>' +
      "</div>"
    );
    var backdrop = U.el("div", "pr-addons-confirm-mask", "");
    backdrop.appendChild(modal);
    mask.appendChild(backdrop);

    var input = modal.querySelector(".pr-addons-confirm__input");
    var yes = modal.querySelector(".pr-addons-confirm__yes");
    input.addEventListener("input", function () {
      yes.disabled = input.value.trim() !== name;
    });
    modal.querySelector(".pr-addons-confirm__no").addEventListener("click", function () { backdrop.remove(); });
    yes.addEventListener("click", function () {
      backdrop.remove();
      P.api("addons/plugins/delete", {
        method: "POST",
        json: { server: serverId(), name: name, dir: dir, confirm: name },
      })
        .then(function () {
          P.toast("Plugin deleted", U.esc(name), "success");
          openPanel("plugins");
        })
        .catch(function (err) { P.toast("Delete failed", U.esc(err.message || "unknown"), "error"); });
    });
  }

  function pluginUpload(file) {
    if (!/\.jar$/i.test(file.name)) {
      P.toast("Upload rejected", "Only .jar files are accepted.", "error");
      return;
    }
    if (file.size > 100 * 1024 * 1024) {
      P.toast("Upload rejected", "File exceeds the 100 MiB limit.", "error");
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      var b64 = String(reader.result).split(",")[1] || "";
      P.api("addons/plugins/upload", {
        method: "POST",
        json: { server: serverId(), name: file.name, dir: "plugins", content: b64 },
      })
        .then(function (res) {
          P.toast("Plugin uploaded", U.esc(res.name), "success");
          openPanel("plugins");
        })
        .catch(function (err) { P.toast("Upload failed", U.esc(err.message || "unknown"), "error"); });
    };
    reader.readAsDataURL(file);
  }

  function fmtBytes(b) {
    if (b == null || isNaN(b)) return "--";
    var u = ["B", "KiB", "MiB", "GiB"], i = 0;
    b = Number(b);
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? Math.round(b) : b.toFixed(1)) + " " + u[i];
  }

  function escHandler(e) { if (e.key === "Escape") close(); }

  function close() {
    if (!mask) return;
    mask.remove();
    mask = null;
    activePanel = null;
    document.removeEventListener("keydown", escHandler);
  }

  /* ── state + lifecycle ──────────────────────────────────────── */
  var fetchSeq = 0;

  function syncState() {
    if (!isServerPage()) return;
    var seq = ++fetchSeq;
    P.api("addons?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        if (seq !== fetchSeq) return;
        hubState = payload;
        ensureTab();
      })
      .catch(function () {
        if (seq !== fetchSeq) return;
        hubState = null;
        removeTab();
      });
  }

  P.ready.then(syncState);
  P.on("page:view", function () {
    close();
    hubState = null;
    removeTab();
    setTimeout(syncState, 120);
  });

  var tabTries = 0;
  function tabRetry() {
    if (mask || !isServerPage()) return;
    if (U.q(".pr-addons-tab")) return;
    if (hubState && hubState.addons && hubState.addons.length) {
      ensureTab();
      /* subnav may not have mounted yet on this pass — keep retrying until the tab lands */
      if (!U.q(".pr-addons-tab")) {
        tabTries += 1;
        if (tabTries < 30) setTimeout(tabRetry, 400);
      }
      return;
    }
    tabTries += 1;
    if (tabTries < 30) setTimeout(tabRetry, 400);
  }
  P.ready.then(tabRetry);
})();
