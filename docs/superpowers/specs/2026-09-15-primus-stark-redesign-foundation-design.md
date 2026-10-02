# Primus Stark Redesign — Phase 1: Foundation

## Context

Primus is a Blueprint extension for Pterodactyl (`/var/www/pterodactyl`, panel `http://localhost:8081`).
It currently skins its own addon surfaces (7-card hub, plugin/mod/world/icon/properties managers,
player stats, console upgrade, MOTD creator, file trash) and lightly restyles stock React pages
through a `:where()` override layer in `public/css/theme.css`.

The goal is a complete visual redesign across the whole panel, in the direction of the Stark
Pterodactyl theme: a near-black base, one strong accent that repaints every surface and state,
navigation moved into a sidebar, an edge-to-edge page container, pinned power controls, generous
corner radius, and light/dark designed separately rather than inverted.

Work is split into four phases, each with its own spec and plan:

1. **Foundation** (this document) — tokens/palette, shell, primitives, editor foundation.
2. **Primus surfaces** — rebuild the hub and all addon panels on the new primitives.
3. **Stock panel** — reskin every remaining stock Pterodactyl page.
4. **Live design editor** — full Stark-grade editor with presets and per-surface control.

A backup of the pre-redesign theme was taken before any work:
git tag `backup/pre-stark-20260915-163545` and archive
`/root/backups/primus-theme-20260915-163545.tar.gz` (live extension, wrappers, routes).

## Goals

- Replace the current violet/indigo identity with a near-black + electric blue palette, dark and
  light designed independently.
- Introduce a real shell: left sidebar navigation, flush page container, pinned power controls,
  with responsive behaviour down to mobile.
- Establish a shared component primitive vocabulary (`pr-*`) that phases 2–4 build on.
- Extend the existing admin customizer so the new shell is configurable (layout, collapse,
  container width, power-control placement) and persisted through the settings API.

## Non-Goals

- Rebuilding the addon panels themselves (phase 2).
- Reskinning account/auth/admin stock pages beyond what tokens inherit (phase 3).
- The full drag-and-drop live editor with per-surface pickers and preset sharing (phase 4).
- Rebuilding `dist/primus.blueprint` (needs panel-wide Blueprint developer mode; skipped).

## Architecture

### Token layer (`public/css/tokens.css`)

Single source of truth, already established. Changes:

- **New accent scale.** `--pr-accent` becomes the fill colour (`#0050B8`), `--pr-accent-strong`
  the hover fill (`#1E6FE0`), and a new `--pr-accent-text` (`#58A6FF`) carries accent-coloured
  text and icons on dark, because the fill blue is too dark to read as text on near-black.
- **Near-black surfaces.** Dark `--pr-bg-950: #06070A` (page), `--pr-bg-900: #0B0D12` (surface),
  `--pr-bg-800: #12151C` (raised), with low-alpha white borders.
- **Neutral text ramp** retuned for the darker base: primary `#F2F4F8`, secondary `#A7AEBD`,
  muted `#6C7382`, faint `#4A505C`.
- **Light theme designed separately** on a `#F6F7F9` page rather than inverting dark.
- **New shell tokens:** `--pr-sidebar-w`, `--pr-sidebar-w-collapsed`, `--pr-topbar-h`,
  `--pr-container-max`, `--pr-shell-gap`, `--pr-power-radius`.
- Semantic state colours (success/warning/danger/info) retuned to sit on near-black.
- All existing multiplier tokens (`--pr-radius-multiplier`, `--pr-shadow-multiplier`,
  `--pr-card-scale`) and the `data-primus-*` attribute hooks are preserved so the existing
  customizer and presets keep working.

### Shell layer (`public/js/shell.js` + `public/css/shell.css`)

Pterodactyl renders a React SPA into `#app`. `NavigationBar` and the page content are siblings
inside `#app`; page content is wrapped by `ContentContainer` (max-width 1200px, `mx-4`/`mx-auto`).

The shell is implemented **CSS-first**: the stock navbar element is repositioned and restructured
with CSS keyed off `data-primus-*` attributes on `<html>`. No React nodes are replaced, so
navigation, logout, search and tooltips keep their stock behaviour.

`shell.js` responsibilities:

- Read `appearance.layout`, `appearance.sidebar_collapsed`, `appearance.container`,
  `appearance.power_position` from the runtime settings and mirror them onto `<html>` as
  `data-primus-layout`, `data-primus-sidebar`, `data-primus-container`, `data-primus-power`.
- Inject a text label into each stable nav item (`#NavigationDashboard`, `#NavigationAdmin`,
  `#NavigationAccount`, `#NavigationLogout`, search container, plus server sub-nav links),
  mapping by element id with a translated fallback. Labels are re-applied through a
  `MutationObserver` so a React re-render cannot strip them permanently.
