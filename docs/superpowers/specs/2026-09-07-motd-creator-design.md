# MOTD Creator

Date: 2026-09-07
Status: draft, pending user review
Scope: let Minecraft server owners customize their server's MOTD from a new tab in the client panel. Carries forward the "Minecraft MOTD creator" sub-project parked in 2026-09-06-deeper-theme-customizer-design.md.

## Goal

Server owner (or subuser with `file-write`) opens their server page, clicks a new **MOTD** tab in the sub-nav, and edits the MOTD in a themed studio: live Minecraft-style preview, section-color palette, 8 templates, 59-character counter. Saving writes the `motd=` line of `server.properties` directly on the node disk. If the server is running, the UI shows "applies after restart" with a one-click Restart for users who hold power-control permissions. Servers failing the two-stage Java-Minecraft gate (egg family + Java `server.properties` sniff) get no tab.

## Non-goals

- Player Manager (kick/ban/op) — next sub-project, own spec
- Multi-game MOTDs (Rust, Bedrock, ...) — the two-stage gate covers Java Minecraft servers only in v1
- Admin-side MOTD controls or per-server MOTD overrides — server properties are the source of truth
- Rich JSON MOTDs (1.19+ protocol JSON) — legacy `§` codes only, which render on all client versions
- Scheduling / rotating MOTDs
- Server icon upload (out of scope; a later candidate feature)

## Current state

- Client panel: Primus already injects a vanilla-JS engine via `dashboard/wrapper.blade.php` (tokens.css, theme.js, widgets.js, page-scoped modules ai-fixer.js / ai-optimizer.js) and `PrimusMarker` anchors via Components.yml, including `Server.SubNavigation.AdditionalServerItems` — the exact spot the new tab lands.
- Server-page context pattern established: modules detect the server from `location.pathname.match(/\/server\/([a-zA-Z0-9]+)/)` (e.g. ai-fixer.js:14).
- PHP API pattern established: `private/routes.php` under `auth` middleware, `Shared::resolveAccessibleServer(uuid, user)` for access (root_admin / owner / subuser), subuser permission checks mirroring `/proxy/power` (routes.php:50-89).
- Wings access: `DaemonFileRepository::getContent(path)` / `putContent(path, content)` (Wings `POST /api/servers/{uuid}/files/write` — creates or updates) and `DaemonPowerRepository::send(signal)` (already wrapped by `/extensions/primus/proxy/power`).
- Verified (minecraft.wiki, server.properties page): `motd` is a single string, supports legacy formatting codes (§ or &), supports non-ASCII ("♥"), and values over ~59 characters make the server list report a communication error. There is no separate server.properties key for a second line in vanilla Java — the MOTD is one line.

## Architecture

Three small units, each independently testable:

```text
motd.js (client engine)
  |  render tab at AdditionalServerItems anchor (server pages only, MC eggs only)
  |  GET /extensions/primus/motd?server={uuidShort}
  |      -> {enabled, egg, motd, running, canRestart, limit}
  |  POST /extensions/primus/motd (json: {server, motd})  -> {ok, running, restartable}
  |  both via the existing P.api helper (theme.js:299-332): auto X-CSRF-TOKEN from
  |  meta[name=csrf-token] (fallback _token -> hidden input), auto /extensions/primus base,
  |  rejects with Error carrying .status and the {error: ...} payload
  v
 MotdController (PHP)  -- Shared::resolveAccessibleServer
                          + two-stage "is this a Java MC server?" gate (see index)
                          + file-write perm on save
  v
 DaemonFileRepository  -- read server.properties -> replace/append motd= line -> putContent
Restart: existing /extensions/primus/proxy/power {signal:'restart'} (power perms re-checked there)
```

1. **`public/js/motd.js`** (new, page-scoped, ~400-500 lines like its siblings):
   - Active only when `location.pathname` matches `/server/{uuidShort}`; re-evaluates on SPA route change (same technique as theme.js:213-215).
   - On entry: GET the motd endpoint. `enabled:false` (non-MC egg) -> render nothing; `enabled:true` -> inject a Primus-styled "MOTD" tab next to the `AdditionalServerItems` marker (icon + label), and on click open the studio overlay.
   - Studio overlay: same overlay pattern as the AI diagnose panel (full-width card, themed tokens, close on Esc/back), content:
     - live preview: mock server-list row (32x32 icon, server name, rendered MOTD with § codes applied) on the classic dark MC background
     - single-line input, character counter (green < 50, amber 50-58, red at 59, hard-blocked past 59)
     - palette: 16 color swatches (§0-§f) + formatting buttons (Bold §l, Italic §o, Underline §n, Strikethrough §m, Obfuscated §k, Reset §r) that wrap the current selection in the input
     - 8 template presets (one-click fill): Survival SMP, Skyblock, Creative, Hardcore, PvP/KitPvP, Mini-games, Community, Minimal — each a tasteful single-line § string <= 45 chars
     - save bar: dirty hint + Save; when `running`: inline banner "Your server is online — changes apply after a restart" + Restart button (only when `canRestart`); when offline: "Active when you start the server"
     - users without `file-write`: preview + current MOTD shown, inputs disabled, hint "Needs file-write permission"
   - Save flow: validate client-side (1..59 rendered chars; no control characters or backslash; UTF-8 allowed) -> `P.api("motd", {method:"POST", json:{server, motd}})` (CSRF header comes from the P.api helper automatically) -> success toast; on `running && canRestart && user clicks restart` -> existing `P.api("proxy/power", {method:"POST", json:{server, signal:"restart"}})` (same as widgets.js:360) -> "Restarting..." state (no polling; the panel status icon handles the rest).

