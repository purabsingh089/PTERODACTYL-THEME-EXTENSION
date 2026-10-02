/*
 * Primus · builder.js
 * Dashboard overlay: per-user AI credentials + prompt-to-server preview/create.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P || !P.util) return;
  var U = P.util;
  var mask = null;
  var status = null;
  var preview = null;
  var busy = false;

  function isDashboard() {
    if (/\/server\/[a-zA-Z0-9]+/.test(location.pathname)) return false;
    if (/^\/auth\//.test(location.pathname)) return false;
    if (/^\/admin\//.test(location.pathname)) return false;
    return true;
  }

  function enabled() {
    return P.set("ai.builder_enabled", true) !== false;
  }

  function fab() {
    return U.q(".pr-builder-fab");
  }

  function ensureFab() {
    if (!isDashboard() || !enabled()) {
      var existing = fab();
      if (existing) existing.remove();
      return;
    }
    if (fab()) return;
    var btn = U.el(
      "button",
      "pr-builder-fab",
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
        '<path d="M12 3l2.2 6.6L21 12l-6.8 2.4L12 21l-2.2-6.6L3 12l6.8-2.4L12 3z"/>' +
        "</svg><span>AI Server Builder</span>"
    );
    btn.type = "button";
    btn.setAttribute("aria-label", "Open AI Server Builder");
    btn.addEventListener("click", open);
    document.body.appendChild(btn);
  }

  function close() {
    if (!mask) return;
    document.removeEventListener("keydown", onEsc);
    mask.remove();
    mask = null;
    preview = null;
  }

  function onEsc(e) {
    if (e.key === "Escape") close();
  }

  function open() {
    if (mask) return;
    mask = U.el("div", "pr-builder-mask pr-fade-in", "");
    mask.addEventListener("click", function (e) { if (e.target === mask) close(); });
    document.body.appendChild(mask);
    document.addEventListener("keydown", onEsc);
    renderShell("Loading builder…");
    P.api("builder")
      .then(function (res) {
        status = res;
        renderForm();
      })
      .catch(function (err) {
        renderShell(U.esc(err.message || "Could not load the builder."));
      });
  }

  function renderShell(bodyHtml) {
    if (!mask) return;
    mask.innerHTML =
      '<div class="pr-builder-card pr-scale-in" role="dialog" aria-modal="true" aria-labelledby="pr-builder-title">' +
        '<div class="pr-builder-head">' +
          '<span class="pr-builder-title" id="pr-builder-title"><span class="pr-builder-dot"></span> AI Server Builder</span>' +
          '<button class="pr-builder-close" type="button" aria-label="Close">&times;</button>' +
        "</div>" +
        '<div class="pr-builder-body">' + bodyHtml + "</div>" +
      "</div>";
    var closer = mask.querySelector(".pr-builder-close");
    if (closer) closer.addEventListener("click", close);
  }

  function attr(s) {
    return U.esc(s || "").replace(/"/g, "&quot;");
  }

  function field(id, label, type, value, extra) {
    extra = extra || "";
    return (
      '<div class="pr-field">' +
        '<label for="' + id + '">' + U.esc(label) + "</label>" +
        '<input class="pr-input" id="' + id + '" type="' + type + '" value="' + attr(value) + '" ' + extra + ">" +
      "</div>"
    );
  }

  function renderForm() {
    if (!mask) return;
    var configured = !!(status && status.configured);
    var html =
      '<p class="pr-builder-lede">Describe a game server. Primus plans it against this panel\'s eggs, then creates it with you as the owner.</p>' +
      '<div id="pr-builder-alert" hidden class="pr-builder-error" role="alert"></div>' +
      field("pr-builder-url", "AI base URL", "url", (status && status.base_url) || "", 'autocomplete="off" spellcheck="false"') +
      field("pr-builder-key", "API key", "password", "", configured ? 'placeholder="•••••••• (saved)" autocomplete="off"' : 'placeholder="sk-..." autocomplete="off"') +
      '<div class="pr-builder-row">' +
        field("pr-builder-model", "Model", "text", (status && status.model) || "", 'list="pr-builder-models" autocomplete="off" spellcheck="false" placeholder="Detect or type a model id"') +
        '<div class="pr-field" style="justify-content:flex-end">' +
          '<label>&nbsp;</label>' +
          '<button class="pr-btn" type="button" id="pr-builder-detect">Detect models</button>' +
        "</div>" +
      "</div>" +
      '<datalist id="pr-builder-models"></datalist>' +
      '<div class="pr-actions pr-builder-actions">' +
        '<button class="pr-btn" type="button" id="pr-builder-save">Save credentials</button>' +
      "</div>" +
      '<div class="pr-field">' +
        '<label for="pr-builder-prompt">What should we create?</label>' +
        '<textarea class="pr-textarea" id="pr-builder-prompt" maxlength="2000" placeholder="Small Paper Minecraft SMP, 2 GB RAM, start after install"></textarea>' +
      "</div>" +
      '<div class="pr-builder-actions">' +
        '<button class="pr-btn pr-btn--primary" type="button" id="pr-builder-preview">Plan server</button>' +
      "</div>" +
      '<div id="pr-builder-preview-host"></div>';
    renderShell(html);
    mask.querySelector("#pr-builder-save").addEventListener("click", saveCreds);
    mask.querySelector("#pr-builder-detect").addEventListener("click", detectModels);
    mask.querySelector("#pr-builder-preview").addEventListener("click", runPreview);
  }

  function alertBox(msg) {
    var el = mask && mask.querySelector("#pr-builder-alert");
    if (!el) return;
    if (!msg) { el.hidden = true; el.textContent = ""; return; }
    el.hidden = false;
    el.textContent = msg;
  }

  function saveCreds() {
    if (busy) return;
    var base = (mask.querySelector("#pr-builder-url").value || "").trim();
    var key = (mask.querySelector("#pr-builder-key").value || "").trim();
    var model = (mask.querySelector("#pr-builder-model").value || "").trim();
    busy = true;
    P.api("builder/credentials", { method: "POST", json: { base_url: base, api_key: key, model: model } })
      .then(function (res) {
        status = Object.assign(status || {}, res);
        mask.querySelector("#pr-builder-key").value = "";
        mask.querySelector("#pr-builder-key").placeholder = "•••••••• (saved)";
        P.toast("Credentials saved", "Your key stays on the panel and is never shown again.", "success");
        alertBox("");
      })
      .catch(function (err) { alertBox(err.message || "Could not save credentials."); })
      .then(function () { busy = false; });
  }

  function detectModels() {
    if (busy) return;
    var base = (mask.querySelector("#pr-builder-url").value || "").trim();
    var key = (mask.querySelector("#pr-builder-key").value || "").trim();
    busy = true;
    P.api("builder/models", { method: "POST", json: { base_url: base, api_key: key } })
      .then(function (res) {
        var list = mask.querySelector("#pr-builder-models");
        list.innerHTML = "";
        (res.models || []).forEach(function (id) {
          var opt = document.createElement("option");
          opt.value = id;
          list.appendChild(opt);
        });
        if (res.error) alertBox(res.error);
        else if (res.warning) alertBox(res.warning);
        else alertBox("");
        if ((res.models || []).length && !(mask.querySelector("#pr-builder-model").value || "").trim()) {
          mask.querySelector("#pr-builder-model").value = res.models[0];
        }
        P.toast("Models", (res.models || []).length ? (res.models.length + " models found") : "No catalog returned", "info");
      })
      .catch(function (err) { alertBox(err.message || "Could not detect models."); })
      .then(function () { busy = false; });
  }

  function runPreview() {
    if (busy) return;
    var prompt = (mask.querySelector("#pr-builder-prompt").value || "").trim();
    busy = true;
    var btn = mask.querySelector("#pr-builder-preview");
    btn.disabled = true;
    P.api("builder/preview", { method: "POST", json: { prompt: prompt } })
      .then(function (res) {
        preview = res;
        alertBox("");
        renderPreview();
      })
      .catch(function (err) { alertBox(err.message || "Could not plan a server."); })
      .then(function () { busy = false; btn.disabled = false; });
  }

  function renderPreview() {
    var host = mask.querySelector("#pr-builder-preview-host");
    if (!host || !preview) return;
    var spec = preview.spec || {};
    var egg = preview.egg || {};
    host.innerHTML =
      '<dl class="pr-builder-spec">' +
        "<div><dt>Name</dt><dd>" + U.esc(spec.name || "") + "</dd></div>" +
        "<div><dt>Egg</dt><dd>" + U.esc(egg.name || "") + (egg.nest ? " / " + U.esc(egg.nest) : "") + "</dd></div>" +
        "<div><dt>Memory</dt><dd>" + U.esc(spec.memory) + " MB</dd></div>" +
        "<div><dt>Disk</dt><dd>" + U.esc(spec.disk) + " MB</dd></div>" +
        "<div><dt>CPU</dt><dd>" + U.esc(spec.cpu) + "%</dd></div>" +
        "<div><dt>Model</dt><dd>" + U.esc(preview.model || "") + "</dd></div>" +
        (spec.summary ? '<p class="pr-builder-summary">' + U.esc(spec.summary) + "</p>" : "") +
      "</dl>" +
      '<div class="pr-builder-actions" style="margin-top:12px">' +
        '<button class="pr-btn pr-btn--primary" type="button" id="pr-builder-create">Create server</button>' +
      "</div>";
    host.querySelector("#pr-builder-create").addEventListener("click", runCreate);
  }

  function runCreate() {
    if (busy || !preview) return;
    var spec = preview.spec || {};
    var egg = preview.egg || {};
    busy = true;
    var btn = mask.querySelector("#pr-builder-create");
    if (btn) { btn.disabled = true; btn.textContent = "Creating…"; }
    P.api("builder/create", {
      method: "POST",
      json: {
        egg_id: egg.id,
        name: spec.name,
        description: spec.description || "",
        memory: spec.memory,
        disk: spec.disk,
        cpu: spec.cpu,
        swap: spec.swap,
        start_on_completion: !!spec.start_on_completion,
      },
    })
      .then(function (res) {
        var url = res.server && res.server.url ? res.server.url : "/";
        P.toast("Server created", res.server && res.server.name ? res.server.name : "Opening console", "success");
        close();
        location.href = url;
      })
      .catch(function (err) {
        alertBox(err.message || "Could not create the server.");
        if (btn) { btn.disabled = false; btn.textContent = "Create server"; }
      })
      .then(function () { busy = false; });
  }

  function boot() {
    ensureFab();
  }

  P.ready.then(boot);
  P.on("page:view", boot);
})();
