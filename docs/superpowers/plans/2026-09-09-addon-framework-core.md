# Addon Framework Core + Plugin Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the addon framework core (AddonRegistry, AddonGate choke point, PathGuard path jail, audit log, dedicated admin page, client Addons hub tab) plus the first real addon — the Plugin Manager — per the approved spec `docs/superpowers/specs/2026-09-09-addon-framework-core-design.md`.

**Architecture:** One central `AddonRegistry` service holds declarative manifests for all 12 addons (1 live, 11 comingSoon). Every addon route funnels through a single `AddonGate::guard()` call chain (enabled → permission) and every file path through `PathGuard::resolve()`. Destructive actions write an audit row before the Wings call. Client is one page-scoped `addons.js` + `addons.css` overlay hub on the existing subnav/overlay engine. Admin gets a dedicated Blade page with toggle cards and an audit viewer.

**Tech Stack:** PHP 8.2 / Laravel (Blueprint extension namespace, `Pterodactyl\BlueprintFramework\Extensions\{identifier}\...`), vanilla JS overlay modules, `ThemeSetting` KV store, one new migration (`primus_addon_audit`), Wings `DaemonFileRepository`. No test framework — verification is tinker assertions + curl suites + Puppeteer (matching every prior feature).

## Global Constraints

- Overlay-only vanilla JS. Never rebuild the React bundle, never restyle stock pages.
- Never trust the frontend: server ids via `Shared::resolveAccessibleServer`, every path via `PathGuard`, permissions dotted and checked server-side (`file.read`, `file.update`, `file.delete`, `file.create` — verified against live `Permission.php`).
- Audit row written BEFORE every destructive Wings call (log-intent-then-act).
- No `..`, null bytes, absolute paths, backslashes, or jail escapes reach Wings (PathGuard rejects all).
- Upload: `.jar` only, size cap default 100 MiB (`addons.plugins.max_upload_mib`), content delivered as base64 through the gated endpoint (never multipart to Wings directly from the browser).
- Rate limit mutations: `Shared::rateLimited($userId, 'addon.plugins')`, default 60/h via `addons.plugins.rate_limit_per_hour` setting.
- Workspace files keep `{identifier}` / `{webroot/public}` / `{timestamp}` placeholders; live copies resolve to `primus` / `/extensions/primus`. Live routes file is `/var/www/pterodactyl/.blueprint/extensions/primus/routers/web.php` (sed-synced from `private/routes.php`).
- Live controller autoload dir: `.blueprint/extensions/primus/app/Controllers/` (workspace `private/Controllers/`); sync with `sed 's/{identifier}/primus/g'`.
- Only stage files listed in this plan. Never `git add -A` (dirty tree with unrelated changes). Never commit `dist/`.
- Admin page lives at the existing Blueprint admin extension route (`/admin/extensions/primus`) — the dedicated Addons page becomes a new **tab section inside the existing admin view** rendered by `primusExtensionController` (no new top-level route registration needed; Blueprint owns that route). Toggles persist through a new gated XHR endpoint.
- No AI this cycle. No API keys in any client payload.

## Live Environment (verified this session)

- Pterodactyl v1.12.3, PHP 8.2.33, Wings 1.13.3 online; file ops work on offline servers.
- MC test server: id 2, uuid `92cb4c95-0f50-4955-94c6-9879b01b1eb4`, short `92cb4c95`, egg "Paper (Preview)", status NULL (offline). Rust server: id 4, short `dd2cfabf`.
- Wings `DaemonFileRepository` (verified): `getContent(string $path)`, `putContent(string $path, string $content)`, `getDirectory(string $path): array` (entries: `name`, `modified` ISO8601, `size` bytes, `file` 1/0, `directory` ''/'d', `mime`), `createDirectory(string $name, string $path)`, `renameFiles(?string $root, array $files)` (files = `[{from, to}]`), `deleteFiles(?string $root, array $files)`, `copyFile`, `compressFiles`, `chmodFiles`.
- Fixtures live on server 2: `plugins/Example.jar`, `plugins/Other.jar.disabled`, `mods/FabricMod.jar` (re-seed via `/tmp/opencode/plugins_seed.php`).
- Login rate limit: 10 requests/min/IP for `/auth/login` — suites must log in once and reuse the session; wait 65s if a 429 appears.
- Unauth GET on a real auth route with `Accept: application/json` → `401 application/json`. Missing route → `200 text/html` (React catchall). TDD "red" asserts the 401-flip, never 404.
- Panel login: `GET /auth/login` (collect `XSRF-TOKEN` cookie, URL-decode) → `POST /auth/login` JSON `{"user","password"}` with `X-XSRF-TOKEN`. Puppeteer login: `/auth/login`, `input[name='username']` + native value setter.
- Puppeteer: `NODE_PATH=/usr/local/lib/node_modules node script.js` (module is `puppeteer-core`), Chromium `/usr/bin/chromium`, `--no-sandbox`.
- Subnav (probe-verified): `div[class*='SubNavigation']` → firstElementChild holds stock `<a>` tabs, then Primus marker span, then admin link. MOTD tab inserts after last `a[href*='/server/']`. Server name: `h1.pr-console-title`.
- Client engine: `P.util` (`q qa el esc attr debounce`), `P.api(path, {method, json})`, `P.toast(title, msg, kind)`, `P.on("page:view")`, `P.ready`, `P.store`. Icon set convention: inline SVG map keyed by name (command-palette.js `icon()`).
- Admin view: `resources/views/admin/extensions/primus/index.blade.php` (workspace `admin/view.blade.php`), controller `app/Http/Controllers/Admin/Extensions/primus/primusExtensionController` (workspace `admin/controller.php`), namespace `Pterodactyl\Http\Controllers\Admin\Extensions\primus` (literal — the `{identifier}` placeholder is only in the extension-data classes).
- ThemeSetting::get/set JSON KV store; models live in `private/Models` with `primus_*` tables; migrations in `private/migrations` run via `php artisan migrate --force`.
- Wrapper (workspace `dashboard/wrapper.blade.php`, live `.blueprint/extensions/primus/wrappers/dashboard.blade.php`): CSS block ends `palette.css` + `motd.css`; scripts end `shortcuts.js` + `motd.js`. Live sync via sed (resolve `{identifier}`, `{webroot/public}`, strip `{timestamp}`, bump `v=1788900000`). After wrapper edits: `php artisan view:clear`.
- Audit table will be created by migration; live migrate via `php artisan migrate --force` after syncing the migration file.

## File Map

| File | Action | Responsibility |
| --- | --- | --- |
| `private/Services/AddonRegistry.php` | Create | Manifests for 12 addons; accessors `all()`, `manifests()`, `manifest($id)` |
| `private/Services/PathGuard.php` | Create | `resolve(string $input, array $allowedRoots): string` path jail |
| `private/Controllers/AddonGate.php` | Create | `enabled`, `canUse`, `guard`, `audit` — single security choke point |
| `private/Models/AddonAudit.php` | Create | Eloquent model for `primus_addon_audit` |
| `private/Controllers/AddonsController.php` | Create | `GET /addons` hub payload; admin toggle + audit endpoints |
| `private/Controllers/PluginsController.php` | Create | list/toggle/delete/upload for plugin & mod jars |
| `private/migrations/2026_09_09_000001_create_primus_addon_audit_table.php` | Create | Audit table + indexes |
| `private/routes.php` | Modify | Add 6 gated routes (hub, plugins×4, admin toggle) |
| `admin/controller.php` | Modify | Pass addons + audit data to the admin view |
| `admin/view.blade.php` | Modify | Add "Addons" admin tab: toggle cards + audit viewer |
| `public/js/addons.js` | Create | Addons subnav tab, overlay hub, Plugin Manager panel |
| `public/css/addons.css` | Create | Hub/table/pills/locked-cards styling |
| `dashboard/wrapper.blade.php` | Modify | 2 lines: addons.css + addons.js |
| `/tmp/opencode/addons_pathguard_test.php` | Test | PathGuard tinker suite (8 cases) |
| `/tmp/opencode/addons_api_test.sh` | Test | Backend curl suite |
| `/tmp/opencode/addons_ui.js` | Test | Puppeteer UI suite |
| `/tmp/opencode/addons_admin_ui.js` | Test | Puppeteer admin suite |
| `/tmp/opencode/addons_regression.js` | Test | Puppeteer regression suite |

---

### Task 1: PathGuard + AddonRegistry + AddonAudit model + migration

**Files:**
- Create: `private/Services/PathGuard.php`
- Create: `private/Services/AddonRegistry.php`
- Create: `private/Models/AddonAudit.php`
- Create: `private/migrations/2026_09_09_000001_create_primus_addon_audit_table.php`
- Test: `/tmp/opencode/addons_pathguard_test.php`

**Interfaces:**
- Consumes: `ThemeSetting::get/set` (existing). Pure PHP — no Wings dependency.
- Produces (exact signatures used by Tasks 2-4):
  - `PathGuard::resolve(string $input, array $allowedRoots = []): string` — throws `InvalidArgumentException` on any violation; returns normalized relative path (no leading slash).
  - `AddonRegistry::all(): array` (full manifest arrays), `AddonRegistry::manifests(): array` (id => manifest), `AddonRegistry::manifest(string $id): ?array`.
  - `AddonAudit` model: fillable `user_id, server_id, addon, action, target, meta` (array cast), table `primus_addon_audit`.

- [ ] **Step 1: RED — write the PathGuard tinker suite**

`/tmp/opencode/addons_pathguard_test.php`:

```php
<?php
// PathGuard tinker suite — 8 cases, PASS/FAIL, non-zero exit on failure.
$guard = new \Pterodactyl\BlueprintFramework\Extensions\primus\Services\PathGuard();
$cases = [
    ['plugins/Example.jar', ['plugins', 'mods'], true, 'plugins/Example.jar'],
    ['plugins/../server.properties', ['plugins', 'mods'], false, null],
    ['../etc/passwd', [], false, null],
    ['/absolute/path.jar', ['plugins'], false, null],
    ["plugins/bad\0byte.jar", ['plugins'], false, null],
    ['plugins\\win.jar', ['plugins'], false, null],
    ['', ['plugins'], false, null],
    ['mods/FabricMod.jar', ['plugins', 'mods'], true, 'mods/FabricMod.jar'],
];
$pass = 0; $fail = 0;
foreach ($cases as $i => [$input, $roots, $shouldPass, $expected]) {
    $label = 'case ' . ($i + 1) . ' (' . json_encode($input) . ')';
    try {
        $out = $guard->resolve($input, $roots);
        if ($shouldPass && $out === $expected) { echo "PASS: $label\n"; $pass++; }
        else { echo "FAIL: $label — resolved to " . json_encode($out) . "\n"; $fail++; }
    } catch (\InvalidArgumentException $e) {
        if (!$shouldPass) { echo "PASS: $label (rejected)\n"; $pass++; }
        else { echo "FAIL: $label — unexpected rejection: {$e->getMessage()}\n"; $fail++; }
    } catch (\Throwable $e) {
        echo "FAIL: $label — wrong exception " . get_class($e) . "\n"; $fail++;
    }
}
echo "PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run it — expect failure (class missing)**

```bash
php artisan tinker --execute="require '/tmp/opencode/addons_pathguard_test.php';"
```

Expected: `PASS=0 FAIL=8` with "wrong exception" or class-not-found errors (exit 1). The suite must fail before the class exists.

- [ ] **Step 3: Write `private/Services/PathGuard.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use InvalidArgumentException;

/**
 * PathGuard — jail every client-supplied file path before it reaches Wings.
 *
 * Rules: relative only, no ".." segments, no null bytes, no backslashes,
 * no drive letters, and (optionally) the first segment must be one of the
 * given allowed roots. Returns the cleaned path or throws.
 */
class PathGuard
{
    public function resolve(string $input, array $allowedRoots = []): string
    {
        if ($input === '' ) {
            throw new InvalidArgumentException('Path is empty.');
        }

        if (str_contains($input, "\0")) {
            throw new InvalidArgumentException('Path contains a null byte.');
        }

        if (str_contains($input, '\\')) {
            throw new InvalidArgumentException('Path contains a backslash.');
        }

        $input = ltrim($input, '/');

        if (preg_match('#^[A-Za-z]:#', $input)) {
            throw new InvalidArgumentException('Path must be relative.');
        }

        $segments = [];
        foreach (explode('/', $input) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new InvalidArgumentException('Path must not contain "..".');
            }
            $segments[] = $seg;
        }

        if ($segments === []) {
            throw new InvalidArgumentException('Path is empty.');
        }

        if ($allowedRoots !== [] && !in_array($segments[0], $allowedRoots, true)) {
            throw new InvalidArgumentException('Path escapes the allowed roots.');
        }

        return implode('/', $segments);
    }
}
```

- [ ] **Step 4: Run the suite — expect 8/8 PASS**

```bash
php artisan tinker --execute="require '/tmp/opencode/addons_pathguard_test.php';"
```

Expected: `PASS=8 FAIL=0`.

- [ ] **Step 5: Write `private/Services/AddonRegistry.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * AddonRegistry — declarative manifests for every Primus addon.
 * 'plugins' is live this cycle; the rest are visible roadmap cards
 * (comingSoon) implemented by later sub-projects. Permissions are
 * dotted strings verified against Pterodactyl's Permission constants.
 */
class AddonRegistry
{
    /** @return array<int, array<string, mixed>> every manifest */
    public static function all(): array
    {
        return [
            self::manifest('plugins'),
            self::manifest('worlds'),
            self::manifest('mods'),
            self::manifest('players'),
            self::manifest('traffic'),
            self::manifest('console'),
            self::manifest('versions'),
            self::manifest('icons'),
            self::manifest('trash'),
            self::manifest('properties'),
            self::manifest('motd'),
            self::manifest('aimotd'),
        ];
    }

    /** @return array<string, array<string, mixed>> id => manifest */
    public static function manifests(): array
    {
        $out = [];
        foreach (self::all() as $manifest) {
            $out[$manifest['id']] = $manifest;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function manifest(string $id): ?array
    {
        $live = [
            'plugins' => [
                'id' => 'plugins',
                'title' => 'Plugin Manager',
                'description' => 'List, enable, disable, upload and delete plugin & mod jars.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'plugins',
                'comingSoon' => false,
            ],
        ];
        $soon = [
            'worlds' => ['World Manager', 'management'],
            'mods' => ['Mod Manager', 'files'],
            'players' => ['Player Manager', 'management'],
            'traffic' => ['Traffic Manager', 'management'],
            'console' => ['Advanced Console', 'console'],
            'versions' => ['Version Manager', 'management'],
            'icons' => ['Icon Manager', 'files'],
            'trash' => ['Trash Bin', 'files'],
            'properties' => ['Properties Manager', 'files'],
            'motd' => ['MOTD Manager', 'files'],
            'aimotd' => ['AI MOTD', 'ai'],
        ];
        if (isset($live[$id])) {
            return $live[$id];
        }
        if (isset($soon[$id])) {
            return [
                'id' => $id,
                'title' => $soon[$id][0],
                'description' => 'Coming in a future Primus update.',
                'category' => $soon[$id][1],
                'perms' => 'file.read',
                'icon' => $id,
                'comingSoon' => true,
            ];
        }

        return null;
    }
}
```

- [ ] **Step 6: Write `private/Models/AddonAudit.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Intent-before-action audit rows for every destructive addon operation.
 *
 * @property int $id
 * @property int $user_id
 * @property int $server_id
 * @property string $addon
 * @property string $action
 * @property string $target
 * @property array|null $meta
 * @property \Carbon\Carbon $created_at
 */
class AddonAudit extends Model
{
    protected $table = 'primus_addon_audit';

    protected $fillable = ['user_id', 'server_id', 'addon', 'action', 'target', 'meta'];

    protected $casts = [
        'user_id' => 'int',
        'server_id' => 'int',
        'meta' => 'array',
    ];
}
```

- [ ] **Step 7: Write `private/migrations/2026_09_09_000001_create_primus_addon_audit_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('primus_addon_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id');
            $table->string('addon', 32);
            $table->string('action', 32);
            $table->string('target', 255);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['server_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['addon', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('primus_addon_audit');
    }
};
```

- [ ] **Step 8: Sync to live + migrate**

```bash
sed 's/{identifier}/primus/g' /workspace/private/Services/PathGuard.php > /var/www/pterodactyl/.blueprint/extensions/primus/app/Services/PathGuard.php
sed 's/{identifier}/primus/g' /workspace/private/Services/AddonRegistry.php > /var/www/pterodactyl/.blueprint/extensions/primus/app/Services/AddonRegistry.php
sed 's/{identifier}/primus/g' /workspace/private/Models/AddonAudit.php > /var/www/pterodactyl/.blueprint/extensions/primus/app/Models/AddonAudit.php
cp /workspace/private/migrations/2026_09_09_000001_create_primus_addon_audit_table.php /var/www/pterodactyl/.blueprint/extensions/primus/private/migrations/
cd /var/www/pterodactyl && php artisan migrate --force && php artisan view:clear
```

Expected: `Migrating: 2026_09_09_000001_create_primus_addon_audit_table` then `DONE`.

- [ ] **Step 9: Lint + model sanity**

```bash
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Services/PathGuard.php
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Services/AddonRegistry.php
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Models/AddonAudit.php
php artisan tinker --execute="echo \Pterodactyl\BlueprintFramework\Extensions\primus\Services\AddonRegistry::manifest('plugins')['title'];"
```

Expected: three `No syntax errors detected` + `Plugin Manager`.

- [ ] **Step 10: Re-run PathGuard suite (still 8/8) + registry sanity via tinker**

```bash
php artisan tinker --execute="require '/tmp/opencode/addons_pathguard_test.php';"
php artisan tinker --execute="echo count(\Pterodactyl\BlueprintFramework\Extensions\primus\Services\AddonRegistry::all());"
```

Expected: `PASS=8 FAIL=0`, then `12`.

**Task 1 exit criteria:** PathGuard 8/8, registry returns 12 manifests, migration migrated, all files lint clean.

---

### Task 2: AddonGate choke point

**Files:**
- Create: `private/Controllers/AddonGate.php`
- Test: `/tmp/opencode/addons_gate_test.php` (tinker)

**Interfaces:**
- Consumes: `AddonRegistry::manifest($id)`, `ThemeSetting::get('addons.<id>.enabled', ...)`, `AddonAudit` model, `Pterodactyl\Models\{User, Server, Subuser}`.
- Produces (used by Tasks 3-4 and admin):
  - `AddonGate::enabled(string $addonId): bool`
  - `AddonGate::canUse(User $user, Server $server, ?Subuser $subuser, string $addonId): bool`
  - `AddonGate::guard(Request $request, string $addonId): JsonResponse|null` — resolves server from `server` key (query for GET, json for POST), returns null when allowed, or the 401/403/404 response.
  - `AddonGate::audit(User $user, Server $server, string $addon, string $action, string $target, array $meta = []): void`

- [ ] **Step 1: RED — gate tinker suite**

`/tmp/opencode/addons_gate_test.php`:

```php
<?php
// AddonGate tinker suite — 8 cases.
use Pterodactyl\BlueprintFramework\Extensions\primus\Controllers\AddonGate;

$admin = \Pterodactyl\Models\User::where('email', 'admin@example.com')->first();
$server = \Pterodactyl\Models\Server::where('uuid', '92cb4c95-0f50-4955-94c6-9879b01b1eb4')->first();

$pass = 0; $fail = 0;
function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { echo "PASS: $label\n"; $pass++; } else { echo "FAIL: $label\n"; $fail++; }
}

check(AddonGate::enabled('plugins') === true, 'plugins enabled by default');
check(AddonGate::enabled('worlds') === true, 'coming-soon addons default enabled');
check(AddonGate::canUse($admin, $server, null, 'plugins') === true, 'admin can use');
check(AddonGate::canUse($admin, $server, null, 'nope') === false, 'unknown addon rejected');

AddonGate::audit($admin, $server, 'plugins', 'toggle', 'plugins/Example.jar', ['to' => 'disabled']);
$row = \Pterodactyl\BlueprintFramework\Extensions\primus\Models\AddonAudit::query()->latest('id')->first();
check($row !== null && $row->addon === 'plugins' && $row->action === 'toggle', 'audit row written');
check($row !== null && $row->meta === ['to' => 'disabled'], 'meta json cast');