2. **`private/Controllers/MotdController.php`** (new, namespace `Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers`):
    - `index(Request)`: `resolveAccessibleServer($request->input('server'))` (query param) -> 404 if null. Then a **two-stage "is this a Java Minecraft server?" gate** (both stages must pass for `enabled:true`):
      - **Stage 1 — egg family (no Wings call):** lowercase `egg->name`, accept if it matches `/(vanilla|paper|purpur|spigot|fabric|forge|spoon|quilt|minecraft)/` and does **not** match `/bedrock/`. Egg names are human strings on this panel (e.g. `Paper (Preview)`), so family-token matching is used instead of an exact-name list; a renamed egg that no longer matches simply gets no tab (safe direction).
      - **Stage 2 — Java properties sniff (one Wings read, reused for the current-motd fetch):** `DaemonFileRepository::getContent('server.properties')`. Note: a missing file and a down node both surface as `DaemonConnectionException` (the repo wraps Guzzle 4xx/transfer errors) — they are distinguished by `getStatusCode()`: `404` = file not present, anything else (504 default when there is no HTTP response) = node unreachable.
        - status `404` -> `enabled:false, motd:""` (Rust-style roots without `server.properties`, including test servers that reuse a Paper egg on a non-MC game)
        - any other status (node/Wings unreachable) -> `enabled:false` + `"error":"Could not reach the Wings daemon for this node."` (UI: no tab, optional toast)
        - file exists but contains no Java marker key (regex `/^(server-port|level-name|online-mode|max-players|view-distance|motd|server-ip)[[:space:]]*=/m` on the content) -> `enabled:false` (wrong file under a matching egg)
        - otherwise -> `enabled:true`; parse the `motd=` line (first match, unescape nothing — vanilla writes it raw; MC writes it once) or `""` when absent
      Returns:
      ```json
      {"enabled": true, "egg": "paper", "motd": "A Minecraft Server", "running": true, "canRestart": true, "limit": 59}
      ```
     - `running`: `server->current_state === 'running'` (panel-cached Wings status; pure DB read, no Wings call for the GET — keeps tab visibility instant).
     - `canRestart`: root_admin OR owner OR any of `control-start/control-stop/control-restart` in subuser permissions (same rule as `/proxy/power`).
    - `save(Request)`: same access guard + stage-1 egg gate (cheap; keeps the endpoint self-contained) + require root_admin, ownership, or subuser permission `file-write` -> 404/403 messages. Validate payload: string (unicode ok), length 1..59 chars, `preg_match('/[\p{C}\\\]/u')` reject (control characters and backslash would corrupt the java-properties file), no `&` normalization — store exactly as entered (MC accepts both § and &; the editor UI only emits §). Then:
      - `getContent('server.properties')`; missing file (`DaemonConnectionException` status `404`) -> 422 `{"error":"Minecraft properties file not found on this server."}` — v1 **never creates** `server.properties` (a running MC server regenerates it; creating one on a non-MC server would be damage); other statuses (unreachable) -> 502 `{"error":"Could not reach the Wings daemon for this server."}` (never blind-write).
      - content must pass the same Java-marker sniff as index -> 422 `{"error":"This server has no Minecraft properties file."}` otherwise (prevents a direct POST on an egg-shared non-MC server from ever writing).
      - if a `^motd=` line exists (first occurrence, matching what index reads — MC writes it once, but be safe): replace the value, preserving line position and every other line byte-for-byte; otherwise append `motd={value}` as the final line (with a leading newline if the file lacks a trailing one).
     - `putContent('server.properties', $new)` -> `{ok:true, running, restartable: canRestart}`.
   - Both endpoints log nothing sensitive (motd content is user-visible server data; `report()` only on Wings exceptions, matching /proxy/power).

3. **`private/routes.php`**: inside the `auth` group:
   ```php
   Route::get('/motd', [MotdController::class, 'index']);
   Route::post('/motd', [MotdController::class, 'save']);
   ```

4. **`dashboard/wrapper.blade.php`**: one added line with the next cache-bust bump:
   `<script src="{webroot/public}/js/motd.js?v=<NEXT>{timestamp}" defer></script>`

5. **Components.yml**: unchanged — the `AdditionalServerItems` anchor is already shipped.

## UI details

