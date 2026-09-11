# Marketplace Addon (Modrinth + CurseForge) — Design Spec

**Status:** pending user review
**Date:** 2026-09-10
**Supersedes:** none. First cycle of the "all 11 remaining addons" program (one addon per cycle), plus a new 12th addon the user requested: the Marketplace.
**Follows:** `2026-09-09-addon-framework-core-design.md` (framework shipped at commit `595d8c7`; this addon builds entirely on `AddonRegistry` / `AddonGate` / `PathGuard` / audit / rate limiter).

## 1. Goal

Server owners search Modrinth and CurseForge from inside the panel and install mod/plugin jars into their server with one click. Keys live server-side in panel settings (never in source, never in the browser); every request flows through the shipped addon-framework guards.

## 2. Scope

**In scope:**
- `marketplace` manifest flips `comingSoon: false` — the 12th live hub card.
- `MarketplaceClient` service: provider adapters (Modrinth, CurseForge), response normalization, 10-minute Laravel cache, download with hostname allowlist + size cap.
- `MarketplaceController`: search, versions, install endpoints (all gated).
- Client: marketplace panel (provider tabs, search results grid, version picker, install progress/toast) reachable from its hub card AND via an "Install" button in the Plugin Manager panel.
- Admin: Marketplace API-key inputs (password type, write-only) in the existing Addons admin page.
- Test-suite updates for the 13-card hub (framework-era suites assert 12).

**Out of scope (later cycles):** Mod Manager card (its panel will reuse this install UI), dependency resolution / auto-install of required libraries, modpack installs, in-place updates of installed jars ("update" badge), search filters beyond provider+type, non-Java games, and the other 10 comingSoon addons.

## 3. Architecture

Same choke point as every addon:

```
auth middleware
  → AddonGate::guard('marketplace', ...)   (enabled? canUse? → 401/403/404 JSON)
  → MarketplaceController
      search/versions: MarketplaceClient → Laravel Cache (600s) → provider API (server-side key)
      install:         perms file.create + Shared::rateLimited('addon.marketplace')
                       → AddonGate::audit('marketplace','install', target) BEFORE any write
                       → MarketplaceClient->download() (allowlisted host, size-capped, PK-zip sniff)
                       → PathGuard::resolve("<plugins|mods>/<name>.jar", ['plugins','mods'])
                       → DaemonFileRepository::putContent()
```

- **Never trust the frontend:** the client sends provider + project id + version id + type only. The backend re-derives the download URL from the provider API — the client can never supply a URL (SSRF guard). Target directory derives from `type` (`plugin`→`plugins/`, `mod`→`mods/`), never from client paths.
- **Keys:** `ThemeSetting` rows `marketplace.curseforge.key` / `marketplace.modrinth.key`, seeded at deploy time via tinker (values never committed; the admin inputs write-only). Missing key for a selected provider → `422 {"error":"<Provider> API key is not configured..."}`.
- **Rate limiting:** `Shared::rateLimited($userId, 'addon.marketplace')` counts `AddonAudit` rows (`addons.rate_limit_per_hour`, default 60) — each install writes its audit row before the download, so the limiter is self-sustaining. Search/versions are GETs, not rate-limited (providers have their own limits; the 10-min cache absorbs bursts).

### 3.1 Data

No new tables, no migration. Settings rows: the two key rows, `marketplace.max_download_mib` (default 200), `marketplace.cache_seconds` (default 600). Provider cache: `Cache::remember("primus.mkt.<provider>.<md5(query)>", ...)`.

## 4. Backend components

### 4.1 Registry manifest

```php
'marketplace' => ['id' => 'marketplace', 'title' => 'Marketplace',
  'description' => 'Search and install mods & plugins from Modrinth and CurseForge.',
  'category' => 'files', 'perms' => 'file.read', 'icon' => 'marketplace',
  'comingSoon' => false],
```

### 4.2 MarketplaceClient (Service)

- `search(string $q, string $provider, string $type): array` — Modrinth: `GET api.modrinth.com/v2/search?query=&facets=[["project_type:mod|plugin"]]`, `Authorization` + descriptive `User-Agent`. CurseForge: `GET api.curseforge.com/v1/mods/search?gameId=432&classId=<mod|plugin>&searchFilter=`, header `x-api-key`. Class ids verified once against `GET /v1/games/432` at implementation start and pinned as constants. Normalized: `{results: [{provider, id, name, summary, author, downloads, icon, updated, slug}]}` (each row carries its provider so the "All" fan-out stays unambiguous).
- `versions(string $project, string $provider, string $type): array` — newest-first `[{id, name, date, size, game_versions, filename}]`.
- `download(string $provider, string $project, string $version, string $type): array{name, bytes}` — resolves the file via the provider API, requires https host in allowlist (`cdn.modrinth.com`, `edge.forgecdn.net`, `mediafilez.forgecdn.net` — the redirect target, verified live), enforces `max_download_mib`, sniffs `PK` zip header, rejects non-`.jar` filenames; returns filename + contents (binary-safe).
- All provider HTTP failures normalize to one `MarketplaceException` → controller maps to `502 {"error": ...}` with the provider name.

