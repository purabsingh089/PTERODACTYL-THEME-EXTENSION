# World Manager Addon — Design Spec

**Status:** pending user review
**Date:** 2026-09-12
**Supersedes:** none. Cycle 2 of the "all 11 remaining addons" program (one addon per cycle). Marketplace shipped at `7fbe39b`.
**Follows:** `2026-09-09-addon-framework-core-design.md` (framework) and `2026-09-10-marketplace-addon-design.md` (install/overlay patterns). Approach A — same choke point as Plugin Manager.

## 1. Goal

Server owners list Minecraft world folders, switch the active world (`level-name`), archive a world to the server root, and delete unused worlds — all from the Addons overlay, gated by `AddonGate` / `PathGuard` / audit-before-Wings.

## 2. Scope

**In scope:**
- `worlds` manifest flips `comingSoon: false` — the hub card unlocks (still 13 cards; locked count 11 → 10; usable 2 → 3).
- `WorldsController`: list, switch, backup, delete (all gated).
- Client: World Manager panel in `addons.js` (table + Active badge + Switch / Backup / Delete-with-confirm).
- Minecraft-only empty state (same egg + `server.properties` sniff as MOTD).
- Test-suite count updates (3 usable / 10 locked).

**Out of scope (later cycles):** create empty world, zip/tar import, auto-restart after switch, a dedicated `worlds-backups/` folder, in-place restore from archive, non-Java games, and the other 10 comingSoon addons.

## 3. Architecture

```
auth middleware
  → AddonGate::guard('worlds', ...)          (enabled? canUse? → 401/403/404 JSON)
  → WorldsController
      GET list:     egg+properties sniff → getDirectory('/') → level.dat probe → {worlds, current, perms, java}
      POST switch:  file.update → audit → rewrite level-name= in server.properties (hardcoded path)
      POST backup:  file.create + rate limit → audit → compressFiles('/', [world + grouped dims])
      POST delete:  file.delete + rate limit → refuse if active → confirm==name → audit → deleteFiles
```

- **Never trust the frontend:** the client sends only `{server, name}`. World identity is a single path segment validated server-side. `server.properties` is a hardcoded path (never client-supplied), matching MOTD.
- **Log-intent-then-act:** every mutation writes `AddonGate::audit('worlds', ...)` BEFORE the Wings call.
- **No auto-restart:** switch writes `level-name` only; toast "restart required". Power stays with the existing hover actions / console.

### 3.1 Data

No new tables. No new ThemeSetting keys beyond the existing `addons.worlds.enabled` (already default-true). Rate limit reuses `addons.rate_limit_per_hour` (default 60) via `Shared::rateLimited($userId, 'addon.worlds')` on backup and delete only (switch is a cheap properties rewrite, not rate-limited).

## 4. Backend components

### 4.1 Registry manifest

Move `worlds` from `$soon` into `$live`:

```php
'worlds' => [
    'id' => 'worlds',
    'title' => 'World Manager',
    'description' => 'List, switch, backup and delete Minecraft world folders.',
    'category' => 'management',
    'perms' => 'file.read',
    'icon' => 'worlds',
    'comingSoon' => false,
],
```

Hub still returns 13 manifests; `comingSoon` false makes the card clickable.

### 4.2 What is a world

A **world** is a top-level directory whose listing contains a file named `level.dat`.

Detection (bounded):
1. `getDirectory('/')` once.
2. Skip files; skip names in the skip-set: `plugins`, `mods`, `config`, `cache`, `libraries`, `versions`, `logs`, `crash-reports`, `dumps`, `defaultconfigs`, `kubejs`, `scripts`, `.cache`, `tmp`, `temp`.
3. Cap remaining candidate dirs at 40.
4. For each candidate, `getDirectory($name)` and look for a file entry `level.dat`. Missing/404 dir → skip.

**DIM grouping:** if both `X` and `X_nether` (or `X_the_end`) exist as worlds, the DIM folders are attached to `X` as `dims: ['X_nether', ...]` and are **not** listed as their own rows. Matches vanilla (`world` / `world_nether` / `world_the_end`) and custom overworlds that follow the same suffix.

**Active world:** parse `level-name` from `server.properties` (same Wings read as the Java sniff). If the key is missing, default `world` (vanilla default). The matching overworld row gets `active: true`.

**Java gate (GET):** reuse MOTD's egg-family tokens + `JAVA_MARKER` on `server.properties`. Non-Java (Rust, Bedrock, missing file) → `200 {ok:true, java:false, worlds:[], current:null, perms}` so the panel can render an empty state. Mutations on non-Java → `422 {"error":"This server has no Minecraft properties file."}`.

### 4.3 Name jail

Client `name` must:
- Be a single path segment matching `^[A-Za-z0-9][A-Za-z0-9._-]*$` (no slash, no `..`, no leading dot).
- Pass `PathGuard::resolve($name)` (empty allowed-roots — first-segment jail is the regex + skip-set).
- Not be in the skip-set.
- Refer to an overworld actually present in the list (not a DIM name, not a made-up name). Switch/backup/delete all re-detect; 422 if the name is not a listed overworld.

`server.properties` is never PathGuard'd — hardcoded string, same as MotdController.

### 4.4 WorldsController + routes

