{{--
  Primus · Admin customizer view (self-contained).
  Rendered by primusExtensionController@index with $settings, $presets,
  $active_preset, $models, $version injected as variables.
  Spec partials under admin/partials/*.blade.php are kept for reference /
  build-time inlining (Blueprint installs only this single view file).
--}}
<style id="primus-admin-css">
@import url("/extensions/primus/css/admin-customizer.css");
</style>

<div class="primus-admin"
     data-version="{{ $version }}"
     data-active-preset="{{ $active_preset }}"
     data-settings='@json($settings, JSON_HEX_APOS | JSON_HEX_QUOT)'>

  <div class="prx-alert prx-alert-{{ $settings['ai']['api_key_configured'] ? 'ok' : 'warn' }}" id="prx-api-status">
    @if($settings['ai']['api_key_configured'])
      AI provider configured — Fixer/Optimizer are live.
    @else
      Configure your <strong>monkeycode-ai.net</strong> API key in the <strong>AI</strong> tab to enable AI features.
    @endif
  </div>

  <div class="prx-tabbar" role="tablist">
    <button class="prx-tab is-active" data-prx-tab="presets" type="button">Presets</button>
    <button class="prx-tab" data-prx-tab="appearance" type="button">Appearance</button>
    <button class="prx-tab" data-prx-tab="branding" type="button">Branding</button>
    <button class="prx-tab" data-prx-tab="footer" type="button">Footer &amp; announcements</button>
    <button class="prx-tab" data-prx-tab="ai" type="button">AI</button>
    <button class="prx-tab" data-prx-tab="usage" type="button">AI usage</button>
  </div>

  {{-- ═══════════ PANEL: presets ═══════════ --}}
  <section class="prx-panel" data-prx-panel="presets">
    <div class="prx-preset-grid">
      @foreach($presets as $presetId => $preset)
        <button class="prx-presetcard {{ $presetId === $active_preset ? 'is-active' : '' }}"
                data-prx-preset="{{ $presetId }}" type="button">
          <span class="prx-presetcard__swatch"
                style="background: linear-gradient(135deg, {{ $preset['swatch']['a'] ?? '#6d5df6' }}, {{ $preset['swatch']['b'] ?? '#8577ff' }})"></span>
          <span class="prx-presetcard__meta">
            <span class="prx-presetcard__name">{{ $preset['name'] ?? $presetId }}</span>
            <span class="prx-presetcard__sub">{{ ($preset['built_in'] ?? true) ? 'Built-in' : 'Custom' }}</span>
          </span>
          @if($presetId === $active_preset)<span class="prx-presetcard__active">● Active</span>@endif
        </button>
      @endforeach
    </div>
    <div class="prx-row">
      <button class="prx-btn prx-btn-ghost" id="prx-preset-reset" type="button">Reset to defaults</button>
      <div class="prx-row-right">
        <a class="prx-btn prx-btn-ghost" href="{{ url('/extensions/primus/admin/presets/export') }}">Export JSON</a>
        <label class="prx-btn prx-btn-ghost prx-file-label">
          Import JSON <input type="file" id="prx-preset-import" accept=".json,application/json" hidden>
        </label>
        <button class="prx-btn" id="prx-preset-save-as" type="button">Save current as preset…</button>
      </div>
    </div>
  </section>

  {{-- ═══════════ PANEL: appearance ═══════════ --}}
  <section class="prx-panel" data-prx-panel="appearance" hidden>
    <div class="prx-columns">
      <div class="prx-col">
        <h3 class="prx-h">Colors</h3>
        <div class="prx-field">
          <label>Theme mode</label>
          <select id="prx-theme-mode">
            <option value="dark">Dark (default)</option>
            <option value="light">Light</option>
          </select>
        </div>
        <div class="prx-field">
          <label>Accent color</label>
          <div class="prx-colorrow">
            <input type="color" id="prx-accent" value="{{ $settings['overrides']['--pr-accent'] ?? '#6d5df6' }}">
            <input type="text" id="prx-accent-text" value="{{ $settings['overrides']['--pr-accent'] ?? '#6d5df6' }}" maxlength="7">
          </div>
        </div>
        <div class="prx-field">
          <label>Accent (bright / hover)</label>
          <div class="prx-colorrow">
            <input type="color" id="prx-accent-strong" value="{{ $settings['overrides']['--pr-accent-strong'] ?? '#8577ff' }}">
            <input type="text" id="prx-accent-strong-text" value="{{ $settings['overrides']['--pr-accent-strong'] ?? '#8577ff' }}" maxlength="7">
          </div>
        </div>
        <div class="prx-field">
          <label>Accent vibrance</label>
          <select id="prx-vibrance">
            <option value="subtle">Subtle</option>
            <option value="normal">Normal</option>
            <option value="vivid">Vivid</option>
          </select>
        </div>
      </div>
      <div class="prx-col">
        <h3 class="prx-h">Shape</h3>
        <div class="prx-field">
          <label>Corner radius <span class="prx-value" id="prx-radius-value"></span></label>
          <input type="range" id="prx-radius" min="0.5" max="2" step="0.05">
        </div>
        <div class="prx-field">
          <label>Shadow intensity <span class="prx-value" id="prx-shadow-value"></span></label>
          <input type="range" id="prx-shadow" min="0" max="2" step="0.05">
        </div>
        <div class="prx-field">
          <label>Quick toggles</label>
          <label class="prx-check"><input type="checkbox" id="prx-shortcuts-hint"> Shortcut hint in client footer</label>
          <label class="prx-check"><input type="checkbox" id="prx-quickactions"> Hover quick-actions on server rows</label>
        </div>
      </div>
    </div>
  </section>

  {{-- ═══════════ PANEL: branding ═══════════ --}}
  <section class="prx-panel" data-prx-panel="branding" hidden>
    <div class="prx-columns">
      <div class="prx-col">
        <h3 class="prx-h">Fonts</h3>
        <div class="prx-field">
          <label>Heading font</label>
          <input type="text" id="prx-font-heading" placeholder="Sora (default)" >
        </div>
        <div class="prx-field">
          <label>Body font</label>
          <input type="text" id="prx-font-body" placeholder="Inter (default)">
        </div>
        <div class="prx-field">
          <label>Monospace font (console)</label>
          <input type="text" id="prx-font-mono" placeholder="JetBrains Mono (default)">
        </div>
      </div>
      <div class="prx-col">
        <h3 class="prx-h">Identity</h3>
        <div class="prx-field">
          <label>Logo URL (uploaded to the extension's public img folder)</label>
          <input type="text" id="prx-logo-url" placeholder="/extensions/primus/img/logo.svg">
        </div>
        <div class="prx-field">
          <label>Favicon URL</label>
          <input type="text" id="prx-favicon-url" placeholder="">
        </div>
        <div class="prx-field">
          <label class="prx-check"><input type="checkbox" id="prx-white-label"> White-label mode (hide all Primus branding)</label>
        </div>
      </div>
    </div>
  </section>

  {{-- ═══════════ PANEL: footer & announcements ═══════════ --}}
  <section class="prx-panel" data-prx-panel="footer" hidden>
    <div class="prx-columns">
      <div class="prx-col">
        <h3 class="prx-h">Announcement banner (Markdown)</h3>
        <div class="prx-field">
          <textarea id="prx-announce-text" rows="6"
                    placeholder="**Maintenance window** tonight 02:00–04:00 UTC. [Status](https://status.example.com)"></textarea>
          <small class="prx-hint">Leave empty to hide. Supports **bold**, *italic*, `code`, [links](url).</small>
        </div>
      </div>
      <div class="prx-col">
        <h3 class="prx-h">Footer</h3>
        <div class="prx-field">
          <label class="prx-check"><input type="checkbox" id="prx-footer-enabled"> Show footer bar</label>
          <label>Footer label</label>
          <input type="text" id="prx-footer-label" placeholder="© 2026 Your hosting network">
        </div>
        <div class="prx-field">
          <label>Social links (JSON array — icons: discord, web, support, github)</label>
          <textarea id="prx-footer-links" rows="7" spellcheck="false"></textarea>
          <small class="prx-hint">Example: [{"label":"Discord","icon":"discord","url":"https://discord.gg/…"}]</small>
        </div>
      </div>
    </div>
  </section>

  {{-- ═══════════ PANEL: ai ═══════════ --}}
  <section class="prx-panel" data-prx-panel="ai" hidden>
    <div class="prx-columns">
      <div class="prx-col">
        <h3 class="prx-h">Provider</h3>
        <div class="prx-field">
          <label>monkeycode-ai.net API key (stored server-side only)</label>
          <input type="password" id="prx-ai-key" autocomplete="off"
                 placeholder="{{ $settings['ai']['api_key_configured'] ? '•••••••• (configured)' : 'sk-…' }}">
        </div>
        <div class="prx-field">
          <label>API base URL</label>
          <input type="text" id="prx-ai-base-url" value="{{ $settings['ai']['base_url'] }}">
        </div>
        <div class="prx-field">
          <label>Rate limit (calls per user per hour)</label>
          <input type="number" id="prx-ai-rate-limit" min="1" max="500" value="{{ $settings['ai']['rate_limit_per_hour'] }}">
        </div>
      </div>
      <div class="prx-col">
        <h3 class="prx-h">Models per feature</h3>
        <div class="prx-field">
          <label>AI Fixer (console diagnostics)</label>
          <select id="prx-model-fix">
            @foreach($models as $modelId => $modelLabel)
              <option value="{{ $modelId }}" {{ ($settings['ai']['models']['fix'] ?? '') === $modelId ? 'selected' : '' }}>{{ $modelLabel }}</option>
            @endforeach
          </select>
        </div>
        <div class="prx-field">
          <label>AI Optimizer (resource advice)</label>
          <select id="prx-model-optimize">
            @foreach($models as $modelId => $modelLabel)
              <option value="{{ $modelId }}" {{ ($settings['ai']['models']['optimize'] ?? '') === $modelId ? 'selected' : '' }}>{{ $modelLabel }}</option>
            @endforeach
          </select>
        </div>
        <div class="prx-field">
          <label class="prx-check"><input type="checkbox" id="prx-ai-fixer-enabled" {{ $settings['ai']['fixer_enabled'] ? 'checked' : '' }}> Enable AI Fixer</label>
          <label class="prx-check"><input type="checkbox" id="prx-ai-optimizer-enabled" {{ $settings['ai']['optimizer_enabled'] ? 'checked' : '' }}> Enable AI Optimizer</label>
        </div>
      </div>
    </div>
  </section>

  {{-- ═══════════ PANEL: usage ═══════════ --}}
  <section class="prx-panel" data-prx-panel="usage" hidden>
    <div class="prx-usage" id="prx-usage">
      <div class="prx-usage-empty">Loading AI usage…</div>
    </div>
  </section>

  <div class="prx-savebar">
    <span class="prx-savebar-hint" id="prx-dirty-hint">No unsaved changes</span>
    <button class="prx-btn prx-btn-primary" id="prx-save" type="button">Save changes</button>
  </div>

  <div class="prx-toast" id="prx-toast" hidden></div>
</div>

<script id="primus-admin-js">
(function () {
  "use strict";
  var root = document.querySelector(".primus-admin");
  if (!root) return;
  var settings = {};
  try { settings = JSON.parse(root.getAttribute("data-settings") || "{}"); } catch (e) { settings = {}; }

  var defaultAppearance = settings.appearance || {};
  var overrides = JSON.parse(JSON.stringify(settings.overrides || {}));
  var appearance = {
    theme: defaultAppearance.theme || "dark",
    vibrance: defaultAppearance.vibrance || "normal",
    radius: defaultAppearance.radius != null ? defaultAppearance.radius : 1,
    shadow: defaultAppearance.shadow != null ? defaultAppearance.shadow : 1,
    white_label: !!defaultAppearance.white_label,
    logo_url: defaultAppearance.logo_url || "",
    favicon_url: defaultAppearance.favicon_url || "",
    font_heading: defaultAppearance.font_heading || "",
    font_body: defaultAppearance.font_body || "",
    font_mono: defaultAppearance.font_mono || ""
  };
  var footer = JSON.parse(JSON.stringify(settings.footer || { enabled: true, label: "", links: [] }));
  var announcements = { text: ((settings.announcements || {}).text) || "" };
  var shortcuts = JSON.parse(JSON.stringify(settings.shortcuts || { hint: true }));
  var quickactions = JSON.parse(JSON.stringify(settings.quickactions || { enabled: true }));
  var ai = {
    api_key: "",
    base_url: settings.ai && settings.ai.base_url,
    rate_limit_per_hour: settings.ai && settings.ai.rate_limit_per_hour,
    fixer_enabled: settings.ai ? settings.ai.fixer_enabled : true,
    optimizer_enabled: settings.ai ? settings.ai.optimizer_enabled : true,
    models: {
      fix: settings.ai && settings.ai.models && settings.ai.models.fix,
      optimize: settings.ai && settings.ai.models && settings.ai.models.optimize
    }
  };
  var dirty = false;

  function markDirty() {
    dirty = true;
    var hint = document.getElementById("prx-dirty-hint");
    if (hint) hint.textContent = "You have unsaved changes";
  }

  function toast(msg, kind) {
    var t = document.getElementById("prx-toast");
    if (!t) return;
    t.textContent = msg;
    t.className = "prx-toast prx-toast-" + (kind || "info");
    t.hidden = false;
    clearTimeout(toast._timer);
    toast._timer = setTimeout(function () { t.hidden = true; }, 3500);
  }

  // ── tabs ──
  Array.prototype.forEach.call(document.querySelectorAll(".prx-tab"), function (tab) {
    tab.addEventListener("click", function () {
      Array.prototype.forEach.call(document.querySelectorAll(".prx-tab"), function (t) { t.classList.remove("is-active"); });
      tab.classList.add("is-active");
      var key = tab.getAttribute("data-prx-tab");
      Array.prototype.forEach.call(document.querySelectorAll(".prx-panel"), function (p) {
        p.hidden = p.getAttribute("data-prx-panel") !== key;
      });
      if (key === "usage") loadUsage();
    });
  });

  // ── appearance wiring ──
  var themeMode = document.getElementById("prx-theme-mode");
  themeMode.value = appearance.theme;
  themeMode.addEventListener("change", function () { appearance.theme = themeMode.value; document.documentElement.setAttribute("data-primus-theme", themeMode.value); markDirty(); });

  var vibrance = document.getElementById("prx-vibrance");
  vibrance.value = appearance.vibrance;
  vibrance.addEventListener("change", function () { appearance.vibrance = vibrance.value; document.documentElement.setAttribute("data-primus-vibrance", vibrance.value); markDirty(); });

  function wireColor(colorId, textId, prop) {
    var c = document.getElementById(colorId), t = document.getElementById(textId);
    c.addEventListener("input", function () {
      overrides[prop] = c.value; t.value = c.value;
      document.documentElement.style.setProperty(prop, c.value);
      markDirty();
    });
    t.addEventListener("change", function () {
      if (/^#[0-9a-fA-F]{6}$/.test(t.value)) {
        overrides[prop] = t.value; c.value = t.value;
        document.documentElement.style.setProperty(prop, t.value);
        markDirty();
      } else { t.value = overrides[prop] || t.value; }
    });
  }
  wireColor("prx-accent", "prx-accent-text", "--pr-accent");
  wireColor("prx-accent-strong", "prx-accent-strong-text", "--pr-accent-strong");

  function wireRange(id, valueId, prop, placeholder) {
    var el = document.getElementById(id), out = document.getElementById(valueId);
    var initial = appearance[prop];
    el.value = initial;
    out.textContent = (+initial).toFixed(2) + "×";
    document.documentElement.style.setProperty(placeholder, initial);
    el.addEventListener("input", function () {
      appearance[prop] = parseFloat(el.value);
      out.textContent = (+el.value).toFixed(2) + "×";
      document.documentElement.style.setProperty(placeholder, el.value);
      markDirty();
    });
  }
  wireRange("prx-radius", "prx-radius-value", "radius", "--pr-radius-multiplier");
  wireRange("prx-shadow", "prx-shadow-value", "shadow", "--pr-shadow-multiplier");

  ["white_label", "logo_url", "favicon_url", "font_heading", "font_body", "font_mono"].forEach(function (key) {
    var id = "prx-" + key.replace(/_/g, "-");
    var el = document.getElementById(id);
    if (!el) return;
    if (el.type === "checkbox") el.checked = !!appearance[key]; else el.value = appearance[key] || "";
    var evt = el.type === "checkbox" ? "change" : "input";
    el.addEventListener(evt, function () {
      appearance[key] = el.type === "checkbox" ? el.checked : el.value.trim();
      if (key === "logo_url" || key === "favicon_url") {
        var target = key === "logo_url" ? '#root img[src*="/logo"], header img' : 'link[rel*="icon"]';
        var node = document.querySelector(target);
        if (node && el.value) { if (node.tagName === "LINK") node.href = el.value; else node.src = el.value; }
      }
      markDirty();
    });
  });

  ["hint"].forEach(function (k) {
    var el = document.getElementById("prx-shortcuts-hint");
    el.checked = !!shortcuts.hint;
    el.addEventListener("change", function () { shortcuts.hint = el.checked; markDirty(); });
  });
  var qa = document.getElementById("prx-quickactions");
  qa.checked = !!quickactions.enabled;
  qa.addEventListener("change", function () { quickactions.enabled = qa.checked; markDirty(); });

  // ── footer / announcements wiring ──
  var announceText = document.getElementById("prx-announce-text");
  announceText.value = announcements.text;
  announceText.addEventListener("input", function () { announcements.text = announceText.value; markDirty(); });

  var footerEnabled = document.getElementById("prx-footer-enabled");
  footerEnabled.checked = !!footer.enabled;
  footerEnabled.addEventListener("change", function () { footer.enabled = footerEnabled.checked; markDirty(); });

  var footerLabel = document.getElementById("prx-footer-label");
  footerLabel.value = footer.label || "";
  footerLabel.addEventListener("input", function () { footer.label = footerLabel.value; markDirty(); });

  var footerLinks = document.getElementById("prx-footer-links");
  footerLinks.value = JSON.stringify(footer.links || [], null, 2);
  footerLinks.addEventListener("change", function () {
    try {
      var parsed = JSON.parse(footerLinks.value);
      if (!Array.isArray(parsed)) throw new Error("expected array");
      footer.links = parsed; markDirty();
      footerLinks.classList.remove("prx-invalid");
    } catch (e) {
      footerLinks.classList.add("prx-invalid");
      toast("Footer links must be a valid JSON array", "error");
    }
  });

  // ── ai wiring ──
  function wireSelect(id, value, onSet) {
    var el = document.getElementById(id);
    if (value) el.value = value;
    el.addEventListener("change", function () { onSet(el.value); markDirty(); });
  }
  wireSelect("prx-model-fix", ai.models.fix, function (v) { ai.models.fix = v; });
  wireSelect("prx-model-optimize", ai.models.optimize, function (v) { ai.models.optimize = v; });

  document.getElementById("prx-ai-base-url").addEventListener("change", function (e) { ai.base_url = e.target.value.trim(); markDirty(); });
  document.getElementById("prx-ai-rate-limit").addEventListener("change", function (e) {
    var n = parseInt(e.target.value, 10);
    if (n && n > 0) { ai.rate_limit_per_hour = n; markDirty(); }
  });
  document.getElementById("prx-ai-key").addEventListener("change", function (e) {
    ai.api_key = e.target.value.trim();
    if (ai.api_key) markDirty();
    e.target.value = ai.api_key ? "••••••••" : "";
  });
  function wireSwitch(id, onSet) {
    var el = document.getElementById(id);
    el.addEventListener("change", function () { onSet(el.checked); markDirty(); });
  }
  wireSwitch("prx-ai-fixer-enabled", function (v) { ai.fixer_enabled = v; });
  wireSwitch("prx-ai-optimizer-enabled", function (v) { ai.optimizer_enabled = v; });

  // ── presets wiring ──
  Array.prototype.forEach.call(document.querySelectorAll("[data-prx-preset]"), function (card) {
    card.addEventListener("click", function () {
      fetch("/extensions/primus/admin/preset/apply", {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken(), "Accept": "application/json" },
        body: JSON.stringify({ preset: card.getAttribute("data-prx-preset") })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res.error) return toast(res.error, "error");
        toast("Preset applied — reloading…", "success");
        setTimeout(function () { location.reload(); }, 700);
      }).catch(function () { toast("Could not apply preset", "error"); });
    });
  });

  document.getElementById("prx-preset-reset").addEventListener("click", function () {
    if (!confirm("Reset all theme settings and AI configuration to defaults?")) return;
    postAdmin("/admin/reset", {}).then(function () { location.reload(); });
  });

  document.getElementById("prx-preset-save-as").addEventListener("click", function () {
    var name = prompt("Name for this preset:");
    if (!name) return;
    postAdmin("/admin/preset/save", { name: name }).then(function (res) {
      if (res.error) return toast(res.error, "error");
      toast("Preset “" + res.name + "” saved", "success");
      setTimeout(function () { location.reload(); }, 700);
    });
  });

  document.getElementById("prx-preset-import").addEventListener("change", function (e) {
    var file = e.target.files && e.target.files[0];
    if (!file) return;
    var fd = new FormData(); fd.append("file", file);
    fetch("/extensions/primus/admin/presets/import", {
      method: "POST", credentials: "same-origin",
      headers: { "X-CSRF-TOKEN": csrfToken(), "Accept": "application/json" },
      body: fd
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res.error) return toast(res.error, "error");
      toast("Imported " + res.imported + " preset(s)", "success");
      setTimeout(function () { location.reload(); }, 700);
    }).catch(function () { toast("Import failed", "error"); });
    e.target.value = "";
  });

  // ── usage ──
  var usageLoaded = false;
  function loadUsage() {
    if (usageLoaded) return;
    fetch("/extensions/primus/admin/usage", { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(renderUsage)
      .catch(function () {
        document.getElementById("prx-usage").innerHTML = '<div class="prx-usage-empty">Usage data temporarily unavailable.</div>';
      });
    usageLoaded = true;
  }
  function renderUsage(u) {
    var host = document.getElementById("prx-usage");
    var tiles = [
      { label: "Calls (7d)", value: u.calls },
      { label: "Prompt tokens", value: u.prompt_tokens },
      { label: "Completion tokens", value: u.completion_tokens },
      { label: "Avg latency", value: u.avg_latency_ms + " ms" },
      { label: "Errors", value: u.errors },
      { label: "Est. cost", value: "$" + (u.est_cost_usd || 0).toFixed(4) }
    ];
    var html = '<div class="prx-usage-grid">';
    tiles.forEach(function (t) {
      html += '<div class="prx-usage-tile"><span class="prx-usage-num">' + t.value + '</span><span>' + t.label + '</span></div>';
    });
    html += '</div>';
    var models = Object.keys(u.by_model || {});
    if (models.length) {
      html += '<h3 class="prx-h" style="margin-top:18px">Per-model breakdown</h3><table class="prx-table"><thead><tr><th>Model</th><th>Calls</th><th>Est. cost</th></tr></thead><tbody>';
      var total = models.reduce(function (s, m) { return s + u.by_model[m].calls; }, 0) || 1;
      models.forEach(function (m) {
        var info = u.by_model[m];
        var pct = Math.round(info.calls / total * 100);
        html += '<tr><td>' + m + '<span class="prx-bar"><span style="width:' + pct + '%"></span></span></td><td>' + info.calls + '</td><td>$' + (info.est_cost_usd || 0).toFixed(4) + '</td></tr>';
      });
      html += '</tbody></table>';
    } else {
      html += '<div class="prx-usage-empty" style="margin-top:14px">No AI calls recorded in this window yet.</div>';
    }
    html += '<div class="prx-usage-foot">Rate limit: <strong>' + u.rate_limit_per_hour + '</strong> calls/user/hour · Fixer ' +
      (u.fixer_enabled ? 'enabled' : 'disabled') + ' · Optimizer ' + (u.optimizer_enabled ? 'enabled' : 'disabled') +
      (u.configured ? ' · API key configured' : ' · <span class="prx-warn">no API key configured</span>') + '</div>';
    host.innerHTML = html;
  }

  // ── save ──
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }
  function postAdmin(path, body) {
    return fetch("/extensions/primus" + path, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken(), "Accept": "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  document.getElementById("prx-save").addEventListener("click", function () {
    var payload = {
      overrides: overrides,
      appearance: appearance,
      footer: footer,
      announcements: announcements,
      shortcuts: shortcuts,
      quickactions: quickactions,
      ai: {
        base_url: ai.base_url,
        rate_limit_per_hour: ai.rate_limit_per_hour,
        fixer_enabled: ai.fixer_enabled,
        optimizer_enabled: ai.optimizer_enabled,
        models: ai.models
      }
    };
    if (ai.api_key && ai.api_key !== "••••••••") payload.ai.api_key = ai.api_key;
    var btn = document.getElementById("prx-save");
    btn.disabled = true; btn.textContent = "Saving…";
    postAdmin("/admin/save", payload).then(function (res) {
      btn.disabled = false; btn.textContent = "Save changes";
      if (res.error) return toast(res.error, "error");
      dirty = false;
      document.getElementById("prx-dirty-hint").textContent = "All changes saved";
      toast("Settings saved", "success");
      var statusEl = document.getElementById("prx-api-status");
      if (statusEl && ai.api_key) { statusEl.className = "prx-alert prx-alert-ok"; statusEl.innerHTML = "AI provider configured — Fixer/Optimizer are live."; }
    }).catch(function () {
      btn.disabled = false; btn.textContent = "Save changes";
      toast("Save failed — check network/CSRF", "error");
    });
  });

  window.addEventListener("beforeunload", function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ""; }
  });
})();
</script>
