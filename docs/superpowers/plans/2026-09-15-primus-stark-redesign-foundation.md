# Primus Stark Redesign — Phase 1 Foundation: Implementation Plan

Spec: `docs/superpowers/specs/2026-09-15-primus-stark-redesign-foundation-design.md`

Each task is self-contained and ends in a verifiable state. Deploy steps copy the workspace file
to the live extension at `/var/www/pterodactyl/.blueprint/extensions/primus/` from `/workspace`.

## Task 1 — Token overhaul

Files: `public/css/tokens.css`

1. Replace the accent block: `--pr-accent: #0050B8`, `--pr-accent-strong: #1E6FE0`,
   `--pr-accent-text: #58A6FF`, `--pr-accent-weak: rgba(0,80,184,0.18)`,
   `--pr-accent-contrast: #ffffff`.
2. Retune dark surfaces to near-black: page `#06070A`, surface `#0B0D12`, raised `#12151C`,
   sunken `#040507`, header `rgba(8,9,13,0.86)`. Retune the neutral text ramp.
3. Add shell tokens: `--pr-sidebar-w: 264px`, `--pr-sidebar-w-collapsed: 76px`,
   `--pr-topbar-h: 56px`, `--pr-container-max: 1680px`, `--pr-shell-gap: 20px`.
4. Redesign the light theme independently on a `#F6F7F9` page; keep the accent fill, set
   `--pr-accent-text: #0050B8` for light.
5. Keep every multiplier/vibrance/attribute hook intact.

Verify: `php -r` not needed; visually confirm after deploy, and confirm no token is referenced
but undefined with a grep across `public/css`.

## Task 2 — Settings keys

Files: `private/Controllers/SettingsController.php`

1. Add `layout`, `sidebar_collapsed`, `container`, `power_position` to `appearance()` defaults.
2. Add them to `$allowed` and validate: `layout` in `sidebar|topbar`, `container` in
   `flush|boxed`, `power_position` in `sidebar|header|floating`, `sidebar_collapsed` boolean.
3. Confirm they arrive in `public()` (they flow through `appearance()` automatically).

Verify: `php artisan tinker` posts a settings payload through the controller path or
`curl` the settings endpoint as an admin and confirm the keys round-trip.

## Task 3 — Shell CSS

Files: `public/css/shell.css` (new), linked from both wrappers.

1. `html[data-primus-layout="sidebar"]`: pin `#NavigationBar` as a fixed left column; stack its
   inner container; render nav items as rows with an accent active rail and injected labels;
   offset `#app > *:not(#NavigationBar)` by the sidebar width.
2. Collapsed variant driven by `data-primus-sidebar="collapsed"`.
3. `data-primus-container="flush"`: widen `ContentContainer`; boxed keeps 1200px.
4. Sub-navigation becomes a rounded in-content tab strip.
5. Power controls: sticky rail honouring `data-primus-power`.
6. Responsive: icons under 1024px, off-canvas drawer + scrim under 640px.
7. `data-primus-layout="topbar"`: sticky blurred rethemed top bar.

## Task 4 — Shell JS

Files: `public/js/shell.js` (new), linked from both wrappers.

1. Read settings; write `data-primus-*` attributes to `<html>` with fallbacks.
2. Label map for stable nav ids; inject a `.pr-navlabel` span; MutationObserver re-applies.
3. Active-item marking from `location.pathname`; observer on the nav for route changes.
4. Collapse/drawer toggles, persisted in `localStorage`, applied on boot.
5. Defensive: every lookup null-checked; on any failure, leave attributes unset so stock layout
   stays intact.

## Task 5 — Primitives

Files: `public/css/primitives.css` (new), linked from both wrappers.

Define the `pr-*` primitive set from the spec, dark+light, with focus rings and reduced-motion
support.

## Task 6 — Stock override widening

Files: `public/css/theme.css`

Widen the Tailwind neutral map and accordion/modal/dropdown/table/badge coverage; update the
`--pr-accent-text` usage where accent-coloured text is currently `--pr-accent`.

## Task 7 — Customizer layout group

Files: `admin/view.blade.php`, `admin/admin.css` (layout controls), `admin/controller.php` if it
enumerates keys.

Add a Layout panel: layout mode, container width, power placement, sidebar collapse, and wire
them to the existing save/apply pipeline.

## Task 8 — Deploy and verify

1. Run `build.sh` only if it is required by the deploy path; otherwise copy files.
2. Copy tokens/shell/primitives/theme CSS and shell.js to the live extension and to the two
   wrapper copies; bump `?v=` cache-bust on changed assets.
3. `php artisan view:clear` and `php artisan route:clear` if routes changed (they do not).
4. Puppeteer form login; check dashboard, server console, server files, account settings in dark
   and light, desktop and mobile; assert no console errors and correct attribute application.
5. Screenshot for the record.

## Verification gates

- No undefined tokens; every `var(--pr-...)` referenced resolves.
- No console errors in the browser suites.
- Stock navigation, logout and search still function.
- Customizer changes apply live and persist across reload.
- `git status` confirms only intended files changed; no `dist/` staged.