// Read-only subuser path (reuse seeding approach from MOTD cycle if absent)
$guest = \Pterodactyl\Models\User::firstOrCreate(
    ['email' => 'addonguest@example.com'],
    ['uuid' => Ramsey\Uuid\Uuid::uuid4()->toString(), 'username' => 'addonguest', 'name_first' => 'Addon', 'name_last' => 'Guest', 'password' => \Illuminate\Support\Facades\Hash::make('AddonGuest123!'), 'root_admin' => false]
);
$sub = \Pterodactyl\Models\Subuser::firstOrCreate(
    ['user_id' => $guest->id, 'server_id' => $server->id],
    ['permissions' => ['file.read', 'file.read-content', 'websocket.connect']]
);
check(AddonGate::canUse($guest, $server, $sub, 'plugins') === true, 'subuser with file.read can use (view)');
check(AddonGate::canUse($guest, $server, $sub, 'properties') === true, 'coming-soon manifests gate on file.read too');
echo "PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run — expect failure (class missing)**

```bash
php artisan tinker --execute="require '/tmp/opencode/addons_gate_test.php';"
```

Expected: all FAIL (class not found), exit 1.

- [ ] **Step 3: Write `private/Controllers/AddonGate.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\AddonRegistry;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;

/**
 * AddonGate — the single security choke point every addon route passes
 * through: registry enabled? acting user allowed? Every destructive
 * action is audited here before the Wings call is made.
 */
class AddonGate
{
    public static function enabled(string $addonId): bool
    {
        if (AddonRegistry::manifest($addonId) === null) {
            return false;
        }

        return (bool) \Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting::get(
            'addons.' . $addonId . '.enabled',
            true
        );
    }

    public static function canUse(User $user, Server $server, ?Subuser $subuser, string $addonId): bool
    {
        $manifest = AddonRegistry::manifest($addonId);
        if ($manifest === null) {
            return false;
        }

        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }

        $perms = (array) ($subuser?->permissions ?? []);
        $required = (string) ($manifest['perms'] ?? 'file.read');

        return in_array($required, $perms, true);
    }

    /**
     * Composite guard for route handlers. Resolves the server from the
     * request (query `server` for GET, json `server` for POST), checks
     * enabled + canUse, and returns the JSON error response to send —
     * or null when the request is allowed (server is bound by reference).
     *
     * @param-out Server|null $server
     */
    public static function guard(\Illuminate\Http\Request $request, string $addonId, ?Server &$server): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $id = (string) ($request->isMethod('GET')
            ? $request->query('server', '')
            : $request->json('server', ''));
        $server = Shared::resolveAccessibleServer($id, $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        if (!self::enabled($addonId)) {
            return response()->json(['error' => 'This addon is disabled.'], 404);
        }

        $subuser = $user->root_admin || $server->owner_id === $user->id
            ? null
            : $server->subusers()->where('user_id', $user->id)->first();
        if (!self::canUse($user, $server, $subuser, $addonId)) {
            return response()->json(['error' => 'You do not have access to this addon on this server.'], 403);
        }

        return null;
    }

    public static function audit(
        User $user,
        Server $server,
        string $addon,
        string $action,
        string $target,
        array $meta = []
    ): void {
        try {
            AddonAudit::query()->create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'addon' => $addon,
                'action' => $action,
                'target' => $target,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('primus addon audit write failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Sync + lint**

```bash
sed 's/{identifier}/primus/g' /workspace/private/Controllers/AddonGate.php > /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/AddonGate.php
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/AddonGate.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Run the gate suite — expect 8/8**

```bash
php artisan tinker --execute="require '/tmp/opencode/addons_gate_test.php';"
```

Expected: `PASS=8 FAIL=0`.

**Task 2 exit criteria:** gate suite 8/8 (default-enabled, admin allow, unknown reject, audit write + meta cast, subuser view allow), lint clean.

---

### Task 3: AddonsController + PluginsController + routes

**Files:**
- Create: `private/Controllers/AddonsController.php`
- Create: `private/Controllers/PluginsController.php`
- Modify: `private/routes.php`

**Interfaces:**
- Consumes: `AddonGate::{guard, audit, enabled, canUse}` (Task 2), `PathGuard::resolve` (Task 1), `Shared::resolveAccessibleServer`, `DaemonFileRepository::{getDirectory, renameFiles, deleteFiles, putContent}`, `Shared::rateLimited`.
- Produces (exact JSON contracts consumed by Task 4 client + Task 5 admin):
  - `GET /addons?server=` → `{ok, addons: [{id, title, description, category, icon, comingSoon, canUse}], perms: {canUpdate, canDelete, canCreate}}`
  - `GET /addons/plugins?server=` → `{ok, jars: [{name, base, dir, size, modified, enabled}], perms: {canUpdate, canDelete, canCreate}}`
  - `POST /addons/plugins/toggle` `{server, name}` → `{ok, name, enabled}` — `name` is the jar's base name without state suffix; PathGuard-validated under `plugins|mods`.
  - `POST /addons/plugins/delete` `{server, name, confirm}` → `{ok}` — `confirm` must equal `name`.
  - `POST /addons/plugins/upload` `{server, name, content}` (base64) → `{ok, name}` — `.jar` enforced.
  - `POST /addons/admin/toggle` `{addon, enabled}` (root admin only) → `{ok, addon, enabled}`.

- [ ] **Step 1: RED — unauth route baselines**

```bash
for path in "addons?server=92cb4c95" "addons/plugins?server=92cb4c95"; do
  curl -s -o /dev/null -w "$path → %{http_code} %{content_type}\n" \
    -H "Accept: application/json" "http://localhost:8081/extensions/primus/$path"
done
```

Expected: both `200 text/html` (React catchall — routes missing). Task 3 Step 6 flips both to `401 application/json`.

- [ ] **Step 2: Write `private/Controllers/AddonsController.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\AddonRegistry;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;

/**
 * Hub payload for the client Addons tab + admin toggle endpoint.
 */
class AddonsController extends Controller
{
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

        $subuser = $this->subuser($server, $user);

        $addons = [];
        foreach (AddonRegistry::all() as $manifest) {
            $id = (string) $manifest['id'];
            if (!AddonGate::enabled($id)) {
                continue;
            }
            $addons[] = [
                'id' => $id,
                'title' => (string) $manifest['title'],
                'description' => (string) $manifest['description'],
                'category' => (string) $manifest['category'],
                'icon' => (string) $manifest['icon'],
                'comingSoon' => (bool) $manifest['comingSoon'],
                'canUse' => AddonGate::canUse($user, $server, $subuser, $id),
            ];
        }

        return response()->json([
            'ok' => true,
            'addons' => $addons,
            'perms' => $this->perms($user, $server, $subuser),
        ]);
    }

    /** Root-admin toggle for the admin Addons page. */
    public function toggle(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || !$user->root_admin) {
            return response()->json(['error' => 'Root admin required.'], 403);
        }

        $addon = (string) $request->json('addon', '');
        if (AddonRegistry::manifest($addon) === null) {
            return response()->json(['error' => 'Unknown addon.'], 422);
        }

        $enabled = (bool) $request->json('enabled', true);
        ThemeSetting::set('addons.' . $addon . '.enabled', $enabled);
        AddonGate::audit($user, new Server(['id' => 0]), 'framework', $enabled ? 'enable' : 'disable', $addon);

        return response()->json(['ok' => true, 'addon' => $addon, 'enabled' => $enabled]);
    }

    private function subuser(Server $server, User $user): ?Subuser
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return null;
        }

        return $server->subusers()->where('user_id', $user->id)->first();
    }

    private function perms(User $user, Server $server, ?Subuser $subuser): array
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return ['canUpdate' => true, 'canDelete' => true, 'canCreate' => true];
        }
        $p = (array) ($subuser?->permissions ?? []);

        return [
            'canUpdate' => in_array('file.update', $p, true),
            'canDelete' => in_array('file.delete', $p, true),
            'canCreate' => in_array('file.create', $p, true),
        ];
    }
}
```

- [ ] **Step 3: Write `private/Controllers/PluginsController.php`**

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PathGuard;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Plugin Manager (pilot addon) — list/toggle/delete/upload jars under
 * plugins/ and mods/. Every path goes through PathGuard with the
 * ['plugins', 'mods'] jail; every mutation is audited before Wings.
 */
class PluginsController extends Controller
{
    private const JAIL = ['plugins', 'mods'];
    private const SUFFIX = '.disabled';

    public function index(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'plugins', $server);
        if ($gate !== null) {
            return $gate;
        }

        $user = $request->user();
        $subuser = $user->root_admin || $server->owner_id === $user->id
            ? null
            : $server->subusers()->where('user_id', $user->id)->first();

