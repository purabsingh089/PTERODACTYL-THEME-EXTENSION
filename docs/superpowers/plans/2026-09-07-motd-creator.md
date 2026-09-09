# MOTD Creator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the approved MOTD Creator — a "MOTD" subnav tab + studio overlay on Java Minecraft server pages that live-previews and safely rewrites the `motd=` line of `server.properties` through two extension API endpoints.

**Architecture:** One new controller (`MotdController`) behind the existing `auth` middleware + `Shared::resolveAccessibleServer` guard, using Wings `DaemonFileRepository` for read-modify-write of `server.properties`. One new page-scoped client module (`motd.js` + `motd.css`) on the existing overlay engine (`P.util` / `P.api` / `P.toast` / `P.register`). No React changes, no new tables.

**Tech Stack:** PHP 8.2 (Laravel, Blueprint extension namespace), vanilla JS overlay modules, token-driven CSS. No test framework exists — verification is curl suites + Puppeteer scripts, matching every prior feature in this repo.

**Spec:** `docs/superpowers/specs/2026-09-07-motd-creator-design.md` (approved).

## Global Constraints

- Overlay-only vanilla JS. Never rebuild the Pterodactyl React bundle, never restyle existing pages.
- Two-stage Java-Minecraft gate (egg family tokens, then `server.properties` sniff). Rust-on-Paper-egg must render no tab.
- `server.properties` is NEVER created. Missing file = `enabled:false` (GET) / 422 (POST).
- Save = read-modify-write; every non-`motd` line stays byte-identical; replace first `motd=` line in place, append as final line if absent.
- Rendered-char limit 59 (strip `§x` pairs before measuring). Reject control chars + backslash on both client and server.
- **Permissions are dotted** (verified against live `app/Models/Permission.php` constants): write = `file.update`, restart = `control.start|control.stop|control.restart`. The spec's `file-write` / `control-start` wording is informal shorthand — implement with the real dotted strings so subuser tests pass.
- `running` = `server->status === 'running'` (DB column; NULL/offline in this env — the UI shows the offline hint).
- Only stage files listed in this plan. Never `git add -A` (the working tree carries unrelated changes).
- Live panel: `/var/www/pterodactyl`, extension at `.blueprint/extensions/primus`, dev port 8081, edgeproxy 8080. Login `admin@example.com` / `Password123!` (root admin, only account).
- Workspace files keep `{identifier}` / `{webroot/public}` / `{timestamp}` placeholders; live copies have them resolved (`primus`, `/extensions/primus`, none).

## Live Environment (verified this session)

- Pterodactyl v1.12.3, PHP 8.2.33, Wings 1.13.3 (online — file ops work on offline servers).
- Servers: id 2 "Minecraft Test" uuid `92cb4c95-0f50-4955-94c6-9879b01b1eb4` / short `92cb4c95` (seeded `server.properties`, fixture below); id 4 "Rust Test" uuid `dd2cfabf-f9a8-42e5-8299-85e293310a01` / short `dd2cfabf` (no file — DCE status 404). Both egg id 1 `Paper (Preview)`. Both `status = NULL`.
- Route chain: `routes/blueprint/web.php` → symlink `routes/blueprint/web/primus.php` → `.blueprint/extensions/primus/routers/web.php` — **the only live routes file**. `private/routes.php` + `app/routes.php` in the extension dir are packaging mirrors.
- Controller autoload: `app/BlueprintFramework/Extensions/primus` → symlink → `.blueprint/extensions/primus/app/`. Namespace `Pterodactyl\BlueprintFramework\Extensions\primus\Controllers\...` (workspace: `{identifier}`).
- Unauth GET on a real auth route with `Accept: application/json` → `401 application/json`. Unauth GET on a missing route → `200 text/html` (React catchall). TDD "red" asserts the flip, never 404.
- Login: `GET /auth/login` (collect `XSRF-TOKEN` cookie) → URL-decode it → `POST /auth/login` JSON `{"user":"...","password":"..."}` with `X-XSRF-TOKEN` header → 200. Panel login field is `user`, NOT `username`.
- Tinker (from `/var/www/pterodactyl`): `php artisan tinker --execute="require '/tmp/opencode/file.php';"` — a file argument only echoes source, always use `--execute` + `require`.
- Puppeteer: `NODE_PATH=/usr/local/lib/node_modules node script.js`, Chromium `/usr/bin/chromium`, login page `/auth/login` with `input[name='username']` + native value setter.
- Wrapper (live, rendered): `resources/views/blueprint/dashboard/wrappers/primus.blade.php` — CSS links end after line 17 (`palette.css`), scripts after line 41 (`shortcuts.js`). Identical copy at `.blueprint/extensions/primus/wrappers/dashboard.blade.php` — edit both, then `php artisan view:clear`.
- Subnav DOM (probe-verified): `div[class*='SubNavigation'] > div` contains 10 `<a>` tabs, `span[data-primus-marker="true"][style="display: contents;"]`, then the admin link. Server name: `h1.pr-console-title`.
- Cache-bust for this feature: `v=1788814100`.

