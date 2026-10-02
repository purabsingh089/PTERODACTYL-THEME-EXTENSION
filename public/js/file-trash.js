/*
 * Primus · file-trash.js
 * Runs on /server/{id}/files pages. Two jobs:
 *   1. Intercept the stock file manager's delete call (POST
 *      /api/client/servers/{uuid}/files/delete carrying {root, files})
 *      and reroute it to the Primus trash backend so deletions land in
 *      .primus-trash/ instead of vanishing. Fail-open: if the addon is
 *      disabled or the request shape looks unexpected, pass through to
 *      stock behavior untouched.
 *   2. Inject a Trash toolbar button + side panel (list / restore /
 *      delete-forever / empty) into the file manager header.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  function serverId() {
    var m = location.pathname.match(/\/server\/([a-zA-Z0-9]+)/);
    return m ? m[1] : "";
  }
  function isFilesPage() {
    return /\/server\/[a-zA-Z0-9]+\/files/.test(location.pathname);
  }

  /* ── 1. fetch intercept ─────────────────────────────────────── */
  var DELETE_URL_RE = /\/api\/client\/servers\/[a-zA-Z0-9-]+\/files\/delete$/;
  var nativeFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    var url = typeof input === "string" ? input : (input && input.url) || "";
    var method = ((init && init.method) || (input && input.method) || "GET");
    var path = url.replace(/^https?:\/\/[^/]+/, "").split("?")[0];

    if (!/post/i.test(method) || !DELETE_URL_RE.test(path)) {
      return nativeFetch(input, init);
    }

    var body = null;
    try {
      if (init && typeof init.body === "string") body = JSON.parse(init.body);
      else if (init && init.body) body = init.body;
    } catch (e) {
      body = null;
    }
    var files = body && Array.isArray(body.files) ? body.files : null;
    if (!files || !files.length) {
      console.warn("[primus] delete intercept skipped: unrecognized body");
      return nativeFetch(input, init);
    }

    /* the stock API sends {root, files-relative-to-root}; join them so
       the backend receives server-root-relative paths */
    var root = (body && typeof body.root === "string" ? body.root : "/") || "/";
    var joined = files.map(function (f) {
      return (root.replace(/\/+$/, "") + "/" + f).replace(/^\/+/, "");
    });

    return P.api("trash/add", { method: "POST", json: { server: serverId(), files: joined } })
      .then(function (res) {
        P.toast("Moved to trash",
          res.moved + " item" + (res.moved === 1 ? "" : "s") + " - restore from the Trash button.",
          "success");
        /* resolve the way the stock API does so React refreshes */
        return new Response(null, {
          status: 204,
          statusText: "No Content",
        });
      })
      .catch(function (err) {
        P.toast("Delete blocked",
          (err && err.message) || "Trash move failed; nothing was deleted.",
          "error");
        return new Response('{"error":"primus-trash"}', {
          status: 500,
          headers: { "Content-Type": "application/json" },
        });
      });
  };

  /* ── 2. Trash toolbar + panel ───────────────────────────────── */
  var observer = null;
  var panel = null;

  function ensureToolbar() {
    if (!isFilesPage() || U.q(".pr-trash-btn")) return;
    /* the stock FM has action buttons (Create Directory / Upload / New
       File); anchor next to their holder. Match on button text so the
       Breadcrumbs component (also FileManager*) is never the anchor. */
    var buttons = U.qa("button");
    var anchor = null;
    for (var i = 0; i < buttons.length; i++) {
      var label = (buttons[i].textContent || "").trim().toLowerCase();
      if (label === "upload" || label === "new file" || label === "create directory") {
        buttons[i].classList.add("pr-fm-cta");
        if (!anchor) anchor = buttons[i];
      }
    }
    if (!anchor) return;
    var holder = anchor.parentElement;
    if (!holder || holder.querySelector(".pr-trash-btn")) return;
    var btn = U.el("button", "pr-trash-btn", "Trash");
    btn.type = "button";
    btn.addEventListener("click", openPanel);
    holder.appendChild(btn);
  }

  function closePanel() {
    if (panel) { panel.remove(); panel = null; }
  }

  function openPanel() {
    if (panel) { closePanel(); return; }
    panel = U.el("div", "pr-trash-panel pr-scale-in", "");
    panel.innerHTML =
      '<div class="pr-trash-panel__head"><span>Trash</span>' +
        '<div><button type="button" class="pr-trash-empty">Empty trash</button>' +
        '<button type="button" class="pr-trash-close" aria-label="Close">&times;</button></div></div>' +
      '<div class="pr-trash-panel__body"><div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div></div>';
    document.body.appendChild(panel);
    panel.querySelector(".pr-trash-close").addEventListener("click", closePanel);
    panel.querySelector(".pr-trash-empty").addEventListener("click", function () {
      if (!window.confirm("Permanently delete all trashed items? This cannot be undone.")) return;
      P.api("trash/empty", { method: "POST", json: { server: serverId() } })
        .then(function (res) {
          P.toast("Trash emptied", ((res && res.purged) || 0) + " item(s) deleted.", "success");
          load();
        })
        .catch(function (err) { P.toast("Empty failed", U.esc(err.message || "unknown"), "error"); });
    });
    load();

    function load() {
      var body = panel.querySelector(".pr-trash-panel__body");
      body.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
      P.api("trash/list?server=" + encodeURIComponent(serverId()))
        .then(function (payload) { render(payload, body); })
        .catch(function (err) {
          body.innerHTML = '<div class="pr-trash-empty-msg">' + U.esc(err.message || "Failed to load trash.") + "</div>";
        });
    }

    function render(payload, body) {
      var entries = (payload && payload.entries) || [];
      if (!entries.length) {
        body.innerHTML = '<div class="pr-trash-empty-msg">Trash is empty. Deleted files land here for 14 days.</div>';
        return;
      }
      body.innerHTML = "";
      entries.forEach(function (e) {
        var row = U.el("div", "pr-trash-item",
          '<div class="pr-trash-item__main">' +
            '<div class="pr-trash-item__name">' + U.esc(e.name) + "</div>" +
            '<div class="pr-trash-item__meta">from ' + U.esc(e.originalPath) + " &middot; " + U.esc(e.purgeInDays) + "d left</div>" +
          "</div>" +
          '<div class="pr-trash-item__acts">' +
            '<button type="button" class="pr-trash-item__restore" data-rid="' + e.id + '">Restore</button>' +
            '<button type="button" class="pr-trash-item__destroy" data-rid="' + e.id + '" data-rname="' + U.esc(e.name) + '">Delete forever</button>' +
          "</div>");
        body.appendChild(row);
      });
      body.addEventListener("click", function (ev) {
        var r = ev.target.closest(".pr-trash-item__restore");
        if (r) {
          P.api("trash/restore", { method: "POST", json: { server: serverId(), id: Number(r.dataset.rid) } })
            .then(function (res) { P.toast("Restored", U.esc(res.path || ""), "success"); load(); })
            .catch(function (err) { P.toast("Restore failed", U.esc(err.message || "unknown"), "error"); });
          return;
        }
        var d = ev.target.closest(".pr-trash-item__destroy");
        if (d) {
          var nm = d.dataset.rname;
          var typed = window.prompt('Type "' + nm + '" to permanently delete:');
          if (typed !== nm) return;
          P.api("trash/destroy", { method: "POST", json: { server: serverId(), id: Number(d.dataset.rid), confirm: nm } })
            .then(function () { P.toast("Deleted forever", U.esc(nm), "success"); load(); })
            .catch(function (err) { P.toast("Delete failed", U.esc(err.message || "unknown"), "error"); });
        }
      }, { once: true });
    }
  }

  /* ── boot + SPA lifecycle ────────────────────────────────────── */
  function boot() {
    if (!isFilesPage()) return;
    ensureToolbar();
    if (!observer) {
      observer = new MutationObserver(ensureToolbar);
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  function teardown() {
    if (observer) { observer.disconnect(); observer = null; }
    closePanel();
  }

  P.ready.then(boot);
  P.on("page:view", function () {
    teardown();
    setTimeout(boot, 150);
  });
})();