        $jars = [];
        try {
            foreach (['plugins', 'mods'] as $dir) {
                try {
                    $entries = $this->repo($server)->getDirectory($dir);
                } catch (DaemonConnectionException $e) {
                    if ($e->getStatusCode() === 404) {
                        continue; // folder missing on this server — empty
                    }
                    throw $e;
                }
                foreach ($entries as $entry) {
                    if (empty($entry['file']) || empty($entry['name'])) {
                        continue;
                    }
                    $name = (string) $entry['name'];
                    if (!str_ends_with($name, '.jar') && !str_ends_with($name, '.jar' . self::SUFFIX)) {
                        continue;
                    }
                    $enabled = !str_ends_with($name, self::SUFFIX);
                    $jars[] = [
                        'name' => $name,
                        'base' => $enabled ? $name : substr($name, 0, -strlen(self::SUFFIX)),
                        'dir' => $dir,
                        'size' => (int) ($entry['size'] ?? 0),
                        'modified' => (string) ($entry['modified'] ?? ''),
                        'enabled' => $enabled,
                    ];
                }
            }
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json([
            'ok' => true,
            'jars' => $jars,
            'perms' => [
                'canUpdate' => $user->root_admin || $server->owner_id === $user->id
                    || in_array('file.update', (array) ($subuser?->permissions ?? []), true),
                'canDelete' => $user->root_admin || $server->owner_id === $user->id
                    || in_array('file.delete', (array) ($subuser?->permissions ?? []), true),
                'canCreate' => $user->root_admin || $server->owner_id === $user->id
                    || in_array('file.create', (array) ($subuser?->permissions ?? []), true),
            ],
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'plugins', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!$this->hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file update access to this server.'], 403);
        }

        if (Shared::rateLimited((int) $user->id, 'addon.plugins')) {
            return response()->json(['error' => 'Rate limit reached. Try again later.'], 429);
        }

        $name = (string) $request->json('name', '');
        $dir = (string) $request->json('dir', 'plugins');
        try {
            $base = basename(PathGuard::resolve($dir . '/' . $name . '.jar', self::JAIL));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $from = $dir . '/' . $base;
        $to = $from . self::SUFFIX;

        try {
            $listing = $this->repo($server)->getDirectory($dir);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        $existsEnabled = false;
        $existsDisabled = false;
        foreach ($listing as $entry) {
            if (($entry['name'] ?? '') === $base) {
                $existsEnabled = true;
            }
            if (($entry['name'] ?? '') === $base . self::SUFFIX) {
                $existsDisabled = true;
            }
        }
        if (!$existsEnabled && !$existsDisabled) {
            return response()->json(['error' => 'Plugin jar not found.'], 404);
        }

        $disable = $existsEnabled; // enabled exists → disable it; else enable

        AddonGate::audit($user, $server, 'plugins', 'toggle', ($disable ? $from : $to), ['to' => $disable ? 'disabled' : 'enabled']);

        try {
            $this->repo($server)->renameFiles(null, [[
                'from' => ltrim($disable ? $from : $to, '/'),
                'to' => ltrim($disable ? $to : $from, '/'),
            ]]);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json(['ok' => true, 'name' => $base, 'enabled' => !$disable]);
    }

    public function delete(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'plugins', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!$this->hasPerm($user, $server, 'file.delete')) {
            return response()->json(['error' => 'You do not have file delete access to this server.'], 403);
        }

        if (Shared::rateLimited((int) $user->id, 'addon.plugins')) {
            return response()->json(['error' => 'Rate limit reached. Try again later.'], 429);
        }

        $name = (string) $request->json('name', '');
        $dir = (string) $request->json('dir', 'plugins');
        $confirm = (string) $request->json('confirm', '');
        try {
            $path = PathGuard::resolve($dir . '/' . $name, self::JAIL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($confirm !== basename($path)) {
            return response()->json(['error' => 'Confirmation does not match the file name.'], 422);
        }

        if (!str_ends_with($path, '.jar') && !str_ends_with($path, '.jar' . self::SUFFIX)) {
            return response()->json(['error' => 'Only plugin jars can be deleted.'], 422);
        }

        AddonGate::audit($user, $server, 'plugins', 'delete', $path);

        try {
            $this->repo($server)->deleteFiles(null, [ltrim($path, '/')]);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json(['ok' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'plugins', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!$this->hasPerm($user, $server, 'file.create')) {
            return response()->json(['error' => 'You do not have file create access to this server.'], 403);
        }

        if (Shared::rateLimited((int) $user->id, 'addon.plugins')) {
            return response()->json(['error' => 'Rate limit reached. Try again later.'], 429);
        }

        $name = (string) $request->json('name', '');
        $dir = (string) $request->json('dir', 'plugins');
        $contentB64 = (string) $request->json('content', '');

        if (!preg_match('/^[A-Za-z0-9._-]+\.jar$/', $name)) {
            return response()->json(['error' => 'File name must be a simple .jar name.'], 422);
        }

        $content = base64_decode($contentB64, true);
        if ($content === false) {
            return response()->json(['error' => 'File content is not valid base64.'], 422);
        }

        $maxBytes = ((int) \Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting::get('addons.plugins.max_upload_mib', 100)) * 1024 * 1024;
        if (strlen($content) > $maxBytes) {
            return response()->json(['error' => 'File exceeds the upload size limit.'], 422);
        }

        try {
            $path = PathGuard::resolve($dir . '/' . $name, self::JAIL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (!str_ends_with($path, '.jar')) {
            return response()->json(['error' => 'Only .jar files can be uploaded.'], 422);
        }

        AddonGate::audit($user, $server, 'plugins', 'upload', $path, ['bytes' => strlen($content)]);

        try {
            $this->repo($server)->putContent($path, $content);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json(['ok' => true, 'name' => basename($path)]);
    }

    private function hasPerm(\Pterodactyl\Models\User $user, Server $server, string $perm): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }
        $subuser = $server->subusers()->where('user_id', $user->id)->first();

        return in_array($perm, (array) ($subuser?->permissions ?? []), true);
    }

    private function repo(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }
}
```

- [ ] **Step 4: Wire `private/routes.php`**

Add imports near the existing controller imports:

```php
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AddonsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\PluginsController;
```

Add after the MOTD group:

```php
// Addon framework — hub + Plugin Manager (pilot). All gated through
// AddonGate inside the controllers; the admin toggle is root-admin only.
Route::middleware(['auth'])->group(function () {
    Route::get('/addons', [AddonsController::class, 'index']);
    Route::get('/addons/plugins', [PluginsController::class, 'index']);
    Route::post('/addons/plugins/toggle', [PluginsController::class, 'toggle']);
    Route::post('/addons/plugins/delete', [PluginsController::class, 'delete']);
    Route::post('/addons/plugins/upload', [PluginsController::class, 'upload']);
});

Route::middleware(['auth', AdminAuthenticate::class])->group(function () {
    Route::post('/addons/admin/toggle', [AddonsController::class, 'toggle']);
});
```

- [ ] **Step 5: Sync to live + clear caches**

```bash
sed 's/{identifier}/primus/g' /workspace/private/Controllers/AddonsController.php > /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/AddonsController.php
sed 's/{identifier}/primus/g' /workspace/private/Controllers/PluginsController.php > /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/PluginsController.php
sed 's/{identifier}/primus/g' /workspace/private/routes.php > /var/www/pterodactyl/.blueprint/extensions/primus/routers/web.php
cd /var/www/pterodactyl && php artisan view:clear && php artisan route:clear
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/AddonsController.php
php -l /var/www/pterodactyl/.blueprint/extensions/primus/app/Controllers/PluginsController.php
php -l /var/www/pterodactyl/.blueprint/extensions/primus/routers/web.php
```

Expected: three `No syntax errors detected`.

- [ ] **Step 6: GREEN — unauth flip**

```bash
for path in "addons?server=92cb4c95" "addons/plugins?server=92cb4c95"; do
  curl -s -o /dev/null -w "$path → %{http_code} %{content_type}\n" \
    -H "Accept: application/json" "http://localhost:8081/extensions/primus/$path"
done
```

Expected: both `401 application/json`.

- [ ] **Step 7: Authenticated backend suite**

Write `/tmp/opencode/addons_api_test.sh` (seed fixtures first: `php artisan tinker --execute="require '/tmp/opencode/plugins_seed.php';"`). Suite (login once, reuse cookie jar; same login pattern as the MOTD suite):

1. `GET /addons?server=92cb4c95` → `ok:true`, 12 addons, plugins `comingSoon:false` `canUse:true`, worlds `comingSoon:true`, `perms.canUpdate:true`.
2. `GET /addons/plugins?server=92cb4c95` → `ok:true`, 3 jars (Example.jar enabled, Other.jar.disabled disabled in plugins/, FabricMod.jar in mods/), `perms` all true.
3. `POST /addons/plugins/toggle` `{server, name:"Example.jar"}` (base) → `ok:true enabled:false`; verify via read-back tinker that `plugins/Example.jar.disabled` exists.
4. Toggle again → `enabled:true`; read-back restored.
5. `POST /addons/plugins/delete` without confirm → 422.
6. Delete with wrong confirm → 422.
7. Delete `Example.jar` with matching confirm → `ok:true`; read-back gone (then re-seed).
8. `POST /addons/plugins/upload` name `evil.txt` → 422.
9. Upload name `New.jar`, content base64 of 12 bytes → `ok:true`; listing shows `New.jar` (then delete it via the endpoint to clean up).
10. `GET /addons?server=dd2cfabf` (Rust — subuser of nothing, admin) → `ok:true` but `canUse` still true for admin (admin sees hub; plugins panel listing will 502/empty on Rust — assert `addons` array present).
11. `POST /addons/admin/toggle` `{addon:"worlds", enabled:false}` as admin → `ok:true`; then `GET /addons` omits `worlds`; re-enable.
12. `POST /addons/admin/toggle` as read-only subuser → 403.

Each case prints PASS/FAIL; exit non-zero on any FAIL. Use the shared login helper from `/tmp/opencode/motd_api_test.sh` (copy the J/JP function block).

- [ ] **Step 8: Verify audit rows**

```bash
php artisan tinker --execute="foreach (\Pterodactyl\BlueprintFramework\Extensions\primus\Models\AddonAudit::query()->latest('id')->limit(6)->get() as \$r) { echo \$r->addon . ' ' . \$r->action . ' ' . \$r->target . PHP_EOL; }"
```

Expected: rows for `plugins toggle`, `plugins delete`, `plugins upload`, `framework enable/disable` from the suite run, newest first.

**Task 3 exit criteria:** unauth flip 401 JSON ×2; backend suite 12/12 PASS; audit rows visible; all files lint clean.

---

### Task 4: Client — addons.js hub + Plugin Manager panel, addons.css, wrapper

**Files:**
- Create: `public/js/addons.js`
- Create: `public/css/addons.css`
- Modify: `dashboard/wrapper.blade.php` (2 lines)

**Interfaces:**
- Consumes: Task 3 JSON contracts; `P.util` (`q qa el esc attr`), `P.api`, `P.toast`, `P.on("page:view")`, `P.ready`; overlay classes `pr-fade-in pr-scale-in`; tokens `--pr-surface-raised --pr-border --pr-accent --pr-radius-lg --pr-shadow-4 --pr-text-* --pr-space-* --pr-success --pr-warning --pr-danger`; MOTD tab pattern (subnav insertion, stale-tab removal on `page:view`).
- Produces: `.pr-addons-tab` subnav tab; `.pr-addons-mask` overlay hub; `.pr-addons-card` grid; `.pr-plugins-panel` with jar table, toggle buttons (`data-toggle`), delete flow (`data-delete` + typed confirm), upload (`data-upload` + file input + base64 via FileReader); buttons all gated on the `perms` payload.

- [ ] **Step 1: Write `public/js/addons.js`**

```javascript
/*
 * Primus · addons.js
 * Addon framework hub — "Addons" subnav tab opening an overlay with a
 * card grid of enabled addons. The Plugin Manager panel (pilot addon)
 * lists/toggles/deletes/uploads plugin & mod jars through the gated
 * /extensions/primus/addons/plugins endpoints.
 */
(function () {
  "use strict";
  var P = window.__primus;
  if (!P) return;
  var U = P.util;

  var hubState = null;    // GET /addons payload
  var pluginsState = null; // GET /addons/plugins payload
  var mask = null;
  var activePanel = null; // null = hub grid, "plugins" = plugin panel

  var ICONS = {
    plugins: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 3v4M14 3v4M8 7h8v4a4 4 0 0 1-4 4 4 4 0 0 1-4-4V7Z"/><path d="M12 15v6"/></svg>',
    worlds: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></svg>',
    mods: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 4h2v6h6v2h-6v6h-2v-6H5v-2h6V4Z"/></svg>',
    players: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.5"/><path d="M3.5 20c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15 14.5c2.8 0 5 1.8 5 4"/></svg>',
    traffic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="7" cy="6" r="1.4" fill="currentColor"/><circle cx="14" cy="12" r="1.4" fill="currentColor"/><circle cx="10" cy="18" r="1.4" fill="currentColor"/></svg>',
    console: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 7 5 5-5 5"/><path d="M12 17h7"/></svg>',
    versions: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M5 21h14"/></svg>',
    icons: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/><path d="M13 3h8v8h-8z" opacity=".4"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/></svg>',
    properties: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/><circle cx="18" cy="18" r="2"/></svg>',
    motd: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16M4 12h16M4 19h10"/></svg>',
    aimotd: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.1 6.2L20 10l-5.9 1.8L12 18l-2.1-6.2L4 10l5.9-1.8L12 2z"/></svg>',
  };

  function serverId() {
    var m = location.pathname.match(/\/server\/([a-zA-Z0-9]+)/);
    return m ? m[1] : "";
  }

  function isServerPage() {
    return /\/server\/[a-zA-Z0-9]+/.test(location.pathname);
  }

  function subnavHolder() {
    var sub = U.q("div[class*='SubNavigation']");
    return sub ? (sub.firstElementChild || sub) : null;
  }

  function ensureTab() {
    var holder = subnavHolder();
    if (!holder) return;
    if (U.q(".pr-addons-tab")) return;
    if (!hubState || !hubState.addons || !hubState.addons.length) return;
    var tab = U.el(
      "a",
      "pr-addons-tab",
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
        '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/>' +
        '<rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>' +
      "<span>Addons</span>"
    );
    tab.href = "#";
    tab.setAttribute("role", "button");
    tab.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      openHub();
    });
    /* insert after the MOTD tab when present, else after last server link */
    var motdTab = U.q(".pr-motd-tab");
    var serverLinks = U.qa("a[href*='/server/']", holder);
    var anchor = motdTab || (serverLinks.length ? serverLinks[serverLinks.length - 1] : null);
    if (!anchor) return;
    if (anchor.nextSibling) holder.insertBefore(tab, anchor.nextSibling);
    else holder.appendChild(tab);
  }

  function removeTab() {
    var tab = U.q(".pr-addons-tab");
    if (tab) tab.remove();
  }

  /* ── overlay ─────────────────────────────────────────────────── */
  function openHub() {
    if (mask) return;
    activePanel = null;
    mask = U.el("div", "pr-addons-mask pr-fade-in", "");
    document.body.appendChild(mask);
    renderShell();
    loadHub();
    document.addEventListener("keydown", escHandler);
  }

  function renderShell() {
    var card = U.el(
      "div",
      "pr-addons-card pr-scale-in",
      '<div class="pr-addons-head">' +
        '<span class="pr-addons-title"><span class="pr-addons-dot"></span> Addons</span>' +
        '<button class="pr-addons-back" type="button" style="display:none">&larr; Back</button>' +
        '<button class="pr-addons-close" type="button" aria-label="Close">&times;</button>' +
      "</div>" +
      '<div class="pr-addons-body"><div class="pr-addons-grid"></div></div>'
    );
    mask.innerHTML = "";
    mask.appendChild(card);
    card.querySelector(".pr-addons-close").addEventListener("click", close);
    card.querySelector(".pr-addons-back").addEventListener("click", function () {
      activePanel = null;
      renderGrid();
    });
    mask.addEventListener("click", function (e) { if (e.target === mask) close(); });
  }

  function renderGrid() {
    var body = mask.querySelector(".pr-addons-body");
    var back = mask.querySelector(".pr-addons-back");
    back.style.display = "none";
    body.innerHTML = '<div class="pr-addons-grid"></div>';
    var grid = body.querySelector(".pr-addons-grid");

    var usable = (hubState && hubState.addons) || [];
    usable.forEach(function (a) {
      var locked = a.comingSoon || !a.canUse;
      var card = U.el(
        "div",
        "pr-addons-app" + (locked ? " is-locked" : ""),
        '<div class="pr-addons-app__icon">' + (ICONS[a.id] || ICONS.plugins) + "</div>" +
        '<div class="pr-addons-app__text">' +
          '<div class="pr-addons-app__title">' + U.esc(a.title) + "</div>" +
          '<div class="pr-addons-app__desc">' + U.esc(a.description) + "</div>" +
        "</div>" +
        (a.comingSoon ? '<span class="pr-addons-app__tag">soon</span>' : "")
      );
      if (!locked) {
        card.setAttribute("role", "button");
        card.addEventListener("click", function () { openPanel(a.id); });
      }
      grid.appendChild(card);
    });

    if (!usable.length) {
      grid.innerHTML = '<div class="pr-addons-empty">No addons are enabled on this panel.</div>';
    }
  }

  function openPanel(id) {
    if (id !== "plugins") return; /* pilot: only plugins has a panel */
    activePanel = "plugins";
    var back = mask.querySelector(".pr-addons-back");
    back.style.display = "";
    var body = mask.querySelector(".pr-addons-body");
    body.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
    P.api("addons/plugins?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        pluginsState = payload;
        renderPlugins();
      })
      .catch(function (err) {
        body.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load plugins.") + "</div>";
      });
  }

  function renderPlugins() {
    var body = mask.querySelector(".pr-addons-body");
    var jars = (pluginsState && pluginsState.jars) || [];
    var perms = (pluginsState && pluginsState.perms) || {};

    var rows = jars.map(function (j) {
      var toggleBtn = perms.canUpdate
        ? '<button type="button" class="pr-addons-act" data-toggle="' + U.esc(j.name) + '" data-dir="' + U.esc(j.dir) + '">' + (j.enabled ? "Disable" : "Enable") + "</button>"
        : "";
      var deleteBtn = perms.canDelete
        ? '<button type="button" class="pr-addons-act pr-addons-act--danger" data-delete="' + U.esc(j.name) + '" data-dir="' + U.esc(j.dir) + '">Delete</button>'
        : "";
      return '<tr class="pr-addons-row" data-name="' + U.esc(j.name) + '">' +
        '<td class="pr-addons-row__name">' + U.esc(j.name) + "</td>" +
        '<td>' + (j.dir === "mods" ? "mods" : "plugins") + "</td>" +
        '<td class="pr-addons-row__size">' + fmtBytes(j.size) + "</td>" +
        '<td><span class="pr-addons-pill" data-state="' + (j.enabled ? "on" : "off") + '">' + (j.enabled ? "enabled" : "disabled") + "</span></td>" +
        '<td class="pr-addons-row__acts">' + toggleBtn + deleteBtn + "</td>" +
      "</tr>";
    }).join("");

    var upload = perms.canCreate
      ? '<div class="pr-addons-upload" id="pr-addons-dropzone">' +
          '<input type="file" accept=".jar" class="pr-addons-file" hidden>' +
          '<button type="button" class="pr-addons-upload__btn">Upload .jar</button>' +
          '<span class="pr-addons-upload__hint">or drop it here</span>' +
        "</div>"
      : "";

    body.innerHTML =
      '<div class="pr-plugins-panel">' +
        '<table class="pr-addons-table"><thead><tr><th>Plugin</th><th>Folder</th><th>Size</th><th>State</th><th></th></tr></thead>' +
        "<tbody>" + (rows || '<tr><td colspan="5" class="pr-addons-empty">No plugin jars found.</td></tr>') + "</tbody></table>" +
        upload +
      "</div>";

    bindPluginEvents(body);
  }

  function bindPluginEvents(body) {
    body.addEventListener("click", function (e) {
      var t = e.target.closest("[data-toggle]");
      if (t) { pluginToggle(t.dataset.toggle, t.dataset.dir); return; }
      var d = e.target.closest("[data-delete]");
      if (d) { pluginDeletePrompt(d.dataset.delete, d.dataset.dir); return; }
      var up = e.target.closest(".pr-addons-upload__btn");
      if (up) { body.querySelector(".pr-addons-file").click(); return; }
    });

    var fileInput = body.querySelector(".pr-addons-file");
    var zone = body.querySelector("#pr-addons-dropzone");
    if (fileInput) {
      fileInput.addEventListener("change", function () {
        if (fileInput.files && fileInput.files[0]) pluginUpload(fileInput.files[0]);
      });
    }
    if (zone) {
      zone.addEventListener("dragover", function (e) { e.preventDefault(); zone.classList.add("is-drag"); });
      zone.addEventListener("dragleave", function () { zone.classList.remove("is-drag"); });
      zone.addEventListener("drop", function (e) {
        e.preventDefault();
        zone.classList.remove("is-drag");
        if (e.dataTransfer.files && e.dataTransfer.files[0]) pluginUpload(e.dataTransfer.files[0]);
      });
    }
  }

  function pluginToggle(name, dir) {
    P.api("addons/plugins/toggle", {
      method: "POST",
      json: { server: serverId(), name: name.replace(/\.jar(\.disabled)?$/, ""), dir: dir },
    })
      .then(function (res) {
        P.toast(res.enabled ? "Plugin enabled" : "Plugin disabled", name, "success");
        openPanel("plugins");
      })
      .catch(function (err) { P.toast("Toggle failed", U.esc(err.message || "unknown"), "error"); });
  }

  function pluginDeletePrompt(name, dir) {
    var modal = U.el(
      "div",
      "pr-addons-confirm pr-scale-in",
      '<div class="pr-addons-confirm__title">Delete plugin</div>' +
      '<p>Type <code>' + U.esc(name) + '</code> to confirm deletion. This cannot be undone.</p>' +
      '<input class="pr-addons-confirm__input" type="text" placeholder="' + U.esc(name) + '">' +
      '<div class="pr-addons-confirm__acts">' +
        '<button type="button" class="pr-addons-confirm__no">Cancel</button>' +
        '<button type="button" class="pr-addons-confirm__yes" disabled>Delete</button>' +
      "</div>"
    );
    var backdrop = U.el("div", "pr-addons-confirm-mask", "");
    backdrop.appendChild(modal);
    mask.appendChild(backdrop);

    var input = modal.querySelector(".pr-addons-confirm__input");
    var yes = modal.querySelector(".pr-addons-confirm__yes");
    input.addEventListener("input", function () {
      yes.disabled = input.value.trim() !== name;
    });
    modal.querySelector(".pr-addons-confirm__no").addEventListener("click", function () { backdrop.remove(); });
    yes.addEventListener("click", function () {
      backdrop.remove();
      P.api("addons/plugins/delete", {
        method: "POST",
        json: { server: serverId(), name: name, dir: dir, confirm: name },
      })
        .then(function () {
          P.toast("Plugin deleted", name, "success");
          openPanel("plugins");
        })
        .catch(function (err) { P.toast("Delete failed", U.esc(err.message || "unknown"), "error"); });
    });
  }

  function pluginUpload(file) {
    if (!/\.jar$/i.test(file.name)) {
      P.toast("Upload rejected", "Only .jar files are accepted.", "error");
      return;
    }
    if (file.size > 100 * 1024 * 1024) {
      P.toast("Upload rejected", "File exceeds the 100 MiB limit.", "error");
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      var b64 = String(reader.result).split(",")[1] || "";
      P.api("addons/plugins/upload", {
        method: "POST",
        json: { server: serverId(), name: file.name, dir: "plugins", content: b64 },
      })
        .then(function (res) {
          P.toast("Plugin uploaded", res.name, "success");
          openPanel("plugins");
        })
        .catch(function (err) { P.toast("Upload failed", U.esc(err.message || "unknown"), "error"); });
    };
    reader.readAsDataURL(file);
  }

  function fmtBytes(b) {
    if (b == null || isNaN(b)) return "--";
    var u = ["B", "KiB", "MiB", "GiB"], i = 0;
    b = Number(b);
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? Math.round(b) : b.toFixed(1)) + " " + u[i];
  }

  function escHandler(e) { if (e.key === "Escape") close(); }

  function close() {
    if (!mask) return;
    mask.remove();
    mask = null;
    activePanel = null;
    document.removeEventListener("keydown", escHandler);
  }

  /* ── state + lifecycle ──────────────────────────────────────── */
  var fetchSeq = 0;

  function syncState() {
    if (!isServerPage()) return;
    var seq = ++fetchSeq;
    P.api("addons?server=" + encodeURIComponent(serverId()))
      .then(function (payload) {
        if (seq !== fetchSeq) return;
        hubState = payload;
        ensureTab();
      })
      .catch(function () {
        if (seq !== fetchSeq) return;
        hubState = null;
        removeTab();
      });
  }

  P.ready.then(syncState);
  P.on("page:view", function () {
    close();
    hubState = null;
    removeTab();
    setTimeout(syncState, 120);
  });

  var tabTries = 0;
  function tabRetry() {
    if (mask || !isServerPage()) return;
    if (U.q(".pr-addons-tab")) return;
    if (hubState && hubState.addons && hubState.addons.length) { ensureTab(); return; }
    tabTries += 1;
    if (tabTries < 30) setTimeout(tabRetry, 400);
  }
  P.ready.then(tabRetry);
})();
```

- [ ] **Step 2: Write `public/css/addons.css`**

```css
/*
 * Primus · addons.css
 * Addon hub overlay + plugin manager panel. Token-driven, matching
 * motd.css conventions (mask z-index 95, pr-scale-in card).
 */

.pr-addons-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0 12px;
  font-size: var(--pr-text-sm);
  font-weight: var(--pr-font-weight-medium);
  color: var(--pr-text-secondary);
  cursor: pointer;
  transition: color var(--pr-dur-1) var(--pr-ease);
}
.pr-addons-tab:hover { color: var(--pr-accent); }
.pr-addons-tab svg { width: 15px; height: 15px; opacity: 0.7; }

.pr-addons-mask {
  position: fixed; inset: 0; z-index: 95;
  background: rgba(5, 6, 14, 0.6);
  backdrop-filter: blur(8px);
  display: flex; align-items: flex-start; justify-content: center;
  padding: 8vh var(--pr-space-4) var(--pr-space-4);
  overflow-y: auto;
}

.pr-addons-card {
  width: 100%; max-width: 760px;
  background: var(--pr-surface-raised);
  border: 1px solid var(--pr-border-strong);
  border-radius: var(--pr-radius-lg);
  box-shadow: var(--pr-shadow-4);
  display: flex; flex-direction: column;
}

.pr-addons-head {
  display: flex; align-items: center; gap: var(--pr-space-3);
  padding: var(--pr-space-4) var(--pr-space-5);
  border-bottom: 1px solid var(--pr-border);
}
.pr-addons-title {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: var(--pr-text-md); font-weight: var(--pr-font-weight-bold);
  color: var(--pr-text-primary); font-family: var(--pr-font-display);
}
.pr-addons-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--pr-accent); box-shadow: var(--pr-shadow-accent);
}
.pr-addons-back {
  background: none; border: 0; color: var(--pr-accent);
  font-size: var(--pr-text-sm); cursor: pointer;
}
.pr-addons-back:hover { text-decoration: underline; }
.pr-addons-close {
  margin-left: auto; background: none; border: 0;
  font-size: 20px; line-height: 1; color: var(--pr-text-muted);
  cursor: pointer; padding: 4px 8px; border-radius: var(--pr-radius-sm);
}
.pr-addons-close:hover { color: var(--pr-text-primary); background: var(--pr-surface-sunken); }

.pr-addons-body { padding: var(--pr-space-5); }

.pr-addons-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: var(--pr-space-3);
}

