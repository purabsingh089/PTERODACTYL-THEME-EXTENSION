@extends('layouts.admin')

{{--
  Primus · Admin customizer view (self-contained).
  Rendered by primusExtensionController@index with $settings, $presets,
  $active_preset, $models, $version injected as variables.
  Spec partials under admin/partials/*.blade.php are kept for reference /
  build-time inlining (Blueprint installs only this single view file).
--}}
@php
    $prxMode = (($settings['appearance']['theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
    $prxPalette = $prxMode === 'light' ? [
        '--pr-page' => '#f4f6fb',
        '--pr-surface' => '#ffffff',
        '--pr-surface-raised' => '#ffffff',
        '--pr-surface-sunken' => '#f4f6fb',
        '--pr-border' => '#e2e6f1',
        '--pr-text-primary' => '#1b1e31',
        '--pr-text-secondary' => '#4c536c',
        '--pr-text-muted' => '#767d97',
        '--pr-success' => '#16a06b',
        '--pr-warning' => '#d48a08',
        '--pr-danger' => '#d94053',
        '--pr-info' => '#1f7bd0',
        '--pr-accent' => $settings['overrides']['--pr-accent'] ?? '#0050b8',
        '--pr-accent-strong' => $settings['overrides']['--pr-accent-strong'] ?? '#1e6fe0',
    ] : [
        '--pr-page' => '#0a0b1a',
        '--pr-surface' => '#12142a',
        '--pr-surface-raised' => '#1c2039',
        '--pr-surface-sunken' => '#0a0b1a',
        '--pr-border' => '#2a2f4a',
        '--pr-text-primary' => '#edeff7',
        '--pr-text-secondary' => '#aab0c8',
        '--pr-text-muted' => '#737a99',
        '--pr-success' => '#3ecf8e',
        '--pr-warning' => '#f5b644',
        '--pr-danger' => '#f45f6f',
        '--pr-info' => '#4ea1ef',
        '--pr-accent' => $settings['overrides']['--pr-accent'] ?? '#0050b8',
        '--pr-accent-strong' => $settings['overrides']['--pr-accent-strong'] ?? '#1e6fe0',
    ];
    // Seed for the pickers: stored override when it is a valid #rrggbb,
    // otherwise the theme's token default (display only — no override is
    // written until the admin actually edits a control).
    $prxHex = function ($prop) use ($settings, $prxPalette) {
        $v = $settings['overrides'][$prop] ?? '';
        if (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) return $v;
        return $prxPalette[$prop] ?? '';
    };
@endphp

@section('title')
    Primus
@endsection

@section('content-header')
    <h1>Primus <small>Appearance &amp; AI</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Primus</li>
    </ol>
@endsection

@section('scripts')
    @parent
    <link rel="stylesheet" href="{webroot/public}/css/admin-customizer.css?v=1788700902{timestamp}" id="primus-admin-css">
@endsection

@section('content')
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
    <button class="prx-tab" data-prx-tab="addons" type="button">Addons</button>
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
            <input type="color" id="prx-accent" value="{{ $prxHex('--pr-accent') }}">
            <input type="text" id="prx-accent-text" value="{{ $prxHex('--pr-accent') }}" maxlength="7">
          </div>
        </div>
        <div class="prx-field">
          <label>Accent (bright / hover)</label>
          <div class="prx-colorrow">
            <input type="color" id="prx-accent-strong" value="{{ $prxHex('--pr-accent-strong') }}">
            <input type="text" id="prx-accent-strong-text" value="{{ $prxHex('--pr-accent-strong') }}" maxlength="7">
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

        <h3 class="prx-h" style="margin-top:var(--pr-space-6)">Surface</h3>
        <div class="prx-colorgrid">
          <div class="prx-field">
            <label>Page background</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-page" value="{{ $prxHex('--pr-page') }}">
              <input type="text" id="prx-c-page-text" value="{{ $prxHex('--pr-page') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Card background</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-surface" value="{{ $prxHex('--pr-surface') }}">
              <input type="text" id="prx-c-surface-text" value="{{ $prxHex('--pr-surface') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Raised background</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-surface-raised" value="{{ $prxHex('--pr-surface-raised') }}">
              <input type="text" id="prx-c-surface-raised-text" value="{{ $prxHex('--pr-surface-raised') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Sunken background</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-surface-sunken" value="{{ $prxHex('--pr-surface-sunken') }}">
              <input type="text" id="prx-c-surface-sunken-text" value="{{ $prxHex('--pr-surface-sunken') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Borders</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-border" value="{{ $prxHex('--pr-border') }}">
              <input type="text" id="prx-c-border-text" value="{{ $prxHex('--pr-border') }}" maxlength="7">
            </div>
          </div>
        </div>

        <h3 class="prx-h" style="margin-top:var(--pr-space-6)">Text</h3>
        <div class="prx-colorgrid">
          <div class="prx-field">
            <label>Primary</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-text-primary" value="{{ $prxHex('--pr-text-primary') }}">
              <input type="text" id="prx-c-text-primary-text" value="{{ $prxHex('--pr-text-primary') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Secondary</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-text-secondary" value="{{ $prxHex('--pr-text-secondary') }}">
              <input type="text" id="prx-c-text-secondary-text" value="{{ $prxHex('--pr-text-secondary') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Muted</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-text-muted" value="{{ $prxHex('--pr-text-muted') }}">
              <input type="text" id="prx-c-text-muted-text" value="{{ $prxHex('--pr-text-muted') }}" maxlength="7">
            </div>
          </div>
        </div>

        <h3 class="prx-h" style="margin-top:var(--pr-space-6)">Semantic</h3>
        <div class="prx-colorgrid">
          <div class="prx-field">
            <label>Success</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-success" value="{{ $prxHex('--pr-success') }}">
              <input type="text" id="prx-c-success-text" value="{{ $prxHex('--pr-success') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Warning</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-warning" value="{{ $prxHex('--pr-warning') }}">
              <input type="text" id="prx-c-warning-text" value="{{ $prxHex('--pr-warning') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Danger</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-danger" value="{{ $prxHex('--pr-danger') }}">
              <input type="text" id="prx-c-danger-text" value="{{ $prxHex('--pr-danger') }}" maxlength="7">
            </div>
          </div>
          <div class="prx-field">
            <label>Info</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-c-info" value="{{ $prxHex('--pr-info') }}">
              <input type="text" id="prx-c-info-text" value="{{ $prxHex('--pr-info') }}" maxlength="7">
            </div>
          </div>
        </div>

        <h3 class="prx-h" style="margin-top:var(--pr-space-6)">Shape</h3>
        <div class="prx-field">
          <label>Corner radius <span class="prx-value" id="prx-radius-value"></span></label>
          <input type="range" id="prx-radius" min="0.5" max="2" step="0.05">
        </div>
        <div class="prx-field">
          <label>Shadow intensity <span class="prx-value" id="prx-shadow-value"></span></label>
          <input type="range" id="prx-shadow" min="0" max="2" step="0.05">
        </div>
      </div>
      <div class="prx-col">
        <details class="prx-details">
          <summary>Login page colors <span class="prx-chip">optional</span></summary>
          <small class="prx-hint" style="display:block;margin-bottom:var(--pr-space-3)">
            Leave empty to inherit the surface &amp; accent colors above.
          </small>
          <div class="prx-field">
            <label>Card background</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-auth-card-bg" value="{{ $prxHex('--pr-surface') }}"
                     data-prx-default="{{ $prxHex('--pr-surface') }}">
              <input type="text" id="prx-auth-card-bg-text" value="{{ $settings['overrides']['--pr-auth-card-bg'] ?? '' }}"
                     placeholder="auto" maxlength="7">
              <button type="button" class="prx-clear" id="prx-clear-auth-card-bg" title="Reset to inherited" hidden>&times;</button>
            </div>
          </div>
          <div class="prx-field">
            <label>Submit button</label>
            <div class="prx-colorrow">
              <input type="color" id="prx-auth-submit" value="{{ $prxHex('--pr-accent') }}"
                     data-prx-default="{{ $prxHex('--pr-accent') }}">
              <input type="text" id="prx-auth-submit-text" value="{{ $settings['overrides']['--pr-auth-submit'] ?? '' }}"
                     placeholder="accent" maxlength="7">
              <button type="button" class="prx-clear" id="prx-clear-auth-submit" title="Reset to inherited" hidden>&times;</button>
            </div>
          </div>
        </details>

        <h3 class="prx-h">Layout</h3>
        <div class="prx-field">
          <label>Content density</label>
          <select id="prx-density">
            <option value="comfortable">Comfortable (default)</option>
            <option value="compact">Compact &mdash; tighter spacing</option>
          </select>
        </div>
        <div class="prx-field">
          <label>Server card size <span class="prx-value" id="prx-card-scale-value"></span></label>
          <input type="range" id="prx-card-scale" min="0.85" max="1.2" step="0.05">
          <small class="prx-hint">Scales the hero-card art height and padding on desktop (0.85&times; &ndash; 1.20&times;). Mobile always stays at 1&times;.</small>
        </div>
        <div class="prx-field">
          <label class="prx-check"><input type="checkbox" id="prx-hide-copyright"> Hide the stock Pterodactyl copyright notice under the login card</label>
        </div>
        <div class="prx-field">
          <label>Quick toggles</label>
          <label class="prx-check"><input type="checkbox" id="prx-shortcuts-hint"> Shortcut hint in client footer</label>
          <label class="prx-check"><input type="checkbox" id="prx-quickactions"> Hover quick-actions on server rows</label>
        </div>
        <small class="prx-hint prx-hint-preset">
          Applying a preset in the Presets tab replaces the custom colors above. Density, card size and the copyright-notice toggle are kept.
        </small>
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
          <label>Provider API key (stored server-side only)</label>
          <input type="password" id="prx-ai-key" autocomplete="off"
                 placeholder="{{ $settings['ai']['api_key_configured'] ? '•••••••• (configured)' : 'sk-...' }}">
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
        <h3 class="prx-h">Models</h3>
        <div class="prx-field">
          <button class="prx-btn prx-btn-ghost" id="prx-ai-detect" type="button">Detect available models</button>
          <small class="prx-hint" id="prx-ai-detect-status">Ask the provider which models it offers, using the base URL and key above.</small>
        </div>
        <div class="prx-field">
          <label>AI Fixer model (console diagnostics)</label>
          <input type="text" id="prx-model-fix" list="prx-model-list"
                 placeholder="Pick a detected model or type any model id" autocomplete="off" spellcheck="false">
        </div>
        <div class="prx-field">
          <label>AI Optimizer model (resource advice)</label>
          <input type="text" id="prx-model-optimize" list="prx-model-list"
                 placeholder="Pick a detected model or type any model id" autocomplete="off" spellcheck="false">
        </div>
        <div class="prx-field">
          <label class="prx-check"><input type="checkbox" id="prx-ai-fixer-enabled" {{ $settings['ai']['fixer_enabled'] ? 'checked' : '' }}> Enable AI Fixer</label>
          <label class="prx-check"><input type="checkbox" id="prx-ai-optimizer-enabled" {{ $settings['ai']['optimizer_enabled'] ? 'checked' : '' }}> Enable AI Optimizer</label>
        </div>
        <datalist id="prx-model-list">
          @if(!empty($settings['ai']['models']['fix']))<option value="{{ $settings['ai']['models']['fix'] }}"></option>@endif
          @if(!empty($settings['ai']['models']['optimize']))<option value="{{ $settings['ai']['models']['optimize'] }}"></option>@endif
        </datalist>
      </div>
    </div>
  </section>

  {{-- ═══════════ PANEL: usage ═══════════ --}}
  <section class="prx-panel" data-prx-panel="usage" hidden>
    <div class="prx-usage" id="prx-usage">
      <div class="prx-usage-empty">Loading AI usage…</div>
    </div>
  </section>

  {{-- ═══════════ PANEL: addons ═══════════ --}}
  <section class="prx-panel" data-prx-panel="addons" hidden>
    <div class="prx-addons-admin">
      <div class="prx-addons-admin__grid">
        @foreach($addons as $a)
          <div class="prx-addon-card" data-addon="{{ $a['id'] }}">
            <div class="prx-addon-card__head">
              <span class="prx-addon-card__title">{{ $a['title'] }}</span>
              @if($a['comingSoon'])
                <span class="prx-addon-card__soon">coming soon</span>
              @endif
              <label class="prx-switch">
                <input type="checkbox" class="prx-addon-toggle" data-addon="{{ $a['id'] }}"
                       @if($a['enabled']) checked @endif>
                <span class="prx-switch__track"></span>
              </label>
            </div>
            <p class="prx-addon-card__desc">{{ $a['description'] }}</p>
            <span class="prx-addon-card__cat">{{ $a['category'] }}</span>
          </div>
        @endforeach
      </div>

      <div class="prx-addon-card" style="max-width:520px">
        <h3>Marketplace API keys</h3>
        <p class="text-xs text-neutral-400">Provider keys are stored server-side and never displayed back.</p>
        <label class="block mt-2 text-sm">CurseForge ({{ $mktKeys['curseforge'] ? 'configured' : 'not configured' }})
          <input type="password" name="cf_key" placeholder="{{ $mktKeys['curseforge'] ? 'configured — leave blank to keep' : 'paste x-api-key' }}" class="prx-input" autocomplete="off" />
        </label>
        <label class="block mt-2 text-sm">Modrinth ({{ $mktKeys['modrinth'] ? 'configured' : 'not configured' }})
          <input type="password" name="mr_key" placeholder="{{ $mktKeys['modrinth'] ? 'configured — leave blank to keep' : 'paste token' }}" class="prx-input" autocomplete="off" />
        </label>
        <button id="prx-mkt-keys-save" class="prx-btn mt-3">Save keys</button>
      </div>

      <h3 class="prx-audit__title">Audit log</h3>
      <table class="prx-audit-table">
        <thead>
          <tr><th>When</th><th>Addon</th><th>Action</th><th>Target</th><th>User</th><th>Server</th></tr>
        </thead>
        <tbody>
          @forelse($audit as $row)
            <tr>
              <td>{{ $row['when'] }}</td>
              <td>{{ $row['addon'] }}</td>
              <td>{{ $row['action'] }}</td>
              <td class="prx-audit__target">{{ $row['target'] }}</td>
              <td>{{ $row['user'] }}</td>
              <td>{{ $row['server'] }}</td>
            </tr>
          @empty
            <tr><td colspan="6">No addon actions recorded yet.</td></tr>
          @endforelse
        </tbody>
      </table>
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
    font_mono: defaultAppearance.font_mono || "",
    density: defaultAppearance.density === "compact" ? "compact" : "comfortable",
    card_scale: defaultAppearance.card_scale != null && +defaultAppearance.card_scale > 0
      ? Math.max(0.85, Math.min(1.2, +defaultAppearance.card_scale)) : 1,
    hide_stock_copyright: !!defaultAppearance.hide_stock_copyright
  };
  var footer = JSON.parse(JSON.stringify(settings.footer || { enabled: true, label: "", links: [] }));
  var announcements = { text: ((settings.announcements || {}).text) || "" };
  var shortcuts = JSON.parse(JSON.stringify(settings.shortcuts || { hint: true }));
  var quickactions = JSON.parse(JSON.stringify(settings.quickactions || { enabled: true }));
  var ai = {
    api_key: "",
    api_key_configured: settings.ai ? !!settings.ai.api_key_configured : false,
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

  // ── full token pickers: surface / text / semantic ──
  ["page", "surface", "surface-raised", "surface-sunken", "border",
   "text-primary", "text-secondary", "text-muted",
   "success", "warning", "danger", "info"].forEach(function (suffix) {
    wireColor("prx-c-" + suffix, "prx-c-" + suffix + "-text", "--pr-" + suffix);
  });

  // ── layout: density, card scale, stock copyright notice ──
  var densitySel = document.getElementById("prx-density");
  densitySel.value = appearance.density;
  densitySel.addEventListener("change", function () {
    appearance.density = densitySel.value;
    document.documentElement.setAttribute("data-primus-density", densitySel.value);
    markDirty();
  });
  wireRange("prx-card-scale", "prx-card-scale-value", "card_scale", "--pr-card-scale");
  var hideCopy = document.getElementById("prx-hide-copyright");
  hideCopy.checked = !!appearance.hide_stock_copyright;
  hideCopy.addEventListener("change", function () {
    appearance.hide_stock_copyright = hideCopy.checked;
    document.documentElement.setAttribute("data-primus-hide-copyright", hideCopy.checked ? "1" : "0");
    markDirty();
  });

  // ── optional login colors: no override until edited, clearable ──
  function wireOptionalColor(colorId, textId, prop, clearId) {
    var c = document.getElementById(colorId), t = document.getElementById(textId), x = document.getElementById(clearId);
    if (!c || !t || !x) return;
    function syncClear() { x.hidden = !(overrides[prop]); }
    c.addEventListener("input", function () {
      overrides[prop] = c.value; t.value = c.value;
      document.documentElement.style.setProperty(prop, c.value);
      syncClear(); markDirty();
    });
    t.addEventListener("change", function () {
      if (/^#[0-9a-fA-F]{6}$/.test(t.value)) {
        overrides[prop] = t.value; c.value = t.value;
        document.documentElement.style.setProperty(prop, t.value);
        syncClear(); markDirty();
      } else if (t.value === "") {
        delete overrides[prop];
        c.value = c.getAttribute("data-prx-default") || c.value;
        document.documentElement.style.removeProperty(prop);
        syncClear(); markDirty();
      } else { t.value = overrides[prop] || ""; }
    });
    x.addEventListener("click", function () {
      delete overrides[prop];
      c.value = c.getAttribute("data-prx-default") || c.value;
      t.value = "";
      document.documentElement.style.removeProperty(prop);
      syncClear(); markDirty();
    });
    syncClear();
  }
  wireOptionalColor("prx-auth-card-bg", "prx-auth-card-bg-text", "--pr-auth-card-bg", "prx-clear-auth-card-bg");
  wireOptionalColor("prx-auth-submit", "prx-auth-submit-text", "--pr-auth-submit", "prx-clear-auth-submit");

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
  function wireModel(id, value, onSet) {
    var el = document.getElementById(id);
    if (!el) return;
    if (value) el.value = value;
    el.addEventListener("change", function () { onSet(el.value.trim()); markDirty(); });
  }
  wireModel("prx-model-fix", ai.models.fix, function (v) { ai.models.fix = v; });
  wireModel("prx-model-optimize", ai.models.optimize, function (v) { ai.models.optimize = v; });

  function keyAvailable() {
    return (ai.api_key && ai.api_key !== "••••••••") || ai.api_key_configured;
  }

  function populateModelList(models) {
    var list = document.getElementById("prx-model-list");
    if (!list) return;
    var merged = [];
    ([ai.models.fix, ai.models.optimize].concat(models || [])).forEach(function (v) {
      if (!v) return;
      v = String(v).trim();
      if (v && merged.indexOf(v) === -1) merged.push(v);
    });
    list.innerHTML = merged.map(function (v) {
      return '<option value="' + v.replace(/"/g, "&quot;") + '"></option>';
    }).join("");
  }

  function detectModels(silent) {
    var btn = document.getElementById("prx-ai-detect");
    var status = document.getElementById("prx-ai-detect-status");
    var baseUrlEl = document.getElementById("prx-ai-base-url");
    if (!keyAvailable()) {
      if (status) status.textContent = "Add an API key above first, then detect the models the provider offers.";
      if (!silent) toast("Add an API key first to list available models", "warn");
      return;
    }
    if (btn) btn.disabled = true;
    if (status) status.textContent = "Contacting " + ((baseUrlEl.value || "").trim() || "the provider") + " …";
    postAdmin("/admin/ai/models", { base_url: (baseUrlEl.value || "").trim(), api_key: ai.api_key || "" })
      .then(function (res) {
        if (btn) btn.disabled = false;
        if (res.error) {
          if (status) status.textContent = res.error;
          if (!silent) toast(res.error, "error");
          return;
        }
        var found = (res.models || []).length;
        populateModelList(res.models);
        if (res.warning) {
          if (status) status.textContent = res.warning;
          if (!silent) toast("Model list unavailable — enter a model id manually", "warn");
          return;
        }
        if (status) status.textContent = "Found " + found + " model(s) — pick one below or type any id.";
        if (!silent) toast("Found " + found + " model(s)", found ? "success" : "warn");
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        if (status) status.textContent = "Could not reach the provider. Check the base URL and API key.";
        if (!silent) toast("Could not reach the AI provider", "error");
      });
  }

  document.getElementById("prx-ai-detect").addEventListener("click", function () { detectModels(false); });

  document.getElementById("prx-ai-base-url").addEventListener("change", function (e) {
    ai.base_url = e.target.value.trim();
    markDirty();
    detectModels(false);
  });
  document.getElementById("prx-ai-rate-limit").addEventListener("change", function (e) {
    var n = parseInt(e.target.value, 10);
    if (n && n > 0) { ai.rate_limit_per_hour = n; markDirty(); }
  });
  document.getElementById("prx-ai-key").addEventListener("change", function (e) {
    ai.api_key = e.target.value.trim();
    if (ai.api_key && ai.api_key !== "••••••••") { markDirty(); detectModels(false); }
    e.target.value = ai.api_key ? "••••••••" : "";
  });
  function wireSwitch(id, onSet) {
    var el = document.getElementById(id);
    el.addEventListener("change", function () { onSet(el.checked); markDirty(); });
  }
  wireSwitch("prx-ai-fixer-enabled", function (v) { ai.fixer_enabled = v; });
  wireSwitch("prx-ai-optimizer-enabled", function (v) { ai.optimizer_enabled = v; });

  if (ai.api_key_configured) {
    detectModels(true);
  } else {
    populateModelList([]);
  }

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

  // ── addon enable/disable toggles — XHR to the gated admin endpoint ──
  Array.prototype.forEach.call(document.querySelectorAll(".prx-addon-toggle"), function (cb) {
    cb.addEventListener("change", function () {
      var prev = !cb.checked;
      postAdmin("/addons/admin/toggle", { addon: cb.getAttribute("data-addon"), enabled: cb.checked })
        .then(function (res) {
          if (res.ok) { toast("Addon " + (cb.checked ? "enabled" : "disabled"), "success"); return; }
          cb.checked = prev;
          toast(res.error || "Toggle failed.", "error");
        })
        .catch(function () { cb.checked = prev; toast("Toggle failed.", "error"); });
    });
  });

  // ── marketplace provider keys — XHR to the gated admin endpoint ──
  document.getElementById("prx-mkt-keys-save").addEventListener("click", function () {
    var body = {};
    var cf = document.querySelector("input[name='cf_key']").value.trim();
    var mr = document.querySelector("input[name='mr_key']").value.trim();
    if (cf) body.curseforge = cf;
    if (mr) body.modrinth = mr;
    if (!body.curseforge && !body.modrinth) { toast("Nothing to save", "Leave-blank fields keep existing keys.", "info"); return; }
    postAdmin("/addons/admin/marketplace/keys", body).then(function (r) {
      if (r.error || !r.ok) return toast("Save failed", r.error || "Request failed — keys were not saved.", "error");
      toast("Marketplace keys", "Saved. CurseForge: " + (r.curseforge ? "configured" : "not configured") + ", Modrinth: " + (r.modrinth ? "configured" : "not configured"), "success");
      document.querySelector("input[name='cf_key']").value = "";
      document.querySelector("input[name='mr_key']").value = "";
    }).catch(function (e) { toast("Save failed", e.message || "unknown", "error"); });
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
    var meta = document.querySelector('meta[name="csrf-token"]') || document.querySelector('meta[name="_token"]');
    if (meta) return meta.getAttribute("content");
    var input = document.querySelector('input[name="_token"]');
    return input ? input.value : "";
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
@endsection