## File Map

| File | Action | Responsibility |
| --- | --- | --- |
| `private/Controllers/MotdController.php` | Create | index (GET gate+state) + save (POST write) + gate/perm/file helpers |
| `private/routes.php` | Modify | Import + auth group with 2 routes; fix `/proxy/power` dotted perms |
| `public/js/motd.js` | Create | Page-scoped tab, studio overlay, § renderer, counter, palette, templates, save/restart |
| `public/css/motd.css` | Create | Studio styling on design tokens |
| `dashboard/wrapper.blade.php` | Modify | 2 lines: motd.css link + motd.js script (`v=1788814100`) |
| `/tmp/opencode/motd_fixtures.php` | Test | Idempotent MC `server.properties` fixture seed |
| `/tmp/opencode/motd_readprops.php` | Test | Print current `server.properties` (env `UUID`) |
| `/tmp/opencode/motd_subuser_seed.php` | Test | Seed read-only subuser on server 2 |
| `/tmp/opencode/motd_subuser_cleanup.php` | Test | Remove the subuser grant row |
| `/tmp/opencode/motd_api_test.sh` | Test | Backend curl suite |
| `/tmp/opencode/motd_ui.js` | Test | Puppeteer main UI suite |
| `/tmp/opencode/motd_subuser_ui.js` | Test | Puppeteer read-only subuser suite |
| `/tmp/opencode/motd_regression.js` | Test | Puppeteer regression suite |

---

### Task 1: Backend — MotdController, routes, proxy/power perm fix

**Files:**
- Create: `private/Controllers/MotdController.php`
- Modify: `private/routes.php`

**Interfaces:**
- Consumes: `Shared::resolveAccessibleServer(string $id, User $user): ?Server`; `DaemonFileRepository::getContent/putContent` (throws `DaemonConnectionException`; `getStatusCode()` = Wings status; 404 = missing file).
- Produces: `GET /extensions/primus/motd?server={uuid|short}` → `{enabled, egg, motd, running, canRestart, canWrite, limit, reason?, error?}`; `POST /extensions/primus/motd` json `{server, motd}` → `{ok, running, restartable}`. Task 2 consumes exactly these keys.

- [ ] **Step 1: RED — capture the missing-endpoint baseline**

```bash
curl -s -o /dev/null -w "status=%{http_code} type=%{content_type}\n" \
  -H "Accept: application/json" \
  "http://localhost:8081/extensions/primus/motd?server=92cb4c95"
```

Expected: `status=200 type=text/html; charset=utf-8` (React catchall — route missing). Step 6 must flip this to `401 application/json`.