.pr-addons-app {
  display: flex; gap: var(--pr-space-3); align-items: flex-start;
  padding: var(--pr-space-4);
  background: var(--pr-surface-sunken);
  border: 1px solid var(--pr-border);
  border-radius: var(--pr-radius-md);
  cursor: pointer; position: relative;
  transition: border-color var(--pr-dur-1) var(--pr-ease), transform var(--pr-dur-1) var(--pr-ease);
}
.pr-addons-app:hover { border-color: var(--pr-accent); transform: translateY(-1px); }
.pr-addons-app.is-locked { cursor: default; opacity: 0.6; }
.pr-addons-app.is-locked:hover { border-color: var(--pr-border); transform: none; }
.pr-addons-app__icon { width: 34px; height: 34px; flex: none; color: var(--pr-accent); }
.pr-addons-app__icon svg { width: 100%; height: 100%; }
.pr-addons-app__title {
  font-size: var(--pr-text-base); font-weight: var(--pr-font-weight-bold);
  color: var(--pr-text-primary);
}
.pr-addons-app__desc {
  font-size: var(--pr-text-xs); color: var(--pr-text-muted);
  margin-top: 2px; line-height: 1.4;
}
.pr-addons-app__tag {
  position: absolute; top: 8px; right: 8px;
  font-size: 9px; font-weight: var(--pr-font-weight-bold);
  text-transform: uppercase; letter-spacing: 0.05em;
  padding: 2px 7px; border-radius: var(--pr-radius-pill);
  background: var(--pr-accent-weak); color: var(--pr-accent);
}

