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
  var pluginsTab = "installed"; // plugins panel active tab
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
    if (id === "worlds") { openWorlds(); return; }
    if (id === "mods") { openMods(); return; }
    if (id === "player-stats") { openPlayerStats(); return; }
    if (id === "versions") { openVersions(); return; }
    if (id === "icons") { openIcons(); return; }
    if (id === "properties") { openProperties(); return; }
    if (id !== "plugins") return;
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

  /* ── search UI (shared by Mod Manager + Plugin Installer) ────── */
  /* opts: { type: "mod"|"plugin", endpoint: "addons/mods/search"|...,
     detect: {loader?, version?, platform?}, onInstalled: fn } */
  function searchUI(host, opts) {
    var state = { provider: "modrinth", seq: 0 };
    var chips = opts.type === "mod"
      ? ["fabric", "quilt", "neoforge", "forge"]
      : [];
    var detected = (opts.detect && (opts.detect.loader || opts.detect.platform)) || "";

    host.innerHTML =
      '<div class="pr-mkt-tabs">' +
        '<button class="pr-mkt-tab" data-provider="all">All</button>' +
        '<button class="pr-mkt-tab is-active" data-provider="modrinth">Modrinth</button>' +
        '<button class="pr-mkt-tab" data-provider="curseforge">CurseForge</button>' +
        '<span class="pr-mkt-spacer"></span>' +
        (chips.length
          ? chips.map(function (c) {
              return '<button class="pr-mkt-tab pr-mkt-chip-btn' + (c === detected ? " is-active" : "") + '" data-loader="' + c + '">' + c + "</button>";
            }).join("")
          : "") +
      "</div>" +
      '<div class="pr-mkt-row">' +
        '<input class="pr-mkt-input" placeholder="Search ' + (opts.type === "mod" ? "mods" : "plugins") + '…" aria-label="Search" />' +
        '<input class="pr-mkt-input pr-mkt-version" placeholder="MC version" value="' + U.esc((opts.detect && opts.detect.version) || "") + '" />' +
      "</div>" +
      '<div class="pr-mkt-grid"></div>';

    var input = host.querySelector(".pr-mkt-input:not(.pr-mkt-version)");
    var versionInput = host.querySelector(".pr-mkt-version");
    var grid = host.querySelector(".pr-mkt-grid");
    var loader = detected || "";

    host.querySelector(".pr-mkt-tabs").addEventListener("click", function (e) {
      var t = e.target.closest(".pr-mkt-tab");
      if (!t) return;
      var isLoader = t.hasAttribute("data-loader");
      host.querySelectorAll(".pr-mkt-tab").forEach(function (b) {
        if (isLoader === b.hasAttribute("data-loader")) b.classList.remove("is-active");
      });
      t.classList.add("is-active");
      if (isLoader) { loader = t.getAttribute("data-loader"); }
      else { state.provider = t.getAttribute("data-provider"); }
      doSearch();
    });

    var debounce = null;
    input.addEventListener("input", function () {
      clearTimeout(debounce);
      debounce = setTimeout(doSearch, 350);
    });
    versionInput.addEventListener("input", function () {
      clearTimeout(debounce);
      debounce = setTimeout(doSearch, 350);
    });

    function doSearch() {
      var q = input.value.trim();
      if (!q) { grid.innerHTML = ""; return; }
      var seq = ++state.seq;
      grid.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
      var qs = "&q=" + encodeURIComponent(q) + "&provider=" + state.provider;
      if (opts.type === "mod" && loader) qs += "&loader=" + encodeURIComponent(loader);
      var ver = versionInput.value.trim();
      if (ver) qs += "&version=" + encodeURIComponent(ver);
      P.api(opts.endpoint + "?server=" + encodeURIComponent(serverId()) + qs)
        .then(function (payload) {
          if (seq !== state.seq || !mask) return;
          renderResults((payload && payload.results) || []);
        })
        .catch(function (err) {
          if (seq !== state.seq || !mask) return;
          grid.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Search failed.") + "</div>";
        });
    }

    function renderResults(list) {
      if (!list.length) { grid.innerHTML = '<div class="pr-addons-empty">No results.</div>'; return; }
      grid.innerHTML = "";
      var canInstall = !!(opts.perms && opts.perms.canCreate);
      list.forEach(function (r) {
        var card = U.el("div", "pr-mkt-card" + (canInstall ? "" : " is-readonly"),
          '<img class="pr-mkt-card__icon" src="' + U.esc(r.icon) + '" alt="" onerror="this.style.display=\'none\'" />' +
          '<div class="pr-mkt-card__text">' +
            '<div class="pr-mkt-card__title">' + U.esc(r.name) + "</div>" +
            '<div class="pr-mkt-card__meta">' + U.esc(r.author) + " · " + fmtDownloads(r.downloads) + "</div>" +
            '<div class="pr-mkt-card__summary">' + U.esc(r.summary) + "</div>" +
          "</div>" +
          '<button class="pr-mkt-card__btn" data-project="' + U.esc(r.id) + '">' + (canInstall ? "Versions" : "View") + "</button>");
        card.querySelector(".pr-mkt-card__btn").addEventListener("click", function () {
          if (!canInstall) { P.toast("Install disabled", "You need file-create permission to install.", "warning"); return; }
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
      host.appendChild(modal);
      modal.querySelector(".pr-mkt-picker__close").addEventListener("click", function () { modal.remove(); });
      P.api("addons/marketplace/versions?server=" + encodeURIComponent(serverId()) +
        "&provider=" + encodeURIComponent(prov) + "&project=" + encodeURIComponent(r.id) + "&type=" + opts.type)
        .then(function (payload) {
          if (seq !== state.seq || !mask) { modal.remove(); return; }
          renderVersions(modal, (payload && payload.versions) || []);
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
            P.api("addons/marketplace/install", {
              method: "POST",
              json: { server: serverId(), provider: prov, project: r.id, version: v.id, type: opts.type },
            })
              .then(function (res) {
                P.toast("Installed", U.esc(res.name), "success");
                modal.remove();
                if (opts.onInstalled) opts.onInstalled();
              })
              .catch(function (err) {
                P.toast("Install failed", U.esc(err.message || "unknown"), "error");
              });
          });
          rows.appendChild(row);
        });
      }
    }
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

  function panelLoad(id, path, render) {
    activePanel = id;
    mask.querySelector(".pr-addons-back").style.display = "";
    var body = mask.querySelector(".pr-addons-body");
    body.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
    P.api(path + (path.indexOf("?") >= 0 ? "&" : "?") + "server=" + encodeURIComponent(serverId()))
      .then(function (payload) { if (mask) render(body, payload); })
      .catch(function (err) {
        body.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load.") + "</div>";
      });
  }

  function javaEmpty(body, msg) {
    body.innerHTML = '<div class="pr-plugins-panel"><div class="pr-addons-empty">' + U.esc(msg) + "</div></div>";
  }

  function typedConfirm(title, name, then) {
    var modal = U.el(
      "div",
      "pr-addons-confirm pr-scale-in",
      '<div class="pr-addons-confirm__title">' + U.esc(title) + "</div>" +
      "<p>Type <code>" + U.esc(name) + "</code> to confirm. This cannot be undone.</p>" +
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
    input.addEventListener("input", function () { yes.disabled = input.value !== name; });
    modal.querySelector(".pr-addons-confirm__no").addEventListener("click", function () { backdrop.remove(); });
    backdrop.addEventListener("click", function (e) { if (e.target === backdrop) backdrop.remove(); });
    yes.addEventListener("click", function () { backdrop.remove(); then(); });
    setTimeout(function () { input.focus(); }, 30);
  }

  /* ── mods panel ──────────────────────────────────────────────── */
  function openMods(tab) {
    panelLoad("mods", "addons/mods", function (body, payload) { renderMods(body, payload, tab || "installed"); });
  }

  function renderMods(body, payload, tab) {
    var jars = (payload && payload.jars) || [];
    var perms = (payload && payload.perms) || {};
    var detect = (payload && payload.detect) || {};

    var rows = jars.map(function (j) {
      var toggleBtn = perms.canUpdate
        ? '<button type="button" class="pr-addons-act" data-mtoggle="' + U.esc(j.name) + '">' + (j.enabled ? "Disable" : "Enable") + "</button>"
        : "";
      var deleteBtn = perms.canDelete
        ? '<button type="button" class="pr-addons-act pr-addons-act--danger" data-mdelete="' + U.esc(j.name) + '">Delete</button>'
        : "";
      return '<tr class="pr-addons-row"><td class="pr-addons-row__name">' + U.esc(j.name) + "</td>" +
        '<td class="pr-addons-row__size">' + fmtBytes(j.size) + "</td>" +
        '<td><span class="pr-addons-pill" data-state="' + (j.enabled ? "on" : "off") + '">' + (j.enabled ? "enabled" : "disabled") + "</span></td>" +
        '<td class="pr-addons-row__acts">' + toggleBtn + deleteBtn + "</td></tr>";
    }).join("");
    var upload = perms.canCreate
      ? '<div class="pr-addons-upload" id="pr-mods-dropzone"><input type="file" accept=".jar" class="pr-mods-file" hidden>' +
        '<button type="button" class="pr-addons-upload__btn">Upload .jar</button><span class="pr-addons-upload__hint">or drop it here</span></div>'
      : "";

    body.innerHTML =
      '<div class="pr-plugins-panel">' +
        '<div class="pr-panel-tabs" role="tablist">' +
          '<button type="button" class="pr-panel-tab' + (tab !== "search" ? " is-active" : "") + '" data-atab="installed">Installed (' + jars.length + ")</button>" +
          '<button type="button" class="pr-panel-tab' + (tab === "search" ? " is-active" : "") + '" data-atab="search">Search &amp; Install</button>' +
        "</div>" +
        '<div class="pr-panel-pane"' + (tab === "search" ? " hidden" : "") + ">" +
          '<div class="pr-plugins-panel__head"><span class="pr-plugins-panel__hint">Mod jars in mods/</span></div>' +
          '<table class="pr-addons-table"><thead><tr><th>Mod</th><th>Size</th><th>State</th><th></th></tr></thead><tbody>' +
          (rows || '<tr><td colspan="4" class="pr-addons-empty">No mod jars found.</td></tr>') + "</tbody></table>" + upload +
        "</div>" +
        '<div class="pr-panel-pane pr-mods-search"' + (tab === "search" ? "" : " hidden") + "></div>" +
      "</div>";

    if (tab === "search") {
      searchUI(body.querySelector(".pr-mods-search"), {
        type: "mod",
        endpoint: "addons/mods/search",
        detect: detect,
        perms: perms,
        onInstalled: function () { openMods("installed"); },
      });
    }

    body.querySelectorAll(".pr-panel-tab").forEach(function (t) {
      t.addEventListener("click", function () { openMods(t.dataset.atab === "search" ? "search" : "installed"); });
    });

    if (!body.getAttribute("data-pr-mods-bound")) {
      body.setAttribute("data-pr-mods-bound", "1");
      body.addEventListener("click", function (e) {
        var t = e.target.closest("[data-mtoggle]");
        if (t) {
          P.api("addons/mods/toggle", { method: "POST", json: { server: serverId(), name: t.dataset.mtoggle.replace(/\.jar(\.disabled)?$/, "") } })
            .then(function (res) { P.toast(res.enabled ? "Mod enabled" : "Mod disabled", U.esc(t.dataset.mtoggle), "success"); openMods("installed"); })
            .catch(function (err) { P.toast("Toggle failed", U.esc(err.message || "unknown"), "error"); });
          return;
        }
        var d = e.target.closest("[data-mdelete]");
        if (d) {
          typedConfirm("Delete mod", d.dataset.mdelete, function () {
            P.api("addons/mods/delete", { method: "POST", json: { server: serverId(), name: d.dataset.mdelete, confirm: d.dataset.mdelete } })
              .then(function () { P.toast("Mod deleted", U.esc(d.dataset.mdelete), "success"); openMods("installed"); })
              .catch(function (err) { P.toast("Delete failed", U.esc(err.message || "unknown"), "error"); });
          });
        }
      });
    }
    var fileInput = body.querySelector(".pr-mods-file");
    var zone = body.querySelector("#pr-mods-dropzone");
    function up(file) {
      if (!/\.jar$/i.test(file.name)) { P.toast("Upload rejected", "Only .jar files are accepted.", "error"); return; }
      var reader = new FileReader();
      reader.onload = function () {
        P.api("addons/mods/upload", { method: "POST", json: { server: serverId(), name: file.name, content: String(reader.result).split(",")[1] || "" } })
          .then(function (res) { P.toast("Mod uploaded", U.esc(res.name), "success"); openMods("installed"); })
          .catch(function (err) { P.toast("Upload failed", U.esc(err.message || "unknown"), "error"); });
      };
      reader.readAsDataURL(file);
    }
    if (fileInput) fileInput.addEventListener("change", function () { if (fileInput.files && fileInput.files[0]) up(fileInput.files[0]); });
    if (zone) {
      zone.addEventListener("dragover", function (e) { e.preventDefault(); zone.classList.add("is-drag"); });
      zone.addEventListener("dragleave", function () { zone.classList.remove("is-drag"); });
      zone.addEventListener("drop", function (e) { e.preventDefault(); zone.classList.remove("is-drag"); if (e.dataTransfer.files && e.dataTransfer.files[0]) up(e.dataTransfer.files[0]); });
    }
  }

  /* ── player stats panel ───────────────────────────────────────── */
  function openPlayerStats() { panelLoad("player-stats", "player-stats", renderPlayerStats); }

  function renderPlayerStats(body, payload) {
    if (!payload.java) { javaEmpty(body, "Player Stats is for Java Minecraft servers."); return; }
    var perms = payload.perms || {};
    var totals = payload.totals || {};
    var players = payload.players || [];
    var feed = payload.feed || [];
    var online = payload.online || [];
    var allocs = payload.allocations || [];

    var onlineChips = online.map(function (n) {
      return '<span class="pr-stat-chip is-on">' + U.esc(n) + "</span>";
    }).join("");

    var playerRows = players.map(function (p) {
      var acts = perms.canCommand && payload.running
        ? '<button type="button" class="pr-addons-act" data-pstat="kick" data-pname="' + U.esc(p.name) + '">Kick</button>' +
          '<button type="button" class="pr-addons-act" data-pstat="ban" data-pname="' + U.esc(p.name) + '">Ban</button>' +
          '<button type="button" class="pr-addons-act" data-pstat="op" data-pname="' + U.esc(p.name) + '">Op</button>'
        : "";
      return '<tr class="pr-addons-row"><td class="pr-addons-row__name">' +
        (p.online ? '<span class="pr-dot is-on"></span> ' : "") + U.esc(p.name) + "</td>" +
        "<td>" + p.joins + "</td><td>" + p.sessions + "</td>" +
        '<td class="pr-addons-row__acts">' + acts + "</td></tr>";
    }).join("");

    var feedRows = feed.slice(-30).reverse().map(function (f) {
      return '<div class="pr-stats-feed__row pr-stats-feed__row--' + U.esc(f.kind) + '">' +
        '<span class="pr-stats-feed__kind">' + U.esc(f.kind) + "</span>" +
        '<span class="pr-stats-feed__text">' + U.esc(f.text) + "</span></div>";
    }).join("");

    var allocRows = allocs.map(function (a) {
      return '<tr class="pr-addons-row' + (a.primary ? " is-primary" : "") + '"><td>' +
        U.esc((a.alias || a.ip) + ":" + a.port) + (a.primary ? ' <span class="pr-addons-pill" data-state="on">primary</span>' : "") + "</td>" +
        '<td><input class="pr-mkt-input pr-alloc-notes" data-nid="' + a.id + '" value="' + U.esc(a.notes) + '" maxlength="256"' +
        (perms.canUpdateNotes ? "" : " disabled") + "></td>" +
        '<td class="pr-addons-row__acts">' + (perms.canUpdateNotes ? '<button type="button" class="pr-addons-act" data-nsave="' + a.id + '">Save</button>' : "") + "</td></tr>";
    }).join("");

    body.innerHTML =
      '<div class="pr-plugins-panel">' +
        (payload.running ? "" : '<div class="pr-addons-empty">Server is offline — actions and the online list need it running.</div>') +
        '<div class="pr-stats-totals">' +
          '<div class="pr-stats-total"><div class="pr-stats-total__n">' + (online.length) + '</div><div class="pr-stats-total__l">online now</div></div>' +
          '<div class="pr-stats-total"><div class="pr-stats-total__n">' + (totals.uniquePlayers || 0) + '</div><div class="pr-stats-total__l">unique players</div></div>' +
          '<div class="pr-stats-total"><div class="pr-stats-total__n">' + (totals.totalJoins || 0) + '</div><div class="pr-stats-total__l">total joins</div></div>' +
        "</div>" +
        (onlineChips ? '<div class="pr-stats-online">' + onlineChips + "</div>" : "") +
        '<div class="pr-section-title">Players</div>' +
        '<table class="pr-addons-table"><thead><tr><th>Player</th><th>Joins</th><th>Sessions</th><th></th></tr></thead><tbody>' +
        (playerRows || '<tr><td colspan="4" class="pr-addons-empty">No joins recorded yet.</td></tr>') + "</tbody></table>" +
        '<div class="pr-section-title">Activity feed</div>' +
        '<div class="pr-stats-feed">' + (feedRows || '<div class="pr-addons-empty">No activity yet.</div>') + "</div>" +
        '<div class="pr-section-title">Allocations</div>' +
        '<table class="pr-addons-table"><thead><tr><th>Address</th><th>Notes</th><th></th></tr></thead><tbody>' +
        (allocRows || '<tr><td colspan="3" class="pr-addons-empty">No allocations.</td></tr>') + "</tbody></table>" +
      "</div>";

    if (!body.getAttribute("data-pr-pstats-bound")) {
      body.setAttribute("data-pr-pstats-bound", "1");
      body.addEventListener("click", function (e) {
        var b = e.target.closest("[data-pstat]");
        if (b) {
          P.api("player-stats/command", { method: "POST", json: { server: serverId(), action: b.dataset.pstat, name: b.dataset.pname } })
            .then(function () { P.toast("Command sent", U.esc(b.dataset.pstat + " " + b.dataset.pname), "success"); })
            .catch(function (err) { P.toast("Command failed", U.esc(err.message || "unknown"), "error"); });
          return;
        }
        var s = e.target.closest("[data-nsave]");
        if (s) {
          var input = body.querySelector('.pr-alloc-notes[data-nid="' + s.dataset.nsave + '"]');
          P.api("player-stats/notes", { method: "POST", json: { server: serverId(), id: Number(s.dataset.nsave), notes: (input || {}).value || "" } })
            .then(function () { P.toast("Notes saved", "", "success"); })
            .catch(function (err) { P.toast("Save failed", U.esc(err.message || "unknown"), "error"); });
        }
      });
    }
  }

  /* ── versions panel ──────────────────────────────────────────── */
  function openVersions() { panelLoad("versions", "addons/versions", renderVersions); }

  function renderVersions(body, payload) {
    var perms = (payload && payload.perms) || {};
    var rows = ((payload && payload.jars) || []).map(function (j) {
      var active = j.name === payload.currentJar;
      return '<tr><td class="pr-addons-row__name">' + U.esc(j.name) + "</td><td>" + fmtBytes(j.size) + "</td>" +
        "<td>" + (active ? '<span class="pr-worlds-badge" data-active="true">Active</span>' : "") + "</td>" +
        '<td>' + (perms.canUpdate && payload.jarEditable && !active
          ? '<button type="button" class="pr-addons-act" data-vjar="' + U.esc(j.name) + '">Use</button>' : "") + "</td></tr>";
    }).join("");
    var images = ((payload && payload.images) || []).map(function (im) {
      var on = im.image === payload.image;
      return '<tr><td>' + U.esc(im.label) + "</td><td class=\"pr-addons-row__name\">" + U.esc(im.image) + "</td>" +
        "<td>" + (on ? '<span class="pr-worlds-badge" data-active="true">Active</span>' : "") + "</td>" +
        "<td>" + (perms.canDocker && !payload.imageLocked && !on
          ? '<button type="button" class="pr-addons-act" data-vimg="' + U.esc(im.image) + '">Use</button>' : "") + "</td></tr>";
    }).join("");
    body.innerHTML =
      '<div class="pr-plugins-panel"><div class="pr-section-title">Server jars</div>' +
      '<table class="pr-addons-table"><thead><tr><th>Jar</th><th>Size</th><th></th><th></th></tr></thead><tbody>' +
      (rows || '<tr><td colspan="4" class="pr-addons-empty">No jars on the server root.</td></tr>') + "</tbody></table>" +
      '<div class="pr-section-title">Docker image</div>' +
      '<table class="pr-addons-table"><thead><tr><th>Label</th><th>Image</th><th></th><th></th></tr></thead><tbody>' +
      (images || '<tr><td colspan="4" class="pr-addons-empty">No images on this egg.</td></tr>') + "</tbody></table>" +
      '<div class="pr-plugins-panel__hint">Changes apply on the next restart.</div></div>';
    if (!body.getAttribute("data-pr-versions-bound")) {
    body.setAttribute("data-pr-versions-bound", "1");
    body.addEventListener("click", function (e) {
      var j = e.target.closest("[data-vjar]");
      if (j) {
        P.api("addons/versions/jar", { method: "POST", json: { server: serverId(), name: j.dataset.vjar } })
          .then(function () { P.toast("Jar selected", "Restart to load " + U.esc(j.dataset.vjar), "success"); openVersions(); })
          .catch(function (err) { P.toast("Switch failed", U.esc(err.message || "unknown"), "error"); });
        return;
      }
      var im = e.target.closest("[data-vimg]");
      if (im) {
        P.api("addons/versions/image", { method: "POST", json: { server: serverId(), image: im.dataset.vimg } })
          .then(function () { P.toast("Image selected", "Restart to apply", "success"); openVersions(); })
          .catch(function (err) { P.toast("Switch failed", U.esc(err.message || "unknown"), "error"); });
      }
    });
    }
  }

  /* ── icons panel ─────────────────────────────────────────────── */
  function openIcons() { panelLoad("icons", "addons/icons", renderIcons); }

  function renderIcons(body, payload) {
    if (!payload.java) { javaEmpty(body, "Icon Manager is for Java Minecraft servers."); return; }
    var perms = payload.perms || {};
    var img = payload.exists && payload.preview
      ? '<img class="pr-icon-preview" alt="server icon" src="' + payload.preview + '">'
      : '<div class="pr-addons-empty">No server-icon.png yet.</div>';
    body.innerHTML =
      '<div class="pr-plugins-panel"><div class="pr-plugins-panel__hint">Minecraft server list icon (PNG, max 128 KiB)</div>' +
      '<div class="pr-icon-wrap">' + img + "</div>" +
      (perms.canCreate || perms.canUpdate
        ? '<div class="pr-addons-upload"><input type="file" accept="image/png" class="pr-icon-file" hidden>' +
          '<button type="button" class="pr-addons-upload__btn">Upload PNG</button></div>' : "") +
      (perms.canDelete && payload.exists
        ? '<button type="button" class="pr-addons-act pr-addons-act--danger" data-idel="1">Remove icon</button>' : "") +
      "</div>";
    var file = body.querySelector(".pr-icon-file");
    var btn = body.querySelector(".pr-addons-upload__btn");
    if (btn && file) {
      btn.addEventListener("click", function () { file.click(); });
      file.addEventListener("change", function () {
        if (!file.files || !file.files[0]) return;
        var f = file.files[0];
        if (f.size > 128 * 1024) { P.toast("Upload rejected", "Icon exceeds 128 KiB.", "error"); return; }
        var reader = new FileReader();
        reader.onload = function () {
          P.api("addons/icons/upload", { method: "POST", json: { server: serverId(), content: String(reader.result).split(",")[1] || "" } })
            .then(function () { P.toast("Icon uploaded", "server-icon.png", "success"); openIcons(); })
            .catch(function (err) { P.toast("Upload failed", U.esc(err.message || "unknown"), "error"); });
        };
        reader.readAsDataURL(f);
      });
    }
    var del = body.querySelector("[data-idel]");
    if (del) del.addEventListener("click", function () {
      P.api("addons/icons/delete", { method: "POST", json: { server: serverId() } })
        .then(function () { P.toast("Icon removed", "", "success"); openIcons(); })
        .catch(function (err) { P.toast("Delete failed", U.esc(err.message || "unknown"), "error"); });
    });
  }

  /* ── properties panel ────────────────────────────────────────── */
  function openProperties() { panelLoad("properties", "addons/properties", renderProperties); }

  function renderProperties(body, payload) {
    if (!payload.java) { javaEmpty(body, "Properties Manager is for Java Minecraft servers."); return; }
    var can = !!(payload.perms && payload.perms.canUpdate);
    var rows = ((payload && payload.keys) || []).map(function (k) {
      var ctrl;
      if (k.choices) {
        ctrl = '<select class="pr-prop-input" data-pkey="' + U.esc(k.key) + '"' + (can ? "" : " disabled") + ">" +
          k.choices.map(function (c) {
            return '<option value="' + U.esc(c) + '"' + (c === k.value ? " selected" : "") + ">" + U.esc(c) + "</option>";
          }).join("") + "</select>";
      } else {
        ctrl = '<input class="pr-prop-input" data-pkey="' + U.esc(k.key) + '" value="' + U.esc(k.value) + '"' + (can ? "" : " disabled") + ">";
      }
      return '<tr><td class="pr-addons-row__name">' + U.esc(k.key) + "</td><td>" + ctrl + "</td></tr>";
    }).join("");
    body.innerHTML =
      '<div class="pr-plugins-panel"><div class="pr-plugins-panel__hint">Safe server.properties keys. Ports and secrets stay in the Files tab.</div>' +
      '<table class="pr-addons-table"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>' + rows + "</tbody></table>" +
      (can ? '<button type="button" class="pr-addons-act" data-psave="1">Save changed</button>' : "") +
      "</div>";
    var orig = {};
    (payload.keys || []).forEach(function (k) { orig[k.key] = k.value; });
    var save = body.querySelector("[data-psave]");
    if (save) save.addEventListener("click", function () {
      var chain = Promise.resolve();
      var n = 0;
      body.querySelectorAll(".pr-prop-input").forEach(function (el) {
        var key = el.dataset.pkey;
        var val = el.value;
        if (val === orig[key]) return;
        n += 1;
        chain = chain.then(function () {
          return P.api("addons/properties/save", { method: "POST", json: { server: serverId(), key: key, value: val } });
        });
      });
      if (!n) { P.toast("Nothing to save", "", "info"); return; }
      chain.then(function () { P.toast("Properties saved", "Restart to apply", "success"); openProperties(); })
        .catch(function (err) { P.toast("Save failed", U.esc(err.message || "unknown"), "error"); });
    });
  }

  function renderPlugins() {
    var body = mask.querySelector(".pr-addons-body");
    var jars = ((pluginsState && pluginsState.jars) || []).filter(function (j) { return j.dir !== "mods"; });
    var perms = (pluginsState && pluginsState.perms) || {};
    var detect = (pluginsState && pluginsState.detect) || {};
    var tab = pluginsTab;

    var rows = jars.map(function (j) {
      var toggleBtn = perms.canUpdate
        ? '<button type="button" class="pr-addons-act" data-toggle="' + U.esc(j.name) + '" data-dir="' + U.esc(j.dir) + '">' + (j.enabled ? "Disable" : "Enable") + "</button>"
        : "";
      var deleteBtn = perms.canDelete
        ? '<button type="button" class="pr-addons-act pr-addons-act--danger" data-delete="' + U.esc(j.name) + '" data-dir="' + U.esc(j.dir) + '">Delete</button>'
        : "";
      return '<tr class="pr-addons-row" data-name="' + U.esc(j.name) + '">' +
        '<td class="pr-addons-row__name">' + U.esc(j.name) + "</td>" +
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
        '<div class="pr-panel-tabs" role="tablist">' +
          '<button type="button" class="pr-panel-tab' + (tab !== "search" ? " is-active" : "") + '" data-ptab="installed">Installed (' + jars.length + ")</button>" +
          '<button type="button" class="pr-panel-tab' + (tab === "search" ? " is-active" : "") + '" data-ptab="search">Search &amp; Install</button>' +
        "</div>" +
        '<div class="pr-panel-pane"' + (tab === "search" ? " hidden" : "") + ">" +
          '<div class="pr-plugins-panel__head"><span class="pr-plugins-panel__hint">Plugin jars in plugins/</span></div>' +
          '<table class="pr-addons-table"><thead><tr><th>Plugin</th><th>Size</th><th>State</th><th></th></tr></thead>' +
          "<tbody>" + (rows || '<tr><td colspan="4" class="pr-addons-empty">No plugin jars found.</td></tr>') + "</tbody></table>" +
          upload +
        "</div>" +
        '<div class="pr-panel-pane pr-plugins-search"' + (tab === "search" ? "" : " hidden") + "></div>" +
      "</div>";

    if (tab === "search") {
      searchUI(body.querySelector(".pr-plugins-search"), {
        type: "plugin",
        endpoint: "addons/plugins/search",
        detect: detect,
        perms: perms,
        onInstalled: function () { openPanel("plugins"); },
      });
    }

    body.querySelectorAll(".pr-panel-tab").forEach(function (t) {
      t.addEventListener("click", function () {
        pluginsTab = t.dataset.ptab === "search" ? "search" : "installed";
        renderPlugins();
      });
    });

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

  function fmtDownloads(n) {
    n = Number(n) || 0;
    return n >= 1e6 ? (n / 1e6).toFixed(1) + "M" : n >= 1e3 ? (n / 1e3).toFixed(1) + "k" : String(n);
  }

  function fmtDate(s) {
    var d = new Date(s);
    return isNaN(d.getTime()) ? "" : d.toISOString().slice(0, 10);
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