- [ ] **Step 2: Write `private/Controllers/MotdController.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * MOTD Creator — read/write the `motd=` line of a Java Minecraft
 * server's server.properties via Wings file access.
 *
 * Two-stage "is this really Java Minecraft?" gate:
 *   1. Egg-name family tokens (DB only, no Wings call).
 *   2. Java-properties sniff on server.properties (one Wings read,
 *      reused as the current-motd fetch).
 *
 * The file is never created; a missing file means "not a Minecraft
 * server" (enabled:false on read, 422 on save).
 */
class MotdController extends Controller
{
    /** Egg-name tokens implying a Java Minecraft egg (names are human strings). */
    private const EGG_FAMILY = '/(vanilla|paper|purpur|spigot|fabric|forge|spoon|quilt|minecraft)/i';

    /** Eggs matching this are never Java Minecraft. */
    private const EGG_EXCLUDE = '/bedrock/i';

    /** At least one of these keys must exist for a Java properties file. */
    private const JAVA_MARKER = '/^(server-port|level-name|online-mode|max-players|view-distance|motd|server-ip)[[:space:]]*=/m';

    /** Vanilla server lists break past this many rendered chars. */
    public const LIMIT = 59;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $server = Shared::resolveAccessibleServer((string) $request->query('server', ''), $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        $eggName = (string) ($server->egg->name ?? '');
        $isRunning = ($server->status ?? '') === 'running';
        $subuser = $this->subuser($server, $user);
        $canWrite = $this->canWrite($user, $server, $subuser);
        $canRestart = $this->canPower($user, $server, $subuser);

        if (!$this->eggMatches($eggName)) {
            return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'egg');
        }

        try {
            $content = $this->fileRepo($server)->getContent('server.properties');
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() !== 404) {
                $e->report();

                return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'unreachable',
                    'Could not reach the Wings daemon for this node.');
            }

            return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'missing');
        }

        if (!preg_match(self::JAVA_MARKER, $content)) {
            return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'non-java');
        }

        return response()->json([
            'enabled' => true,
            'egg' => $eggName,
            'motd' => $this->currentMotd($content),
            'running' => $isRunning,
            'canRestart' => $canRestart,
            'canWrite' => $canWrite,
            'limit' => self::LIMIT,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $server = Shared::resolveAccessibleServer((string) $request->json('server', ''), $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        $subuser = $this->subuser($server, $user);
        if (!$this->canWrite($user, $server, $subuser)) {
            return response()->json(['error' => 'You do not have file access to this server.'], 403);
        }

        $motd = $request->json('motd');
        if (!is_string($motd) || mb_strlen($motd) < 1) {
            return response()->json(['error' => 'MOTD cannot be empty.'], 422);
        }

        $visible = preg_replace('/§./us', '', $motd);
        if (mb_strlen($visible) > self::LIMIT) {
            return response()->json(['error' => 'MOTD is too long (max ' . self::LIMIT . ' rendered characters).'], 422);
        }

        if (preg_match('/[\p{C}\\\\]/u', $motd)) {
            return response()->json(['error' => 'MOTD may not contain control characters or backslashes.'], 422);
        }

        if (!$this->eggMatches((string) ($server->egg->name ?? ''))) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        try {
            $content = $this->fileRepo($server)->getContent('server.properties');
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 404) {
                return response()->json(['error' => 'Minecraft properties file not found on this server.'], 422);
            }
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        if (!preg_match(self::JAVA_MARKER, $content)) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        try {
            $this->fileRepo($server)->putContent('server.properties', $this->writeMotdLine($content, $motd));
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json([
            'ok' => true,
            'running' => ($server->status ?? '') === 'running',
            'restartable' => $this->canPower($user, $server, $subuser),
        ]);
    }

    private function fileRepo(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    private function eggMatches(string $eggName): bool
    {
        $name = mb_strtolower($eggName);

        return preg_match(self::EGG_FAMILY, $name) === 1
            && preg_match(self::EGG_EXCLUDE, $name) === 0;
    }

    private function currentMotd(string $content): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (str_starts_with($line, 'motd=')) {
                return substr($line, 5);
            }
        }

        return '';
    }

    /**
     * Replace the first `motd=` line in place, or append as the final
     * line. Every other line stays byte-for-byte identical.
     */
    private function writeMotdLine(string $content, string $motd): string
    {
        $newLine = 'motd=' . $motd;

        if (preg_match('/^motd=/m', $content)) {
            return (string) preg_replace_callback(
                '/^motd=[^\r\n]*/m',
                function () use ($newLine): string {
                    return $newLine;
                },
                $content,
                1
            );
        }

        $prefix = $content !== '' && substr($content, -1) !== "\n" ? "\n" : '';

        return $content . $prefix . $newLine . "\n";
    }

    private function subuser(Server $server, User $user): ?Subuser
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return null;
        }

        return $server->subusers()->where('user_id', $user->id)->first();
    }

    private function canWrite(User $user, Server $server, ?Subuser $subuser): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }

        return in_array('file.update', (array) ($subuser?->permissions ?? []), true);
    }

    private function canPower(User $user, Server $server, ?Subuser $subuser): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }
        $perms = (array) ($subuser?->permissions ?? []);

        return in_array('control.start', $perms, true)
            || in_array('control.stop', $perms, true)
            || in_array('control.restart', $perms, true);
    }

    private function disabled(
        string $eggName,
        bool $running,
        bool $canWrite,
        bool $canRestart,
        string $reason,
        ?string $error = null
    ): JsonResponse {
        $payload = [
            'enabled' => false,
            'egg' => $eggName,
            'motd' => '',
            'running' => $running,
            'canRestart' => $canRestart,
            'canWrite' => $canWrite,
            'limit' => self::LIMIT,
            'reason' => $reason,
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }

        return response()->json($payload);
    }
}
```

