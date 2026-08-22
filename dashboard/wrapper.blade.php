{{--
  Primus · Client dashboard wrapper.
  Injected through conf.yml `dashboard.wrapper` — markup lands at the end of
  the page, outside the React bundle. This is where the token system, fonts
  and widget bootstrap scripts are shipped from (theme.css itself is compiled
  into the React bundle per Blueprint docs).
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700&family=Sora:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style id="primus-tokens">
@import url("{webroot/public}/css/tokens.css");
</style>
<style id="primus-animations">
@import url("{webroot/public}/css/animations.css");
</style>
<style id="primus-widgets">
@import url("{webroot/public}/css/widgets.css");
</style>
<style id="primus-palette">
@import url("{webroot/public}/css/palette.css");
</style>

<script>
  window.__primus = {
    version: "{version}",
    mode: "{mode}",
    webroot: "{webroot/public}",
    wspath: "{webroot}",
    identifier: "{identifier}",
    @can('viewUser')
      isAdmin: true
    @else
      isAdmin: false
    @endcan
  };
</script>

<script src="{webroot/public}/js/theme.js?v={timestamp}" defer></script>
<script src="{webroot/public}/js/widgets.js?v={timestamp}" defer></script>
<script src="{webroot/public}/js/ai-fixer.js?v={timestamp}" defer></script>
<script src="{webroot/public}/js/ai-optimizer.js?v={timestamp}" defer></script>
<script src="{webroot/public}/js/command-palette.js?v={timestamp}" defer></script>
<script src="{webroot/public}/js/shortcuts.js?v={timestamp}" defer></script>
