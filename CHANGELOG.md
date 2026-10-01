# Changelog

## Unreleased

### Added
- AI Server Builder (dashboard overlay): each client user stores their own
  OpenAI-compatible base URL, API key and model, describes a server in
  plain language, previews the matched egg and resources, then creates it
  on the panel as owner via `ServerCreationService`. Admin toggle lives
  on the AI tab (`ai.builder_enabled`); keys never leave the server.
- Deeper theme customizer (Admin → Appearance): the accent-only palette is
  expanded to the full token set — Surface (page, card, raised, sunken,
  borders), Text (primary / secondary / muted) and Semantic (success,
  warning, danger, info) color pickers with live preview on the admin page.
- Optional login-page colors: override the `/auth/login` card background and
  submit button independently of the surface/accent tokens; both stay
  "inherit" (empty) until edited, with one-click reset-to-inherited.
- Content density: Comfortable (default) or Compact — tighter spacing across
  server cards, console stat cards and graphs (controls keep a 44px target).
- Server card size slider (0.85x–1.20x): scales the hero-card banner height
  and content padding via `--pr-card-scale` on desktop; mobile always stays
  at 1x (enforced on resize, no `transform` scaling so the grid stays intact).
- "Hide stock copyright notice" toggle for the login card.
- Applying a preset still replaces custom colors; the new layout controls
  (density, card size, copyright toggle) persist across preset application
  and are cleared by "Reset to defaults".

### Fixed
- Dashboard server cards in list mode overlaid the banner on the stock
  row, so leftover CPU/RAM/DISK columns leaked through and the darker
  game art (Rust) looked like a broken half-card. Cards now keep the
  hero layout in both list and grid (list is a single column); stock
  columns stay hidden as soon as a banner is attached.

### Added
- Premium `/auth/login` overlay (checkpoint, forgot, and reset too): the stock
  white Pterodactyl card is replaced with a centered glass panel, Primus mark,
  display heading, dark inputs, and a full-width accent Login button. CSS
  restyles as soon as the form is in the DOM; JS injects brand copy per route.
- Console page premium overlay: stock stat cards (Address, Uptime, CPU,
  Memory, Disk, Network in/out) get gradient surfaces, per-metric accent
  chips, uppercase micro labels and tabular mono values. The terminal
  gains a window chrome (traffic lights + Live Console + status pill) and
  the Start / Restart / Stop buttons become colored pills.
- Dashboard hero grid: server cards use a 132px game banner, overlay status
  chips, hover quick-actions, and CPU / RAM / DISK bars under the name.
  Grid is the default layout (toolbar still toggles list). Stock allocation
  and resource columns are hidden in grid mode so leftover rows no longer
  stretch the card.
- Mobile (<720px): the server list always renders stacked hero cards; the
  list/grid toggle and keyboard hint hide, quick-actions and favorites stay
  visible without hover, and leftover stock columns can never leak through.
- Real game banner art: 19 game tiles (minecraft, rust, cs, valheim,
  terraria, ark, gmod, arma, tf2, l4d, source, squad, unturned, dayz,
  factorio, satisfactory, seven-days, dont-starve, zomboid) now ship official
  capsule art as `.jpg` next to the SVG tiles; the client prefers the photo
  when one exists. These JPEGs are copyrighted game imagery for local /
  personal use — the original SVG tiles remain the redistributable fallback
  and still cover the non-game types (voice, database, web, nodejs, python,
  bot, generic, default).
- Dashboard server cards: every server row now carries a game banner image.
  Banners resolve automatically from the server's egg / nest name via a
  server-side keyword map (tiles shipped under `public/img/games/`), or from
  a custom image URL saved per server.
- Server settings page: new "Server Card Image" box with live preview, custom
  URL input, Save and Reset-to-auto actions. Only the server owner (or a
  root admin) can change the image; subusers get 403.
- Server card badges: small live CPU / memory chips on each dashboard card,
  polled from the client resources API every 30s with offline fallback ("--").
- Extension endpoints for the feature: `POST /server-cards/images` (batch
  resolve) and `POST /server-cards/image` (set / clear custom image, absolute
  http(s) URLs only — `javascript:`, `data:` and relative paths are rejected).
- Console resource graphs: stock Chart.js canvases are hidden and replaced
   with Primus token-driven CPU / Memory / Network (in + out) area charts in
   the same three-column row. Live values sit in each card header; offline
   and empty states show "—" instead of a flat zero line.
- Admin node view: Disk Space Allocated and Memory Allocated use circular
   gauges (percent in the ring, used/max below) instead of linear bars.

