{{--
  Primus · Client dashboard wrapper.
  Injected through conf.yml `dashboard.wrapper` — markup lands at the end of
  the page, outside the React bundle. This is where the token system, fonts
  and widget bootstrap scripts are shipped from (theme.css itself is compiled
  into the React bundle per Blueprint docs).
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700&family=Sora:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700&family=Sora:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"></noscript>

<link rel="stylesheet" href="{webroot/public}/css/theme.css?v=1788700901{timestamp}" id="primus-theme">
<link rel="stylesheet" href="{webroot/public}/css/tokens.css?v=1788700901{timestamp}" id="primus-tokens">
<link rel="stylesheet" href="{webroot/public}/css/animations.css?v=1788700901{timestamp}" id="primus-animations">
<link rel="stylesheet" href="{webroot/public}/css/widgets.css?v=1788700901{timestamp}" id="primus-widgets">
<link rel="stylesheet" href="{webroot/public}/css/palette.css?v=1788700901{timestamp}" id="primus-palette">
<link rel="stylesheet" href="{webroot/public}/css/motd.css?v=1788700901{timestamp}" id="primus-motd">
<link rel="stylesheet" href="{webroot/public}/css/addons.css?v=1788700901{timestamp}" id="primus-addons">

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

<script src="{webroot/public}/js/theme.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/widgets.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/resource-graphs.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/server-cards.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/ai-fixer.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/ai-optimizer.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/command-palette.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/shortcuts.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/motd.js?v=1788700901{timestamp}" defer></script>
<script src="{webroot/public}/js/addons.js?v=1789100007{timestamp}" defer></script>
<script src="{webroot/public}/js/file-trash.js?v=1789100003{timestamp}" defer></script>
<script src="{webroot/public}/js/console-upgrade.js?v=1789100004{timestamp}" defer></script>
<link rel="stylesheet" href="{webroot/public}/css/file-trash.css?v=1789100003{timestamp}">