- [ ] **Step 3: Write `private/routes.php` changes**

Edit the file to import `MotdController` and add two routes inside a new `auth`-middleware group (after the existing `/proxy/power` route):

```php
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\MotdController;
```

```php
// MOTD Creator — read/write the motd= line of server.properties.
// GET gates on egg + file sniff; POST additionally requires file.update.
Route::middleware(['auth'])->group(function () {
    Route::get('/motd', [MotdController::class, 'index']);
    Route::post('/motd', [MotdController::class, 'save']);
});
```

Also **fix the pre-existing bug in `/proxy/power`** (lines 70-72): `control-start` / `control-stop` / `control-restart` → `control.start` / `control.stop` / `control.restart` (dotted — verified against `app/Models/Permission.php` constants; the dashed spelling makes every subuser power check fail, so the quick-action restarts currently 403 for all subusers).

- [ ] **Step 4: Sync to live**

```bash
cp /workspace/private/Controllers/MotdController.php /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/MotdController.php
sed 's/{identifier}/primus/g' /workspace/private/routes.php > /var/www/pterodactyl/.blueprint/extensions/primus/routers/web.php
php artisan view:clear && php artisan route:clear && php artisan config:clear
```

- [ ] **Step 5: Lint**

```bash
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/MotdController.php
php -l /var/www/pterodactyl/.blueprint/extensions/primus/routers/web.php
```

- [ ] **Step 6: GREEN — route now exists (401 unauth, JSON)**

```bash
curl -s -o /dev/null -w "status=%{http_code} type=%{content_type}\n" \
  -H "Accept: application/json" \
  "http://localhost:8081/extensions/primus/motd?server=92cb4c95"
```

Expected: `status=401 type=application/json`.

- [ ] **Step 7: Authenticated curl suite (write `/tmp/opencode/motd_api_test.sh`)**

Fixture file first (`/tmp/opencode/motd_fixtures.php`), run via tinker:

```php
<?php
// Idempotent: seed a realistic Java server.properties on server 2 (uuid env UUID).
$uuid = getenv('UUID') ?: '92cb4c95-0f50-4955-94c6-9879b01b1eb4';
$server = \Pterodactyl\Models\Server::where('uuid', $uuid)->first();
if (!$server) { exit("no server\n"); }
$repo = app(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)->setServer($server);
try { $current = $repo->getContent('server.properties'); } catch (\Throwable $e) { $current = ''; }
$lines = [
  'enable-rcon=false',
  'level-seed=',
  'gamemode=survival',
  'enable-command-block=false',
  'motd=A Primus Minecraft Server',
  'query.port=25565',
  'server-port=25565',
  'level-name=world',
  'enable-status=true',
  'online-mode=true',
  'max-players=20',
  'rcon.port=25575',
  'view-distance=10',
  'white-list=false',
];
$next = implode("\n", $lines) . "\n";
if ($current !== $next) { $repo->putContent('server.properties', $next); }
echo $repo->getContent('server.properties');
```

The suite logs in once (cookie jar), then asserts:

```bash
# 1. GET state (happy path) — enabled, motd value, canWrite true (root admin)
# 2. GET Rust server dd2cfabf — enabled:false, reason:missing (422-grade file sniff, but GET returns 200 body)
# 3. GET unknown server — 404 JSON
# 4. GET no server param — 404 JSON
# 5. POST empty motd — 422
# 6. POST 60+ rendered chars — 422
# 7. POST control char (\x01) — 422
# 8. POST backslash — 422
# 9. POST valid save — ok:true, then read-back via readprops shows the new motd= line, and every OTHER line byte-identical (diff against pre-save capture)
# 10. POST Rust server — 422 (no properties file)
# 11. GET + POST with logged-in session on wrong server id — 404
# 12. POST malformed JSON body — handled as validation 422 (server field missing)
```

