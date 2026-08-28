/*
 * Primus · ai-fixer.js
 * Adds "Diagnose with AI" next to the server console. Grabs the rendered
 * terminal buffer (xterm DOM), POSTs it to /extensions/primus/ai/fix and
 * renders the structured report in a modal. Suggestions only — never applied.
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
    return /\/server\/[a-zA-Z0-9]+\/?$/.test(location.pathname);
  }

  function consoleHost() {
    var col = U.q("#app div[class*='col-span-4'][class*='lg:col-span-3'], div[class*='col-span-4'][class*='lg:col-span-3']");
    if (col) return col;
    var term = U.q("#terminal, .xterm");
    if (term) return term.closest("div");
    return null;
  }

  function grabConsoleText() {
    var rows = U.qa(".xterm-rows > div");
    if (rows.length) {
      return rows
        .map(function (r) { return r.textContent || ""; })
        .filter(function (t) { return t.trim(); })
        .slice(-160)
        .join("\n");
    }
    var fallback = U.q(".xterm, [class*='console'] pre");
    return fallback ? fallback.textContent || "" : "";
  }

  function mountFixerButton(host) {
    if (U.q(".pr-ai-fixer-btn")) return;
    var bar = U.el(
      "div",
      "pr-ai-fixer-bar pr-fade-in",
      ""
    );
    var btn = U.el(
      "button",
      "pr-ai-fixer-btn",
      '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.1 6.2L20 10l-5.9 1.8L12 18l-2.1-6.2L4 10l5.9-1.8L12 2z"/></svg>' +
        "<span>Diagnose with AI</span>"
    );
    btn.type = "button";
    btn.addEventListener("click", openFixer);
    bar.appendChild(btn);
    host.insertBefore(bar, host.firstChild);
  }

  function thinking(len) {
    var html = '<div class="pr-fix-thinking"><span class="pr-fix-thinking__label">' +
      '<span class="pr-typing-dots"><span></span><span></span><span></span></span> ' +
      (len ? U.esc(len) : "Analyzing console output") + "</span>";
    for (var i = 0; i < 5; i++) {
      html += '<div class="pr-skeleton pr-skeleton--text" style="width:' + (55 + Math.round(Math.random() * 40)) + '%"></div>';
    }
    return html + "</div>";
  }

  function openFixer() {
    if (U.q(".pr-fix-modal-mask")) return;
    var snippet = grabConsoleText();
    var mask = U.el("div", "pr-fix-modal-mask", "");
    var modal = U.el(
      "div",
      "pr-fix-modal",
      '<div class="pr-fix-modal__head">' +
        '<span class="pr-fix-modal__title"><span class="pr-ai-dot"></span> AI Fixer</span>' +
        '<button class="pr-fix-modal__close" type="button" aria-label="Close">&times;</button>' +
      "</div>" +
      '<div class="pr-fix-modal__body">' +
        (snippet.trim() ? thinking() : '<div class="pr-fix-empty">Console buffer is empty — start the server or run a command first.</div>') +
      "</div>"
    );
    mask.appendChild(modal);
    document.body.appendChild(mask);
    mask.addEventListener("click", function (e) { if (e.target === mask) mask.remove(); });
    modal.querySelector(".pr-fix-modal__close").addEventListener("click", function () { mask.remove(); });

    if (!snippet.trim()) return;

    P.api("ai/fix", {
      method: "POST",
      json: { server: serverId(), snippet: snippet.slice(-48000) },
    })
      .then(function (res) { renderReport(modal, res); })
      .catch(function (err) {
        renderError(modal, err);
      });
  }

  function escMd(text) {
    return U.esc(text || "")
      .replace(/`([^`]+)`/g, "<code>$1</code>")
      .replace(/\n/g, "<br>");
  }

  function renderError(modal, err) {
    var detail = err && err.status === 429
      ? "<small>AI rate limit reached. Please try again in a minute.</small>"
      : "";
    modal.querySelector(".pr-fix-modal__body").innerHTML =
      '<div class="pr-fix-error">' + U.esc(err && err.message ? err.message : "The AI request failed.") + detail + "</div>";
  }

  function renderReport(modal, res) {
    var body = modal.querySelector(".pr-fix-modal__body");
    if (!res || typeof res !== "object") {
      body.innerHTML = '<div class="pr-fix-error">Unexpected response from the AI service.</div>';
      return;
    }
    var conf = res.confidence || res.confidence_label || "";
    var tone =
      /high/i.test(conf) ? "success"
      : /low/i.test(conf) ? "danger"
      : conf ? "warning" : "";

    var html = '<div class="pr-fix-report">';
    if (conf) {
      html += '<span class="pr-fix-report__conf" data-tone="' + tone + '">' + U.esc(conf) + " confidence</span>";
    }

    var cause = res.cause || res.summary || res.diagnosis || "";
    if (cause) {
      html += '<div class="pr-fix-section"><h4>Likely cause</h4><p>' + escMd(cause) + "</p></div>";
    }

    var fix = res.suggestion || res.fix || res.steps || res.fix_text || "";
    if (fix) {
      html += '<div class="pr-fix-section"><h4>Suggested fix</h4><p>' + escMd(fix) + "</p></div>";
    }

    if (res.command) {
      html += codeBlock("Suggested command", res.command);
    }
    if (res.config_change) {
      html += codeBlock("Config change", res.config_change);
    }
    html += "</div>";
    body.innerHTML = html;

    U.qa(".pr-codeblock__copy", body).forEach(function (btn) {
      btn.addEventListener("click", function () {
        navigator.clipboard.writeText(btn.dataset.copy || "").then(function () {
          btn.textContent = "Copied";
          setTimeout(function () { btn.textContent = "Copy"; }, 1200);
        });
      });
    });

    P.notify("AI diagnosis ready", (cause || "A report was generated.").slice(0, 110), "success");
  }

  function codeBlock(label, code) {
    return '<div class="pr-codeblock">' +
      '<div class="pr-codeblock__head"><span>' + U.esc(label) + '</span>' +
      '<button class="pr-codeblock__copy" type="button" data-copy="' + U.esc(code).replace(/"/g, "&quot;") + '">Copy</button></div>' +
      "<pre>" + U.esc(code) + "</pre></div>";
  }

  /* mount when the console page renders (terminal may be disconnected) */
  P.register({
    each: "div[class*='col-span-4'][class*='lg:col-span-3'], .xterm, #terminal",
    observe: function () {
      if (!isConsolePage()) return;
      var host = consoleHost();
      if (host) mountFixerButton(host);
    },
  });

  /* deep-link from quick actions: /server/{id}?primus=diagnose */
  P.ready.then(function () {
    if (!P.requestDiagnose || !isConsolePage()) return;
    var tries = 0;
    var timer = setInterval(function () {
      tries++;
      var host = consoleHost();
      if (host) {
        clearInterval(timer);
        mountFixerButton(host);
        setTimeout(openFixer, 800);
      } else if (tries > 20) {
        clearInterval(timer);
      }
    }, 500);
  });
})();
