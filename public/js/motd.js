/*
 * Primus · motd.js
 * MOTD Creator — "MOTD" subnav tab on Java Minecraft servers that opens
 * a studio overlay: live §-code preview, 16-color palette, templates,
 * 59-char counter. Saves through /extensions/primus/motd (server-side
 * read-modify-write of only the motd= line of server.properties).
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  var state = null;   // last GET payload, null until fetched
  var mask = null;    // open overlay element

  /* ── page context ─────────────────────────────────────────────── */
  function serverId() {
    var m = location.pathname.match(/\/server\/([a-zA-Z0-9]+)/);
    return m ? m[1] : "";
  }

  function isServerPage() {
    return /\/server\/[a-zA-Z0-9]+/.test(location.pathname);
  }

  /* ── subnav tab ────────────────────────────────────────────────── */
  function subnavHolder() {
    var sub = U.q("div[class*='SubNavigation']");
    if (!sub) return null;
    return sub.firstElementChild || sub;
  }

  function ensureTab() {
    var holder = subnavHolder();
    if (!holder) return;
    if (U.q(".pr-motd-tab")) return updateTab();
    /* never insert a tab for servers the backend gate disabled */
    if (!state || !state.enabled) return;
    var tab = U.el(
      "a",
      "pr-motd-tab",
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
        '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h10"/></svg>' +
      "<span>MOTD</span>"
    );
    tab.href = "#";
    tab.setAttribute("role", "button");
    tab.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (state && state.enabled) openStudio();
    });
    /* Insert after the last stock <a> whose href targets this server,
     * never before the Primus marker span or the admin link. */
    var serverLinks = U.qa("a[href*='/server/']", holder);
    var anchor = serverLinks.length ? serverLinks[serverLinks.length - 1] : null;
    if (anchor && anchor.nextSibling) holder.insertBefore(tab, anchor.nextSibling);
    else if (anchor) holder.appendChild(tab);
    else return; /* no stock links — subnav not ready, retry next pass */
    updateTab();
  }

  function updateTab() {
    var tab = U.q(".pr-motd-tab");
    if (!tab) return;
    var show = !!(state && state.enabled);
    tab.style.display = show ? "" : "none";
  }

  /* ── § code renderer ───────────────────────────────────────────── */
  var COLOR_CLASSES = "0123456789abcdef";

  function renderMotd(text) {
    var out = "";
    var bold = false, italic = false, strike = false, under = false, obf = false;
    var color = "";
    var plain = "";
    var i = 0;

    function flush() {
      if (!plain) return;
      var cls = "pr-motd-seg";
      if (color) cls += " pr-motd-c" + color;
      if (bold) cls += " pr-motd-fb";
      if (italic) cls += " pr-motd-fi";
      if (strike) cls += " pr-motd-fs";
      if (under) cls += " pr-motd-fu";
      if (obf) cls += " pr-motd-fo";
      out += '<span class="' + cls + '">' + U.esc(plain) + "</span>";
      plain = "";
    }

    while (i < text.length) {
      var ch = text[i];
      if (ch === "\u00a7" && i + 1 < text.length) {
        var code = text[i + 1].toLowerCase();
        if (COLOR_CLASSES.indexOf(code) >= 0 || "klmnor".indexOf(code) >= 0) {
          flush();
          if (COLOR_CLASSES.indexOf(code) >= 0) {
            color = code;
            bold = italic = strike = under = obf = false;
          } else if (code === "k") obf = true;
          else if (code === "l") bold = true;
          else if (code === "m") strike = true;
          else if (code === "n") under = true;
          else if (code === "o") italic = true;
          else if (code === "r") {
            color = "";
            bold = italic = strike = under = obf = false;
          }
          i += 2;
          continue;
        }
      }
      plain += ch;
      i += 1;
    }
    flush();
    return out || '<span class="pr-motd-seg">&nbsp;</span>';
  }

  function visibleLen(text) {
    return text.replace(/\u00a7./g, "").length;
  }

  /* ── templates ─────────────────────────────────────────────────── */
  var TEMPLATES = [
    {
      name: "Welcome",
      motd: "\u00a7b\u00a7lWelcome to our Server!\u00a7r \u00a77\u00a7oupdated 2026",
    },
    {
      name: "Features",
      motd: "\u00a76\u00a7lSurvival \u00a78\u2502 \u00a7bSkyblock \u00a78\u2502 \u00a7aMinigames \u00a78\u2502 \u00a7dEvents",
    },
    {
      name: "Vote",
      motd: "\u00a7e\u00a7lVote for rewards!\u00a7r \u00a77/vote \u00a78\u2502 \u00a7a5x keys daily",
    },
    {
      name: "Discord",
      motd: "\u00a7a\u25b8 Join our Discord:\u00a7f discord.gg/example \u00a77(free rank!)",
    },
    {
      name: "Maintenance",
      motd: "\u00a7c\u00a7lUnder maintenance\u00a7r \u00a77back soon, sorry!",
    },
    {
      name: "Event",
      motd: "\u00a7d\u25c6 Winter Event live!\u00a7b \u00a7oends Sunday\u00a7r \u00a78\u2502 \u00a7a/warp event",
    },
  ];

  var PALETTE = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f"];

  /* ── studio overlay ────────────────────────────────────────────── */
  function openStudio() {
    if (mask) return;
    var cur = (state && state.motd) || "";
    var limit = (state && state.limit) || 59;

    mask = U.el("div", "pr-motd-mask pr-fade-in", "");
    var card = U.el(
      "div",
      "pr-motd-card pr-scale-in",
      '<div class="pr-motd-head">' +
        '<span class="pr-motd-title"><span class="pr-motd-dot"></span> MOTD Creator</span>' +
        '<span class="pr-motd-chip" data-state="offline">offline</span>' +
        '<button class="pr-motd-close" type="button" aria-label="Close">&times;</button>' +
      "</div>" +
      '<div class="pr-motd-body">' +
        '<div class="pr-motd-preview" aria-label="Live preview">' +
          '<span class="pr-motd-preview-label">server list preview</span>' +
          '<div class="pr-motd-preview-line">' + renderMotd(cur) + "</div>" +
        "</div>" +
        '<textarea class="pr-motd-input" rows="3" spellcheck="false" placeholder="Type your MOTD — use \u00a7 codes for colors"></textarea>' +
        '<div class="pr-motd-row">' +
          '<div class="pr-motd-counter">0 / ' + limit + "</div>" +
          '<div class="pr-motd-palette" role="group" aria-label="Color codes">' +
            PALETTE.map(function (c) {
              return '<button type="button" class="pr-motd-sw pr-motd-sw--' + c +
                '" data-code="\u00a7' + c + '" title="\u00a7' + c + '"><span></span></button>';
            }).join("") +
          "</div>" +
        "</div>" +
        '<div class="pr-motd-tpls">' +
          TEMPLATES.map(function (t, idx) {
            return '<button type="button" class="pr-motd-tpl" data-idx="' + idx + '">' + U.esc(t.name) + "</button>";
          }).join("") +
        "</div>" +
        '<div class="pr-motd-tools">' +
          '<button type="button" class="pr-motd-fmt" data-code="\u00a7l" title="Bold">\u00a7l</button>' +
          '<button type="button" class="pr-motd-fmt" data-code="\u00a7o" title="Italic">\u00a7o</button>' +
          '<button type="button" class="pr-motd-fmt" data-code="\u00a7n" title="Underline">\u00a7n</button>' +
          '<button type="button" class="pr-motd-fmt" data-code="\u00a7m" title="Strikethrough">\u00a7m</button>' +
          '<button type="button" class="pr-motd-fmt" data-code="\u00a7k" title="Obfuscated">\u00a7k</button>' +
          '<button type="button" class="pr-motd-fmt" data-code="\u00a7r" title="Reset">\u00a7r</button>' +
        "</div>" +
      "</div>" +
      '<div class="pr-motd-foot">' +
        '<span class="pr-motd-note"></span>' +
        '<span class="pr-motd-error"></span>' +
        '<div class="pr-motd-actions">' +
          '<button class="pr-motd-cancel" type="button">Cancel</button>' +
          '<button class="pr-motd-save" type="button">Save</button>' +
          '<button class="pr-motd-restart" type="button">Save &amp; restart</button>' +
        "</div>" +
      "</div>"
    );
    mask.appendChild(card);
    document.body.appendChild(mask);

    var input = card.querySelector(".pr-motd-input");
    var counterEl = card.querySelector(".pr-motd-counter");
    var previewEl = card.querySelector(".pr-motd-preview-line");
    var noteEl = card.querySelector(".pr-motd-note");
    var errEl = card.querySelector(".pr-motd-error");
    var chipEl = card.querySelector(".pr-motd-chip");
    var saveBtn = card.querySelector(".pr-motd-save");
    var restartBtn = card.querySelector(".pr-motd-restart");

    input.value = cur;
    var running = !!(state && state.running);
    chipEl.textContent = running ? "running" : "offline";
    chipEl.setAttribute("data-state", running ? "running" : "offline");
    noteEl.textContent = running ? "" : "Server is offline — MOTD applies on next start.";
    restartBtn.style.display = running && state && state.canRestart ? "" : "none";
    if (state && state.canWrite === false) {
      saveBtn.style.display = "none";
      restartBtn.style.display = "none";
      input.readOnly = true;
      noteEl.textContent = "Read-only — file.update permission required to save.";
    }

    function refresh() {
      var val = input.value;
      previewEl.innerHTML = renderMotd(val);
      var n = visibleLen(val);
      counterEl.textContent = n + " / " + limit;
      if (n > limit) counterEl.classList.add("pr-motd-over");
      else counterEl.classList.remove("pr-motd-over");
    }

    function insertCode(code) {
      var s = input.selectionStart, e = input.selectionEnd;
      input.value = input.value.slice(0, s) + code + input.value.slice(e);
      var pos = s + code.length;
      input.setSelectionRange(pos, pos);
      input.focus();
      refresh();
    }

    input.addEventListener("input", refresh);
    card.addEventListener("click", function (e) {
      var sw = e.target.closest("[data-code]");
      if (sw) { insertCode(sw.dataset.code); return; }
      var tpl = e.target.closest(".pr-motd-tpl");
      if (tpl) {
        input.value = TEMPLATES[Number(tpl.dataset.idx)].motd;
        refresh();
        input.focus();
        return;
      }
    });

    card.querySelector(".pr-motd-close").addEventListener("click", close);
    card.querySelector(".pr-motd-cancel").addEventListener("click", close);
    mask.addEventListener("click", function (e) { if (e.target === mask) close(); });
    document.addEventListener("keydown", escHandler);

    saveBtn.addEventListener("click", function () { save(false); });
    restartBtn.addEventListener("click", function () { save(true); });

    refresh();
  }

  function escHandler(e) { if (e.key === "Escape") close(); }

  function close() {
    if (!mask) return;
    mask.remove();
    mask = null;
    document.removeEventListener("keydown", escHandler);
  }

  /* ── save ──────────────────────────────────────────────────────── */
  var VALID_RE = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/;

  function save(restart) {
    if (!mask) return;
    var input = mask.querySelector(".pr-motd-input");
    var errEl = mask.querySelector(".pr-motd-error");
    var val = input.value;
    var limit = (state && state.limit) || 59;

    errEl.textContent = "";
    if (!val) { errEl.textContent = "MOTD cannot be empty."; return; }
    if (visibleLen(val) > limit) { errEl.textContent = "MOTD is too long (max " + limit + " rendered characters)."; return; }
    if (VALID_RE.test(val)) { errEl.textContent = "MOTD may not contain control characters or backslashes."; return; }
    if (val.indexOf("\\") >= 0) { errEl.textContent = "MOTD may not contain control characters or backslashes."; return; }

    var saveBtn = mask.querySelector(".pr-motd-save");
    var restartBtn = mask.querySelector(".pr-motd-restart");
    var cancelBtn = mask.querySelector(".pr-motd-cancel");
    saveBtn.disabled = restartBtn.disabled = true;
    saveBtn.classList.add("pr-motd-busy");
    restartBtn.classList.add("pr-motd-busy");
    cancelBtn.disabled = true;

    P.api("motd", { method: "POST", json: { server: serverId(), motd: val } })
      .then(function (res) {
        P.toast("MOTD saved", "The server MOTD was updated.", "success");
        state.motd = val;
        close();
        if (restart && state.running && state.canRestart) {
          P.api("proxy/power", { method: "POST", json: { server: serverId(), signal: "restart" } })
            .then(function () { P.toast("Restart sent", "Server restart requested.", "info"); })
            .catch(function () { P.toast("Restart failed", "Could not send the restart signal.", "error"); });
        }
        return res;
      })
      .catch(function (err) {
        errEl.textContent = (err && err.message) || "Save failed.";
        saveBtn.disabled = restartBtn.disabled = false;
        saveBtn.classList.remove("pr-motd-busy");
        restartBtn.classList.remove("pr-motd-busy");
        cancelBtn.disabled = false;
      });
  }

  /* ── state fetch + lifecycle ───────────────────────────────────── */
  var fetchSeq = 0;

  function syncState() {
    if (!isServerPage()) return;
    var id = serverId();
    var seq = ++fetchSeq;
    P.api("motd?server=" + encodeURIComponent(id))
      .then(function (payload) {
        if (seq !== fetchSeq) return;
        state = payload;
        ensureTab();
      })
      .catch(function () {
        if (seq !== fetchSeq) return;
        state = null;
        updateTab(); /* hide if previously shown */
      });
  }

  P.ready.then(syncState);
  P.on("page:view", function () {
    close();
    state = null;
    /* tab belongs to the previous server until the next state lands */
    var stale = U.q(".pr-motd-tab");
    if (stale) stale.remove();
    updateTab();
    setTimeout(syncState, 120);
  });

  /* late-mount safety: if the subnav appears after our fetch resolves */
  var tabTries = 0;
  function tabRetry() {
    if (mask || !isServerPage()) return;
    if (U.q(".pr-motd-tab")) return;
    if (state && state.enabled) { ensureTab(); return; }
    tabTries += 1;
    if (tabTries < 30) setTimeout(tabRetry, 400);
  }
  P.ready.then(tabRetry);
})();
