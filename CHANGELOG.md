# Changelog

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