.pr-addons-empty {
  grid-column: 1 / -1; padding: var(--pr-space-8) var(--pr-space-4);
  text-align: center; color: var(--pr-text-muted); font-size: var(--pr-text-sm);
}
.pr-addons-loading { display: flex; justify-content: center; padding: var(--pr-space-10); }

.pr-addons-table { width: 100%; border-collapse: collapse; font-size: var(--pr-text-sm); }
.pr-addons-table th {
  text-align: left; padding: var(--pr-space-2) var(--pr-space-3);
  color: var(--pr-text-muted); font-weight: var(--pr-font-weight-medium);
  border-bottom: 1px solid var(--pr-border);
  font-size: var(--pr-text-xs); text-transform: uppercase; letter-spacing: 0.03em;
}
.pr-addons-row td { padding: var(--pr-space-3); border-bottom: 1px solid var(--pr-border); }
.pr-addons-row__name { font-family: var(--pr-font-mono); color: var(--pr-text-primary); word-break: break-all; }
.pr-addons-row__size { color: var(--pr-text-muted); white-space: nowrap; }
.pr-addons-row__acts { text-align: right; white-space: nowrap; }

.pr-addons-act {
  background: var(--pr-surface-sunken); border: 1px solid var(--pr-border-strong);
  color: var(--pr-text-secondary); font-size: var(--pr-text-xs);
  font-weight: var(--pr-font-weight-medium);
  padding: 5px 12px; border-radius: var(--pr-radius-sm); cursor: pointer;
}
.pr-addons-act:hover { border-color: var(--pr-accent); color: var(--pr-accent); }
.pr-addons-act--danger:hover { border-color: var(--pr-danger); color: var(--pr-danger); }

.pr-addons-pill {
  font-size: 10px; font-weight: var(--pr-font-weight-bold);
  padding: 3px 9px; border-radius: var(--pr-radius-pill);
  text-transform: uppercase; letter-spacing: 0.04em;
}
.pr-addons-pill[data-state="on"] { background: var(--pr-success-weak); color: var(--pr-success); }
.pr-addons-pill[data-state="off"] { background: var(--pr-warning-weak); color: var(--pr-warning); }

.pr-addons-upload {
  margin-top: var(--pr-space-4);
  padding: var(--pr-space-5);
  border: 2px dashed var(--pr-border-strong);
  border-radius: var(--pr-radius-md);
  display: flex; align-items: center; gap: var(--pr-space-3); justify-content: center;
  transition: border-color var(--pr-dur-1) var(--pr-ease);
}
.pr-addons-upload.is-drag { border-color: var(--pr-accent); background: var(--pr-accent-weak); }
.pr-addons-upload__btn {
  background: var(--pr-accent); border: 1px solid var(--pr-accent);
  color: var(--pr-accent-contrast); font-size: var(--pr-text-sm);
  font-weight: var(--pr-font-weight-bold); padding: 7px 16px;
  border-radius: var(--pr-radius-md); cursor: pointer;
}
.pr-addons-upload__btn:hover { background: var(--pr-accent-strong); }
.pr-addons-upload__hint { font-size: var(--pr-text-xs); color: var(--pr-text-muted); }