### Fixed
- Server lookup in `Shared::resolveAccessibleServer()` queried a non-existent
  `uuid_short` column (SQL 1054), which 500'd AI Fixer / Optimizer / power
  proxy when the console URL used the 8-character identifier. Lookup now uses
  grouped `uuid` / `uuidShort` (the actual `servers` column).
- Client `P.api()` CSRF helper now falls back to `meta[name="_token"]` and
  the hidden `_token` input, matching the admin customizer. Previously only
  `csrf-token` was read, so Diagnose / Optimize POSTs could 419.
- `P.api()` now prefixes requests with `P.identifier` instead of a hardcoded
  `/extensions/primus` path.

- Admin panel typography: Bootstrap's admin root sets `html { font-size: 10px }`,
  which shrank the rem-based `--pr-text-*` tokens to ~62% of their intended
  size. The admin wrapper now pins the text tokens to px
  (12/13/14/16/20/24) so the customizer page renders at normal readability.
- Admin customizer save flow: `csrfToken()` read `meta[name="csrf-token"]`,
  which the Pterodactyl admin layout does not emit (it uses
  `meta[name="_token"]`). POSTs to the extension endpoints failed with a 419.
  The helper now falls back to the `_token` meta / hidden input.

### Changed
- AI model selection is now fully provider-driven. The fields start empty —
  no preset models are preloaded. Setting the base URL and API key (or
  opening the page with a configured key) automatically asks the provider
  via `GET /models` (`POST /extensions/{identifier}/admin/ai/models`) and
  offers its full catalog as suggestions; a "Detect available models"
  button re-runs the lookup on demand. Any model id can also be typed
  manually for providers without a `/models` endpoint.
- `MonkeyCodeClient::modelFor()` accepts any configured model id. When no
  model is stored it uses the first id reported by the provider's catalog;
  the built-in ids are only a last resort when the catalog is unreachable.
- `SettingsController::save()` now persists emptyed model fields (nulls from
  ConvertEmptyStringsToNull) as cleared values instead of silently keeping
  the previous model.
- Model detection no longer reports a blocked endpoint as a key rejection:
  HTTP 403/404 on `/models` (common with CDN/WAF bot checks) now shows a
  warning and keeps manual model entry available; only a 401 is reported as
  an API key problem.

## v1.0.0 — 2026-08-20

### Added
- Initial release of the Primus theme for Pterodactyl (Blueprint beta-2026-08).
- Full design token system (`public/css/tokens.css`): 50–950 color scales,
  5-step elevation shadows, radius + spacing scales, dual font pairing,
  dark (default) and light modes, reduced-motion support.
- Complete client panel restyle: cards, buttons, inputs, tables, console,
  skeleton loaders, animated status badges, empty-state illustrations,
  hover-lift micro-interactions (`public/css/theme.css`, `animations.css`,
  `widgets.css`, `palette.css`).
- Admin panel restyle (`admin/admin.css`, `admin/wrapper.blade.php`) applying
  the same token system with DB-stored overrides as CSS custom properties.
- Admin theme customizer (`admin/view.blade.php` + `public/css/admin-customizer.css`):
  color pickers, radius/shadow intensity sliders, theme mode/vibrance, font
  inputs, logo/favicon URLs, white-label toggle, footer/socials and
  announcement (Markdown) editor.
- Four built-in presets: **Midnight** (default), **Aurora**, **Slate**,
  **Sunset** — plus save-as-preset, reset, and JSON export/import of custom
  presets (`private/presets/*.json`).
- AI Server Fixer: "Diagnose with AI" on the console page; logs are sanitized
  server-side and analyzed by monkeycode-ai.net (default: DeepSeek V4 Flash).
- AI Resource Optimizer: usage-aware suggestions on the server overview
  (default: Qwen 3.5 Plus, switchable to Qwen 3.8).
- AI usage dashboard in the admin panel: call volume, per-model breakdown,
  cost estimate and rate-limit configuration.
- Command palette (Ctrl/Cmd+K), keyboard shortcuts with overlay, onboarding
  tour, notification center, server search/filter/favorites, grid/list toggle.
- Hover quick-actions on server rows (start/stop/restart via the power proxy
  using `DaemonPowerRepository`).
- Packaging: `build.sh` reproduces `bpd:export primus` → `dist/primus.blueprint`.

### Security
- All AI requests are proxied server-side (PHP); API keys never reach the
  client bundle. Console logs are sanitized (passwords, tokens, JWTs, emails,
  IPs, IPv6, UUIDs) before being sent to the AI provider. Per-user rate
  limits are enforced. Settings saves are whitelisted per group/key.