| Route | Perms | Behavior |
| --- | --- | --- |
| `GET /addons/worlds?server=` | gate + `file.read` | `{ok, java, worlds:[{name,size,modified,active,dims[]}], current, perms:{canUpdate,canCreate,canDelete}}`. Size = overworld dir size from the root listing (dims not summed). |
| `POST /addons/worlds/switch` `{server,name}` | gate + `file.update` | Audit `switch` target=`server.properties` meta=`{from,to}` → rewrite only the `level-name=` line (MOTD's line-replace pattern) → `{ok, current, restart:true}`. Name must already exist. |
| `POST /addons/worlds/backup` `{server,name}` | gate + `file.create` + rate limit | Audit `backup` target=`$name` → `set_time_limit(900)` → `compressFiles('/', [name, ...dims])` → `{ok, archive}` where `archive` is the filename Wings returned. Archive lands at server root (Wings typically emits `.tar.gz`; we do not rename). |
| `POST /addons/worlds/delete` `{server,name,confirm}` | gate + `file.delete` + rate limit | `confirm` must equal `name` (422 otherwise). Refuse if `name === current` (`409 {"error":"Cannot delete the active world. Switch away first."}`). Audit `delete` target=`$name` meta=`{dims}` → `deleteFiles('/', [name, ...dims])` → `{ok}`. |

Error convention matches plugins/marketplace: `{error}` with 401/403/404/409/422/429/502.

### 4.5 server.properties rewrite

Copy MotdController's line-replace: if a `level-name=` line exists, replace it; if not, append `level-name=<name>\n`. Value is the already-jailed overworld name (no escaping beyond rejecting the name jail). File is never created from scratch — missing file is the Java-gate 422.

## 5. Client components

- **`openPanel('worlds')`** in `addons.js` (alongside plugins + marketplace). Overlay body: table of worlds (name, size, modified, Active badge, dims as muted chips). Per-row actions gated on `perms`: Switch (`canUpdate`, hidden on the active row), Backup (`canCreate`), Delete (`canDelete`) with typed-confirm modal (type the world name), same visual pattern as plugin delete.
- **Non-Java:** `java:false` → empty state "World Manager is for Java Minecraft servers." — not a crash, not a fake table.
- **Toasts:** every server-derived string through `U.esc` (post-`595d8c7` rule), including archive filenames and error messages. Switch success: "Active world set to {name}. Restart the server to load it."
- **Read-only:** table visible, action buttons omitted.
- **CSS:** token-driven additions in `addons.css` (active badge, dim chips). No restyle of existing plugin/marketplace rules.
- **Hub:** `openPanel` grows one branch; worlds icon already exists in `ICONS`.
- **Admin page:** no new block (enable toggle + audit viewer already cover worlds).

## 6. Security review checklist

- [ ] Every route behind `auth` + `AddonGate::guard('worlds')`.
- [ ] World `name` is a single jailed segment; skip-set; must exist as a listed overworld; DIM names not accepted as `name`.
- [ ] `server.properties` path is hardcoded; client cannot point the rewrite elsewhere.
- [ ] Audit row before every Wings mutation (switch/backup/delete).
- [ ] Delete: server-side `confirm === name`; refuse the active world (409).
- [ ] Rate limit on backup and delete.
- [ ] Backup timeout bounded (`set_time_limit(900)`); compress root is `'/'` with an explicit file list, never a client-supplied root.
- [ ] Render paths + toasts escaped via `U.esc`.
- [ ] Non-Java servers cannot switch/backup/delete (422).

## 7. Testing strategy

1. **Curl suite (cookie jar, established pattern):** Java MC server 2 (`92cb4c95`): list shape (at least `world` with `active:true`, dims if present); switch to a second world if one exists (or skip with note) then switch back; backup `world` → archive name returned + file appears in root listing; delete refused on active (409); delete of a non-active world requires confirm; guest 403 on backup/delete; disabled addon 404; rate-limit fire on backup (limit=1). Rust server 4 (`dd2cfabf`): GET `java:false`, worlds empty; POST switch → 422.
2. **Puppeteer suite:** hub 13 cards / 10 locked / 3 usable; worlds card opens the table; Active badge on current; Switch toast; Backup toast with archive name; Delete modal requires typed name; guest sees table without action buttons; Rust: empty-state copy, no crash.
3. **Suite updates:** `addons_ui.js` / `addons_admin_ui.js` / `motd_regression.js` / `marketplace_ui.js` usable/locked counts 2→3 / 11→10. MOTD + marketplace regression re-run green.
4. **Cleanup:** leftover backup archives from the suite deleted via Wings; no guest grant left behind; active world restored to `world`.

## 8. Rollout & packaging

- New: `private/Controllers/WorldsController.php`. Edits: `AddonRegistry.php` (manifest), `private/routes.php` (+4), `public/js/addons.js` (panel), `public/css/addons.css` (badge/chips). Wrapper cache-bust bump for addons.js/css (live only; workspace template stays dirty).
- Live deploy: sed/cp sync of controller to both `app/` and `private/` copies + js/css copy + `view:clear`.
- `build.sh` → `dist/primus.blueprint`; commit message `feat: world manager with list, switch, backup and delete`.