.pr-addons-confirm-mask {
  position: absolute; inset: 0;
  background: rgba(5, 6, 14, 0.55);
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--pr-radius-lg);
}
.pr-addons-confirm {
  width: 90%; max-width: 420px;
  background: var(--pr-surface-raised);
  border: 1px solid var(--pr-border-strong);
  border-radius: var(--pr-radius-md); padding: var(--pr-space-5);
}
.pr-addons-confirm__title {
  font-size: var(--pr-text-base); font-weight: var(--pr-font-weight-bold);
  color: var(--pr-text-primary); margin-bottom: var(--pr-space-2);
}
.pr-addons-confirm p { font-size: var(--pr-text-sm); color: var(--pr-text-secondary); }
.pr-addons-confirm p code { font-family: var(--pr-font-mono); color: var(--pr-accent); }
.pr-addons-confirm__input {
  width: 100%; margin-top: var(--pr-space-3);
  background: var(--pr-surface-sunken); border: 1px solid var(--pr-border-strong);
  border-radius: var(--pr-radius-sm); color: var(--pr-text-primary);
  font-family: var(--pr-font-mono); font-size: var(--pr-text-sm);
  padding: var(--pr-space-2) var(--pr-space-3); outline: none;
}
.pr-addons-confirm__input:focus { border-color: var(--pr-accent); }
.pr-addons-confirm__acts { display: flex; gap: var(--pr-space-2); justify-content: flex-end; margin-top: var(--pr-space-4); }
.pr-addons-confirm__no {
  background: none; border: 1px solid var(--pr-border-strong); color: var(--pr-text-secondary);
  font-size: var(--pr-text-sm); padding: 7px 14px;
  border-radius: var(--pr-radius-md); cursor: pointer;
}
.pr-addons-confirm__yes {
  background: var(--pr-danger); border: 1px solid var(--pr-danger); color: #fff;
  font-size: var(--pr-text-sm); font-weight: var(--pr-font-weight-bold);
  padding: 7px 14px; border-radius: var(--pr-radius-md); cursor: pointer;
}
.pr-addons-confirm__yes:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 640px) {
  .pr-addons-card { max-width: 92vw; }
  .pr-addons-mask { padding: 4vh var(--pr-space-3) var(--pr-space-3); }
  .pr-addons-table th:nth-child(3), .pr-addons-row td:nth-child(3) { display: none; }
}
```

- [ ] **Step 3: Wire the wrapper (workspace + live)**

Workspace `dashboard/wrapper.blade.php` — after the motd.css line add:

```html
<link rel="stylesheet" href="{webroot/public}/css/addons.css?v=1788700901{timestamp}" id="primus-addons">
```

After the motd.js script line add:

```html
<script src="{webroot/public}/js/addons.js?v=1788700901{timestamp}" defer></script>
```

Live regeneration (same sed chain as MOTD, new cache-bust version `v=1788900000`):

```bash
sed -e 's/{identifier}/primus/g' -e 's#{webroot/public}#/extensions/primus#g' \
  -e 's/{timestamp}//g' -e 's/v=1788700901/v=1788900000/g' \
  /workspace/dashboard/wrapper.blade.php > /var/www/pterodactyl/.blueprint/extensions/primus/wrappers/dashboard.blade.php
cp /workspace/public/js/addons.js /var/www/pterodactyl/.blueprint/extensions/primus/public/js/addons.js
cp /workspace/public/css/addons.css /var/www/pterodactyl/.blueprint/extensions/primus/public/css/addons.css
cd /var/www/pterodactyl && php artisan view:clear
```

- [ ] **Step 4: Lint + asset checks**

```bash
node --check /workspace/public/js/addons.js
curl -s -o /dev/null -w "addons.js: %{http_code}\n" "http://localhost:8081/extensions/primus/js/addons.js?v=1788900000"
curl -s -o /dev/null -w "addons.css: %{http_code}\n" "http://localhost:8081/extensions/primus/css/addons.css?v=1788900000"
```

Expected: `node --check` silent; both `200`.

- [ ] **Step 5: Puppeteer UI suite**

Write `/tmp/opencode/addons_ui.js` (login once — reuse the MOTD suite login helper; admin session):

1. `/server/92cb4c95`: `.pr-addons-tab` visible with text "Addons"; `.pr-motd-tab` also present (coexistence).
2. Click Addons tab → `.pr-addons-mask` with grid; count `.pr-addons-app` === 12; `.pr-addons-app.is-locked` count === 11; one non-locked card (plugins).
3. Click the plugins card → `.pr-plugins-panel` renders; table rows === 3 (seeded); `Example.jar` shows `enabled` pill; `Other.jar.disabled` shows `disabled` pill; upload zone present.
4. Toggle `Example.jar` (click its Disable button) → toast appears; re-enter panel → pill now `disabled`; read-back via API fetch inside the page.
5. Toggle back → pill `enabled`.
6. Delete flow: click Delete on `Other.jar.disabled` → confirm modal; Delete button disabled until input matches; wrong input keeps it disabled; correct name enables it; confirm → toast + row gone (re-seed via tinker afterwards to restore fixture).
7. Upload: set a file through the hidden input (Puppeteer `elementHandle.uploadFile` with a local fixture `/tmp/opencode/dummy.jar` created by the suite: 12-byte file) → toast + row `dummy.jar` appears; then delete it through the API to clean up.
8. `page:view` stale check: navigate `/server/92cb4c95` → `/server/dd2cfabf` — `.pr-addons-tab` removed (Rust has no addons state... note: GET /addons works for admin on Rust since admin can access; assert tab is removed then re-added only if hubState returns addons with canUse — the spec's hub is universal, so assert: tab removed on transition, and after syncState on Rust the tab is present again only if addons array non-empty — admin sees it; verify it renders with 12 cards).
9. Esc closes overlay.
10. Read-only subuser (login as `addonguest@example.com` / `AddonGuest123!` — seeded in Task 2 suite): tab visible, hub shows cards, plugins panel renders WITHOUT toggle/delete buttons and WITHOUT upload zone.

Note for case 8: keep the assertion simple — after settling on the Rust page as admin, `.pr-addons-tab` must exist again (hub is server-agnostic for admins).

- [ ] **Step 6: Run the suite**

```bash
php artisan tinker --execute="require '/tmp/opencode/plugins_seed.php';"
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/addons_ui.js
```

Expected: 10/10 PASS. If login 429 appears, wait 65s and re-run.

**Task 4 exit criteria:** UI suite 10/10 PASS; `node --check` clean; wrapper has exactly 2 new lines in both copies; no `[primus]` console errors.

---

### Task 5: Admin Addons page — toggle cards + audit viewer

**Files:**
- Modify: `admin/controller.php` (index passes addons + audit rows)
- Modify: `admin/view.blade.php` (new Addons tab section)

**Interfaces:**
- Consumes: `AddonRegistry::all()`, `AddonGate::enabled()`, `AddonAudit` model, `ThemeSetting`; the Task 3 `POST /addons/admin/toggle` endpoint (already live).
- Produces: `$addons` (array of `['id','title','description','category','enabled','comingSoon']`) and `$audit` (paginated 50 newest rows: `created_at, addon, action, target, user_email, server_name`) passed to the admin view; DOM hooks `.prx-addon-toggle[data-addon]` for the toggle switches; audit table `.prx-audit-table`.

- [ ] **Step 1: Modify `admin/controller.php` — inject addons + audit into index()**

Add to the `use` block:

```php
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\AddonRegistry;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AddonGate;
```

Inside `index()`, add to the `->with([...])` array (after `'settings' => [...]`):

```php
'addons' => array_map(function ($m) {
    return [
        'id' => $m['id'],
        'title' => $m['title'],
        'description' => $m['description'],
        'category' => $m['category'],
        'enabled' => AddonGate::enabled((string) $m['id']),
        'comingSoon' => (bool) $m['comingSoon'],
    ];
}, AddonRegistry::all()),
'audit' => AddonAudit::query()
    ->join('users', 'users.id', '=', 'primus_addon_audit.user_id')
    ->join('servers', 'servers.id', '=', 'primus_addon_audit.server_id')
    ->orderByDesc('primus_addon_audit.id')
    ->limit(50)
    ->get(['primus_addon_audit.*', 'users.email as user_email', 'servers.name as server_name'])
    ->map(fn ($r) => [
        'when' => $r->created_at?->diffForHumans() ?? 'just now',
        'addon' => $r->addon,
        'action' => $r->action,
        'target' => $r->target,
        'user' => $r->user_email,
        'server' => $r->server_name,
    ]),
```

- [ ] **Step 2: Modify `admin/view.blade.php` — Addons tab section**

The existing view renders tabbed sections (locate the existing tab navigation markup — the AI usage dashboard and customizer panels use `prx-tab` style sections; follow the same pattern). Add a new tab "Addons" to the nav, and a section:

```blade
{{-- ── Addons ─────────────────────────────────────────────── --}}
<section class="prx-tab-panel" data-prx-tab="addons" hidden>
  <div class="prx-addons-admin">
    <div class="prx-addons-admin__grid">
      @foreach ($addons as $a)
        <div class="prx-addon-card" data-addon="{{ $a['id'] }}">
          <div class="prx-addon-card__head">
            <span class="prx-addon-card__title">{{ $a['title'] }}</span>
            @if ($a['comingSoon'])
              <span class="prx-addon-card__soon">coming soon</span>
            @endif
            <label class="prx-switch">
              <input type="checkbox" class="prx-addon-toggle" data-addon="{{ $a['id'] }}"
                @if ($a['enabled']) checked @endif>
              <span class="prx-switch__track"></span>
            </label>
          </div>
          <p class="prx-addon-card__desc">{{ $a['description'] }}</p>
          <span class="prx-addon-card__cat">{{ $a['category'] }}</span>
        </div>
      @endforeach
    </div>

    <h3 class="prx-audit__title">Audit log</h3>
    <table class="prx-audit-table">
      <thead>
        <tr><th>When</th><th>Addon</th><th>Action</th><th>Target</th><th>User</th><th>Server</th></tr>
      </thead>
      <tbody>
        @forelse ($audit as $row)
          <tr>
            <td>{{ $row['when'] }}</td>
            <td>{{ $row['addon'] }}</td>
            <td>{{ $row['action'] }}</td>
            <td class="prx-audit__target">{{ $row['target'] }}</td>
            <td>{{ $row['user'] }}</td>
            <td>{{ $row['server'] }}</td>
          </tr>
        @empty
          <tr><td colspan="6">No addon actions recorded yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
