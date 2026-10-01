# Primus — Premium AI-Powered Pterodactyl Theme

A sellable Blueprint extension that completely re-skins both the **Client** and
**Admin** panels, and adds AI-powered diagnostics/optimization features built
on top of [monkeycode-ai.net](https://monkeycode-ai.net).

> Requires the [Blueprint](https://blueprint.zip) framework
> (target: `beta-2026-08`, Pterodactyl v1.15.x).

## Highlights

| Area | What Primus adds |
| --- | --- |
| Visual | Full design-token system (color 50–950 scales, 5-step elevation, radius & spacing scales, dual fonts), dark + light mode, skeleton loaders, empty states, micro-interactions |
| Client | Restyled server list with hero game-banner cards (official capsule art for 19 games, SVG fallbacks otherwise) + live CPU/Memory badges, stacked hero cards on mobile, console, live CPU/Memory/Network area graphs, resource cards, files, backups, schedules, startup, settings pages (incl. per-server card image picker); notifications; footer/socials; announcements |
| Admin | Node Disk/Memory allocated circular gauges; restyled AdminLTE chrome |
| Power-user | Command palette (Ctrl/Cmd+K), keyboard shortcuts + overlay, onboarding tour |
| AI | **AI Server Fixer** (console), **AI Resource Optimizer** (overview), and **AI Server Builder** (dashboard overlay that plans and creates a server from a prompt using the user's own OpenAI-compatible credentials) — keys stay server-side |
| Customizer | Admin UI with color pickers, sliders, font/logo/favicon settings, 4 presets (Midnight, Aurora, Slate, Sunset), JSON export/import, white-label mode, full surface/text/semantic palette, optional login colors, density + card size controls |
| Ops | Per-user rate limits, AI request logging, usage dashboard with cost estimates |

## Installation

Download `primus.blueprint` (built archive) and install from the panel root
where the Blueprint CLI is already set up:

```bash
bpm:install primus.blueprint
```

Or install directly during development:

```bash
bpd:build primus
bpd:install primus
```

## Configuration

All settings are managed from the admin UI:

1. Visit **Admin → Extensions → Primus** (`/admin/extensions/primus`).
2. On the **Presets** tab pick a built-in preset (Midnight, Aurora, Slate,
   Sunset), or use the **Appearance** tab customizer:
   - **Colors**: theme mode, accent + hover accent, vibrance, full surface
     palette (page, card, raised, sunken, borders), text (primary /
     secondary / muted) and semantic colors (success, warning, danger, info)
   - **Login page colors** (optional): override the login card background and
     submit button — leave empty to inherit the surface / accent tokens
   - **Layout**: content density (Comfortable / Compact), server card size
     slider (0.85x–1.20x, desktop only — mobile stays 1x), and a toggle to
     hide the stock Pterodactyl copyright notice under the login card
   - **Shape**: radius / shadow intensity sliders

   Applying a preset replaces the custom colors but keeps the layout
   controls (density, card size, copyright toggle).
3. On the **Branding** tab set heading/body/mono fonts, logo URL, favicon URL
   and white-label mode.
4. On the **AI** tab configure:
   - `base_url` (defaults to `https://monkeycode-ai.net/v1`)
   - `api_key` (stored in the panel database only — never exposed client-side)
   - per-feature models (`ai.models.fix`, `ai.models.optimize`, `ai.models.notes`)
    - feature toggles (Fixer, Optimizer, Server Builder) and the per-user hourly rate limit
    - Server Builder users add their own base URL / API key / model in the
      dashboard overlay; the admin key is not reused for create-planning
5. Use **Export JSON / Import JSON** to back up or transfer presets, and
   **AI usage** to review call volume, per-model cost and error counts.

## AI privacy model

- Console output is **only** sent when the user explicitly clicks
  “Diagnose with AI”.
- Before transmission, `LogSanitizer` strips passwords, tokens, JWTs, emails,
  IPs, UUIDs, IPv6 addresses and other sensitive values.
- Requests are rate-limited per user and logged (`primus_ai_request_logs`) for
  abuse monitoring.

## Development

Assets are committed pre-built, so no compile step is needed for development.
To repackage the installable archive:

```bash
./build.sh
```

This reproduces Blueprint's `bpd:export primus` step (stage + zip) and writes
`dist/primus.blueprint`.

## Project layout

See `conf.yml` for the Blueprint bindings. Key directories:

- `private/` — PHP code (controllers, services, models, routes, migrations)
- `public/` — compiled CSS/JS/assets served to the browser
- `dashboard/` — React components injected into the client panel
- `admin/` — admin customizer views + admin CSS
- `build.sh` — packaging script (reproduces `bpd:export primus`)

## License

MIT — see `LICENSE`. The visual design is original; this project does not
contain or derive code from Nebula (proprietary) or NookTheme.