- Tab: sub-nav item "MOTD" with a small speech-bubble/flag SVG (matches existing inline SVG icon style in widgets.js), Primus token styling, `data-primus-tab` for testability.
- Overlay: `.pr-motd` card, `max-width: 720px`, reuses `--pr-surface`, `--pr-radius-lg`, `--pr-shadow-*`, fade/slide animation tokens (pr-fade-slide-in), Escape closes, backdrop click closes.
- Preview row: 2:1 dark rounded box, `#1b1b1b`-ish MC background via inline gradient (not a token — it's mock client chrome), fixed 16px Primus server glyph (no per-server icon fetch in v1), server name from the page (`.server-name` DOM text, already localized; GET payload as fallback), MOTD rendered by a tiny §-code renderer (split on §x and map to color/italic/etc. spans; unknown code = reset).
- Counter rule (explicit): the limit counts **rendered characters** — each `§x` pair counts as 0 (invisibility) and each visible glyph as 1. This mirrors how the 59-char wire limit behaves for padded MOTDs. Implementation: strip two-char § sequences before measuring. (Keeps users from confusing code overhead with length.)
- Templates (each a single line, §-coded, <= 45 rendered chars):
  1. Survival SMP — `§8» §l§a{srv}§r§8 «  §7Survival SMP`
  ... final 8 defined in code with the server name substituted; placeholder `{srv}` replaced with server name, re-measured against the limit (if substitution exceeds 59, fall back to the fixed string without name).
- Read-only state (no file-write): preview + "Current MOTD" line + disabled inputs + hint; no Save button.

## Error handling

| Failure | API | UI |
|---|---|---|
| Server not accessible (uuid/permission) | 404 `Server not found or not accessible.` | no tab rendered (GET failed) / toast on save |
| Gate fails: egg not Java-MC, or `server.properties` missing / non-Java | 200 `enabled:false` | no tab rendered |
| Node/Wings unreachable on GET | 200 `enabled:false` + `error` | no tab + toast "Could not load — is the node online?" |
| No file-write on save | 403 `You do not have file access to this server.` | toast, inputs stay dirty |
| Wings unreachable on save (node dropped between GET and POST) | 502 `Could not reach the Wings daemon for this server.` | toast, value untouched |
| Invalid payload | 422 with specific reason (too long / illegal character) | inline field error + counter turns red |
| CSRF mismatch | Laravel 419 | toast "Session expired — reload the page" |

## Testing

1. **Backend round-trip (curl)**: GET MC server (uuid `92cb4c95-0f50-4955-94c6-9879b01b1eb4`) returns `enabled:true` + current motd; GET Rust server (uuid `dd2cfabf-f9a8-42e5-8299-85e293310a01`) returns `enabled:false` — important: in this env both servers share the same `Paper (Preview)` egg, so the Rust negative case specifically proves the stage-2 Java-properties sniff (Rust roots have `server.cfg`/`dedicated-server.json`, no Java `server.properties`); direct POST motd save on the Rust uuid -> 422. Then on the MC server: POST a new §-colored motd; GET again returns it; read the file — assert exactly one line changed (diff of non-motd lines empty), comment lines and `max-players` etc. byte-identical; POST 60 rendered chars -> 422; POST with a control char -> 422; save as subuser without file-write -> 403 (seed a subuser for the test, then remove the grant).
2. **Puppeteer UI**: on the MC server page the tab exists, overlay opens, palette click inserts codes, preview updates live, counter colors at 50/59, hard-block at 60; on the Rust server page (uuid `dd2cfabf-f9a8-42e5-8299-85e293310a01`) the tab is absent; Save -> success toast; read-only mode for a no-file-write subuser.
3. **Restart integration (manual, server offline in this env)**: on a running server the banner + Restart button appear; clicking Restart reuses `/proxy/power` (covered by existing endpoint behavior).
4. **Regression**: dashboard and console overlays unaffected (motd.js is page-scoped; run the existing theme screenshots script after bumping the wrapper).

## Rollout

1. Implement + unit-level curl tests on the live panel (8081), sync pattern identical to the theme work (resolve placeholders, `view:clear` where views change — none for this feature, only JS/PHP/routes).
2. Verify via puppeteer on 8080/8081.
3. Bump wrapper cache-bust, rebuild `dist/primus.blueprint`.
4. Player Manager spec next (uses `DaemonCommandRepository` console commands; separate spec/plan).

## Open risks

- Egg names are human strings (`Paper (Preview)` here); the gate therefore matches Java-family tokens rather than exact names. A renamed egg that loses all tokens gets no tab (false negative — safe direction, user re-enables by renaming back or the file sniff still passes if the name token is present). Test eggs reused across games are caught by the stage-2 sniff (proven case: Rust server on a Paper egg in this env).
- Wings `putContent` rewrites the whole file; a concurrent user edit in the panel file manager could race. Probability low (single-file, one-line change); documented in the save banner as "don't edit server.properties in the file manager at the same time" — v1 keeps the read-modify-write without locking.
