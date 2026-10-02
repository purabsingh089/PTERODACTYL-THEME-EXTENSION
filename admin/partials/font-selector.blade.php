{{--
  Reference partial — font selector + shape sliders section of the customizer.

  NOTE: Blueprint installs ONLY the single file bound to admin.view and does
  not copy sibling partials, so this section is inlined in admin/view.blade.php
  behind the banner "PARTIAL: font-selector". Kept for spec-structure parity.

  Data it renders: appearance.font_heading / font_body / font_mono and the
  radius + shadow multipliers (appearance.radius / appearance.shadow).
--}}

@php
    $appearance = $settings['appearance'] ?? [];
@endphp

<div class="prx-field">
    <label>Heading font</label>
    <input data-prx-appearance="font_heading" class="prx-input" id="prx-font-heading"
           placeholder="Sora (default)" value="{{ $appearance['font_heading'] ?? '' }}">
</div>
<div class="prx-field">
    <label>Body font</label>
    <input data-prx-appearance="font_body" class="prx-input" id="prx-font-body"
           placeholder="Inter (default)" value="{{ $appearance['font_body'] ?? '' }}">
</div>
<div class="prx-field">
    <label>Monospace font (console)</label>
    <input data-prx-appearance="font_mono" class="prx-input" id="prx-font-mono"
           placeholder="JetBrains Mono (default)" value="{{ $appearance['font_mono'] ?? '' }}">
</div>
<div class="prx-field">
    <label>Corner radius <span class="prx-field__value" id="prx-radius-value"></span></label>
    <input type="range" min="0.5" max="2" step="0.05" data-prx-slider="radius" id="prx-radius"
           value="{{ $appearance['radius'] ?? 1 }}">
</div>
<div class="prx-field">
    <label>Shadow depth <span class="prx-field__value" id="prx-shadow-value"></span></label>
    <input type="range" min="0" max="2" step="0.05" data-prx-slider="shadow" id="prx-shadow"
           value="{{ $appearance['shadow'] ?? 1 }}">
</div>