Assertions are `grep` on JSON body + `%{http_code}`. Each numbered test prints PASS/FAIL; script exits non-zero on any FAIL.

- [ ] **Step 8: Verify the `/proxy/power` dotted-perm fix**

Re-run the existing quick-actions subuser scenario (if a subuser test account exists in the suite) or at minimum confirm `rg -n "control-" /workspace/private/routes.php` finds nothing and the live web.php matches.

**Task 1 exit criteria:** Steps 1-8 all pass; both files `php -l` clean; curl suite 12/12 PASS.

---

### Task 2: Client — motd.js tab + studio overlay, motd.css, wrapper wiring

**Files:**
- Create: `public/js/motd.js`
- Create: `public/css/motd.css`
- Modify: `dashboard/wrapper.blade.php` (2 lines)
- Modify live: `/var/www/pterodactyl/.blueprint/extensions/primus/wrappers/dashboard.blade.php` (same 2 lines, resolved)

**Interfaces:**
- Consumes: GET/POST `motd` payloads from Task 1; `P.util` (`q qa el esc attr debounce`), `P.api`, `P.toast`, `P.on("page:view")`, `P.ready`; overlay classes `pr-fade-in`, `pr-scale-in`; design tokens `--pr-accent --pr-bg-1 --pr-bg-2 --pr-text-1 --pr-text-2 --pr-border --pr-radius --pr-dur-1 --pr-dur-2 --pr-ease --pr-success --pr-warning --pr-danger`.
- Produces: subnav tab injected after the stock subnav links (before the Primus marker span), studio overlay (backdrop + card), § code renderer, live char counter, color palette (16 § colors), 6 quick templates, save + restart actions.

- [ ] **Step 1: RED — Puppeteer probe of stock subnav DOM (no tab yet)**

Write `/tmp/opencode/motd_ui.js` skeleton to log in, go to `/server/92cb4c95`, and dump: `document.querySelectorAll("div[class*='SubNavigation'] a").length`, whether any tab text is "MOTD" (must be no), marker span position. This probe output calibrates the exact insertion selector for Step 2 code. **Do not hardcode any element order** — the marker span may sit before the admin link; tab must go after the last stock `<a>` but before any admin-link separator if present.

- [ ] **Step 2: Write `public/js/motd.js`**

Structure (IIFE, `"use strict"`, `var P = window.__primus; if (!P) return;` — same pattern as ai-fixer.js):

```
serverId()       — /\/server\/([a-zA-Z0-9]+)/
isServerPage()   — /\/server\/[a-zA-Z0-9]+/ (any subpage: console, files, ...)
state            — cached GET payload; null until fetched
ensureTab()      — find subnav container: q("div[class*='SubNavigation']") → its firstElementChild; skip if q(".pr-motd-tab") exists; build <a> with icon + "MOTD" label + class pr-motd-tab; insert after the last <a> whose href contains "/server/" (fallback: append as last child before marker span)
onPage()         — P.on("page:view") + initial run: if isServerPage() → ensureTab(); fetch state via P.api("motd?server="+id); update tab visibility (state.enabled === false → hide tab)
openStudio()     — tab click handler; overlay = pr-motd-mask > pr-motd-card (header: title + status chip + close; body: preview pane (pr-motd-preview, renders § codes live), textarea (pr-motd-input, monospace, 59-char counter with over-limit red), palette grid (16 buttons, each writes its §code at caret), template chips row, footer: Cancel + Save button + "Save & restart" (hidden unless state.running && state.canRestart))
renderMotd(text) — parser: split on §X tokens (§0-§f, §k-o formatting, §r reset), output spans with class pr-motd-c{i}; formatting codes map to CSS text-decoration/italic/bold via classes; U.esc() everything else
counter()        — visible length = text.replace(/§./g, "").length; update "n / 59"; .pr-motd-over when > 59
templates        — 6 strings using § codes (Welcome, Features, Vote, Discord, Maintenance, Event)
save(restart)    — client-side validation mirrors server (empty, >59 visible, control chars, backslash regex /[\u0000-\u001F\u007F\\]/); disable buttons + spinner during flight; POST P.api("motd", {method:"POST", json:{server, motd}}); on ok: P.toast success; if restart && state.running: POST proxy/power restart (fire-and-forget, toast); close overlay; refresh state
error handling   — GET fails → hide tab silently (P.api catch); POST 422/403/502 → inline error line in overlay footer + re-enable buttons
offline hint     — if !state.running show note in header ("Offline — MOTD applies on next start"); restart button hidden
```