```

Append to `admin/admin.css` (workspace — the admin page style sheet):

```css
/* ── addon framework admin ─────────────────────────────── */
.prx-addons-admin__grid {
  display: grid; gap: 12px;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  margin-bottom: 24px;
}
.prx-addon-card {
  background: var(--pr-surface-sunken, #12142a);
  border: 1px solid var(--pr-border, #2a2f4a);
  border-radius: 12px; padding: 14px 16px;
}
.prx-addon-card__head { display: flex; align-items: center; gap: 10px; }
.prx-addon-card__title { font-weight: 650; font-size: 14px; }
.prx-addon-card__soon {
  font-size: 9px; font-weight: 700; text-transform: uppercase;
  background: rgba(109, 93, 246, .16); color: #8577ff;
  padding: 2px 7px; border-radius: 999px;
}
.prx-addon-card__desc { font-size: 12px; opacity: .7; margin: 8px 0 10px; }
.prx-addon-card__cat {
  font-size: 10px; text-transform: uppercase; letter-spacing: .05em; opacity: .5;
}
.prx-switch { margin-left: auto; cursor: pointer; }
.prx-switch input { display: none; }
.prx-switch__track {
  display: inline-block; width: 34px; height: 19px;
  background: rgba(160, 170, 213, .25); border-radius: 999px;
  position: relative; transition: background .18s ease;
}
.prx-switch__track::after {
  content: ""; position: absolute; top: 2.5px; left: 3px;
  width: 14px; height: 14px; border-radius: 50%; background: #edeff7;
  transition: left .18s ease;
}
.prx-switch input:checked + .prx-switch__track { background: #6d5df6; }
.prx-switch input:checked + .prx-switch__track::after { left: 17px; }
.prx-audit__title { font-size: 15px; margin: 26px 0 10px; }
.prx-audit-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.prx-audit-table th {
  text-align: left; padding: 8px 10px; opacity: .55;
  border-bottom: 1px solid var(--pr-border, #2a2f4a);
  text-transform: uppercase; font-size: 10px; letter-spacing: .04em;
}
.prx-audit-table td { padding: 9px 10px; border-bottom: 1px solid rgba(160, 170, 213, .12); }
.prx-audit__target { font-family: "JetBrains Mono", monospace; font-size: 11px; }
```

Add the toggle wiring to the existing admin JS (inside the view's main `<script>` block, alongside the existing save handlers):

```javascript
/* Addon enable/disable toggles — XHR to the gated endpoint. */
document.querySelectorAll(".prx-addon-toggle").forEach(function (cb) {
  cb.addEventListener("change", function () {
    var body = { addon: cb.dataset.addon, enabled: cb.checked };
    fetch("/extensions/primus/addons/admin/toggle", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-TOKEN": (document.querySelector('meta[name="csrf-token"]') || {}).content || "",
      },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { cb.checked = !cb.checked; alert(j.error || "Toggle failed."); }
    }).catch(function () { cb.checked = !cb.checked; alert("Toggle failed."); });
  });
});
```

Note: the view posts with `X-CSRF-TOKEN` header (the same mechanism `P.api` uses on the client side). If the existing admin save flow uses a different token mechanism, copy whatever pattern `admin/save` uses in the existing script block.

- [ ] **Step 3: Sync to live**

```bash
sed 's/{identifier}/primus/g' /workspace/admin/controller.php > /var/www/pterodactyl/app/Http/Controllers/Admin/Extensions/primus/primusExtensionController.php
# view: live copy is rendered from resources/views — copy the whole file
cp /workspace/admin/view.blade.php /var/www/pterodactyl/resources/views/admin/extensions/primus/index.blade.php
cp /workspace/admin/admin.css /var/www/pterodactyl/.blueprint/extensions/primus/public/../admin/admin.css 2>/dev/null || cp /workspace/admin/admin.css /var/www/pterodactyl/.blueprint/extensions/primus/admin/admin.css
cd /var/www/pterodactyl && php artisan view:clear
php -l /var/www/pterodactyl/app/Http/Controllers/Admin/Extensions/primus/primusExtensionController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Puppeteer admin suite (`/tmp/opencode/addons_admin_ui.js`)**

Login as admin → `/admin/extensions/primus`:

1. Page renders; Addons tab nav entry exists; clicking it shows the section.
2. 12 `.prx-addon-card` elements; plugins card not marked coming-soon; worlds card marked coming-soon.
3. Audit table shows rows from the Task 3/4 suite runs (`plugins toggle` etc.).
4. Toggle worlds off → reload → worlds unchecked; client hub (`/server/92cb4c95`) shows 11 cards (worlds missing); toggle worlds back on → 12 cards again.
5. Audit table gains a `framework disable` + `framework enable` row (reload).

**Task 5 exit criteria:** admin suite 5/5 PASS; controller lint clean; toggles persist through reload; hub reflects the toggle.

---

### Task 6: Regression, packaging, commit

**Files:**
- Test: `/tmp/opencode/addons_regression.js`
- Packaging: `build.sh` run (no file changes)

- [ ] **Step 1: Puppeteer regression suite**

Login once (admin). Cases:

a. Dashboard `/`: server cards render; neither `.pr-addons-tab` nor `.pr-motd-tab` present.
b. `/server/92cb4c95` console: `.pr-term__chrome` count === 1, `.pr-ai-fixer-bar` present, `.pr-graphs` present (no duplicated chrome).
c. MOTD tab still works: click `.pr-motd-tab` → `.pr-motd-mask` opens; close.
d. Addons tab + MOTD tab coexist in subnav (both visible).
e. `/server/dd2cfabf`: no visible `.pr-motd-tab` (Rust gate); `.pr-addons-tab` present for admin (hub is universal) — open hub, 12 cards, plugins card locked (admin canUse true — but clicking plugins panel on Rust will error 502; assert the panel shows the error state gracefully, not a crash).
f. `GET /extensions/primus/settings.json` → 200 (fetch in page).
g. No `[primus]` or `addons` console errors across the tour (exclude stock `object Y`/WebSocket/504/405 noise).
h. `curl -s http://localhost:8081/server/92cb4c95 | grep -c 'addons.js'` → 1 (wrapper loads it once).

- [ ] **Step 2: Run regression + re-run all suites**

```bash
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/addons_regression.js
bash /tmp/opencode/addons_api_test.sh
```

Expected: regression 8/8, backend 12/12.

- [ ] **Step 3: Packaging**

```bash
cd /workspace && bash build.sh
```

Expected: `Built dist/primus.blueprint` exit 0.

- [ ] **Step 4: Cleanup live test artifacts**

```bash
php artisan tinker --execute="
\$g = \Pterodactyl\Models\User::where('email', 'addonguest@example.com')->first();
if (\$g) { \Pterodactyl\Models\Subuser::where('user_id', \$g->id)->delete(); }
echo 'guest subuser grants removed';"
```

(Removes only the rows this plan created — the addonguest user may remain harmlessly, or be removed with a targeted `User::where('email', ...)` delete if desired. DB deletes of our own seeded test rows are in scope.)

- [ ] **Step 5: Commit (only framework files)**

```bash
git add private/Services/AddonRegistry.php private/Services/PathGuard.php \
  private/Controllers/AddonGate.php private/Controllers/AddonsController.php \
  private/Controllers/PluginsController.php private/Models/AddonAudit.php \
  private/migrations/2026_09_09_000001_create_primus_addon_audit_table.php \
  private/routes.php admin/controller.php admin/view.blade.php admin/admin.css \
  public/js/addons.js public/css/addons.css dashboard/wrapper.blade.php \
  docs/superpowers/plans/2026-09-09-addon-framework-core.md
git commit -m "feat: addon framework core with plugin manager pilot

Adds the addon framework (AddonRegistry, AddonGate choke point, PathGuard
path jail, intent-before-action audit log, dedicated admin Addons page)
and the first addon built on it — the Plugin Manager with jar listing,
enable/disable via rename, typed-confirm delete and size-capped upload.
Future managers register as coming-soon roadmap cards.

Co-authored-by: monkeycode-ai <monkeycode-ai@chaitin.com>"
```

Never `git add -A`; never commit `dist/`.

**Task 6 exit criteria:** regression 8/8 + backend 12/12 + UI 10/10 + admin 5/5 + PathGuard 8/8 + gate 8/8 all green; build exit 0; commit created with only the 15 intended files.

---

## Risks & mitigations

- **Subnav DOM drift**: tab insertion anchored to MOTD tab / last server link, re-probed in the UI suite; `page:view` handler removes stale tabs.
- **getDirectory on missing folder** (server without plugins/): Wings returns 404 → DaemonConnectionException 404 branch missing in `index()` — the plugins list endpoint must catch 404 as "empty list", not 502. **Implement:** in `PluginsController::index`, catch DaemonConnectionException with `getStatusCode() === 404` and treat that directory as empty (continue), only non-404 errors return 502.
- **Upload through base64**: doubles request size (~133%); 100 MiB cap on decoded bytes keeps it safe. The client-side 100 MiB check prevents wasted transfer; server enforces on decoded length.
- **Rate limits**: mutations gated at 60/h default; login limiter (10/min/IP) can flake suites — suites log in once and the plan documents the 65s wait.
- **Admin route availability**: the Addons admin page reuses the existing Blueprint extension route (no new route registration) — verified live (`admin/extensions/primus` exists).
- **Rename race**: toggle reads the directory listing to decide direction (enabled→disabled vs disabled→enabled), so a stale UI cannot force the wrong direction twice.

## Self-check before completion

- [ ] Every spec section maps to a task (registry T1, gate T2, hub+plugins API T3, client T4, admin T5, audit migration T1, testing T1-T6, packaging T6).
- [ ] `php -l` on all 6 PHP files; `node --check` on addons.js.
- [ ] PathGuard 8/8, gate 8/8, backend 12/12, UI 10/10, admin 5/5, regression 8/8 — all PASS with captured output.
- [ ] Audit rows exist for toggle/delete/upload/enable/disable.
- [ ] `build.sh` exit 0; staged file list matches the commit exactly; dist/ untouched.
- [ ] Summary to the user with verified evidence.




