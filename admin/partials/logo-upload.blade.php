{{--
  Reference partial — branding section of the customizer (logo/favicon upload
  URL fields + white-label toggle).

  NOTE: Blueprint installs ONLY the single file bound to admin.view, so this
  section is inlined in admin/view.blade.php behind "PARTIAL: logo-upload".
  Kept for spec-structure parity.

  Data it renders: appearance.logo_url, appearance.favicon_url,
  appearance.white_label.
--}}

@php
    $appearance = $settings['appearance'] ?? [];
@endphp

<div class="prx-field">
    <label>Logo URL</label>
    <input data-prx-appearance="logo_url" class="prx-input" id="prx-logo-url"
           placeholder="/extensions/primus/img/logo.svg" value="{{ $appearance['logo_url'] ?? '' }}">
    <small style="opacity:.6">Upload files to the extension's public img folder, or use any URL.</small>
</div>
<div class="prx-field">
    <label>Favicon URL</label>
    <input data-prx-appearance="favicon_url" class="prx-input" id="prx-favicon-url"
           value="{{ $appearance['favicon_url'] ?? '' }}">
</div>
<label class="prx-switch" style="margin-top:6px">
    <input type="checkbox" id="prx-white-label" {{ !empty($appearance['white_label']) ? 'checked' : '' }}>
    <span class="prx-switch__track"></span>
    <span>White-label mode <small style="opacity:.6;display:block">Hides all “Primus” branding from the client panel.</small></span>
</label>