### 4.3 MarketplaceController + routes

| Route | Perms | Behavior |
| --- | --- | --- |
| `GET /addons/marketplace/search?server=&q=&provider=&type=` | gate + `file.read` | Normalized results (empty array, not error, on zero hits) |
| `GET /addons/marketplace/versions?server=&provider=&project=&type=` | gate + `file.read` | Version list for picker |
| `POST /addons/marketplace/install` `{server, provider, project, version, type}` | gate + `file.create` + rate limit | Audit → download → verify → PathGuard → Wings write → `{ok, name, dir}` |

`type` ∈ {mod, plugin} validated server-side (422 otherwise). Admin route (root, existing group): `POST /addons/admin/marketplace/keys` `{curseforge?, modrinth?}` — blank fields keep existing values; never echoes values back.

## 5. Client components

- **Panel (in `addons.js`, `openPanel('marketplace')`):** provider tabs (All / Modrinth / CurseForge), type toggle (Plugin / Mod), debounced search input, results grid (icon, name, author, downloads, summary), per-result "Versions" → picker modal (version, date, size, game-version chips), Install button → `POST install` → toast + re-render of the jar table. Errors surface as toasts (`502` → provider message; `422` → key hint).
- **Entry points:** the Marketplace hub card, plus an "Install from marketplace" button in the Plugin Manager panel header (shown only when `perms.canCreate`), both opening the same panel.
- **Admin page:** existing Addons page gains a "Marketplace API keys" block — two password inputs (`placeholder: "configured"` / `"not configured"`), save via `postAdmin` to the admin route; success toast.
- **Read-only users:** marketplace card visible (gate passes on `file.read`) but Install buttons disabled with a tooltip; searches still work.

## 6. Security review checklist

- [ ] Keys only in `ThemeSetting`; no key in any response payload, source file, or client asset.
- [ ] Download URLs derived server-side from provider ids; https + hostname allowlist (SSRF guard).
- [ ] PathGuard on every target; directory derives from validated `type`, not client input.
- [ ] Size cap on downloads; `PK` header sniff; `.jar` filename suffix enforced.
- [ ] Audit row before every Wings write; rate limit active on install.
- [ ] Search results escaped via `U.esc` in every render path (incl. toasts — post-`595d8c7` rule).

## 7. Testing strategy

1. **Tinker client suite:** live-key search (`modrinth` "sodium" → hits; `curseforge` "worldedit" → hits), versions shape, cache hit (2nd call < 5ms or counter proves no re-fetch), missing-key 422 path, non-allowlisted host rejection, oversize rejection.
2. **Curl suite (cookie jar, established pattern):** gate 404 (marketplace disabled via admin toggle) → re-enable; guest 403 install; search/versions 200 shape; real install of a small known mod → jar appears via plugins list endpoint + audit row exists; rate-limit fire (limit=1 via tinker → 2nd install 429 → restore).
3. **Puppeteer suite:** 13 hub cards (12 comingSoon-era + marketplace now live: 11 locked, 2 usable), marketplace panel open, search renders results, version picker opens, install flow end-to-end (smallest real hit), Plugin-panel Install button, read-only guest sees disabled Installs.
4. **Suite updates (framework era):** `addons_ui.js` case 2 (12→13 cards / 11 locked / 2 usable), regression suite case e (12→13 cards). MOTD regression re-run green.

## 8. Rollout & packaging

- New: `private/Services/MarketplaceClient.php`, `private/Controllers/MarketplaceController.php`; edits: `AddonRegistry.php` (manifest), `private/routes.php` (+4), `public/js/addons.js` (panel + install button), `admin/controller.php` + `admin/view.blade.php` (key block), `public/css/addons.css` (grid/picker styles). Wrapper unchanged (assets already loaded; cache-bust bump to `v=1789000000`).
- Live deploy: sed/cp sync + tinker seed of the two key rows (values provided by the user in chat; never committed) + `php artisan view:clear`.
- `build.sh` → `dist/primus.blueprint`; commit message `feat: marketplace addon with modrinth and curseforge installers`.
