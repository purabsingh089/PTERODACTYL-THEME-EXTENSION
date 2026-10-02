{{--
  Reference partial — color picker section of the customizer.

  NOTE: Blueprint installs ONLY the single file bound to admin.view and does
  not copy sibling partials, so this section is inlined in admin/view.blade.php
  behind the banner "PARTIAL: color-picker". This file is kept so the spec's
  folder structure stays intact and the section can be lifted into the view at
  build time if Blueprint adds multi-view support.

  Data it renders: {{ $settings['overrides'] }} (--pr-accent, --pr-accent-strong).
--}}

@php
    $accent = $settings['overrides']['--pr-accent'] ?? '#6d5df6';
    $accentStrong = $settings['overrides']['--pr-accent-strong'] ?? '#8577ff';
@endphp

<div class="prx-color">
    <span class="prx-color__swatch" id="prx-swatch-accent" style="background: {{ $accent }}"></span>
    <div style="flex:1">
        <label>Accent</label>
        <small>Primary brand colour, buttons, links</small>
    </div>
    <input type="color" data-prx-color="--pr-accent" id="prx-color-accent" value="{{ $accent }}">
</div>
<div class="prx-color">
    <span class="prx-color__swatch" id="prx-swatch-accent-strong" style="background: {{ $accentStrong }}"></span>
    <div style="flex:1">
        <label>Accent (bright)</label>
        <small>Hover states, gradients</small>
    </div>
    <input type="color" data-prx-color="--pr-accent-strong" id="prx-color-accent-strong" value="{{ $accentStrong }}">
</div>