- Mark the active nav item from `location.pathname`, updated on route change.
- Provide a collapse toggle (desktop) and a drawer toggle (mobile), persisted in local storage
  and mirrored onto `<html>`.

`shell.css` responsibilities:

- Sidebar layout: pin `#NavigationBar` to a fixed left column of `--pr-sidebar-w`, stack its
  inner flex row vertically, render nav items as icon+label rows with active accent rail, and
  offset `#app` content by the sidebar width.
- Topbar layout: retain the stock horizontal bar but retheme it (sticky, blurred, accented).
- Flush container: raise `ContentContainer`'s max-width to `--pr-container-max` and reduce side
  gutters so content sits near the edges; boxed mode keeps the stock 1200px centred column.
- Sub-navigation strip: pin it inside the content column as a rounded, scrollable tab bar.
- Pinned power controls: on server pages, the power action group is lifted into a sticky rail
  (sidebar or header depending on `power_position`) with stop/restart visual hierarchy preserved.
- Responsive: below 1024px the sidebar collapses to icons; below 640px it becomes an off-canvas
  drawer behind a hamburger, with a scrim.

### Primitives layer (`public/css/primitives.css`)

A shared vocabulary consumed by phases 2–4 and available to addons immediately:

`.pr-card`, `.pr-card__header`, `.pr-card__body`, `.pr-btn` (+ `--primary`, `--ghost`, `--danger`,
`--icon`), `.pr-input`, `.pr-field`, `.pr-tabs` / `.pr-tab`, `.pr-table`, `.pr-modal` /
`.pr-modal__scrim`, `.pr-badge`, `.pr-chip`, `.pr-toolbar`, `.pr-section`, `.pr-stat`,
`.pr-empty`, `.pr-skeleton`, `.pr-divider`, `.pr-kbd`.

Each primitive is built only from tokens, ships dark and light variants, has a visible
`:focus-visible` ring, and honours `prefers-reduced-motion`.

### Stock override layer (`public/css/theme.css`, extended)

The existing `:where()` map from Pterodactyl's Tailwind neutrals onto tokens is kept and widened:
additional neutral steps, `bg-black`, hover states, `rounded`/border utilities, dialog/dropdown
surfaces, tables, badges and progress bars are mapped so pages the theme has not rebuilt already
read as the new design. Specificity is kept low so the customizer and primitives win.

### Settings and editor foundation

New `appearance` keys, validated and clamped server-side:

| Key | Type | Values | Default |
| --- | --- | --- | --- |
| `layout` | enum | `sidebar`, `topbar` | `sidebar` |
| `sidebar_collapsed` | bool | — | `false` |
| `container` | enum | `flush`, `boxed` | `flush` |
| `power_position` | enum | `sidebar`, `header`, `floating` | `sidebar` |

These are added to the settings `$allowed` list, the `appearance()` defaults, and the public
payload, and surfaced in the existing admin customizer as a Layout group alongside the current
appearance controls. The full editor lands in phase 4.

## Data Flow

`ThemeSetting` (DB) → `SettingsController::public()` → `window.__primus.settings` → `shell.js`
applies attributes/flags → `shell.css` and `tokens.css` render. The customizer writes back with
`POST /extensions/primus/settings` (already rate-limited and admin-gated) and the client
re-applies immediately.

## Error Handling

- Missing or malformed appearance keys fall back to defaults; enums are validated server-side and
  clamped client-side, so an unknown layout value cannot break the shell.
- `shell.js` is defensive: every DOM lookup is null-checked, and failure leaves the stock layout
  fully intact (no partial transform).
- If the settings request fails, the last applied attributes remain and a toast reports it.

## Testing

- Server: settings validation accepts only known enum values and clamps out-of-range numbers.
- Client: unit-level checks that each layout/container/power value maps to the expected
  `<html>` attribute, and that an unknown value falls back to default.
- Browser (Puppeteer, form login): sidebar renders with labels, active state follows navigation,
  collapse toggle persists across reload, mobile viewport shows the drawer, content is offset
  correctly, and no console errors are logged. Verify on dashboard, a server console page, a
  server files page, and account settings.
- Visual: screenshot dark and light at desktop and mobile widths.

## Risks

- **React re-renders** may strip injected labels; mitigated with a MutationObserver and by
  injecting only attributes/child spans, never replacing React-owned nodes.
- **Selector fragility** against Pterodactyl's Tailwind classes; mitigated by anchoring on stable
  ids (`#NavigationBar`, `#SubNavigation`, `#NavigationDashboard`) first and classes second.
- **Specificity conflicts** with the bundle's utilities; mitigated by the established `:where()`
  plus explicit `#app` scoping where the bundle uses utilities.
- **Power-control relocation** could hide a destructive action; the pinned rail keeps all controls
  present and the stop/kill deny-list from the existing console work is unchanged.
