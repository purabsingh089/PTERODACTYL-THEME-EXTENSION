/*
 * Primus · widgets.js
 * Client-side feature widgets built on the theme.js widget framework:
 *   announcements banner, custom footer/socials, notification center (bell),
 *   server favorites + grid/list toggle + fuzzy search, empty states,
 *   backups timeline, file-manager drop-zone polish, onboarding tour,
 *   auth/login overlay (login, checkpoint, forgot, reset).
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  /* tiny markdown renderer (bold / italic / inline code / links / breaks) */
  function md(src) {
    var html = U.esc(src || "");
    return html
      .replace(/`([^`]+)`/g, "<code>$1</code>")
      .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
      .replace(/\*([^*]+)\*/g, "<em>$1</em>")
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
      .replace(/\n/g, "<br>");
  }

  /* ═══════════ announcements banner ═══════════ */
  var announceShown = false;
  function mountAnnouncement() {
    var root = U.q("#root");
    if (!root || announceShown) return;
    var text = P.set("announcements.text", "");
    if (!text) return;
    var key = "announce-seen:" + btoa(unescape(encodeURIComponent(text))).slice(0, 24);
    if (P.store.get(key)) return;
    announceShown = true;

    var bar = U.el(
      "div",
      "pr-announce pr-fade-in",
      '<span class="pr-announce__dot"></span>' +
        '<div class="pr-announce__body">' + md(text) + "</div>" +
        '<button class="pr-announce__close" aria-label="Dismiss">&times;</button>'
    );
    bar.querySelector(".pr-announce__close").addEventListener("click", function () {
      P.store.set(key, true);
      bar.style.animation = "pr-fade-in var(--pr-dur-2) var(--pr-ease) reverse forwards";
      setTimeout(function () { bar.remove(); }, 200);
    });
    root.insertBefore(bar, root.firstChild);
  }

  /* ═══════════ custom footer / socials bar ═══════════ */
  function footerIcon(name) {
    var icons = {
      discord: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.3 4.4A19.8 19.8 0 0 0 15.9 3l-.6 1.3a15 15 0 0 0-6.6 0L8.1 3a19.7 19.7 0 0 0-4.4 1.4C1.3 8.6.7 12.6 1 16.6a19.9 19.9 0 0 0 6 3l1.3-2a12.9 12.9 0 0 1-2-1l.5-.4a14.2 14.2 0 0 0 10.4 0l.5.4c-.7.4-1.4.7-2 1l1.3 2a19.8 19.8 0 0 0 6-3c.4-4.6-.7-8.6-2.7-12.2ZM8.6 14.2c-1 0-1.9-1-1.9-2.2 0-1.2.8-2.2 1.9-2.2s2 1 1.9 2.2c0 1.2-.9 2.2-1.9 2.2Zm6.8 0c-1 0-1.9-1-1.9-2.2 0-1.2.8-2.2 1.9-2.2s2 1 1.9 2.2c0 1.2-.8 2.2-1.9 2.2Z"/></svg>',
      web: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.4 3.8 5.6 3.8 9S14.5 18.6 12 21c-2.5-2.4-3.8-5.6-3.8-9S9.5 5.4 12 3Z"/></svg>',
      support: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 10a8 8 0 0 1 16 0v6a3 3 0 0 1-3 3h-2"/><path d="M4 10v4a2 2 0 0 0 2 2h2v-6H6a2 2 0 0 0-2 2Zm16 0a2 2 0 0 0-2-2h-2v6h2a2 2 0 0 0 2-2v-4Z"/></svg>',
      github: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.8.6-3.4-1.3-3.4-1.3-.5-1.1-1-1.4-1-1.4-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.6 2.4 1.1 3 .9.1-.7.4-1.1.7-1.4-2.2-.3-4.6-1.1-4.6-5a3.9 3.9 0 0 1 1-2.7 3.6 3.6 0 0 1 .1-2.7s.9-.3 2.8 1a9.5 9.5 0 0 1 5 0c1.9-1.3 2.8-1 2.8-1a3.6 3.6 0 0 1 .1 2.7 3.9 3.9 0 0 1 1 2.7c0 3.9-2.4 4.7-4.6 5 .4.3.7.9.7 1.9V21c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>',
    };
    return icons[name] || icons.web;
  }

  function mountFooter() {
    if (U.q(".pr-footer") || !P.set("footer.enabled", true)) return;
    var root = U.q("#root");
    if (!root) return;
    var footer = U.el("div", "pr-footer pr-fade-in");
    var left = U.el("div", "pr-footer__left");
    var label = P.set("footer.label", "");
    if (label) left.innerHTML = "<span>" + U.esc(label) + "</span>";
    var right = U.el("div", "pr-footer__right");
    var links = P.set("footer.links", []);
    (Array.isArray(links) ? links : []).forEach(function (l) {
      if (!l || !l.url) return;
      var a = U.el("a", "pr-footer__link");
      a.href = l.url;
      a.target = "_blank";
      a.rel = "noopener";
      a.innerHTML = footerIcon(l.icon) + "<span>" + U.esc(l.label || l.icon || "Link") + "</span>";
      right.appendChild(a);
    });
    footer.appendChild(left);
    footer.appendChild(right);
    root.appendChild(footer);
  }

  /* ═══════════ notification center (bell) ═══════════ */
  var notifications = { items: P.store.get("notifications", []) };

  function persistNotifications() {
    P.store.set("notifications", notifications.items.slice(-30));
  }

  P.notify = function (title, body, kind) {
    notifications.items.push({
      id: Date.now() + Math.random(),
      title: title,
      body: body || "",
      kind: kind || "info",
      ts: Date.now(),
      read: false,
    });
    persistNotifications();
    renderBellBadge();
    P.emit("notify:update");
    if (!document.hidden) P.toast(title, U.esc(body || ""), kind);
  };

  function kindColor(kind) {
    return {
      success: "var(--pr-success)",
      error: "var(--pr-danger)",
      warning: "var(--pr-warning)",
      info: "var(--pr-accent)",
    }[kind] || "var(--pr-accent)";
  }

  function renderBellBadge() {
    var badge = U.q(".pr-bell__badge");
    if (!badge) return;
    var unread = notifications.items.filter(function (i) { return !i.read; }).length;
    badge.textContent = unread > 9 ? "9+" : String(unread || "");
    badge.style.display = unread ? "" : "none";
  }

  function mountBell() {
    if (U.q(".pr-bell")) return;
    var header = U.q("header");
    if (!header) return;
    var bell = U.el(
      "button",
      "pr-bell pr-scale-in",
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8Z"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/></svg>' +
        '<span class="pr-bell__badge"></span>'
    );
    bell.setAttribute("aria-label", "Notifications");
    bell.addEventListener("click", function (e) {
      e.stopPropagation();
      toggleBellPanel(bell);
    });
    var anchor = header.querySelector("div:last-of-type") || header;
    anchor.appendChild(bell);
    renderBellBadge();
  }

  function toggleBellPanel(anchor) {
    var panel = U.q(".pr-bellpanel");
    if (panel) { panel.remove(); return; }
    panel = U.el(
      "div",
      "pr-bellpanel pr-scale-in",
      '<div class="pr-bellpanel__head">' +
        "<span>Notifications</span>" +
        '<button class="pr-bellpanel__clear" type="button">Mark all read</button>' +
      "</div>" +
      '<div class="pr-bellpanel__list"></div>'
    );
    document.body.appendChild(panel);
    var rect = anchor.getBoundingClientRect();
    panel.style.top = (rect.bottom + 8) + "px";
    panel.style.right = Math.max(8, window.innerWidth - rect.right) + "px";

    panel.querySelector(".pr-bellpanel__clear").addEventListener("click", function () {
      notifications.items.forEach(function (i) { i.read = true; });
      persistNotifications();
      renderBellBadge();
      fillBellList(panel);
    });
    fillBellList(panel);

    setTimeout(function () {
      function onDocClick(ev) {
        if (!panel.contains(ev.target)) {
          panel.remove();
          document.removeEventListener("click", onDocClick);
        }
      }
      document.addEventListener("click", onDocClick);
    }, 0);
  }

  function fillBellList(panel) {
    var list = panel.querySelector(".pr-bellpanel__list");
    list.innerHTML = "";
    var items = notifications.items.slice().reverse();
    if (!items.length) {
      list.innerHTML =
        '<div class="pr-bellpanel__empty">' +
          '<img src="' + P.webroot + '/img/empty-states/notifications.svg" alt="">' +
          "<p>No notifications yet.<br>Server events and AI suggestions land here.</p>" +
        "</div>";
      return;
    }
    items.forEach(function (it) {
      var row = U.el("div", "pr-bellpanel__item" + (it.read ? "" : " is-unread"));
      row.innerHTML =
        '<span class="pr-bellpanel__dot" style="background:' + kindColor(it.kind) + '"></span>' +
        '<div class="pr-bellpanel__text">' +
          '<span class="pr-bellpanel__title">' + U.esc(it.title) + "</span>" +
          '<span class="pr-bellpanel__time">' + U.relTime(new Date(it.ts).toISOString()) + "</span>" +
          (it.body ? '<div class="pr-bellpanel__body">' + U.esc(it.body) + "</div>" : "") +
        "</div>";
      row.addEventListener("click", function () {
        it.read = true;
        persistNotifications();
        renderBellBadge();
        fillBellList(panel);
      });
      list.appendChild(row);
    });
  }

  /* ═══════════ server list: favorites, grid/list, search ═══════════ */
  function serverIdFromRow(row) {
    return ((row.getAttribute("href") || "").split("/")[2] || "").split("?")[0];
  }

  function enrichServerRow(row) {
    if (U.attr(row, "data-pr-enhanced")) return;
    var id = serverIdFromRow(row);
    if (!id) return;
    U.attr(row, "data-pr-enhanced", id);

    var favs = P.store.get("favorites", []);
    var star = U.el("button", "pr-fav-btn" + (favs.indexOf(id) >= 0 ? " is-fav" : ""));
    star.type = "button";
    star.setAttribute("aria-label", "Toggle favorite");
    star.innerHTML =
      '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="' +
      (favs.indexOf(id) >= 0 ? "currentColor" : "none") +
      '"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.5 6.1 20.6l1.2-6.5L2.5 9.5l6.6-.9L12 2.6z"/></svg>';
    star.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var list = P.store.get("favorites", []);
      var idx = list.indexOf(id);
      if (idx >= 0) list.splice(idx, 1); else list.push(id);
      P.store.set("favorites", list);
      var isFav = idx < 0;
      star.classList.toggle("is-fav", isFav);
      star.querySelector("svg").setAttribute("fill", isFav ? "currentColor" : "none");
      row.classList.toggle("is-favorite", isFav);
      orderServerRows();
    });
    var first = row.querySelector("div") || row;
    first.insertBefore(star, first.firstChild);
    if (favs.indexOf(id) >= 0) row.classList.add("is-favorite");
  }

  function isServerCardLink(a) {
    if (a.closest && a.closest(".pr-quickactions")) return false;
    if (a.closest && a.closest('nav, [class*="SubNavigation"], [class*="Navigation"], header')) return false;
    var href = a.getAttribute("href") || "";
    if (!/^\/server\/[a-zA-Z0-9-]+\/?$/.test(href.split("?")[0])) return false;
    var parentCard = a.parentElement && a.parentElement.closest && a.parentElement.closest('a[href^="/server/"]');
    if (parentCard) return false;
    return true;
  }

  function serverRowNodes() {
    return U.qa('a[href^="/server/"]').filter(isServerCardLink);
  }

  function orderServerRows() {
    var rows = serverRowNodes();
    if (!rows.length) return;
    var favs = P.store.get("favorites", []);
    var container = rows[0].parentNode;
    rows.sort(function (a, b) {
      var af = favs.indexOf(serverIdFromRow(a)) >= 0 ? 0 : 1;
      var bf = favs.indexOf(serverIdFromRow(b)) >= 0 ? 0 : 1;
      return af - bf;
    });
    rows.forEach(function (r) { container.appendChild(r); });
  }

  function isNarrowServerList() {
    return window.innerWidth < 720;
  }

  function applyServerListLayout() {
    var layout = isNarrowServerList() ? "grid" : P.store.get("serverlist:layout", "grid");
    var rows = serverRowNodes();
    if (!rows.length) return;
    var container = rows[0].parentNode;
    container.classList.add("pr-servergrid");
    container.classList.toggle("pr-serverlist", layout === "list");
    rows.forEach(function (r) { r.classList.add("pr-servergrid__card"); });
    var toolbar = U.q(".pr-servers-toolbar");
    if (toolbar) {
      U.qa("[data-pr-layout]", toolbar).forEach(function (btn) {
        btn.classList.toggle("is-active", btn.dataset.prLayout === layout);
      });
    }
  }

  function mountServerToolbar() {
    if (U.q(".pr-servers-toolbar")) return;
    var rows = serverRowNodes();
    if (!rows.length) return;
    var toolbar = U.el(
      "div",
      "pr-servers-toolbar pr-slide-up",
      '<div class="pr-searchwrap">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' +
        '<input type="search" id="primus-server-search" placeholder="Search servers…" autocomplete="off">' +
        "<kbd>/</kbd></div>" +
      '<div class="pr-layout-toggle" role="group" aria-label="Server list layout">' +
        '<button type="button" data-pr-layout="list" title="List layout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>' +
        '<button type="button" data-pr-layout="grid" title="Grid layout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg></button>' +
      "</div>"
    );
    rows[0].parentNode.insertBefore(toolbar, rows[0]);

    var active = P.store.get("serverlist:layout", "grid");
    U.qa("[data-pr-layout]", toolbar).forEach(function (btn) {
      btn.classList.toggle("is-active", btn.dataset.prLayout === active);
      btn.addEventListener("click", function () {
        P.store.set("serverlist:layout", btn.dataset.prLayout);
        applyServerListLayout();
      });
    });

    var input = toolbar.querySelector("input");
    input.addEventListener("input", U.debounce(function () {
      var q = input.value.trim().toLowerCase();
      serverRowNodes().forEach(function (row) {
        var match = !q || (row.textContent || "").toLowerCase().indexOf(q) >= 0;
        row.style.display = match ? "" : "none";
      });
    }, 120));
    P.focusServerSearch = function () { input.focus(); input.select(); };
  }

  /* quick-action reveal on server rows (restart/console/AI-fix) */
  function quickActionsOnRow(row) {
    if (U.attr(row, "data-pr-qa") || !P.set("quickactions.enabled", true)) return;
    U.attr(row, "data-pr-qa", "1");
    var id = serverIdFromRow(row);
    var qa = U.el(
      "div",
      "pr-quickactions",
      '<a title="Open console" href="/server/' + U.esc(id) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 7 5 5-5 5"/><path d="M12 17h7"/></svg></a>' +
      '<button title="Restart server" type="button" data-pr-send="restart"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg></button>' +
      '<a title="AI diagnostics" href="/server/' + U.esc(id) + '?primus=diagnose"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.1 6.2L20 10l-5.9 1.8L12 18l-2.1-6.2L4 10l5.9-1.8L12 2z"/></svg></a>'
    );
    qa.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-pr-send]");
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      sendPowerSignal(id, btn.dataset.prSend).then(function () {
        P.notify("Power signal sent", "Restart requested for server " + id + ".", "info");
      });
    });
    row.appendChild(qa);
  }

  function sendPowerSignal(id, signal) {
    return P.api("proxy/power", { method: "POST", json: { server: id, signal: signal } })
      .catch(function () {
        /* fall back: the client API handles power; toast only */
      });
  }

  /* ═══════════ empty states ═══════════ */
  function decorateEmptyStates(root) {
    root = root || U.q("#root");
    if (!root) return;
    var texts = U.qa("p, span, div", root).filter(function (el) {
      var t = (el.textContent || "").trim();
      return t && t.length > 4 && t.length < 90 && el.childElementCount === 0 &&
        /no servers found|didn[’']t? find any servers?|no backups found|no files|no schedules|there are no (?:servers|files|backups|schedules|databases|allocations)|you have no/i.test(t);
    });
    texts.forEach(function (el) {
      if (el.closest(".pr-emptystate")) return;
      var map = [
        [/backup/i, "backups"],
        [/file/i, "files"],
        [/schedule/i, "schedules"],
        [/database/i, "databases"],
        [/allocation|network/i, "network"],
      ];
      var kind = "default";
      map.forEach(function (m) { if (kind === "default" && m[0].test(el.textContent)) kind = m[1]; });
      var wrap = U.el(
        "div",
        "pr-emptystate pr-fade-in",
        '<img src="' + P.webroot + "/img/empty-states/" + kind + '.svg" alt="">' +
          '<div class="pr-emptystate__text">' + U.esc(el.textContent) + "</div>"
      );
      el.textContent = "";
      el.appendChild(wrap);
    });
  }

  /* ═══════════ backups timeline ═══════════ */
  function timelineifyBackups() {
    if (!/\/backups/.test(location.pathname)) return;
    var rows = U.qa("li, tr, div").filter(function (el) {
      var t = el.textContent || "";
      return /restore|restore now/i.test(t) && /\d+\s?(MiB|GiB|KiB|bytes?)/i.test(t) && el.querySelectorAll("li").length === 0;
    });
    if (rows.length < 2) return;
    rows.forEach(function (row) {
      if (U.attr(row, "data-pr-timeline")) return;
      U.attr(row, "data-pr-timeline", "1");
      var dot = U.el("span", "pr-timeline-dot");
      row.style.position = "relative";
      row.insertBefore(dot, row.firstChild);
    });
    if (!U.q(".pr-timeline-guide")) {
      rows[0].insertAdjacentHTML("afterbegin", '<span class="pr-timeline-guide"></span>');
    }
  }

  /* ═══════════ file manager drop zone ═══════════ */
  function mountFileDropZone(host) {
    if (U.attr(host, "data-pr-dropzone")) return;
    U.attr(host, "data-pr-dropzone", "1");
    var zone = null;
    host.addEventListener("dragover", function (e) {
      e.preventDefault();
      if (!zone) {
        zone = U.el("div", "pr-dropzone pr-fade-in", "<span>Drop files to upload</span>");
        host.appendChild(zone);
      }
    });
    host.addEventListener("dragleave", function (e) {
      if (e.target === host && zone) { zone.remove(); zone = null; }
    });
    host.addEventListener("drop", function () {
      if (zone) { zone.remove(); zone = null; }
    });
  }

  /* ═══════════ onboarding tour ═══════════ */
  function runTour() {
    if (P.store.get("tour:done")) return;
    var steps = [];
    var fixer = U.q(".pr-ai-fixer-btn");
    if (fixer) steps.push({
      el: fixer,
      title: "AI Server Fixer",
      body: "Something broken? Click here and Primus analyzes the console logs with AI and suggests the fix.",
    });
    var bell = U.q(".pr-bell");
    if (bell) steps.push({
      el: bell,
      title: "Notification center",
      body: "Server events and AI suggestions show up here so you never miss anything.",
    });
    if (!steps.length) return;

    var overlay = U.el("div", "pr-tour pr-fade-in", "");
    var spot = U.el("div", "pr-tour__spot");
    var card = U.el(
      "div",
      "pr-tour__card pr-scale-in",
      '<div class="pr-tour__step"></div><h3></h3><p></p>' +
        '<div class="pr-tour__actions">' +
          '<button class="pr-tour__skip" type="button">Skip</button>' +
          '<button class="pr-tour__next" type="button">Next</button>' +
        "</div>"
    );
    overlay.appendChild(spot);
    overlay.appendChild(card);
    document.body.appendChild(overlay);

    var step = 0;
    function place() {
      if (step >= steps.length) return end();
      var item = steps[step];
      item.el.scrollIntoView({ behavior: "smooth", block: "center" });
      var rect = item.el.getBoundingClientRect();
      spot.style.top = (rect.top - 8) + "px";
      spot.style.left = (rect.left - 8) + "px";
      spot.style.width = (rect.width + 16) + "px";
      spot.style.height = (rect.height + 16) + "px";
      var top = rect.bottom + 14;
      if (top + 190 > window.innerHeight) top = Math.max(10, rect.top - 190);
      card.style.top = top + "px";
      card.style.left = Math.max(10, Math.min(rect.left, window.innerWidth - 370)) + "px";
      card.querySelector("h3").textContent = item.title;
      card.querySelector("p").textContent = item.body;
      card.querySelector(".pr-tour__step").textContent = "Step " + (step + 1) + " of " + steps.length;
      card.querySelector(".pr-tour__next").textContent = step + 1 >= steps.length ? "Done" : "Next";
      card.classList.remove("pr-scale-in");
      void card.offsetWidth;
      card.classList.add("pr-scale-in");
    }
    function end() {
      P.store.set("tour:done", true);
      overlay.remove();
    }
    card.querySelector(".pr-tour__skip").addEventListener("click", end);
    card.querySelector(".pr-tour__next").addEventListener("click", function () { step++; place(); });
    overlay.addEventListener("click", function (e) { if (e.target === overlay) end(); });
    place();
  }

  /* ═══════════ auth / login overlay ═══════════ */
  function isAuthPage() {
    var path = location.pathname || "";
    return /^\/auth(\/|$)/.test(path) || path === "/login";
  }

  function authCopy() {
    var path = location.pathname || "";
    if (/\/auth\/login\/checkpoint/.test(path)) {
      return { title: "Device checkpoint", sub: "Enter the code from your authenticator" };
    }
    if (/\/auth\/password\/reset/.test(path)) {
      return { title: "Set a new password", sub: "Choose a password at least 8 characters long" };
    }
    if (/\/auth\/password/.test(path)) {
      return { title: "Forgot password", sub: "We'll send reset instructions to your email" };
    }
    return { title: "Welcome back", sub: "Sign in to continue to your panel" };
  }

  function brandHtml() {
    return (
      '<div class="pr-auth-brand">' +
        '<span class="pr-auth-mark" aria-hidden="true">' +
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="48" height="48" fill="none" role="img" aria-label="Primus">' +
            "<defs>" +
              '<linearGradient id="prAuthMark" x1="0" y1="0" x2="64" y2="64">' +
                '<stop offset="0" stop-color="#1e6fe0"/>' +
                '<stop offset="1" stop-color="#0050b8"/>' +
              "</linearGradient>" +
            "</defs>" +
            '<rect x="2" y="2" width="60" height="60" rx="16" fill="url(#prAuthMark)"/>' +
            '<rect x="2.75" y="2.75" width="58.5" height="58.5" rx="15.25" stroke="#ffffff" stroke-opacity="0.16" stroke-width="1.5"/>' +
            '<path d="M22 46V18h11.4a8.6 8.6 0 0 1 0 17.2H30.5V46H22Zm8.5-24.2H30.5v6.4h3a3.2 3.2 0 0 0 0-6.4Z" fill="#fff" fill-opacity="0.92"/>' +
          "</svg>" +
        "</span>" +
        '<span class="pr-auth-wordmark">Primus</span>' +
        '<h1 class="pr-auth-heading"></h1>' +
        '<p class="pr-auth-tag"></p>' +
      "</div>"
    );
  }

  function polishAuth() {
    var on = isAuthPage();
    document.documentElement.classList.toggle("pr-auth", on);
    document.body.classList.toggle("pr-auth", on);
    if (!on) return;

    var form = U.q("#app form") || U.q("form");
    if (!form) return;
    form.classList.add("pr-auth-form");

    var shell = form.parentElement;
    if (shell) {
      shell.classList.add("pr-auth-shell");
      ["padding", "padding-top", "padding-right", "padding-bottom", "padding-left"].forEach(function (prop) {
        shell.style.setProperty(prop, "0px", "important");
      });
      shell.style.setProperty("width", "min(420px, calc(100vw - 32px))", "important");
      shell.style.setProperty("max-width", "min(420px, calc(100vw - 32px))", "important");
      shell.style.setProperty("box-sizing", "border-box", "important");
    }
    form.style.setProperty("width", "100%", "important");
    form.style.setProperty("max-width", "none", "important");
    form.style.setProperty("display", "block", "important");

    var card = form.querySelector(":scope > div") || form.firstElementChild;
    if (card) {
      card.classList.add("pr-auth-card");
      if (!card.querySelector(".pr-auth-brand")) {
        var kids = Array.prototype.slice.call(card.children);
        kids.forEach(function (el) {
          if (el.querySelector && el.querySelector('img[src*="pterodactyl"]')) {
            el.classList.add("pr-auth-aside");
          } else {
            el.classList.add("pr-auth-fields");
          }
        });
        var wrap = U.el("div");
        wrap.innerHTML = brandHtml();
        card.insertBefore(wrap.firstElementChild, card.firstChild);
      }
    }

    var copy = authCopy();
    var heading = form.querySelector(".pr-auth-heading");
    var tag = form.querySelector(".pr-auth-tag");
    if (heading) heading.textContent = copy.title;
    if (tag) tag.textContent = copy.sub;

    var h2 = shell && shell.querySelector("h2");
    if (h2) {
      h2.classList.add("pr-auth-title");
      h2.setAttribute("aria-hidden", "true");
    }

    U.qa("button[type='submit']", form).forEach(function (b) {
      b.classList.add("pr-auth-submit");
    });
    U.qa("a[href='/auth/password'], a[href='/auth/login']", form).forEach(function (a) {
      a.classList.add("pr-auth-link");
    });
    U.qa('img[src*="pterodactyl"]', form).forEach(function (img) {
      img.setAttribute("alt", "");
      img.setAttribute("aria-hidden", "true");
    });
  }

  /* ═══════════ wiring ═══════════ */
  P.register({
    each: "#root",
    observe: function () {
      mountAnnouncement();
      mountFooter();
      mountServerToolbar();
      decorateEmptyStates();
    },
  });

  P.register({
    each: 'a[href^="/server/"]',
    observe: function (row) {
      if (!isServerCardLink(row)) return;
      enrichServerRow(row);
      quickActionsOnRow(row);
      orderServerRows();
      applyServerListLayout();
    },
  });

  P.register({
    each: 'div[class*="FileManager"], div[class*="files_container"], div[class*="filesContainer"]',
    observe: function (node) { mountFileDropZone(node); },
  });

  P.register({
    each: "#app form, form",
    observe: function () { polishAuth(); },
  });

  P.on("page:view", function (ev) {
    setTimeout(function () {
      polishAuth();
      decorateEmptyStates();
      timelineifyBackups();
      if (/\/server\/[a-zA-Z0-9]+\/?$/.test(ev.path)) setTimeout(runTour, 1500);
    }, 700);
  });

  if (isAuthPage()) {
    if (document.body) polishAuth();
    else document.addEventListener("DOMContentLoaded", polishAuth);
  }

  P.ready.then(function () {
    setTimeout(function () {
      polishAuth();
      mountBell();
      mountServerToolbar();
      applyServerListLayout();
      orderServerRows();
      decorateEmptyStates();
      timelineifyBackups();
      if (/primus=diagnose/.test(location.pathname + location.search)) {
        P.requestDiagnose = true;
      }
    }, 600);
    setInterval(function () {
      if (!U.q(".pr-bell")) mountBell();
    }, 5000);
    window.addEventListener("resize", U.debounce(applyServerListLayout, 120));
  });
})();
