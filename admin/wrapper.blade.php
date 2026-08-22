{{--
  Primus · Admin wrapper — injected on every /admin page via conf.yml
  `admin.wrapper`. Ships the token system, applies DB-stored overrides as CSS
  custom properties and loads fonts so the admin panel matches the client
  theme end-to-end.
--}}
@php
    $primusSettings = [];
    $primusOverrides = [];
    $primusAppearance = [];
    try {
        $primusSettings = \Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::allAsArray();
        $primusOverrides = is_array($primusSettings['overrides'] ?? null) ? $primusSettings['overrides'] : [];
        $primusAppearance = is_array($primusSettings['appearance'] ?? null) ? $primusSettings['appearance'] : [];
    } catch (\Throwable $e) {
        /* migrations not run yet or dev-mode edge case — render defaults */
    }
@endphp

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700&family=Sora:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style id="primus-admin-tokens">
@import url("{webroot/public}/css/tokens.css");
@import url("{webroot/public}/css/animations.css");
</style>

<style id="primus-admin-override">
:root {
    --pr-admin-mode: admin;
@foreach($primusOverrides as $primusProperty => $primusValue)
    {!! htmlspecialchars($primusProperty, ENT_QUOTES) !!}: {!! htmlspecialchars($primusValue, ENT_QUOTES) !!};
@endforeach
    @if(!empty($primusAppearance['radius']))
        --pr-radius-multiplier: {{ (float) $primusAppearance['radius'] }};
    @endif
    @if(!empty($primusAppearance['shadow']))
        --pr-shadow-multiplier: {{ (float) $primusAppearance['shadow'] }};
    @endif
    @if(!empty($primusAppearance['font_heading']))
        --pr-font-display: "{{ $primusAppearance['font_heading'] }}", var(--pr-font-display);
    @endif
    @if(!empty($primusAppearance['font_body']))
        --pr-font-ui: "{{ $primusAppearance['font_body'] }}", var(--pr-font-ui);
    @endif
    @if(!empty($primusAppearance['font_mono']))
        --pr-font-mono: "{{ $primusAppearance['font_mono'] }}", var(--pr-font-mono);
    @endif
}
</style>

<script>
  document.documentElement.setAttribute("data-primus-theme", "{{ $primusAppearance['theme'] ?? 'dark' }}");
  document.documentElement.setAttribute("data-primus-vibrance", "{{ $primusAppearance['vibrance'] ?? 'normal' }}");
</script>

@if(!empty($primusAppearance['favicon_url']))
<script>
(function () {
  var link = document.querySelector('link[rel*="icon"]') || document.createElement("link");
  link.rel = "icon";
  link.href = "{{ $primusAppearance['favicon_url'] }}";
  document.head.appendChild(link);
})();
</script>
@endif
