# Primus UX Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the 13-card addon hub into 7 cards; move trash into the Files page with DB-tracked intercept; upgrade the stock console; convert Traffic/Player managers into Player Stats; add search/install to Mod Manager + Plugin Installer; merge AI into the MOTD tab.

**Architecture:** Extension follows established patterns: `{identifier}` placeholders in workspace, `AddonGate::guard()` + `Shared::rateLimited()` + audit on every mutation, `PathGuard` jail for all file paths, `MarketplaceClient` for search/download. New: `primus_trash` table + `TrashService`, `PlayerStatsController`, fetch-intercept JS (`file-trash.js`, `console-upgrade.js`).

**Tech Stack:** PHP 8.2 (Laravel/Pterodactyl extension API), vanilla JS (no build step), Puppeteer-core + curl for tests.

## Global Constraints

- Workspace files keep `{identifier}` placeholders; never hardcode `primus` in workspace sources.
- Every addon mutation route: `auth` → `AddonGate::guard($request, '<id>', $server)` → perm check → `Shared::rateLimited($userId, 'addon.<id>')` → `AddonGate::audit(...)` BEFORE any Wings call.
- All user-controlled paths jailed; trash names `NAME_RE = /^[A-Za-z0-9_.-]+$/`, player names `/^[A-Za-z0-9_]{1,16}$/`.
- `U.esc()` on every render and toast; no `git add -A`; never commit `dist/`; never touch pre-existing dirty files (CHANGELOG.md, README.md, admin/* — verify `git status` before each commit, stage explicitly).
- Live deploy: `/var/www/pterodactyl/.blueprint/extensions/primus/` with `{identifier}` → `primus`; routes go to both `routers/web.php` and `app/routes.php`; clear `php artisan view:clear` after wrapper changes.
- Cache-bust: bump wrapper version tags after each JS/CSS change (currently `?v=1789100002`).
- MC server `92cb4c95` (uuid `92cb4c95-0f50-4955-94c6-9879b01b1eb4`, id 2), Rust `dd2cfabf` (id 4), panel `http://localhost:8081`. Admin `admin@example.com`/`Password123!`; login rate 10/min/IP.
- Puppeteer auth: browser form login (cookie injection fails); pattern in `/tmp/opencode/addon_click3.js`.
- No emoji in code/UI strings.

---

### Task 1: Trash migration + model + TrashService

**Files:**
- Create: `private/migrations/2026_09_14_000001_create_primus_trash_table.php`
- Create: `private/Models/TrashEntry.php`
- Create: `private/Services/TrashService.php`

**Interfaces:**
- Consumes: `Shared::fileRepo(Server): DaemonFileRepository` (renameFiles/deleteFiles/getDirectory), `Server::uuid`.
- Produces: `TrashService::TRASH_DIR = '.primus-trash'`; `add(Server, User, array $files): array{moved:int, entries:array}`; `listing(Server): Collection`; `restore(Server, User, int $id): array{path:string}`; `destroy(Server, int $id): void` (signature: server+id only — user not needed); `empty(Server): int`; `purge(): int`; `entryName(Server, int): ?string`.

- [ ] **Step 1: Write the migration** (model follows `private/migrations/2026_09_09_000001_create_primus_addon_audit_table.php` pattern):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('primus_trash', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36);
            $table->string('original_path', 500);
            $table->string('trash_name', 255);
            $table->boolean('is_dir')->default(false);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('deleted_by');
            $table->timestamp('created_at')->index();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('purged_at')->nullable();

            $table->index(['server_uuid', 'purged_at']);
            $table->index(['purged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('primus_trash');
    }
};
```

- [ ] **Step 2: Write the model** (namespace `...\{identifier}\Models`, `$table = 'primus_trash'`, `$timestamps = false`, fillable `[server_uuid, original_path, trash_name, is_dir, size, deleted_by, created_at, restored_at, purged_at]`, casts is_dir bool / size int / deleted_by int / three datetime).

- [ ] **Step 3: Write TrashService** (namespace `...\{identifier}\Services`). Core logic:

```php
public static function add(Server $server, User $user, array $files): array
{
    $moved = 0;
    $entries = [];
    foreach ($files as $raw) {
        $name = basename((string) $raw);
        if ($name === '' || $name === '.' || $name === '..'
            || preg_match(self::NAME_RE, $name) !== 1
            || str_starts_with(trim((string) $raw, '/'), self::TRASH_DIR)) {
            continue;
        }
        $trashName = time() . '-' . bin2hex(random_bytes(3)) . '-' . $name;
        try {
            Shared::fileRepo($server)->renameFiles('/', [
                ['from' => trim((string) $raw, '/'), 'to' => self::TRASH_DIR . '/' . $trashName],
            ]);
        } catch (\Throwable $e) {
            continue; // Wings failure: leave file in place, no row
        }
        $entries[] = TrashEntry::create([
            'server_uuid' => $server->uuid,
            'original_path' => '/' . trim((string) $raw, '/'),
            'trash_name' => $trashName,
            'is_dir' => false,
            'size' => 0,
            'deleted_by' => $user->id,
            'created_at' => now(),
        ]);
        $moved++;
    }

    return ['moved' => $moved, 'entries' => $entries];
}
```

Plus: `listing()` = rows for server where purged_at AND restored_at null, newest first. `restore()` renames `.primus-trash/{trash_name}` back to original path (strip leading `/`); on Wings failure retry as `restored-{basename}`; then set restored_at; return `['path' => '/'.$target]`. `destroy($server, $id)` = deleteFiles from `.primus-trash` + set purged_at. `empty($server)` = loop destroy over listing, count successes. `purge()` = chunkById over rows older than 14 days (not restored/purged), resolve `Server` by uuid, deleteFiles best-effort, always set purged_at (missing file drops row anyway), return count. `entry($server, $id)` = find or throw RuntimeException. `entryName($server, $id)` = basename(original_path) or null.

Note: Wings `renameFiles` with a `to` inside a non-existent `.primus-trash/` fails — the service must create the dir first via `putContent('.primus-trash/.keep', '')` wrapped in try/catch on first add (skip error = already exists).

- [ ] **Step 4: Syntax + deploy + migrate**

```bash
php -l private/migrations/2026_09_14_000001_create_primus_trash_table.php
php -l private/Models/TrashEntry.php
php -l private/Services/TrashService.php
```

Deploy the three files to `/var/www/pterodactyl/.blueprint/extensions/primus/` (same subpaths, `{identifier}` → `primus`), then (workdir `/var/www/pterodactyl`):

```bash
php artisan migrate --path=.blueprint/extensions/primus/migrations/2026_09_14_000001_create_primus_trash_table.php --force
```

Expected: `Migrating: 2026_09_14_000001_create_primus_trash_table` / `Migrated:`.

- [ ] **Step 5: Smoke via tinker** — `TrashService::purge()` returns 0, no exception.

- [ ] **Step 6: Commit** — `git add` the three files; message `feat: trash table, model and service with 14-day purge`.

---

### Task 2: Trash API controller + routes

**Files:**
- Create: `private/Controllers/TrashApiController.php`
- Modify: `private/routes.php` (replace the three legacy `addons/trash*` routes; TrashController retires)

**Interfaces:**
- Consumes: Task 1 TrashService; `AddonGate::guard('trash')`; `Shared::hasPerm(user, server, 'file.update')`; `Shared::rateLimited(userId, 'addon.trash')`; `AddonGate::audit/retarget`.
- Produces: `GET trash/list?server=` → `{ok, entries:[{id, name, originalPath, deletedAt, purgeInDays, deletedBy}], canRestore}`; `POST trash/add {server, files[]}` → `{ok, moved}`; `POST trash/restore {server, id}` → `{ok, path}`; `POST trash/destroy {server, id, confirm}` (confirm === entry name else 422) → `{ok}`; `POST trash/empty {server}` → `{ok, purged}`; `POST trash/purge` (root-admin only, not in auth group — same admin section as `addons/admin/marketplace/keys`).

- [ ] **Step 1: Write the controller** — namespace `...\{identifier}\Controllers`, class `TrashApiController` with methods `listing`, `add`, `restore`, `destroy`, `emptyTrash`, `purge`. Every method pattern (copy the established shape from `private/Controllers/TrashController.php`):

```php
$gate = AddonGate::guard($request, 'trash', $server);
if ($gate) return $gate;
```

Then per method: perm check `Shared::hasPerm($user, $server, 'file.update')` → 403 JSON on fail (mutations only); `Shared::rateLimited($user->id, 'addon.trash')` → 429; input validation → 422 (`add`: files array 1..100; `destroy`: confirm === entryName; `restore`: id > 0); `AddonGate::audit($request, $server, 'trash', '<action>', '<target>', meta)` BEFORE the service call (on service throw, `AddonGate::retarget($audit->id, 'error')` + 502). `purge` checks `$request->user()->root_admin` only. `listing` maps `purgeInDays = max(0, 14 - now()->diffInDays(created_at))`.

- [ ] **Step 2: Wire routes** — in `private/routes.php` auth group (replacing legacy trash routes):

```php
// Trash — file-manager delete interception + Files-page panel.
Route::get('/trash/list', [TrashApiController::class, 'listing']);
Route::post('/trash/add', [TrashApiController::class, 'add']);
Route::post('/trash/restore', [TrashApiController::class, 'restore']);
Route::post('/trash/destroy', [TrashApiController::class, 'destroy']);
Route::post('/trash/empty', [TrashApiController::class, 'emptyTrash']);
```

Plus `Route::post('/trash/purge', [TrashApiController::class, 'purge']);` inside the admin route group (next to `addons/admin/marketplace/keys`).

- [ ] **Step 3: Syntax + deploy + live-route copy** — `php -l`; deploy controller + routes to live `app/Controllers/` + `app/routes.php` + `routers/web.php` copy; then curl `GET /extensions/primus/trash/list?server=92cb4c95` with admin JSON-login cookie jar (pattern: `/tmp/opencode/worlds_api_test.sh`) → expect `{"ok":true,"entries":[],"canRestore":true}`.

- [ ] **Step 4: API test — add/restore/destroy/empty** — seed `trash-me.txt` at root via tinker `Shared::fileRepo($server)->putContent('trash-me.txt', 'x')` (live class namespace `primus`), then with the cookie jar:
  - `POST trash/add {server, files:["trash-me.txt"]}` → `{"ok":true,"moved":1}`; tinker `getDirectory('/')` shows `.primus-trash` entry; root listing no longer has the file.
  - `GET trash/list` → 1 entry, `purgeInDays` 13-14, name `trash-me.txt`.
  - `POST trash/restore {server, id}` → `{"ok":true,"path":"/trash-me.txt"}`; root listing shows it back.
  - Re-add → `POST trash/destroy` with wrong confirm → 422; with `confirm:"trash-me.txt"` → ok.
  - Re-add → `POST trash/empty` → `{"ok":true,"purged":1}`; `.primus-trash/` empty.

- [ ] **Step 5: Commit** — message `feat: trash api with intercept add, restore, destroy, empty, admin purge`.

<!-- CONTINUE -->

---

### Task 3: file-trash.js — fetch intercept + Files-page trash panel

**Files:**
- Create: `public/js/file-trash.js`
- Create: `public/css/file-trash.css`
- Modify: `dashboard/wrapper.blade.php` (script + link tags)

**Interfaces:**
- Consumes: `window.__primus` (`P.api`, `P.util`, `P.ready`, `P.on('page:view')`), trash endpoints from Task 2.
- Produces: global `window.fetch` wrapper intercepting the stock file manager's delete call; `.pr-trash-btn` toolbar button; `.pr-trash-panel` side panel (list/restore/destroy/empty). After page load, `document.querySelectorAll('.pr-trash-btn')` finds the button on Files pages.

- [ ] **Step 1: Write file-trash.js** — IIFE, `"use strict"`, `var P = window.__primus; if (!P) return;`. Helpers: `serverId()` (pathname `/server/([a-zA-Z0-9]+)/`), `isFilesPage()` (`/\/server\/[a-zA-Z0-9]+\/files/`).

Intercept core:

```javascript
var nativeFetch = window.fetch.bind(window);
window.fetch = function (input, init) {
  var url = typeof input === "string" ? input : (input && input.url) || "";
  var method = ((init && init.method) || (input && input.method) || "GET");
  var path = url.replace(/^https?:\/\/[^/]+/, "").split("?")[0];
  var isDelete = /\/files\/delete$/.test(path) && /post/i.test(method);
  if (!isDelete) return nativeFetch(input, init);
  var body = null;
  try { body = init && init.body ? JSON.parse(init.body) : (input && input.body ? input.body : null); } catch (e) { body = null; }
  var files = body && Array.isArray(body.files) ? body.files : null;
  if (!files || !files.length) {
    console.warn("[primus] delete intercept skipped: unrecognized body");
    return nativeFetch(input, init);
  }
  return P.api("trash/add", { method: "POST", json: { server: serverId(), files: files } })
    .then(function (res) {
      P.toast("Moved to trash", res.moved + " item" + (res.moved === 1 ? "" : "s") + " - restore from the Trash button.", "success");
      return new Response("{}", { status: 204, headers: { "Content-Type": "application/json" } });
    })
    .catch(function (err) {
      P.toast("Delete blocked", err.message || "Trash move failed; nothing was deleted.", "error");
      return new Response('{"error":"primus-trash"}', { status: 500, headers: { "Content-Type": "application/json" } });
    });
};
```

Panel UI: MutationObserver watching `document.body` (childList, subtree) calls `ensureToolbar()` which looks for the file-manager container (`div[class*='FileManager'], div[class*='files_container']`), finds the header action area (first `button` ancestor div), appends `button.pr-trash-btn` ("Trash") unless present. `openPanel()` toggles a fixed side panel `.pr-trash-panel` (header: Trash + Empty trash + close; body: rows) that loads `trash/list`, renders rows with Restore / Delete forever buttons (data-rid, data-rname), destroy uses `window.prompt("Type \"" + name + "\" to permanently delete:")` matching the typed-confirm backend. Empty calls `trash/empty` after `window.confirm`. Boot: `P.ready.then(boot)` where boot only observes on files pages; `P.on("page:view")` disconnects observer, removes panel, re-boots after 150ms.

- [ ] **Step 2: Write file-trash.css** — `.pr-trash-btn` (surface/border/text vars with fallbacks, radius 8px, padding 6px 14px, hover accent border); `.pr-trash-panel` fixed right 18px top 64px bottom 18px width 340px, flex column, z-index 60, shadow; `__head` flex row with bottom border; `__body` flex-1 overflow-y auto padding 10px; `.pr-trash-empty`/`.pr-trash-close` small bordered buttons (empty hovers red `#e5484d`); `.pr-trash-item` bordered card rows, `__name` 13px 550 weight word-break, `__meta` 11px 65% opacity, `__acts` flex gap 6px, restore hovers accent, destroy hovers red.

- [ ] **Step 3: Wrapper wiring** — in `dashboard/wrapper.blade.php` after the addons.js line:

```html
<script src="{webroot/public}/js/file-trash.js?v=1789100003{timestamp}" defer></script>
<link rel="stylesheet" href="{webroot/public}/css/file-trash.css?v=1789100003{timestamp}">
```

- [ ] **Step 4: Syntax check + deploy** — `node --check public/js/file-trash.js`; deploy js+css live (placeholder swap), sync wrapper to both live wrappers (`resources/views/blueprint/dashboard/wrappers/primus.blade.php` + `.blueprint/extensions/primus/wrappers/dashboard.blade.php`) with `v=1789100003` on the new tags, `php artisan view:clear`.

- [ ] **Step 5: Browser test** — Puppeteer: login → `/server/92cb4c95/files`; seed `trash-me.txt` via tinker; `page.evaluate` fetch POST to `/api/client/servers/92cb4c95-0f50-4955-94c6-9879b01b1eb4/files/delete` with `{root:'/',files:['trash-me.txt']}` + CSRF header → expect success toast, `trash/list` shows entry; click `.pr-trash-btn`, click Restore → file back (tinker verify root listing).

- [ ] **Step 6: Commit** — `feat: files-page trash intercept, panel and restore UI`.

---

### Task 4: PlayerStatsController (online, joins, sessions, feed, actions, allocations)

**Files:**
- Create: `private/Controllers/PlayerStatsController.php`
- Modify: `private/routes.php`

**Interfaces:**
- Consumes: `AddonGate::guard('player-stats')`, `Shared::isJava/readProperties/fileRepo/hasPerm/rateLimited`, `DaemonCommandRepository` (send), `Allocation` model, PlayersController command map, TrafficController notes logic.
- Produces: `GET player-stats?server=` → `{ok, java, running, online:[], totals:{uniquePlayers,totalJoins}, players:[{name,joins,firstSeen,lastSeen,sessions,playtimeSec,online}], feed:[{kind,name,text}], allocations:[{id,ip,alias,port,notes,primary}], canCommand, canUpdateNotes}`; `POST player-stats/command {server, action, name}`; `POST player-stats/notes {server, id, notes}`.

- [ ] **Step 1: Port PlayersController + TrafficController logic** — read `private/Controllers/PlayersController.php` (command ACTIONS map: kick/ban/pardon/op/deop/whitelist-add/whitelist-remove with `sprintf` templates, NAME_RE `/^[A-Za-z0-9_]{1,16}$/`, running-check before send, DaemonCommandRepository) and `private/Controllers/TrafficController.php` (allocations list from `$server->allocations()`, notes update perm `allocation.update`, 256-char cap). Both fold into the new controller; `index()` merges stats + allocations + perms flags.

- [ ] **Step 2: Write the controller** — namespace + class per pattern; `index()` flow:
  1. gate `AddonGate::guard($request, 'player-stats', $server)`;
  2. `[$props, $err] = Shared::readProperties($server)` — err passthrough; if `!Shared::isJava($server->egg->name, $props ?? '')` → `{'ok':true,'java':false,'running':false}`;
  3. `$running = in_array($server->status, ['running','starting'], true)`;
  4. `$log = self::readLog($server)` — `Shared::fileRepo($server)->getContent('logs/latest.log')` in try/catch, return `''` on any throw (404 included);
  5. `[$stats, $feed] = self::parseLog($log)` — line loop, strip timestamp prefix `[HH:MM:SS] [thread/INFO]:` via `/^\[[0-9:]+\].*?:\s*/` then match: `(\w{1,16}) joined the game` (join: totalJoins++, player joins++, openSessions[name]=now), `left the game` (close session: playtime += diff, record lastSeen), `^<name> text` (chat feed), `name died` (death feed). Players array: name, joins, firstSeen (first join line timestamp null in v1 — log lines carry no date; use null), lastSeen null, sessions count, playtimeSec sum, online = in openSessions. Feed entries `{kind, name, text}` capped last 50.
  6. `probeOnline($server)` — only if running: `DaemonCommandRepository->send('list')` try/catch; `usleep(700000)`; re-read log tail (last 4000 chars); regex `There are (\d+) of a max of \d+ players? online:?\s*([A-Za-z0-9_, ]*)` → names split/trim/unique/NAME_RE filter.
  7. allocations from TrafficController shape; response assembly with `canCommand = $running && hasPerm(control.console)`, `canUpdateNotes = hasPerm(allocation.update)`.

  `command()` — full gate chain (control.console perm, running check, rate limit `addon.player-stats`, ACTIONS map jail, audit before send). `notes()` — gate chain (allocation.update perm, rate limit, 256 cap, Allocation find via `$server->allocations()` or 404, audit, update).

- [ ] **Step 3: Wire routes + registry** — routes in auth group:

```php
// Player Stats — online, joins, sessions, feed, actions, allocations.
Route::get('/player-stats', [PlayerStatsController::class, 'index']);
Route::post('/player-stats/command', [PlayerStatsController::class, 'command']);
Route::post('/player-stats/notes', [PlayerStatsController::class, 'notes']);
```

Remove the retiring routes: `addons/players*`, `addons/traffic*`, `addons/console*`, `addons/motd*`, `addons/aimotd*`, `addons/marketplace/search|versions|install` (keep `admin/marketplace/keys` — admin key config stays). AddonRegistry: remove marketplace, trash, console, traffic, players, motd, aimotd manifests; add `player-stats` (title Player Stats, desc Online players, join history, sessions and allocations., perms allocation.read, category management, icon players, comingSoon false).

- [ ] **Step 4: Syntax + deploy + API test** — `php -l`; deploy controller + routes + registry (workspace `private/` → live `app/` with primus swap + routes copy). Cookie-jar tests:
  - MC: `GET player-stats?server=92cb4c95` → `{ok, java:true, running:false, totals, players, feed, allocations:[1 alloc]}` (MC offline, log may be empty).
  - Rust: `GET player-stats?server=dd2cfabf` → `{ok:true, java:false}`.
  - `POST player-stats/command {server:92cb4c95, action:"kick", name:"../x"}` → 422 invalid name; `action:"nope"` → 422 unknown action.
  - `POST player-stats/notes {server:92cb4c95, id:<MC alloc id>, notes:"primary game port"}` → ok; tinker verify.
  - Guest/403 + 429 checks per established pattern.

- [ ] **Step 5: Commit** — `feat: player stats controller with online probe, log parse, actions, allocations`.

---

### Task 5: Search tabs in Mod Manager + Plugin Installer

**Files:**
- Modify: `private/Controllers/ModsController.php` (add `search()` + `detect()`)
- Modify: `private/Controllers/PluginsController.php` (same)
- Modify: `private/routes.php`
- Modify: `public/js/addons.js` (Search/Installed tabs in openMods/openPlugins; marketplace rendering reuse)
- Modify: `public/css/addons.css` (tab styles if missing — `.pr-mkt-*` classes already exist)

**Interfaces:**
- Consumes: `MarketplaceClient::search(q, provider, type)/versions/download` (unchanged); existing `.pr-mkt-card`, `.pr-mkt-picker` CSS + `openMarketplace` render functions.
- Produces: `GET addons/mods/search?q=&loader=&version=&provider=&server=` → `{ok, results, detect:{loader, version}}`; `GET addons/plugins/search?q=&provider=&server=` → `{ok, results, detect:{platform}}`; install reuses `POST addons/marketplace/install {server, provider, project, version, type, into}` — MarketplaceController install already accepts `into` (verify; if it derives dir from type, pass `type` mod|plugin which maps mods/|plugins/). JS: `openMods`/`openPlugins` render two-tab header (Installed / Search); Search tab = input + filter chips + result grid + version picker modal (reuse marketplace functions).

- [ ] **Step 1: Backend search endpoints** — in ModsController:

```php
public function search(Request $request): JsonResponse
{
    $gate = AddonGate::guard($request, 'mods', $server);
    if ($gate) return $gate;
    $q = mb_substr(trim((string) $request->input('q', '')), 0, 80);
    if ($q === '') return response()->json(['error' => 'Empty search.'], 422);
    $provider = in_array($request->input('provider', 'modrinth'), ['modrinth', 'curseforge', 'all'], true)
        ? $request->input('provider', 'modrinth') : 'modrinth';
    $detect = $this->detect($server);
    try {
        $res = MarketplaceClient::search($q, $provider, 'mod');
    } catch (\Throwable $e) {
        return response()->json(['error' => $e->getMessage() ?: 'Search failed.'], 502);
    }
    return response()->json(['ok' => true, 'results' => $res['results'], 'detect' => $detect]);
}

private function detect(Server $server): array
{
    /* scan root + mods/ jar names for loader markers + MC version */
    $hay = '';
    try {
        foreach (['/', '/mods'] as $dir) {
            foreach (Shared::fileRepo($server)->getDirectory($dir) as $f) {
                $hay .= ' ' . basename((string) ($f['name'] ?? $f));
            }
        }
    } catch (\Throwable $e) {
        $hay = '';
    }
    $hay .= ' ' . mb_strtolower($server->egg->name ?? '');
    $loader = '';
    foreach (['fabric', 'quilt', 'neoforge', 'forge'] as $l) {
        if (str_contains($hay, $l)) { $loader = $l; break; }
    }
    $version = '';
    if (preg_match('/\b(1\.(?:[2-9]\d?|1\d)(?:\.\d+)?)\b/', $hay, $m)) {
        $version = $m[1];
    }
    return ['loader' => $loader, 'version' => $version];
}
```

PluginsController: same shape, type `plugin`, detect = platform only (egg name paper/spigot/purpur — return first match or `''`); jail for plugins listing narrows to `plugins/` only (remove `mods` from its JAIL — overlap fix).

- [ ] **Step 2: Routes** — `Route::get('/addons/mods/search', [ModsController::class, 'search']);` and `Route::get('/addons/plugins/search', [PluginsController::class, 'search']);` in auth group (GETs, no rate limit — matches marketplace search precedent).

- [ ] **Step 3: Frontend tabs** — in `public/js/addons.js`:
  - Refactor: extract the marketplace rendering internals (`renderResults`, `openVersions`, install flow) from `openMarketplace` into reusable `mkSearchUI(body, opts)` where `opts = {type, into, detect}`. `openMarketplace` becomes a thin wrapper (or is removed with the card — remove it; the hub no longer lists marketplace, but keep the function if referenced elsewhere: check `data-gomkt` handler in mods panel — update it to switch to the Search tab instead of `openMarketplace()`).
  - `renderMods`: header gains tabs `Installed` / `Search` (`.pr-panel-tabs` buttons). Installed = current table. Search = `mkSearchUI` with type mod, chips for loader (Fabric/Quilt/NeoForge/Forge/All — preselect `detect.loader`), version input (prefill `detect.version`), provider tabs (Modrinth/CurseForge/All), results grid, version picker modal, install → `addons/marketplace/install` with `type:'mod'` then refresh installed list.
  - `renderPlugins`: same with type plugin, platform detect chip, install to `plugins/`; installed table lists `plugins/` only now.
  - Keep bind-once click-guard pattern per panel.

- [ ] **Step 4: CSS** — `.pr-panel-tabs` (flex row, bordered pill buttons, `.is-active` accent) if not already present in addons.css; reuse `.pr-mkt-*`.

- [ ] **Step 5: Syntax + deploy + tests** — `node --check public/js/addons.js`; `php -l` controllers; deploy all four files live; cache-bust bump addons.js (`v=1789100004`). Cookie-jar: `GET addons/mods/search?q=sodium&server=92cb4c95` → `{ok, results:[...], detect:{loader:'fabric', version:'26.3'?}}` (server has sodium fabric jars — version regex must match `26.3` or `1.21.x` style; verify against actual jar names: `sodium-fabric-0.9.2-beta.2+mc26.3r1.jar` contains `mc26.3` — extend regex to `/(?:mc)?(1\.\d+(?:\.\d+)?)|(?:\bmc)(\d{2,3}(?:\.\d+)?)/`); empty q → 422. `GET addons/plugins/search?q=essentials&server=92cb4c95` → results. Install flow test: pick a small mod version → install → `mods/` listing gains jar (verify via tinker), audit row exists.

- [ ] **Step 6: Commit** — `feat: built-in mod/plugin search with loader auto-detect and install`.

---

### Task 6: Console upgrade (console-upgrade.js)

**Files:**
- Create: `public/js/console-upgrade.js`
- Modify: `dashboard/wrapper.blade.php` (script tag)

**Interfaces:**
- Consumes: `window.__primus` (P.api for presets? no — presets are static strings; P.toast, P.util, P.ready, `P.on('page:view')`, `socket:stats` events from resource-graphs.js).
- Produces: command history (localStorage key `primus.cmdhist.<serverId>`), preset bar (`.pr-preset-bar`), output filter (`.pr-console-filter`), player chip (`.pr-player-chip`).

- [ ] **Step 1: Write console-upgrade.js** — IIFE on `/server/{id}` root page (`isConsolePage() = /\/server\/[a-zA-Z0-9]+\/?$/.test(path)` — console is the server root route; ALSO the terminal exists on subpages? No — v1 targets `^/server/[id]$` exact). MutationObserver waits for the terminal container (`div[class*='Terminal']` or `.xterm` parent) + the command input (textarea/input inside the console area). Features:
  1. **History**: wrap the input's keydown — ArrowUp when input empty or cursor at start → replace value with previous command (hist index--), ArrowDown → next (index++); Enter → push value to history (dedupe consecutive, cap 100, localStorage). Input identified as the visible textarea in the console area (`document.querySelector('div[class*="Terminal"] textarea, form textarea')`).
  2. **Preset bar**: insert `div.pr-preset-bar` above the input row (terminal parent): chips `list`, `save-all`, `time set day`, `time set night`, `clear`, `weather clear` + 5 custom slots (localStorage `primus.presets.<server>` — long-press? v1: a `+` chip prompts for command via window.prompt, stores, renders; click chip → set input value + dispatch Enter). Deny-list `/^(stop|restart|end|halt|shutdown|kill-server)\b/` → toast error, do not send.
  3. **Filter**: `input.pr-console-filter` injected into the terminal chrome row (resource-graphs.js creates `.pr-term__chrome` — observe for it); on input, iterate visible xterm rows (`.xterm-rows > div` or `.terminal .xterm-rows div`): matching rows get `.pr-row-hit` highlight, non-matching `.pr-row-dim` opacity .25; Escape clears. Re-apply on new output via MutationObserver on the rows container (debounced).
  4. **Player chip**: `span.pr-player-chip` appended to `.pr-term__chrome`; subscribe `P.on('socket:stats')` — if frame has `players` (Pterodactyl stats frame sometimes carries currentPlayersCount) use it; else if running and every 60s, parse last `list` line from xterm DOM text; render `N players` or `--`.
  - No server-side routes needed (deny-list client-side only; presets send through the stock console input = the user's own console permission already applies).

- [ ] **Step 2: CSS** — append to `public/css/widgets.css` (console styles live there already): `.pr-preset-bar` (flex, gap 6px, margin 6px 0), `.pr-preset-chip` (small bordered pill, hover accent; `.is-custom` dashed border), `.pr-console-filter` (terminal chrome right side, dark input), `.pr-row-hit` (accent background), `.pr-row-dim` (opacity .25), `.pr-player-chip` (accent-tinted pill in chrome).

- [ ] **Step 3: Wrapper + deploy** — script tag `console-upgrade.js?v=1789100005{timestamp}` in wrapper; deploy js + css live; sync wrappers; `php artisan view:clear`; cache-bust.

- [ ] **Step 4: Browser test** — Puppeteer on `/server/92cb4c95`: `.pr-preset-bar` present with 6 chips + `+`; type `list` in input, Enter, ArrowUp repopulates `list`; filter input dims non-matching rows; deny-list: set input to `stop` + Enter via chip simulation → toast error, no send (verify via network: no console send request from preset path). Player chip shows `--` (server offline).

- [ ] **Step 5: Commit** — `feat: console command history, preset bar, output filter, player chip`.

---

### Task 7: MOTD merge (AI button in subnav tab)

**Files:**
- Modify: `public/js/motd.js` (add Generate with AI section)
- Modify: `private/Controllers/MotdController.php` (add `aiGenerate()` method — port AiMotdController::generate logic)
- Modify: `private/routes.php` (add `POST /motd/ai/generate`; retire `addons/aimotd*` routes)

**Interfaces:**
- Consumes: `MonkeyCodeClient` + `PromptBuilder::aimotdMessages` (existing), motd.js modal structure (`.pr-motd-*`), validation via MotdController::save path.
- Produces: `POST /motd/ai/generate {server, prompt}` → `{ok, motd}` (validated 8..200-char prompt; AI rate limit `Shared::rateLimited(userId,'motd.ai')` counts AiRequestLog; returns 1-3 suggestions as motd strings); frontend "Generate with AI" button + prompt field inside the MOTD creator; Apply fills the main input (never auto-saves).

- [ ] **Step 1: Backend** — in MotdController add:

```php
public function aiGenerate(Request $request): JsonResponse
{
    /* auth + server resolve same as save(); then: */
    $prompt = trim((string) $request->input('prompt', ''));
    if (mb_strlen($prompt) < 8 || mb_strlen($prompt) > 200) {
        return response()->json(['error' => 'Prompt must be 8-200 characters.'], 422);
    }
    if (Shared::rateLimited($user->id, 'motd.ai')) {
        return response()->json(['error' => 'AI rate limit reached. Try again later.'], 429);
    }
    [$props, $err] = Shared::readProperties($server);
    if ($err) return $err;
    if (!Shared::isJava($server->egg->name, $props ?? '')) {
        return response()->json(['error' => 'AI MOTD is for Java Minecraft servers.'], 422);
    }
    /* MonkeyCodeClient call with PromptBuilder::aimotdMessages($prompt) — copy the
       exact call shape from AiMotdController::generate (MonkeyCodeClient::complete(
       messages, model) returning content string; normalizeAiJson already applied);
       log AiRequestLog row per that controller's pattern. */
    return response()->json(['ok' => true, 'motd' => $suggestions]);
}
```

(Port the exact MonkeyCodeClient invocation + AiRequestLog logging from `AiMotdController::generate` — read that file first; delete AiMotdController after porting.)

- [ ] **Step 2: Route** — `Route::post('/motd/ai/generate', [MotdController::class, 'aiGenerate']);` next to the existing motd routes; remove `addons/aimotd` routes.

- [ ] **Step 3: Frontend** — in motd.js creator modal, add an "AI" section between templates and the input: prompt input (`pr-motd-ai-prompt`, placeholder "Describe the vibe...") + "Generate" button; on click POST `motd/ai/generate`; response fills a small suggestions row (up to 3 chips); clicking a chip sets the main MOTD input value (existing preview updates live); "Discard" clears. Apply/save uses the existing save button — no new save path. U.esc everything; error toasts on 422/429.

- [ ] **Step 4: Syntax + deploy + tests** — `php -l`; `node --check public/js/motd.js`; deploy MotdController + routes + motd.js live; cache-bust bump motd.js (`v=1789100006`). Cookie-jar: `POST motd/ai/generate {server:92cb4c95, prompt:"epic dragon adventure server"}` → `{ok:true, motd:"..."}`; prompt `short` → 422; Rust server → 422 java; three rapid calls → 429 (AI limit). Puppeteer: open MOTD tab, type prompt, Generate → chips render, click chip → input value changes, preview reflects.

- [ ] **Step 5: Commit** — `feat: ai motd generation merged into the motd creator tab`.

---

### Task 8: Hub cleanup + addons.js panel removal + regression

**Files:**
- Modify: `public/js/addons.js` (remove openMarketplace/openPlayers/openTraffic/openConsole/openMotdAddon/openAiMotd + their render fns + ICONS entries; add openPlayerStats)
- Modify: `public/css/addons.css` (player-stats panel styles: online chips, totals cards, feed list, allocations table, action row)
- Modify: `private/Services/AddonRegistry.php` (final 7-card state — done in Task 4; verify)
- Modify: `private/routes.php` (final route state — verify all retired routes gone)

**Interfaces:**
- Consumes: player-stats endpoints (Task 4).
- Produces: hub with 7 cards; `openPanel` dispatcher matches registry ids; PlayerStats panel `openPlayerStats()`.

- [ ] **Step 1: addons.js surgery** — remove dead functions (openMarketplace/renderers, openPlayers/renderPlayers, openTraffic/renderTraffic, openConsole/renderConsole, openMotdAddon, openAiMotd + aimotd api calls) and their `openPanel` branches; `data-gomkt` in mods/plugins panels now switches to that panel's Search tab (store per-panel active tab in a var; re-render). Add `openPlayerStats() { panelLoad("player-stats", "player-stats", renderPlayerStats); }` + `renderPlayerStats(body, payload)`: java/running empty states (existing `javaEmpty` + offline message), totals cards (unique players, total joins, online now), online chips row, player table (name, joins, sessions, playtime, online dot, action buttons kick/ban/op/whitelist when canCommand), feed list (last 50, kind icons via text prefix), allocations section (table + notes inputs when canUpdateNotes, POST player-stats/notes on save). Keep bind-once guards.

- [ ] **Step 2: CSS** — `.pr-stats-totals` (grid 3 cards), `.pr-stat-chip`, `.pr-stats-feed` (scrollable column, kind-colored left border), `.pr-stats-actions` (action button row), allocation table reuses `.pr-addons-table`.

- [ ] **Step 3: Registry + routes verification** — registry exactly: plugins, mods, worlds, player-stats, versions, icons, properties (7). Routes: no `addons/marketplace/`, `addons/players`, `addons/traffic`, `addons/console`, `addons/trash` (legacy), `addons/motd`, `addons/aimotd`; has `trash/*` (new), `player-stats*`, `mods/search`, `plugins/search`, `motd/ai/generate`. Live deploy + view:clear + cache-bust `v=1789100007`.

- [ ] **Step 4: Full regression** — Puppeteer hub click-test (adapt `/tmp/opencode/addon_click3.js`): 7 cards, all panels render (MC: mods table, plugins table, worlds, player-stats offline empty, versions, icons empty, properties form; Rust: player-stats java:false). Cookie-jar API sweep: every GET 200, every removed endpoint 404, mutations 422/429/403 cases (name jails, empty q, rate limit, guest perms). Console page: preset bar + history + filter. Files page: delete → trash → restore.

- [ ] **Step 5: Commit** — `feat: hub consolidated to 7 cards with player stats panel`.

---

### Task 9: Live deploy finalize + docs

**Files:**
- Modify: `CHANGELOG.md` is dirty pre-existing — DO NOT touch. Update `README.md`? Also dirty — skip. Docs: none needed beyond spec.

- [ ] **Step 1: Final live state check** — wrappers synced (both copies), all JS/CSS deployed, cache-bust uniform (`v=1789100007` on changed tags), `php artisan view:clear` run, `dist/primus.blueprint` rebuilt (blueprint build via `blueprint -build` or the established zip process — check `.blueprint` tooling; NOT committed).

- [ ] **Step 2: Progress ledger** — append `.superpowers/sdd/progress.md` entry: consolidation cycle summary (spec b1e8fad/16cee8f, tasks, verification results, live state).

- [ ] **Step 3: Final commit** — any remaining workspace files (routes.php, registry, addons.js, addons.css, motd.js, motd.css?, controllers) — message `feat: primus ux consolidation - trash in files, player stats, search install, console upgrade, motd ai merge`.

## Self-Review Notes (resolved during writing)

- Hub count: 13 - 6 removed + 1 merged (player-stats) = 8? No: 13 cards - marketplace, motd, aimotd, trash, console, traffic (6 removed) = 7, player-stats replaces nothing (new) but absorbs players (which was among 13) — recount: original 13 = plugins, marketplace, worlds, mods, players, traffic, console, versions, icons, trash, properties, motd, aimotd. Remove marketplace, traffic, console, trash, motd, aimotd, players (7 removed) = 6 remain + player-stats = 7 cards. Registry list in Task 4 matches: plugins, mods, worlds, player-stats, versions, icons, properties. Correct.
- `TrashService::destroy(Server, int $id)` — Task 2 controller calls `TrashService::destroy($server, $user, $id)` in the draft but interface says server+id; final: `destroy(Server $server, int $id)`; controller passes `($server, $id)`. `empty(Server $server)` similarly.
- Mods version regex: jar names use `mc26.3` format — detect() regex extended to catch `mc(\d{2,3}\.\d+)` and `1.x` styles.
- Console page is React-routed `/server/{id}` — observer must handle SPA arrival (P.on page:view re-boot), same as file-trash.
