/*
 * Primus · builder-tab.js
 * Server-level AI Server Builder: describe -> plan -> review -> execute.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P || !P.util) return;
  var U = P.util;
  var mask = null;
  var state = null;
  var plan = null;
  var buildId = null;
  var mode = "build";
  var busy = false;
  var view = "home";

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
  function enabled() {
    return P.set("ai.builder_enabled", true) !== false;
  }

  function ensureTab() {
    var holder = subnavHolder();
    if (!holder || !isServerPage() || !enabled()) return;
    if (U.q(".pr-sbuild-tab")) return;
    var tab = U.el(
      "a",
      "pr-sbuild-tab",
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
        '<path d="M12 3l2.2 6.6L21 12l-6.8 2.4L12 21l-2.2-6.6L3 12l6.8-2.4L12 3z"/></svg>' +
        "<span>AI Builder</span>"
    );
    tab.href = "#";
    tab.setAttribute("role", "button");
    tab.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      open();
    });
    var addons = U.q(".pr-addons-tab");
    var motd = U.q(".pr-motd-tab");
    var serverLinks = U.qa("a[href*='/server/']", holder);
    var anchor = addons || motd || (serverLinks.length ? serverLinks[serverLinks.length - 1] : null);
    if (!anchor) return;
    if (anchor.nextSibling) holder.insertBefore(tab, anchor.nextSibling);
    else holder.appendChild(tab);
  }

  function removeTab() {
    var t = U.q(".pr-sbuild-tab");
    if (t) t.remove();
  }

  function close() {
    if (!mask) return;
    document.removeEventListener("keydown", escHandler);
    mask.remove();
    mask = null;
    plan = null;
    buildId = null;
    view = "home";
    busy = false;
  }
  function escHandler(e) {
    if (e.key === "Escape" && !busy) close();
  }

  function open() {
    if (mask) return;
    mask = U.el("div", "pr-sbuild-mask pr-fade-in", "");
    document.body.appendChild(mask);
    document.addEventListener("keydown", escHandler);
    view = "home";
    render();
    loadState();
  }

  function loadState() {
    P.api("server-builder/state?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        state = payload;
        if (mask) render();
      })
      .catch(function (err) {
        state = { error: err.message || "Failed to load builder." };
        if (mask) render();
      });
  }

  function render() {
    if (!mask) return;
    mask.innerHTML = "";
    var card = U.el("div", "pr-sbuild-card pr-scale-in", "");
    mask.appendChild(card);
    card.appendChild(head());
    var body = U.el("div", "pr-sbuild-body", "");
    card.appendChild(body);
    if (!state) {
      body.innerHTML = '<div class="pr-sbuild-empty">Loading server state...</div>';
      return;
    }
    if (state.error) {
      body.innerHTML = '<div class="pr-sbuild-empty">' + U.esc(state.error) + "</div>";
      return;
    }
    if (view === "plan") renderPlan(body);
    else if (view === "progress") renderProgress(body);
    else if (view === "ask") renderAsk(body);
    else renderHome(body);
  }

  function head() {
    var h = U.el("div", "pr-sbuild-head", "");
    h.appendChild(U.el("div", "pr-sbuild-title", '<span class="pr-sbuild-dot"></span>AI Server Builder'));
    var closeBtn = U.el("button", "pr-sbuild-close", "x");
    closeBtn.type = "button";
    closeBtn.addEventListener("click", close);
    h.appendChild(closeBtn);
    return h;
  }

  function renderHome(body) {
    var meta = U.el(
      "div",
      "pr-sbuild-meta",
      "<span>" + U.esc(state.software || "Unknown") + "</span>" +
        "<span>" + (state.java ? "Java" : "Non-Java") + "</span>" +
        "<span>" + (state.running ? "Running" : "Stopped") + "</span>" +
        "<span>" + (state.jars ? state.jars.length : 0) + " plugins</span>"
    );
    body.appendChild(meta);

    var modes = U.el("div", "pr-sbuild-modes", "");
    modes.appendChild(modeBtn("ask", "Ask"));
    modes.appendChild(modeBtn("build", "Build"));
    body.appendChild(modes);

    var ta = document.createElement("textarea");
    ta.className = "pr-sbuild-prompt";
    ta.placeholder = "What kind of server do you want?";
    ta.maxLength = 2000;
    ta.rows = 5;
    ta.id = "pr-sbuild-prompt";
    body.appendChild(ta);

    var chips = U.el("div", "pr-sbuild-chips", "");
    var templates = state.templates || {};
    Object.keys(templates).forEach(function (slug) {
      var t = templates[slug];
      var chip = U.el("button", "pr-sbuild-chip", U.esc(t.label || slug));
      chip.type = "button";
      chip.addEventListener("click", function () {
        ta.value = t.prompt || "";
        ta.focus();
      });
      chips.appendChild(chip);
    });
    body.appendChild(chips);

    var go = U.el("button", "pr-sbuild-go", mode === "ask" ? "Ask about this server" : "Build my server");
    go.type = "button";
    go.addEventListener("click", function () { submitPlan(ta.value); });
    body.appendChild(go);

    if (state.history && state.history.length) {
      body.appendChild(U.el("div", "pr-sbuild-hlabel", "Build history"));
      var list = U.el("div", "pr-sbuild-hist", "");
      state.history.slice(0, 8).forEach(function (b) {
        list.appendChild(U.el(
          "div",
          "pr-sbuild-hrow",
          "<strong>" + U.esc(b.status) + "</strong> " + U.esc(b.summary || b.prompt || "")
        ));
      });
      body.appendChild(list);
    }
  }

  function modeBtn(id, label) {
    var b = U.el("button", "pr-sbuild-mode" + (mode === id ? " is-on" : ""), label);
    b.type = "button";
    b.addEventListener("click", function () {
      mode = id;
      if (mask) render();
    });
    return b;
  }

  function submitPlan(prompt) {
    if (busy) return;
    prompt = (prompt || "").trim();
    if (prompt.length < 8) {
      P.toast("Describe more", "Need at least 8 characters.", "warn");
      return;
    }
    busy = true;
    P.api("server-builder/plan", {
      method: "POST",
      json: { server: serverId(), prompt: prompt, mode: mode },
    })
      .then(function (payload) {
        busy = false;
        if (payload.mode === "ask") {
          plan = { answer: payload.answer };
          view = "ask";
        } else {
          plan = payload.plan;
          buildId = payload.build_id;
          view = "plan";
        }
        render();
      })
      .catch(function (err) {
        busy = false;
        P.toast("Plan failed", err.message || "Could not generate a plan.", "error");
      });
  }

  function renderAsk(body) {
    body.appendChild(U.el("div", "pr-sbuild-answer", U.esc((plan && plan.answer) || "")));
    var back = U.el("button", "pr-sbuild-back", "Back");
    back.type = "button";
    back.addEventListener("click", function () { view = "home"; render(); });
    body.appendChild(back);
  }

  function renderPlan(body) {
    if (!plan) {
      body.innerHTML = '<div class="pr-sbuild-empty">No plan.</div>';
      return;
    }
    body.appendChild(U.el("div", "pr-sbuild-summary", U.esc(plan.summary || "Build plan")));
    if (plan.software && plan.software.name) {
      body.appendChild(U.el(
        "div",
        "pr-sbuild-row",
        "<strong>Software</strong> " + U.esc(plan.software.name) +
          (plan.software.reason ? " — " + U.esc(plan.software.reason) : "")
      ));
    }
    if (plan.minecraft_version) {
      body.appendChild(U.el("div", "pr-sbuild-row", "<strong>Minecraft</strong> " + U.esc(plan.minecraft_version)));
    }
    if (plan.plugins && plan.plugins.length) {
      var ul = U.el("ul", "pr-sbuild-list", "");
      plan.plugins.forEach(function (p) {
        ul.appendChild(U.el("li", "", U.esc(p.action) + " " + U.esc(p.name) + (p.reason ? " — " + U.esc(p.reason) : "")));
      });
      body.appendChild(ul);
    }
    if (plan.properties && Object.keys(plan.properties).length) {
      var props = Object.keys(plan.properties).map(function (k) {
        return k + "=" + plan.properties[k];
      }).join(", ");
      body.appendChild(U.el("div", "pr-sbuild-row", "<strong>Properties</strong> " + U.esc(props)));
    }
    if (plan.motd) body.appendChild(U.el("div", "pr-sbuild-row", "<strong>MOTD</strong> " + U.esc(plan.motd)));
    if (plan.risks && plan.risks.length) {
      body.appendChild(U.el("div", "pr-sbuild-risks", U.esc(plan.risks.join(" · "))));
    }
    var acts = U.el("div", "pr-sbuild-acts", "");
    var cancel = U.el("button", "pr-sbuild-back", "Cancel");
    cancel.type = "button";
    cancel.addEventListener("click", function () { view = "home"; plan = null; render(); });
    var go = U.el("button", "pr-sbuild-go", "Build server");
    go.type = "button";
    go.addEventListener("click", execute);
    acts.appendChild(cancel);
    acts.appendChild(go);
    body.appendChild(acts);
  }

  function execute() {
    if (busy || !buildId) return;
    busy = true;
    view = "progress";
    render();
    P.api("server-builder/execute", {
      method: "POST",
      json: { server: serverId(), build: buildId },
    })
      .then(function (payload) {
        busy = false;
        plan = payload;
        view = "progress";
        render();
        loadState();
      })
      .catch(function (err) {
        busy = false;
        P.toast("Build failed", err.message || "Could not execute the plan.", "error");
        view = "plan";
        render();
      });
  }

  function renderProgress(body) {
    var payload = plan || {};
    var steps = (payload.progress && payload.progress.steps) || payload.steps || [];
    var failed = payload.status === "failed" || (payload.progress && payload.progress.failed);
    body.appendChild(U.el("div", "pr-sbuild-summary", failed ? "Build failed" : (busy ? "Building your server..." : "Build complete")));
    var ul = U.el("ul", "pr-sbuild-steps", "");
    steps.forEach(function (s) {
      var mark = s.status === "done" ? "+" : s.status === "failed" ? "x" : s.status === "running" ? ">" : oChar(s.status);
      ul.appendChild(U.el("li", "is-" + U.esc(s.status || "pending"), mark + " " + U.esc(s.label || s.key)));
    });
    body.appendChild(ul);
    if (!busy && failed && payload.backup) {
      var rb = U.el("button", "pr-sbuild-go", "Restore backup");
      rb.type = "button";
      rb.addEventListener("click", rollback);
      body.appendChild(rb);
    }
    var back = U.el("button", "pr-sbuild-back", "Close");
    back.type = "button";
    back.addEventListener("click", close);
    body.appendChild(back);
  }

  function oChar(status) {
    return status === "skipped" ? "-" : "o";
  }

  function rollback() {
    if (busy || !buildId) return;
    busy = true;
    P.api("server-builder/rollback", {
      method: "POST",
      json: { server: serverId(), build: buildId },
    })
      .then(function () {
        busy = false;
        P.toast("Restored", "Previous server state restored.", "ok");
        close();
      })
      .catch(function (err) {
        busy = false;
        P.toast("Restore failed", err.message || "Could not restore.", "error");
      });
  }

  var tries = 0;
  function boot() {
    if (!isServerPage() || !enabled()) {
      removeTab();
      return;
    }
    ensureTab();
    if (!U.q(".pr-sbuild-tab") && tries < 30) {
      tries += 1;
      setTimeout(boot, 400);
    }
  }

  P.ready.then(function () {
    tries = 0;
    boot();
  });
  P.on("page:view", function () {
    close();
    removeTab();
    tries = 0;
    setTimeout(boot, 120);
  });
})();