- [ ] **Step 3: Write `public/css/motd.css`**

Classes: `.pr-motd-tab`, `.pr-motd-mask` (fixed, z-index var(--pr-z-modal, 9999), backdrop blur), `.pr-motd-card` (bg var(--pr-bg-1), border, radius, max-width 680px, pr-scale-in animation), `.pr-motd-preview` (bg-2, min-height, mono font, dark MC-style background #333 with rounded corners to mimic the multiplayer server list), `.pr-motd-c0..f` (16 MC colors: #FFFFFF #9AA0A6 #C6EFCE #92C579 #FF8A80 #FEC891 #F9C74F #7EE0D2 #9CE0FF #C7B3FF #FFB3D1 #64C8FF #E8D9A0 #8899A6 #3F3F3F #202020 — keep standard MC palette), `.pr-motd-c{l,i,m,n,o,r}`-based formatting classes, `.pr-motd-input`, `.pr-motd-counter`, `.pr-motd-palette button[data-code]`, `.pr-motd-tpl chip`, `.pr-motd-error`, spinner keyframes. Responsive: card max-width 92vw under 640px.

- [ ] **Step 4: Wire the wrapper (workspace + live)**

Workspace `dashboard/wrapper.blade.php`: after palette.css line add `<link rel="stylesheet" href="{webroot/public}/css/motd.css?v=1788700901{timestamp}" id="primus-motd">`; after shortcuts.js line add `<script src="{webroot/public}/js/motd.js?v=1788700901{timestamp}" defer></script>`. Live copy: same two lines with resolved paths + `v=1788814100`. Then `php artisan view:clear`.

- [ ] **Step 5: Sync assets**

```bash
cp /workspace/public/js/motd.js /var/www/pterodactyl/.blueprint/extensions/primus/public/js/motd.js
cp /workspace/public/css/motd.css /var/www/pterodactyl/.blueprint/extensions/primus/public/css/motd.css
```

- [ ] **Step 6: GREEN — Puppeteer UI suite (extend `/tmp/opencode/motd_ui.js`)**

Cases (each prints PASS/FAIL; login once, reuse page):

1. Console page `/server/92cb4c95`: tab exists with text "MOTD", clickable, visible.
2. Click tab → overlay opens: preview, textarea (prefilled with current motd from GET), counter shows "n / 59".
3. Type `§aGreen §lBold§r plain` in textarea → preview DOM contains spans with classes pr-motd-c_a-style (`pr-motd-ca`) and bold class; counter excludes § tokens.
4. Click palette button data-code="§a" → textarea value gains "§a" at caret.
5. Click template chip → textarea repopulates with template string; counter updates.
6. Over-limit: set textarea to 60 visible chars → counter red class pr-motd-over; Save disabled or click shows validation error.
7. Save happy path: set short MOTD, click Save → toast (`.pr-toast`) appears; re-GET via page fetch or reload overlay shows new value; server.properties read-back (via fixture readprops tinker) matches.
8. Cancel / mask click / Esc closes overlay without saving.
9. Files subpage `/server/92cb4c95/files` → tab still rendered (subnav persists).
10. Rust server `/server/dd2cfabf` → NO tab (enabled:false reason missing/egg sniff — GET returns 200 enabled:false, client hides tab).
11. Server overview `/` (dashboard) → no `.pr-motd-tab`.
12. Console page for server 2 shows offline hint (status NULL) — overlay header note present, restart button hidden.

- [ ] **Step 7: Lint**

```bash
node --check /workspace/public/js/motd.js
```

**Task 2 exit criteria:** UI suite 12/12 PASS; `node --check` clean; wrapper diff shows exactly 2 added lines in both copies; browser console free of `[primus]` errors during runs.

---

### Task 3: Permissions + regression + commit

**Files:**
- Test-only: `/tmp/opencode/motd_subuser_seed.php`, `/tmp/opencode/motd_subuser_cleanup.php`, `/tmp/opencode/motd_subuser_ui.js`, `/tmp/opencode/motd_regression.js`

- [ ] **Step 1: Seed read-only subuser**

`/tmp/opencode/motd_subuser_seed.php` (tinker): create user `motdguest@example.com` / `MotdGuest123!` (if absent), add row in `subusers` (server_id 2, user_id new, permissions JSON `["file.read","file.read-content","websocket.connect"]` — read-only, no file.update, no control.*). Verify `Subuser::first()` row shape.

- [ ] **Step 2: RED-GREEN subuser curl checks**

Login as subuser (curl jar #2): GET motd → `canWrite:false, canRestart:false, enabled:true` (egg + file pass). POST save → 403. (403 is the baseline behavior that must hold — write this check as RED before Task 1 controller even exists? No — Task 1 is already GREEN at this point, so this is a direct GREEN assertion.)

- [ ] **Step 3: Puppeteer subuser UI suite (`motd_subuser_ui.js`)**

Login as subuser → server 2 console: tab visible (read-only view is allowed), overlay opens, textarea shows current motd, **Save button hidden or disabled**, restart hidden. Try nothing further.

- [ ] **Step 4: Cleanup subuser**

Run `motd_subuser_cleanup.php` via tinker (removes the seeded subusers row for that user only — targeted delete of the one row we created, allowed since we created it this session; confirm scope with `->where('user_id', ...)`).

- [ ] **Step 5: Regression suite (`motd_regression.js`)**

Login as admin: (a) dashboard renders, server card grid intact, no `.pr-motd-tab` outside server pages; (b) console page keeps `.pr-ai-fixer-bar`, `.pr-term__chrome`, `.pr-graphs` — no duplicated chrome; (c) files page renders stock file manager + tab; (d) `/server/dd2cfabf` console renders fine with no tab; (e) `GET /extensions/primus/settings.json` still 200; (f) no `[primus]` console errors anywhere; (g) AI fixer button still present and opens its modal (sanity click); (h) wrapper view: `curl -s http://localhost:8081/ | grep -c 'motd.js'` → 1.

- [ ] **Step 6: Packaging + commit prep**

1. `bash /workspace/build.sh` → `dist/primus.blueprint` builds clean (exit 0).
2. `git status` — stage ONLY: `private/Controllers/MotdController.php`, `private/routes.php`, `public/js/motd.js`, `public/css/motd.css`, `dashboard/wrapper.blade.php`, plus this plan file. Never `git add -A`.
3. Commit message (repo style):

```
feat: add MOTD Creator with safe server.properties editing

Adds a MOTD subnav tab and studio overlay on Java Minecraft servers,
backed by two new /extensions/primus/motd endpoints that read and
rewrite only the motd= line of server.properties through Wings.
Includes a fix for the /proxy/power subuser permission strings
(control-start -> control.start) discovered during implementation.

Co-authored-by: monkeycode-ai <monkeycode-ai@chaitin.com>
```

4. Do NOT commit dist/ (gitignored). Do NOT push unless asked.

**Task 3 exit criteria:** subuser 403 + hidden Save verified; cleanup executed; regression 8/8 PASS; build.sh exit 0; commit created with only the 6 intended files.

---

## Risks & mitigations

- **Subnav selector drift** (Pterodactyl 1.12.3 class names): Step T2.1 probes before coding; insertion anchored to "last a[href*='/server/']" not positional index.
- **§ rendering vs server list width**: 59-char limit matches vanilla client behavior; preview approximates, not pixel-exact.
- **Offline servers**: file ops work via Wings regardless of power state (verified in prior segments); UI communicates "applies on next start".
- **Race on save**: read-modify-write inside a single request; per-request Wings calls are atomic enough for a single-line edit; no partial-line corruption possible (regex anchors `^motd=` line-wise).
- **Subuser with file.update but not control.\***: canWrite true, canRestart false — UI hides restart but shows Save; POST works, restartable:false returned. Covered by suite case 2/3.
- **Unauth 401 JSON vs React catchall 200**: Step T1.1/6 flip check catches route misregistration early.

## Self-check before completion

- [ ] Spec self-review diff applied to the plan (canWrite in GET, running from DB status, never-create, direct POST 422).
- [ ] All 3 tasks' exit criteria boxes checked with command outputs captured.
- [ ] `php -l` + `node --check` clean.
- [ ] curl 12/12, UI 12/12, subuser 3/3, regression 8/8 — all PASS.
- [ ] build.sh exit 0; staged file list matches plan exactly; dist/ untouched by git.
- [ ] Summary to user with verified evidence.

