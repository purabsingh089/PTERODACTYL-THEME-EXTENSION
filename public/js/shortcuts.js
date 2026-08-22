/*
 * Primus · shortcuts.js
 * Global keyboard shortcuts + "?" shortcuts overlay.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  var SHORTCUTS = [
    { keys: ["Ctrl", "K"], label: "Open command palette" },
    { keys: ["/"], label: "Focus server search" },
    { keys: ["?"], label: "Show this overlay" },
    { keys: ["G", "C"], label: "Go to current server console" },
    { keys: ["G", "D"], label: "Go to dashboard" },
    { keys: ["G", "F"], label: "Go to current server files" },
  ];

  var sequence = [];
  var seqTimer = null;

  function currentServerId() {
    var m = location.pathname.match(/\/server\/([a-zA-Z0-9]+)/);
    return m ? m[1] : null;
  }

  function handleSequence(key) {
    sequence.push(key);
    clearTimeout(seqTimer);
    seqTimer = setTimeout(function () { sequence = []; }, 900);
    var seq = sequence.join("");
    if (seq === "GC" && currentServerId()) {
      sequence = [];
      location.href = "/server/" + currentServerId() + "/console";
      return true;
    }
    if (seq === "GD") { sequence = []; location.href = "/"; return true; }
    if (seq === "GF" && currentServerId()) {
      sequence = [];
      location.href = "/server/" + currentServerId() + "/files";
      return true;
    }
    return false;
  }

  function isTyping() {
    var el = document.activeElement;
    if (!el) return false;
    var tag = el.tagName;
    return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable;
  }

  document.addEventListener("keydown", function (e) {
    if (isTyping()) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === "/") {
      if (P.focusServerSearch) {
        e.preventDefault();
        P.focusServerSearch();
      }
      return;
    }
    if (e.key === "?") {
      e.preventDefault();
      overlay();
      return;
    }
    var k = e.key.toUpperCase();
    if (k.length === 1 && /[A-Z]/.test(k)) {
      if (handleSequence(k)) e.preventDefault();
    }
  });

  function kbd(keys) {
    return keys.map(function (k) { return "<kbd>" + U.esc(k) + "</kbd>"; }).join("");
  }

  function overlay() {
    var existing = U.q(".pr-kbd-mask");
    if (existing) { existing.remove(); return; }
    var rows = SHORTCUTS.map(function (s) {
      return '<li><span>' + U.esc(s.label) + "</span><span class='pr-kbd__keys'>" + kbd(s.keys) + "</span></li>";
    }).join("");
    var win = U.el(
      "div",
      "pr-kbd-mask pr-fade-in",
      '<div class="pr-kbd pr-scale-in">' +
        '<div class="pr-kbd__head"><span>Keyboard shortcuts</span>' +
        '<button type="button" aria-label="Close">&times;</button></div>' +
        '<ul class="pr-kbd__list">' + rows + "</ul>" +
      "</div>"
    );
    document.body.appendChild(win);
    win.addEventListener("click", function (e) {
      if (e.target === win || e.target.closest(".pr-kbd__head button")) win.remove();
    });
  }

  P.showShortcuts = overlay;

  /* include a small help hint in the footer when enabled */
  P.ready.then(function () {
    if (!P.set("shortcuts.hint", true)) return;
    var hintTimer = setInterval(function () {
      var footerLabel = U.q(".pr-footer__left");
      if (!footerLabel) return;
      clearInterval(hintTimer);
      if (!footerLabel.querySelector(".pr-footer__hint")) {
        var hint = U.el(
          "span",
          "pr-footer__hint",
          'Press <kbd>Ctrl</kbd><kbd>K</kbd> for the command palette \u00b7 <kbd>?</kbd> for shortcuts'
        );
        footerLabel.appendChild(hint);
      }
    }, 2000);
  });
})();
