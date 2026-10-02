/*
 * Primus · console-upgrade.js
 * Upgrades the stock console page (/server/{id}) in place:
 *   - command history (ArrowUp/ArrowDown, per-server localStorage)
 *   - preset bar above the command input (built-ins + 5 custom slots)
 *   - output filter (dim non-matching xterm rows, highlight matches)
 *   - player-count chip in the terminal chrome
 * Presets send through the stock input (user console permission
 * applies); stop/restart-family commands are blocked client-side.
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
  function isConsolePage() {
    return /^\/server\/[a-zA-Z0-9-]+$/.test(location.pathname);
  }

  var DENY_RE = /^(stop|restart|end|halt|shutdown|kill-server)\b/i;
  var BUILTIN_PRESETS = ["list", "save-all", "time set day", "time set night", "clear", "weather clear"];
  var MAX_CUSTOM = 5;

  function histKey() { return "primus.cmdhist." + serverId(); }
  function presetKey() { return "primus.presets." + serverId(); }

  function loadHist() {
    try { return JSON.parse(localStorage.getItem(histKey()) || "[]"); } catch (e) { return []; }
  }
  function saveHist(h) {
    try { localStorage.setItem(histKey(), JSON.stringify(h.slice(-100))); } catch (e) {}
  }
  function loadPresets() {
    try { return JSON.parse(localStorage.getItem(presetKey()) || "[]"); } catch (e) { return []; }
  }
  function savePresets(p) {
    try { localStorage.setItem(presetKey(), JSON.stringify(p.slice(0, MAX_CUSTOM))); } catch (e) {}
  }

  /* ── console input wiring ─────────────────────────────────────── */
  function findInput() {
    return U.q('input[aria-label="Console command input."]')
      || U.q('input[placeholder*="command" i]');
  }

  function sendCommand(value) {
    if (DENY_RE.test(value.trim())) {
      P.toast("Command blocked", "Power commands are not available from presets.", "warning");
      return;
    }
    var input = findInput();
    if (!input) return;
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value").set;
    setter.call(input, value);
    input.dispatchEvent(new Event("input", { bubbles: true }));
    setTimeout(function () {
      input.dispatchEvent(new KeyboardEvent("keydown", {
        key: "Enter", code: "Enter", which: 13, keyCode: 13, bubbles: true,
      }));
      if (input.value !== "") {
        /* React did not consume it — clear our value */
        setter.call(input, "");
        input.dispatchEvent(new Event("input", { bubbles: true }));
      }
    }, 30);
  }

  function wireHistory(input) {
    if (input.dataset.prHist) return;
    input.dataset.prHist = "1";
    var idx = -1;
    input.addEventListener("keydown", function (e) {
      if (e.key !== "ArrowUp" && e.key !== "ArrowDown") return;
      var hist = loadHist();
      if (!hist.length) return;
      /* only navigate when the field is at the edges, like a shell */
      var pos = input.selectionStart || 0;
      if (e.key === "ArrowUp" && pos !== 0) return;
      if (e.key === "ArrowDown" && pos !== input.value.length) return;
      e.preventDefault();
      e.stopPropagation();
      if (e.key === "ArrowUp") {
        idx = Math.min(idx + 1, hist.length - 1);
      } else {
        idx = idx - 1;
        if (idx < -1) idx = -1;
        if (idx === -1) { input.value = ""; return; }
      }
      input.value = hist[hist.length - 1 - idx] || "";
    }, true);
    input.addEventListener("keydown", function (e) {
      if (e.key !== "Enter") return;
      var v = input.value.trim();
      if (!v) return;
      var hist = loadHist();
      if (hist[hist.length - 1] !== v) {
        hist.push(v);
        saveHist(hist);
      }
      idx = -1;
    });
  }

  /* ── preset bar ──────────────────────────────────────────────── */
  function ensurePresetBar() {
    var input = findInput();
    if (!input) return;
    if (U.q(".pr-preset-bar")) return;
    var holder = input.parentElement;
    if (!holder) return;
    var bar = U.el("div", "pr-preset-bar", "");
    renderBar(bar);
    holder.insertBefore(bar, holder.firstChild);

    function renderBar(bar) {
      var customs = loadPresets();
      bar.innerHTML = "";
      BUILTIN_PRESETS.forEach(function (cmd) {
        bar.appendChild(chip(cmd, false));
      });
      customs.forEach(function (cmd) {
        bar.appendChild(chip(cmd, true));
      });
      if (customs.length < MAX_CUSTOM) {
        var add = U.el("button", "pr-preset-chip pr-preset-chip--add", "+");
        add.type = "button";
        add.title = "Add a custom preset";
        add.addEventListener("click", function () {
          var cmd = window.prompt("Custom preset command:");
          if (!cmd) return;
          cmd = cmd.trim().slice(0, 60);
          if (!cmd || DENY_RE.test(cmd)) {
            P.toast("Preset rejected", "Power commands cannot be presets.", "warning");
            return;
          }
          savePresets(loadPresets().concat([cmd]));
          renderBar(bar);
        });
        bar.appendChild(add);
      }
    }

    function chip(cmd, isCustom) {
      var c = U.el("button", "pr-preset-chip" + (isCustom ? " pr-preset-chip--custom" : ""), U.esc(cmd));
      c.type = "button";
      c.title = isCustom ? "Custom preset (right-click to remove)" : "";
      c.addEventListener("click", function () { sendCommand(cmd); });
      if (isCustom) {
        c.addEventListener("contextmenu", function (e) {
          e.preventDefault();
          savePresets(loadPresets().filter(function (p) { return p !== cmd; }));
          renderBar(bar);
        });
      }
      return c;
    }
  }

  /* ── output filter ────────────────────────────────────────────── */
  function ensureFilter() {
    var chrome = U.q(".pr-term__chrome");
    if (!chrome || U.q(".pr-console-filter")) return;
    var filter = U.el("input", "pr-console-filter", "");
    filter.type = "search";
    filter.placeholder = "Filter output";
    filter.setAttribute("aria-label", "Filter console output");
    filter.addEventListener("input", applyFilter);
    filter.addEventListener("keydown", function (e) {
      if (e.key === "Escape") { filter.value = ""; applyFilter(); }
    });
    chrome.appendChild(filter);
  }

  var filterDebounce = null;
  var rowObserver = null;
  function applyFilter() {
    clearTimeout(filterDebounce);
    filterDebounce = setTimeout(function () {
      var q = ((U.q(".pr-console-filter") || {}).value || "").trim().toLowerCase();
      var rows = U.qa(".xterm-rows > div");
      rows.forEach(function (row) {
        if (!q) {
          row.classList.remove("pr-row-dim", "pr-row-hit");
          return;
        }
        var text = (row.textContent || "").toLowerCase();
        var hit = text.indexOf(q) !== -1;
        row.classList.toggle("pr-row-hit", hit);
        row.classList.toggle("pr-row-dim", !hit);
      });
    }, 120);
  }

  function watchRows() {
    var rows = U.q(".xterm-rows");
    if (!rows || rowObserver) return;
    rowObserver = new MutationObserver(function () { applyFilter(); });
    rowObserver.observe(rows, { childList: true, subtree: false });
  }

  /* ── player chip ──────────────────────────────────────────────── */
  var playerCount = null;
  function ensurePlayerChip() {
    var chrome = U.q(".pr-term__chrome");
    if (!chrome || U.q(".pr-player-chip")) return;
    var chip = U.el("span", "pr-player-chip", "players --");
    chrome.appendChild(chip);
  }
  function updatePlayerChip() {
    var chip = U.q(".pr-player-chip");
    if (!chip) return;
    chip.textContent = "players " + (playerCount == null ? "--" : String(playerCount));
  }
  if (P.on) {
    P.on("socket:stats", function (frame) {
      var n = frame && (frame.players || frame.currentPlayersCount);
      if (typeof n === "number") {
        playerCount = n;
        updatePlayerChip();
      }
    });
  }

  /* ── boot ─────────────────────────────────────────────────────── */
  var bootObserver = null;
  function boot() {
    if (!isConsolePage()) return;
    var tries = 0;
    (function tick() {
      if (!isConsolePage()) return;
      var input = findInput();
      if (input) {
        wireHistory(input);
        ensurePresetBar();
        ensureFilter();
        watchRows();
        ensurePlayerChip();
        updatePlayerChip();
      }
      tries += 1;
      if (tries < 120) setTimeout(tick, 500);
    })();
  }

  function teardown() {
    if (bootObserver) { bootObserver.disconnect(); bootObserver = null; }
    if (rowObserver) { rowObserver.disconnect(); rowObserver = null; }
  }

  P.ready.then(boot);
  P.on("page:view", function () {
    teardown();
    setTimeout(boot, 200);
  });
})();
