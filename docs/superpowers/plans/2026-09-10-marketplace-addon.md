# Marketplace Addon (Modrinth + CurseForge) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 12th addon — Marketplace — letting panel users search Modrinth/CurseForge and one-click install mod/plugin jars, with provider API keys held server-side.

**Architecture:** Extends the shipped addon framework (commit `595d8c7`): a `MarketplaceClient` service (provider adapters + Laravel cache + hardened download) behind a gated `MarketplaceController`; client UI inside the existing `addons.js` overlay; key admin in the existing Addons admin page.

**Tech Stack:** PHP 8.2 / Laravel (`Illuminate\Http\Client` via Guzzle), vanilla JS on the Primus engine (`P.util/P.api/P.toast`), Puppeteer + curl + tinker test suites.

## Global Constraints

- Every marketplace route: `auth` → `AddonGate::guard('marketplace', ...)` → perms. Install additionally: `file.create` + `Shared::rateLimited($userId, 'addon.marketplace')` (audit-row counter, `addons.rate_limit_per_hour`, default 60).
- Install target dir derives from server-validated `type` (`plugin`→`plugins/`, `mod`→`mods/`) — never from client paths. Final path through `PathGuard::resolve($path, ['plugins','mods'])`.
- Download URL derived server-side from provider ids; https only; hostname allowlist: `cdn.modrinth.com`, `edge.forgecdn.net`, `mediafilez.forgecdn.net` (redirect target, verified live). Client can never supply a URL.
- Size cap `marketplace.max_download_mib` (default 200) on downloaded bytes; `PK` zip-header sniff; `.jar` filename suffix enforced.
- Keys only in `ThemeSetting` rows `marketplace.curseforge.key` / `marketplace.modrinth.key`; never echoed back, never in source; seeded live via tinker (values supplied in chat by the user, NOT committed).
- CurseForge classIds verified live: `5` = Bukkit Plugins, `6` = Mods (gameId 432). Modrinth facet: `project_type:plugin|mod`. Provider cache: `Cache::remember`, `marketplace.cache_seconds` (default 600).
- Audit row BEFORE the Wings write: `AddonGate::audit($user, $server, 'marketplace', 'install', $target, [...])`.
- Every client render path escapes server-derived strings via `U.esc` (including toasts — post-`595d8c7` rule).
- Workspace files keep `{identifier}` placeholders; sync via `sed 's/{identifier}/primus/g'`. Live `Shared.php` autoloads from `app/Controllers/` (both copies synced when touched).
- Wrapper cache-bust bumps to `v=1789000000` (global sed like last cycle; benign full refetch).
- Never `git add -A`; never commit `dist/`. Commit style: `type: subject` (hook auto-appends co-author trailer — pass subject only).
- Test logins: admin `admin@example.com`/`Password123!`, read-only guest `addonguest@example.com`/`AddonGuest123!` (subuser grant on server 2 re-created for suites, removed in Task 4 cleanup). Login rate limit 10/min/IP — suites log in sparingly, wait 65s on 429.
- MC server: uuid `92cb4c95-0f50-4955-94c6-9879b01b1eb4` / short `92cb4c95` (id 2). Rust: short `dd2cfabf` (id 4).
- Tinker suites print `PASS=0 FAIL=0` despite real PASS lines (PsySH artifact) — judge by check lines + exit code.

---

### Task 1: MarketplaceClient service (providers, cache, hardened download)

**Files:**
- Create: `private/Services/MarketplaceClient.php`
- Test: `/tmp/opencode/marketplace_client_test.php`

**Interfaces:**
- Consumes: `ThemeSetting` static `get(key, default)` / `set(key, value)`; Laravel `Cache`, `Illuminate\Support\Facades\Http`.
- Produces (used by Task 2's controller):
  - `MarketplaceClient::search(string $q, string $provider, string $type): array` → `['results' => [['id','name','summary','author','downloads','icon','updated','slug'], ...]]` (`$type` ∈ `mod|plugin`, `$provider` ∈ `modrinth|curseforge`)
  - `MarketplaceClient::versions(string $project, string $provider, string $type): array` → `[['id','name','date','size','filename','game_versions'=>[...]], ...]` newest-first
  - `MarketplaceClient::download(string $provider, string $project, string $version, string $type): array` → `['name' => 'x.jar', 'bytes' => '<binary>']` — throws `MarketplaceException` on any failure
  - `class MarketplaceException extends \RuntimeException` with public `string $provider` — constructor `(string $provider, string $message)`

- [ ] **Step 1: Write the tinker test suite (RED)**

Create `/tmp/opencode/marketplace_client_test.php`:

```php
<?php
/* MarketplaceClient tinker suite — 10 cases. Run via:
 * php artisan tinker --execute="require '/tmp/opencode/marketplace_client_test.php';" */
$C = \Pterodactyl\BlueprintFramework\Extensions\primus\Services\MarketplaceClient::class;
use \Pterodactyl\BlueprintFramework\Extensions\primus\Services\MarketplaceException;
$pass = 0; $fail = 0;
function ok($n) { global $pass; $pass++; echo "PASS: $n" . PHP_EOL; }
function bad($n, $e = '') { global $fail; $fail++; echo "FAIL: $n" . ($e ? " — $e" : '') . PHP_EOL; }
$TS = \Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::class;

/* 1 — Modrinth search 'sodium' type=mod returns normalized hits */
try {
    $r = $C::search('sodium', 'modrinth', 'mod');
    $first = $r['results'][0] ?? null;
    if ($first && isset($first['id'], $first['name'], $first['summary'], $first['downloads'], $first['icon'], $first['updated']))
        ok('1 modrinth search sodium');
    else bad('1 modrinth search sodium', json_encode($first));
} catch (\Throwable $e) { bad('1 modrinth search sodium', $e->getMessage()); }

/* 2 — CurseForge search 'jei' type=mod returns hits */
try {
    $r = $C::search('jei', 'curseforge', 'mod');
    if (count($r['results']) > 0 && isset($r['results'][0]['id'], $r['results'][0]['name']))
        ok('2 curseforge search jei');
    else bad('2 curseforge search jei', 'empty');
} catch (\Throwable $e) { bad('2 curseforge search jei', $e->getMessage()); }

/* 3 — type=plugin uses classId 5 (differs from mod results) */
try {
    $p = $C::search('worldedit', 'curseforge', 'plugin');
    $m = $C::search('worldedit', 'curseforge', 'mod');
    if (count($p['results']) > 0 && $p['results'][0]['id'] !== ($m['results'][0]['id'] ?? 'x'))
        ok('3 curseforge plugin vs mod classes');
    else bad('3 curseforge plugin vs mod classes', 'same/empty');
} catch (\Throwable $e) { bad('3 curseforge plugin vs mod classes', $e->getMessage()); }

/* 4 — versions(): modrinth sodium has file fields */
try {
    $v = $C::versions('AANobbMI', 'modrinth', 'mod');
    $f = $v[0] ?? null;
    if ($f && isset($f['id'], $f['name'], $f['date'], $f['size'], $f['filename'], $f['game_versions']))
        ok('4 modrinth versions sodium');
    else bad('4 modrinth versions sodium', json_encode($f));
} catch (\Throwable $e) { bad('4 modrinth versions sodium', $e->getMessage()); }

/* 5 — versions(): curseforge JEI 238222 */
try {
    $v = $C::versions('238222', 'curseforge', 'mod');
    if (count($v) > 0 && isset($v[0]['filename'])) ok('5 curseforge versions jei');
    else bad('5 curseforge versions jei', 'empty');
} catch (\Throwable $e) { bad('5 curseforge versions jei', $e->getMessage()); }

/* 6 — cache: second identical search is near-instant */
try {
    $t0 = microtime(true); $C::search('sodium-cache-test-xyz', 'modrinth', 'mod');
    $t1 = microtime(true); $C::search('sodium-cache-test-xyz', 'modrinth', 'mod');
    $t2 = microtime(true);
    if (($t2 - $t1) * 1000 < 20) ok('6 cache hit fast on repeat');
    else bad('6 cache hit fast on repeat', sprintf('%.1fms', ($t2 - $t1) * 1000));
} catch (\Throwable $e) { bad('6 cache hit fast on repeat', $e->getMessage()); }

/* 7 — missing curseforge key → MarketplaceException; key restored after */
try {
    $k = $TS::get('marketplace.curseforge.key');
    $TS::set('marketplace.curseforge.key', '');
    try { $C::search('jei', 'curseforge', 'mod'); bad('7 missing cf key throws', 'no exception'); }
    catch (MarketplaceException $e) { ok('7 missing cf key throws'); }
    finally { $TS::set('marketplace.curseforge.key', (string) $k); }
} catch (\Throwable $e) { bad('7 missing cf key throws', $e->getMessage()); }

/* 8 — invalid provider rejected */
try { $C::search('x', 'evil', 'mod'); bad('8 invalid provider rejected', 'no exception'); }
catch (\InvalidArgumentException $e) { ok('8 invalid provider rejected'); }

/* 9 — download() returns PK jar bytes with .jar name (sodium 0.9.2-beta.1 ~1.2MB) */
try {
    $d = $C::download('modrinth', 'AANobbMI', 'gQDMcWww', 'mod');
    if (str_ends_with($d['name'], '.jar') && strlen($d['bytes']) > 100000 && strncmp($d['bytes'], 'PK', 2) === 0)
        ok('9 modrinth download real jar');
    else bad('9 modrinth download real jar', $d['name'] . ' ' . strlen($d['bytes']));
} catch (\Throwable $e) { bad('9 modrinth download real jar', $e->getMessage()); }

/* 10 — unknown version id → MarketplaceException (never crash) */
try { $C::download('modrinth', 'AANobbMI', 'zzzznotrealzzzz', 'mod'); bad('10 unknown version throws', 'no exception'); }
catch (MarketplaceException $e) { ok('10 unknown version throws'); }

echo "PASS=$pass FAIL=$fail" . PHP_EOL;
if ($fail > 0) exit(1);
```

- [ ] **Step 2: Seed the live key settings (tinker)**

Run from `/var/www/pterodactyl` (key values: the two keys the user supplied in chat — NEVER write them into any workspace file):

```bash
php artisan tinker --execute="
use Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting;
ThemeSetting::set('marketplace.curseforge.key', '<CF-KEY-FROM-CHAT>');
ThemeSetting::set('marketplace.modrinth.key', '<MR-KEY-FROM-CHAT>');
echo ThemeSetting::get('marketplace.curseforge.key') ? 'cf-set ' : 'cf-missing ';
echo ThemeSetting::get('marketplace.modrinth.key') ? 'mr-set' : 'mr-missing';
"
```

Expected: `cf-set mr-set`.

- [ ] **Step 3: Run suite to verify it fails (RED)**

```bash
php artisan tinker --execute="require '/tmp/opencode/marketplace_client_test.php';"
```

Expected: `Error Class "...MarketplaceClient" not found` (suite aborts before PASS lines).

- [ ] **Step 4: Implement MarketplaceClient**

Create `/workspace/private/Services/MarketplaceClient.php`:

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;

class MarketplaceException extends \RuntimeException
{
    public function __construct(public readonly string $provider, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * MarketplaceClient — one hardened door to Modrinth + CurseForge.
 * Keys live in ThemeSetting; responses cached; downloads constrained to
 * an https host allowlist, a size cap and a PK zip sniff. All provider
 * errors surface as MarketplaceException (never a raw HTTP exception).
 */
class MarketplaceClient
{
    private const CF_GAME = 432;                       // Minecraft
    private const CF_CLASS = ['plugin' => 5, 'mod' => 6]; // verified live
    private const CF_HOST = 'https://api.curseforge.com/v1';
    private const MR_HOST = 'https://api.modrinth.com/v2';
    private const ALLOWED_HOSTS = ['cdn.modrinth.com', 'edge.forgecdn.net', 'mediafilez.forgecdn.net'];
    private const UA = 'PrimusPanel/1.0 (Pterodactyl extension marketplace addon)';

    public static function search(string $q, string $provider, string $type): array
    {
        self::assertProvider($provider);
        self::assertType($type);

        return self::cached('s.' . $provider . '.' . $type . '.' . md5($q), function () use ($q, $provider, $type) {
            $results = [];
            if ($provider === 'modrinth') {
                $facet = urlencode(json_encode([["project_type:$type"]]));
                $json = self::mrGet('/search?query=' . urlencode($q) . "&facets=$facet&limit=20");
                foreach ($json['hits'] ?? [] as $h) {
                    $results[] = [
                        'id' => (string) ($h['project_id'] ?? ''),
                        'name' => (string) ($h['title'] ?? ''),
                        'summary' => (string) ($h['description'] ?? ''),
                        'author' => (string) ($h['author'] ?? ''),
                        'downloads' => (int) ($h['downloads'] ?? 0),
                        'icon' => (string) ($h['icon_url'] ?? ''),
                        'updated' => (string) ($h['date_modified'] ?? ''),
                        'slug' => (string) ($h['slug'] ?? ''),
                    ];
                }
            } else {
                $json = self::cfGet('/mods/search', [
                    'gameId' => self::CF_GAME,
                    'classId' => self::CF_CLASS[$type],
                    'searchFilter' => $q,
                    'pageSize' => 20,
                    'sortField' => 2,
                ]);
                foreach ($json['data'] ?? [] as $m) {
                    $results[] = [
                        'id' => (string) ($m['id'] ?? ''),
                        'name' => (string) ($m['name'] ?? ''),
                        'summary' => (string) ($m['summary'] ?? ''),
                        'author' => (string) ($m['authors'][0]['name'] ?? ''),
                        'downloads' => (int) ($m['downloadCount'] ?? 0),
                        'icon' => (string) ($m['logo']['thumbnailUrl'] ?? ''),
                        'updated' => (string) ($m['dateModified'] ?? ''),
                        'slug' => (string) ($m['slug'] ?? ''),
                    ];
                }
            }

            return ['results' => $results];
        });
    }

    public static function versions(string $project, string $provider, string $type): array
    {
        self::assertProvider($provider);
        self::assertType($type);

        return self::cached('v.' . $provider . '.' . $project . '.' . $type, function () use ($project, $provider) {
            $out = [];
            if ($provider === 'modrinth') {
                $json = self::mrGet('/project/' . rawurlencode($project) . '/version');
                foreach ($json as $v) {
                    $file = null;
                    foreach ($v['files'] ?? [] as $f) {
                        if (!empty($f['primary'])) { $file = $f; break; }
                    }
                    $file = $file ?: ($v['files'][0] ?? null);
                    if (!$file || ($file['filename'] ?? '') === '') {
                        continue;
                    }
                    $out[] = [
                        'id' => (string) ($v['id'] ?? ''),
                        'name' => (string) ($v['name'] ?? $file['filename']),
                        'date' => (string) ($v['date_published'] ?? ''),
                        'size' => (int) ($file['size'] ?? 0),
                        'filename' => (string) $file['filename'],
                        'game_versions' => array_slice(array_map('strval', $v['game_versions'] ?? []), 0, 6),
                    ];
                }
            } else {
                $json = self::cfGet('/mods/' . rawurlencode($project) . '/files', ['pageSize' => 20]);
                foreach ($json['data'] ?? [] as $f) {
                    if (empty($f['downloadUrl'])) {
                        continue; // author-disabled files are not installable
                    }
                    $out[] = [
                        'id' => (string) ($f['id'] ?? ''),
                        'name' => (string) ($f['displayName'] ?? $f['fileName'] ?? ''),
                        'date' => (string) ($f['fileDate'] ?? ''),
                        'size' => (int) ($f['fileLength'] ?? 0),
                        'filename' => (string) ($f['fileName'] ?? ''),
                        'game_versions' => array_slice(array_map('strval', $f['gameVersions'] ?? []), 0, 6),
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * Resolve the version server-side and download from the allowlisted
     * CDN. Returns ['name' => 'x.jar', 'bytes' => binary].
     *
     * @return array{name: string, bytes: string}
     */
    public static function download(string $provider, string $project, string $version, string $type): array
    {
        self::assertProvider($provider);
        self::assertType($type);

        if ($provider === 'modrinth') {
            $json = self::mrGet('/version/' . rawurlencode($version));
            $file = null;
            foreach ($json['files'] ?? [] as $f) {
                if (!empty($f['primary'])) { $file = $f; break; }
            }
            $file = $file ?: ($json['files'][0] ?? null);
            $url = (string) ($file['url'] ?? '');
            $filename = (string) ($file['filename'] ?? '');
        } else {
            $json = self::cfGet('/mods/' . rawurlencode($project) . '/files/' . rawurlencode($version));
            $url = (string) ($json['data']['downloadUrl'] ?? '');
            $filename = (string) ($json['data']['fileName'] ?? '');
        }
        if ($url === '' || $filename === '') {
            throw new MarketplaceException($provider, 'No downloadable jar for that version.');
        }

        $filename = basename($filename);
        if (!str_ends_with($filename, '.jar')) {
            throw new MarketplaceException($provider, 'Only .jar files can be installed.');
        }
        if (!in_array((string) parse_url($url, PHP_URL_HOST), self::ALLOWED_HOSTS, true)) {
            throw new MarketplaceException($provider, 'Download host is not allowed.');
        }

        $limit = (int) ThemeSetting::get('marketplace.max_download_mib', 200) * 1024 * 1024;
        try {
            $resp = Http::withHeaders(['User-Agent' => self::UA])
                ->withOptions(['timeout' => 120, 'verify' => true])
                ->get($url);
        } catch (\Throwable $e) {
            throw new MarketplaceException($provider, 'Download failed.');
        }
        if ($resp->status() !== 200) {
            throw new MarketplaceException($provider, 'Download failed (' . $resp->status() . ').');
        }
        $bytes = (string) $resp->body();
        if (strlen($bytes) < 4 || strncmp($bytes, 'PK', 2) !== 0) {
            throw new MarketplaceException($provider, 'Downloaded file is not a jar.');
        }
        if (strlen($bytes) > $limit) {
            throw new MarketplaceException($provider, 'File exceeds the size limit.');
        }

        return ['name' => $filename, 'bytes' => $bytes];
    }

    /* ── internals ─────────────────────────────────────────────── */

    private static function assertProvider(string $provider): void
    {
        if (!in_array($provider, ['modrinth', 'curseforge'], true)) {
            throw new \InvalidArgumentException('Unknown provider.');
        }
    }

    private static function assertType(string $type): void
    {
        if (!in_array($type, ['mod', 'plugin'], true)) {
            throw new \InvalidArgumentException('Unknown type.');
        }
    }

    private static function cached(string $key, callable $fn): mixed
    {
        $ttl = max(30, (int) ThemeSetting::get('marketplace.cache_seconds', 600));

        return Cache::remember('primus.mkt.' . $key, $ttl, $fn);
    }

    private static function mrGet(string $path): array
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => self::UA])
                ->withOptions(['timeout' => 15, 'verify' => true])
                ->get(self::MR_HOST . $path);
        } catch (\Throwable $e) {
            throw new MarketplaceException('modrinth', 'Modrinth request failed.');
        }
        if ($resp->status() !== 200) {
            throw new MarketplaceException('modrinth', 'Modrinth error (' . $resp->status() . ').');
        }

        return (array) $resp->json();
    }

    private static function cfGet(string $path, array $query = []): array
    {
        $key = (string) ThemeSetting::get('marketplace.curseforge.key', '');
        if ($key === '') {
            throw new MarketplaceException('curseforge', 'CurseForge API key is not configured. Set it on the Addons admin page.');
        }
        try {
            $resp = Http::withHeaders([
                'User-Agent' => self::UA,
                'x-api-key' => $key,
                'Accept' => 'application/json',
            ])->withOptions(['timeout' => 15, 'verify' => true])->get(self::CF_HOST . $path, $query);
        } catch (\Throwable $e) {
            throw new MarketplaceException('curseforge', 'CurseForge request failed.');
        }
        if ($resp->status() !== 200) {
            throw new MarketplaceException('curseforge', 'CurseForge error (' . $resp->status() . ').');
        }

        return (array) $resp->json();
    }
}
```

- [ ] **Step 5: Sync live + run suite (GREEN)**

```bash
sed 's/{identifier}/primus/g' /workspace/private/Services/MarketplaceClient.php \
  > /var/www/pterodactyl/.blueprint/extensions/primus/private/Services/MarketplaceClient.php
cd /var/www/pterodactyl && php artisan tinker --execute="require '/tmp/opencode/marketplace_client_test.php';"
```

Expected: `PASS=10 FAIL=0` (case 6 < 20ms). Run twice — second run fully cache-warm.

- [ ] **Step 6: Commit**

```bash
git add private/Services/MarketplaceClient.php
git commit -m "feat: marketplace client service with provider adapters and hardened download"
```

### Task 2: MarketplaceController, routes, registry manifest, admin key route

**Files:**
- Create: `private/Controllers/MarketplaceController.php`
- Modify: `private/Services/AddonRegistry.php` (13th manifest, live)
- Modify: `private/routes.php` (3 client routes + 1 admin route)
- Test: `/tmp/opencode/marketplace_api_test.sh`

**Interfaces:**
- Consumes: Task 1 `MarketplaceClient::{search,versions,download}` + `MarketplaceException`; shipped `AddonGate::{guard,audit}`, `Shared::{rateLimited}`, `PathGuard::resolve`, `ThemeSetting`; Pterodactyl `DaemonFileRepository::putContent(path, contents)`.
- Produces (used by Task 3 client + suites):
  - `GET /extensions/primus/addons/marketplace/search?server=&q=&provider=&type=` → `200 {ok, results:[...]}` | 401/403/404/422/502 `{error}`
  - `GET /extensions/primus/addons/marketplace/versions?server=&provider=&project=&type=` → `200 {ok, versions:[...]}`
  - `POST /extensions/primus/addons/marketplace/install` body `{server, provider, project, version, type}` → `200 {ok, name, dir}` | 429 | 422/502 `{error}`
  - `POST /extensions/primus/addons/admin/marketplace/keys` (root) body `{curseforge?, modrinth?}` → `200 {ok, curseforge: bool, modrinth: bool}` (presence booleans only, never values)
  - Hub `GET /addons` now returns 13 manifests (marketplace live) — suites assert 13 cards.

- [ ] **Step 1: Re-create the read-only guest grant for suites**

```bash
cd /var/www/pterodactyl && php artisan tinker --execute="
\$g = \Pterodactyl\Models\User::where('email','addonguest@example.com')->first();
\Pterodactyl\Models\Subuser::updateOrCreate(['server_id'=>2,'user_id'=>\$g->id],['permissions'=>json_encode(['file.read','file.read-content','websocket.connect'])]);
echo 'grant ok';
"
```

Expected: `grant ok` (removed again in Task 4 cleanup).

- [ ] **Step 2: Write the curl suite (RED)**

Create `/tmp/opencode/marketplace_api_test.sh` — login pattern reused verbatim from the working `/tmp/opencode/addons_api_test.sh` (read its login block first and copy it; do not invent a new one). Suite body, 12 cases:

```bash
#!/bin/bash
# Marketplace API suite — 12 cases. ONE login (rate limit 10/min/IP).
BASE="http://localhost:8081"; CJ=/tmp/opencode/mkt_cj.txt
PASS=0; FAIL=0
ok() { PASS=$((PASS+1)); echo "PASS: $1"; }
bad() { FAIL=$((FAIL+1)); echo "FAIL: $1${2:+ — $2}"; }
jv() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1" 2>/dev/null; }

# --- login: COPY VERBATIM from /tmp/opencode/addons_api_test.sh login block ---

api() { # method path [json-body]
  local m=$1 p=$2 body=${3:-}
  if [ -n "$body" ]; then
    curl -s -b "$CJ" -X "$m" "$BASE/extensions/primus$p" -H "Content-Type: application/json" -H "Accept: application/json" -d "$body" -w "\n%{http_code}"
  else
    curl -s -b "$CJ" -X "$m" "$BASE/extensions/primus$p" -H "Accept: application/json" -w "\n%{http_code}"
  fi
}
codeof() { tail -n1; }
bodyof() { sed '$d'; }

# 1 — modrinth search
R=$(api GET "/addons/marketplace/search?server=92cb4c95&q=sodium&provider=modrinth&type=mod"); C=$(echo "$R"|codeof); B=$(echo "$R"|bodyof)
[ "$C" = 200 ] && [ "$(echo "$B"|jv "len(d['results'])>0")" = "True" ] && ok "1 modrinth search" || bad "1 modrinth search" "$C"

# 2 — curseforge search
R=$(api GET "/addons/marketplace/search?server=92cb4c95&q=jei&provider=curseforge&type=mod"); C=$(echo "$R"|codeof); B=$(echo "$R"|bodyof)
[ "$C" = 200 ] && [ "$(echo "$B"|jv "len(d['results'])>0")" = "True" ] && ok "2 curseforge search" || bad "2 curseforge search" "$C"

# 3 — invalid provider 422
R=$(api GET "/addons/marketplace/search?server=92cb4c95&q=x&provider=evil&type=mod"); C=$(echo "$R"|codeof)
[ "$C" = 422 ] && ok "3 invalid provider 422" || bad "3 invalid provider" "$C"

# 4 — versions
R=$(api GET "/addons/marketplace/versions?server=92cb4c95&provider=modrinth&project=AANobbMI&type=mod"); C=$(echo "$R"|codeof); B=$(echo "$R"|bodyof)
[ "$C" = 200 ] && [ "$(echo "$B"|jv "len(d['versions'])>0")" = "True" ] && ok "4 versions endpoint" || bad "4 versions" "$C"

# 5 — cf plugin search shape (empty results OK)
R=$(api GET "/addons/marketplace/search?server=92cb4c95&q=zzqqxx&provider=curseforge&type=plugin"); C=$(echo "$R"|codeof)
[ "$C" = 200 ] && ok "5 cf plugin search shape" || bad "5 cf plugin" "$C"

# 6 — disabled addon 404 then re-enabled
cd /var/www/pterodactyl && php artisan tinker --execute="\Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::set('addons.marketplace.enabled','false');" >/dev/null 2>&1
R=$(api GET "/addons/marketplace/search?server=92cb4c95&q=x&provider=modrinth&type=mod"); C=$(echo "$R"|codeof)
[ "$C" = 404 ] && ok "6 disabled addon 404" || bad "6 disabled" "$C"
php artisan tinker --execute="\Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::set('addons.marketplace.enabled','true');" >/dev/null 2>&1

# 7 — install happy path (sodium 1.2MB) → {ok, name, dir=mods}
R=$(api POST /addons/marketplace/install '{"server":"92cb4c95","provider":"modrinth","project":"AANobbMI","version":"gQDMcWww","type":"mod"}'); C=$(echo "$R"|codeof); B=$(echo "$R"|bodyof)
[ "$C" = 200 ] && [ "$(echo "$B"|jv "d['ok'] and d['name'].endswith('.jar') and d['dir']=='mods'")" = "True" ] && ok "7 install jar" || bad "7 install" "$(echo $C)"

# 8 — installed jar appears via plugins list endpoint
R=$(api GET "/addons/plugins?server=92cb4c95")
echo "$R" | bodyof | jv "'sodium' in json.dumps(d)" | grep -q True && ok "8 jar listed" || bad "8 jar listed" ""

# 9 — audit row
php artisan tinker --execute="
\$r = \Pterodactyl\BlueprintFramework\Extensions\primus\Models\AddonAudit::query()->where('addon','marketplace')->where('action','install')->latest('id')->first();
echo \$r ? 'audit-ok' : 'audit-missing';" | grep -q audit-ok && ok "9 audit row" || bad "9 audit row" ""

# 10 — reinstall idempotent (putContent overwrites)
R=$(api POST /addons/marketplace/install '{"server":"92cb4c95","provider":"modrinth","project":"AANobbMI","version":"gQDMcWww","type":"mod"}'); C=$(echo "$R"|codeof)
[ "$C" = 200 ] && ok "10 reinstall idempotent" || bad "10 reinstall" "$C"

# 11+12 — rate limit: 0 disables (200), 1 fires (429)
php artisan tinker --execute="\Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::set('addons.rate_limit_per_hour','0');" >/dev/null 2>&1
R=$(api POST /addons/marketplace/install '{"server":"92cb4c95","provider":"modrinth","project":"AANobbMI","version":"gQDMcWww","type":"mod"}'); C=$(echo "$R"|codeof)
[ "$C" = 200 ] && ok "11 rate 0 off" || bad "11 rate 0" "$C"
php artisan tinker --execute="\Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::set('addons.rate_limit_per_hour','1');" >/dev/null 2>&1
R=$(api POST /addons/marketplace/install '{"server":"92cb4c95","provider":"modrinth","project":"AANobbMI","version":"gQDMcWww","type":"mod"}'); C=$(echo "$R"|codeof)
[ "$C" = 429 ] && ok "12 rate fires 429" || bad "12 rate fires" "$C"
php artisan tinker --execute="\Pterodactyl\BlueprintFramework\Extensions\primus\Models\ThemeSetting::set('addons.rate_limit_per_hour','60');" >/dev/null 2>&1

echo "-----------------------------"; echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
```

`chmod +x /tmp/opencode/marketplace_api_test.sh`.

- [ ] **Step 3: Run to verify RED**

```bash
cd /var/www/pterodactyl && bash /tmp/opencode/marketplace_api_test.sh
```

Expected: FAILs (routes missing → 404/HTML).

- [ ] **Step 4: Registry manifest + controller + routes**

4a. `/workspace/private/Services/AddonRegistry.php`: in `all()`, insert `self::manifest('marketplace'),` after `self::manifest('plugins'),`. In `manifest()`, add to `$live`:

```php
'marketplace' => [
    'id' => 'marketplace',
    'title' => 'Marketplace',
    'description' => 'Search and install mods & plugins from Modrinth and CurseForge.',
    'category' => 'files',
    'perms' => 'file.read',
    'icon' => 'marketplace',
    'comingSoon' => false,
],
```

4b. Create `/workspace/private/Controllers/MarketplaceController.php`. Signature facts (verified against the shipped framework): `AddonGate::guard(\Illuminate\Http\Request $request, string $addonId, ?Server &$server): ?JsonResponse` — call as `$gate = AddonGate::guard($request, 'marketplace', $server); if ($gate !== null) { return $gate; }` then per-action perm via the same `hasPerm` helper the plugins controller uses (copy its private method verbatim — root_admin/owner always pass, subuser needs the dotted perm):

```php
<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MarketplaceClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MarketplaceException;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PathGuard;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

class MarketplaceController
{
    private const DIRS = ['plugin' => 'plugins', 'mod' => 'mods'];

    public function search(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'marketplace', $server);
        if ($gate !== null) {
            return $gate;
        }
        [$q, $provider, $type] = [$request->query('q', ''), $request->query('provider', ''), $request->query('type', '')];

        return $this->runProvider(fn () => MarketplaceClient::search((string) $q, (string) $provider, (string) $type), (string) $provider);
    }

    public function versions(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'marketplace', $server);
        if ($gate !== null) {
            return $gate;
        }
        $provider = (string) $request->query('provider', '');
        $project = (string) $request->query('project', '');
        $type = (string) $request->query('type', '');
        if ($project === '') {
            return response()->json(['error' => 'Project is required.'], 422);
        }

        return $this->runProvider(fn () => ['versions' => MarketplaceClient::versions($project, $provider, $type)], $provider);
    }

    public function install(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'marketplace', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!$this->hasPerm($user, $server, 'file.create')) {
            return response()->json(['error' => 'You do not have file create access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.marketplace')) {
            return response()->json(['error' => 'Too many installs this hour.'], 429);
        }

        $provider = (string) ($request->input('provider') ?? '');
        $project = (string) ($request->input('project') ?? '');
        $version = (string) ($request->input('version') ?? '');
        $type = (string) ($request->input('type') ?? '');
        if ($project === '' || $version === '' || !isset(self::DIRS[$type])) {
            return response()->json(['error' => 'provider, project, version and type are required.'], 422);
        }
        $dir = self::DIRS[$type];

        try {
            $dl = MarketplaceClient::download($provider, $project, $version, $type);
        } catch (MarketplaceException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        try {
            $path = (new PathGuard())->resolve($dir . '/' . $dl['name'], ['plugins', 'mods']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Refusing unsafe file name.'], 422);
        }

        AddonGate::audit($user, $server, 'marketplace', 'install', $path, [
            'provider' => $provider, 'project' => $project, 'version' => $version,
        ]);

        try {
            app(DaemonFileRepository::class)->setServer($server)->putContent($path, $dl['bytes']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Writing the file to the server failed.'], 502);
        }

        return response()->json(['ok' => true, 'name' => $dl['name'], 'dir' => $dir]);
    }

    public function saveKeys(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->root_admin) {
            return response()->json(['error' => 'Root admin required.'], 403);
        }
        foreach (['curseforge', 'modrinth'] as $p) {
            $v = trim((string) ($request->input($p) ?? ''));
            if ($v !== '') {
                ThemeSetting::set("marketplace.$p.key", $v);
            }
        }

        return response()->json([
            'ok' => true,
            'curseforge' => (bool) ThemeSetting::get('marketplace.curseforge.key', ''),
            'modrinth' => (bool) ThemeSetting::get('marketplace.modrinth.key', ''),
        ]);
    }

    /* hasPerm: copy the private method verbatim from PluginsController
     * (root_admin / owner always pass; subuser needs the dotted perm). */
    private function hasPerm($user, Server $server, string $perm): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }
        $subuser = $server->subusers()->where('user_id', $user->id)->first();

        return in_array($perm, (array) ($subuser?->permissions ?? []), true);
    }

    private function runProvider(callable $fn, string $provider): JsonResponse
    {
        try {
            $payload = $fn();
        } catch (MarketplaceException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $payload);
    }
}
```

4c. `/workspace/private/routes.php` — inside the existing gated group (where the plugins routes live):

```php
Route::get('/addons/marketplace/search', [MarketplaceController::class, 'search']);
Route::get('/addons/marketplace/versions', [MarketplaceController::class, 'versions']);
Route::post('/addons/marketplace/install', [MarketplaceController::class, 'install']);
```

Inside the existing admin (AdminAuthenticate) group:

```php
Route::post('/addons/admin/marketplace/keys', [MarketplaceController::class, 'saveKeys']);
```

- [ ] **Step 5: Sync live + GREEN**

```bash
sed 's/{identifier}/primus/g' /workspace/private/Services/AddonRegistry.php > /var/www/pterodactyl/.blueprint/extensions/primus/private/Services/AddonRegistry.php
sed 's/{identifier}/primus/g' /workspace/private/Controllers/MarketplaceController.php > /var/www/pterodactyl/.blueprint/extensions/primus/private/Controllers/MarketplaceController.php
sed 's/{identifier}/primus/g' /workspace/private/routes.php > /var/www/pterodactyl/.blueprint/extensions/primus/private/routes.php
# Copy routes into the live routes file the panel actually loads — reuse the exact
# sync convention from the framework cycle (see /workspace/.superpowers/sdd/task-3-report.md).
cd /var/www/pterodactyl && php artisan view:clear && bash /tmp/opencode/marketplace_api_test.sh
```

Expected: `PASS=12 FAIL=0` exit 0. Note: the framework cycle syncs BOTH `private/` and `app/Controllers/` copies for `Shared.php` — check the task-3 report's sync list and mirror it for any controller the panel autoloads from `app/`.

- [ ] **Step 6: Commit**

```bash
git add private/Services/AddonRegistry.php private/Controllers/MarketplaceController.php private/routes.php
git commit -m "feat: marketplace endpoints with gated routes and registry manifest"
```

### Task 3: Client marketplace panel + admin key block + suite updates

**Files:**
- Modify: `public/js/addons.js` (marketplace panel, plugin-panel Install button, ICONS.marketplace)
- Modify: `public/css/addons.css` (results grid, picker, provider tabs)
- Modify: `admin/controller.php` (key-configured booleans for the view)
- Modify: `admin/view.blade.php` (Marketplace API keys block)
- Test: `/tmp/opencode/marketplace_ui.js` (Puppeteer, 9 cases)

**Interfaces:**
- Consumes: Task 2 endpoints (`search`, `versions`, `install`, admin `keys`); existing engine `P.util` (`U.esc`), `P.api`, `P.toast`, `postAdmin` (admin view, existing); shipped panel open/close primitives in addons.js (`mask`, `openPanel(id)`, `renderPlugins`, `perms` from hub/plugins payloads).
- Produces: `openPanel('marketplace')` renders the marketplace panel; `openMarketplace()` helper used by both the hub card and the plugin-panel button. All server-derived strings escaped via `U.esc` in every innerHTML path AND every toast.

- [ ] **Step 1: Write the Puppeteer suite (RED)**

Create `/tmp/opencode/marketplace_ui.js` — copy the login + console-noise preamble verbatim from `/tmp/opencode/addons_ui.js` (native setter login, noise regex `/WebSocket|504|405|net::|object Y/i`, primus/addons errors never masked). Cases (one admin login; one guest login at the end):

1. Hub renders **13** cards: 11 locked (comingSoon), **2** usable (Plugin Manager, Marketplace).
2. Marketplace card click → panel opens with provider tabs (All / Modrinth / CurseForge) + type toggle (Plugin / Mod) + search input.
3. Type "sodium" (modrinth, mod) → debounce → results render (≥1 result card with icon+name).
4. Result "Versions" → picker modal lists versions (≥1 row with filename + size).
5. Install from picker → toast "installed" → sodium jar appears in the jar table (open Plugin panel to verify OR re-search shows no crash — assert toast text contains the jar name, escaped).
6. Plugin Manager panel header shows "Install from marketplace" button (admin has canCreate) → click opens the marketplace panel.
7. CurseForge tab + "jei" (mod) → results render (live key works through the panel).
8. Guest (read-only): marketplace card usable; search works; Install buttons disabled with hint; direct POST install → 403 toast.
9. No `[primus]` console errors; no leaked masks at the end (`.pr-addons-mask` count === 0 after Esc closes).

Print PASS/FAIL per case; `process.exit(FAIL ? 1 : 0)`. Guard every panel-body query with null checks (close-mid-race → FAIL not crash).

- [ ] **Step 2: Run to verify RED**

```bash
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/marketplace_ui.js
```

Expected: case 1 FAILs (12 cards), marketplace card locked → panel never opens.

- [ ] **Step 3: Implement the client panel**

In `/workspace/public/js/addons.js`:

- `ICONS` gains `marketplace` (reuse the `plugins` storefront-ish inline SVG — any existing 24x24 stroke icon; do not import external assets).
- `openPanel(id)` currently returns early for `id !== 'plugins'`. Change to:

```js
if (id === "marketplace") { openMarketplace(); return; }
if (id !== "plugins") return;
```

- Hub card click already calls `openPanel(a.id)` — marketplace now routes correctly.
- Add `openMarketplace()` (module-level, reuses `mask` body + `pr-addons-back` exactly like `openPanel`):

```js
function openMarketplace() {
  activePanel = "marketplace";
  mask.querySelector(".pr-addons-back").style.display = "";
  var body = mask.querySelector(".pr-addons-body");
  var hasCreate = !!(pluginsState && pluginsState.perms && pluginsState.perms.canCreate);
  body.innerHTML =
    '<div class="pr-mkt-tabs">' +
      '<button class="pr-mkt-tab is-active" data-provider="modrinth">Modrinth</button>' +
      '<button class="pr-mkt-tab" data-provider="curseforge">CurseForge</button>' +
      '<span class="pr-mkt-spacer"></span>' +
      '<button class="pr-mkt-type is-active" data-type="mod">Mods</button>' +
      '<button class="pr-mkt-type" data-type="plugin">Plugins</button>' +
    "</div>" +
    '<input class="pr-mkt-input" placeholder="Search mods and plugins…" aria-label="Search marketplace" />' +
    '<div class="pr-mkt-grid"></div>';
  var input = body.querySelector(".pr-mkt-input");
  var grid = body.querySelector(".pr-mkt-grid");
  var state = { provider: "modrinth", type: "mod", seq: 0 };

  body.querySelector(".pr-mkt-tabs").addEventListener("click", function (e) {
    var t = e.target.closest(".pr-mkt-tab");
    if (t) {
      body.querySelectorAll(".pr-mkt-tab").forEach(function (b) { b.classList.remove("is-active"); });
      t.classList.add("is-active");
      state.provider = t.getAttribute("data-provider");
      doSearch();
      return;
    }
    var ty = e.target.closest(".pr-mkt-type");
    if (ty) {
      body.querySelectorAll(".pr-mkt-type").forEach(function (b) { b.classList.remove("is-active"); });
      ty.classList.add("is-active");
      state.type = ty.getAttribute("data-type");
      doSearch();
    }
  });

  var debounce = null;
  input.addEventListener("input", function () {
    clearTimeout(debounce);
    debounce = setTimeout(doSearch, 350);
  });

  function doSearch() {
    var q = input.value.trim();
    if (!q) { grid.innerHTML = ""; return; }
    var seq = ++state.seq;
    grid.innerHTML = '<div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div>';
    P.api("addons/marketplace/search?server=" + encodeURIComponent(serverId()) +
      "&q=" + encodeURIComponent(q) + "&provider=" + state.provider + "&type=" + state.type)
      .then(function (payload) {
        if (seq !== state.seq || !mask) return;
        renderResults(payload.results || []);
      })
      .catch(function (err) {
        if (seq !== state.seq || !mask) return;
        grid.innerHTML = '<div class="pr-addons-empty">' + U.esc(err.message || "Search failed.") + "</div>";
      });
  }

  function renderResults(list) {
    if (!list.length) { grid.innerHTML = '<div class="pr-addons-empty">No results.</div>'; return; }
    grid.innerHTML = "";
    list.forEach(function (r) {
      var card = U.el("div", "pr-mkt-card" + (hasCreate ? "" : " is-readonly"),
        '<img class="pr-mkt-card__icon" src="' + U.esc(r.icon) + '" alt="" onerror="this.style.display=\'none\'" />' +
        '<div class="pr-mkt-card__text">' +
          '<div class="pr-mkt-card__title">' + U.esc(r.name) + "</div>" +
          '<div class="pr-mkt-card__meta">' + U.esc(r.author) + " · " + fmtDownloads(r.downloads) + "</div>" +
          '<div class="pr-mkt-card__summary">' + U.esc(r.summary) + "</div>" +
        "</div>" +
        '<button class="pr-mkt-card__btn" data-project="' + U.esc(r.id) + '">' + (hasCreate ? "Versions" : "View") + "</button>");
      card.querySelector(".pr-mkt-card__btn").addEventListener("click", function () {
        if (!hasCreate) { P.toast("Install disabled", "You need file-create permission to install.", "warning"); return; }
        openVersions(r);
      });
      grid.appendChild(card);
    });
  }

  function openVersions(r) {
    var seq = ++state.seq;
    var modal = U.el("div", "pr-mkt-picker pr-scale-in",
      '<div class="pr-mkt-picker__head">' +
        '<div class="pr-mkt-picker__title">' + U.esc(r.name) + "</div>" +
        '<button class="pr-mkt-picker__close" type="button">&times;</button>' +
      "</div>" +
      '<div class="pr-mkt-picker__body"><div class="pr-addons-loading"><span class="pr-typing-dots"><span></span><span></span><span></span></span></div></div>');
    body.appendChild(modal);
    modal.querySelector(".pr-mkt-picker__close").addEventListener("click", function () { modal.remove(); });
    P.api("addons/marketplace/versions?server=" + encodeURIComponent(serverId()) +
      "&provider=" + state.provider + "&project=" + encodeURIComponent(r.id) + "&type=" + state.type)
      .then(function (payload) {
        if (seq !== state.seq || !mask) { modal.remove(); return; }
        renderVersions(modal, payload.versions || []);
      })
      .catch(function (err) {
        modal.querySelector(".pr-mkt-picker__body").innerHTML =
          '<div class="pr-addons-empty">' + U.esc(err.message || "Failed to load versions.") + "</div>";
      });
  }

  function renderVersions(modal, list) {
    var rows = modal.querySelector(".pr-mkt-picker__body");
    rows.innerHTML = "";
    list.slice(0, 20).forEach(function (v) {
      var row = U.el("div", "pr-mkt-version",
        '<div class="pr-mkt-version__main">' +
          '<div class="pr-mkt-version__name">' + U.esc(v.name) + "</div>" +
          '<div class="pr-mkt-version__meta">' + U.esc(v.filename) + " · " + fmtBytes(v.size) + "</div>" +
        "</div>" +
        '<button class="pr-mkt-version__install" data-v="' + U.esc(v.id) + '">Install</button>');
      row.querySelector(".pr-mkt-version__install").addEventListener("click", function () {
        install(state.provider, state.type, r.id, v);
      });
      rows.appendChild(row);
    });
  }

  function install(provider, type, project, v) {
    P.api("addons/marketplace/install", {
      method: "POST",
      json: { server: serverId(), provider: provider, project: project, version: v.id, type: type },
    })
      .then(function (res) {
        P.toast("Installed", U.esc(res.name), "success");
      })
      .catch(function (err) {
        P.toast("Install failed", U.esc(err.message || "unknown"), "error");
      });
  }

  function fmtDownloads(n) { return n >= 1e6 ? (n / 1e6).toFixed(1) + "M" : n >= 1e3 ? (n / 1e3).toFixed(1) + "k" : String(n); }
  function fmtBytes(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + " MiB" : Math.max(1, Math.round(n / 1024)) + " KiB"; }
}
```

IMPORTANT implementation notes:
- `openVersions(r)` closes over `r` from the results loop — the install call uses plain `r.id` (caller closure; no extra state needed).
- Store `state` and `renderResults/renderResults helpers` inside `openMarketplace()` (closure) — no globals; `mask` null-guard every `.then` (post-`595d8c7` review rule).
- In `renderPlugins()` (plugin panel header), add an "Install from marketplace" button when `perms.canCreate`:

```js
+ (state.perms.canCreate ? '<button class="pr-plugins-mkt" type="button">Install from marketplace</button>' : "")
```

Wire with a one-time delegated handler (the existing `data-pr-bound` body-delegate pattern) opening `openMarketplace()`.

- `/workspace/public/css/addons.css` — append a marketplace block (token-driven: `var(--pr-surface)`, `var(--pr-border)`, `var(--pr-accent, #6d5df6)` fallbacks allowed per established convention): `.pr-mkt-tabs` (flex row, tab pills), `.pr-mkt-input` (full-width input matching `.pr-addons-search` conventions if present), `.pr-mkt-grid` (2-col responsive 1-col), `.pr-mkt-card` (flex, icon 48px, hover border-accent), `.pr-mkt-card__btn`, `.pr-mkt-picker` (absolute overlay inside panel, `.pr-scale-in`), `.pr-mkt-version` rows (flex space-between), `.is-readonly` (reduced opacity). Reuse animation classes `pr-fade-in`/`pr-scale-in` that already exist.

- [ ] **Step 4: Update framework-era suites (13 cards)**

- `/tmp/opencode/addons_ui.js` case 2: `cards === 12 && locked === 11` → `cards === 13 && locked === 11` (usable now 2). Also its hub-open helper unchanged.
- `/workspace/private/tests/motd_regression.js` case e: `cards === 12` → `13` (Rust hub count) — this file IS committed, so edit it and include in the Task 4 commit.
- Re-check `/tmp/opencode/addons_admin_ui.js` for card-count assertions (case 2: `12 cards`) → update to 13 + marketplace live.

- [ ] **Step 5: Admin key block**

`/workspace/admin/controller.php` `index()`: add to the `->with([...])`:

```php
'mktKeys' => [
    'curseforge' => (bool) ThemeSetting::get('marketplace.curseforge.key', ''),
    'modrinth' => (bool) ThemeSetting::get('marketplace.modrinth.key', ''),
],
```

`/workspace/admin/view.blade.php` — inside the Addons tab, after the addon cards section:

```blade
<div class="prx-addon-card" style="max-width:520px">
  <h3>Marketplace API keys</h3>
  <p class="text-xs text-neutral-400">Provider keys are stored server-side and never displayed back.</p>
  <label class="block mt-2 text-sm">CurseForge ({{ $mktKeys['curseforge'] ? 'configured' : 'not configured' }})
    <input type="password" name="cf_key" placeholder="{{ $mktKeys['curseforge'] ? 'configured — leave blank to keep' : 'paste x-api-key' }}" class="prx-input" autocomplete="off" />
  </label>
  <label class="block mt-2 text-sm">Modrinth ({{ $mktKeys['modrinth'] ? 'configured' : 'not configured' }})
    <input type="password" name="mr_key" placeholder="{{ $mktKeys['modrinth'] ? 'configured — leave blank to keep' : 'paste token' }}" class="prx-input" autocomplete="off" />
  </label>
  <button id="prx-mkt-keys-save" class="prx-btn mt-3">Save keys</button>
</div>
```

Script (existing inline `<script>` conventions with `postAdmin`):

```js
document.getElementById("prx-mkt-keys-save").addEventListener("click", function () {
  var body = {};
  var cf = document.querySelector("input[name='cf_key']").value.trim();
  var mr = document.querySelector("input[name='mr_key']").value.trim();
  if (cf) body.curseforge = cf;
  if (mr) body.modrinth = mr;
  if (!body.curseforge && !body.modrinth) { toast("Nothing to save", "Leave-blank fields keep existing keys.", "info"); return; }
  postAdmin("/addons/admin/marketplace/keys", body).then(function (r) {
    toast("Marketplace keys", "Saved. CurseForge: " + (r.curseforge ? "configured" : "not configured") + ", Modrinth: " + (r.modrinth ? "configured" : "not configured"), "success");
    document.querySelector("input[name='cf_key']").value = "";
    document.querySelector("input[name='mr_key']").value = "";
  }).catch(function (e) { toast("Save failed", e.message || "unknown", "error"); });
});
```

Sync via the established admin file conventions from Task 5 (controller → `app/Http/Controllers/Admin/Extensions/primus/primusExtensionController.php`, view → `resources/views/admin/extensions/primus/index.blade.php`; css target if any via `assets/admin.style.css`) + `php artisan view:clear`.

- [ ] **Step 6: Sync client + GREEN**

```bash
cp /workspace/public/js/addons.js /var/www/pterodactyl/.blueprint/extensions/primus/public/js/addons.js
cp /workspace/public/css/addons.css /var/www/pterodactyl/.blueprint/extensions/primus/public/css/addons.css
sed -e 's/{identifier}/primus/g' -e 's#{webroot/public}#/extensions/primus#g' -e 's/{timestamp}//g' -e 's/v=1788900000/v=1789000000/g' /workspace/dashboard/wrapper.blade.php > /var/www/pterodactyl/.blueprint/extensions/primus/wrappers/dashboard.blade.php
node --check /workspace/public/js/addons.js && cd /var/www/pterodactyl && php artisan view:clear
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/marketplace_ui.js
```

Expected: 9/9 PASS, exit 0. Then re-run the updated framework suites: `addons_ui.js` (10/10 with 13-card case), `motd_regression.js` (12 PASS), `addons_admin_ui.js` (6/6). Space browser-suite runs 65s apart (login rate limit).

- [ ] **Step 7: Commit**

```bash
git add public/js/addons.js public/css/addons.css admin/controller.php admin/view.blade.php private/tests/motd_regression.js
git commit -m "feat: marketplace panel UI with admin key management"
```

(Stage ONLY addons/marketplace hunks — the admin files carry unrelated dirty hunks; use the Task 5 baseline-copy technique.)

### Task 4: Regression, cleanup, packaging, final commit

**Files:**
- Modify: `private/tests/motd_regression.js` (already updated in Task 3 — verify count assertions)
- Test: full matrix re-run (no new suite files)
- Packaging: `build.sh` run (no file changes)

**Interfaces:**
- Consumes: all prior tasks green (client 10/10, client-mkt 9/9, API 12/12, client tinker 10/10, admin 6/6, regression 12 PASS).
- Produces: release state — guest grant removed, fixtures cleaned, `dist/primus.blueprint` rebuilt, final commit(s) in place.

- [ ] **Step 1: Re-run the complete matrix**

Space browser suites 65s apart (login rate limit 10/min/IP). From `/var/www/pterodactyl`:

```bash
# tinker (fast)
php artisan tinker --execute="require '/tmp/opencode/marketplace_client_test.php';"     # 10/10
php artisan tinker --execute="require '/tmp/opencode/addons_pathguard_test.php';"     # 8/8
php artisan tinker --execute="require '/tmp/opencode/addons_gate_test.php';"          # 8/8
# api (re-seed fixtures first)
php artisan tinker --execute="require '/tmp/opencode/plugins_seed.php';"
bash /tmp/opencode/addons_api_test.sh           # 12/12
bash /tmp/opencode/marketplace_api_test.sh      # 12/12
# browsers
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/marketplace_ui.js    # 9/9
sleep 65
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/addons_ui.js         # 10/10
sleep 65
NODE_PATH=/usr/local/lib/node_modules node /tmp/opencode/addons_admin_ui.js   # 6/6
sleep 65
NODE_PATH=/usr/local/lib/node_modules node /workspace/private/tests/motd_regression.js  # 12 PASS
```

Expected: every suite green with exit 0. On 429 contamination: wait 65s, rerun ONCE, note it in the report.

- [ ] **Step 2: Cleanup live test artifacts**

```bash
cd /var/www/pterodactyl && php artisan tinker --execute="
\$g = \Pterodactyl\Models\User::where('email','addonguest@example.com')->first();
\Pterodactyl\Models\Subuser::where('user_id', \$g->id)->delete();
echo 'grants-left:' . \Pterodactyl\Models\Subuser::where('user_id', \$g->id)->count();
"
```

Expected: `grants-left:0` (guest user itself stays; audit rows stay — they are the trail). Remove installed test jars if any remain (`mods/sodium-*.jar` from suite case 7 via the plugins delete endpoint or tinker `deleteFiles('/mods', ['sodium-…jar'])` — list first, delete by exact name).

- [ ] **Step 3: Packaging**

```bash
cd /workspace && bash build.sh
```

Expected: `Built dist/primus.blueprint` exit 0. Verify `dist/` still gitignored and NOT staged.

- [ ] **Step 4: Final commit (if anything remains uncommitted)**

Typically nothing remains — Tasks 1-3 committed their files. If `private/tests/motd_regression.js` count updates from Task 3 were not yet committed, commit them here:

```bash
git add private/tests/motd_regression.js
git commit -m "test: update regression counts for marketplace card"
```

- [ ] **Step 5: Self-check before reporting**

- [ ] Every suite in Step 1 green with captured output.
- [ ] `php -l` on the two new PHP files (live synced copies, since workspace has `{identifier}` placeholders); `node --check` on addons.js.
- [ ] Audit rows exist for marketplace installs (case 9 of API suite).
- [ ] No API key value appears in any committed file (`git grep -i "x-api-key\|mrp_"` clean; the CF key prefix `$2a$10$` clean).
- [ ] `git log --oneline ccf5e36..HEAD` shows the marketplace commits with only intended files.

---

## Risks & mitigations

- **Provider API drift**: search/version shapes pinned by the Task 1 suite against live APIs at authoring time (2026-09-10). If a case fails on shape, re-verify with curl before "fixing" the client.
- **Live keys**: seeded only into the live DB; the tinker command in Task 1 Step 2 contains the real values — run it from chat, never copy it into a committed file or report.
- **Dirty workspace**: unrelated uncommitted changes exist in `admin/`, `public/css|js`, `private/Controllers/{SettingsController,Shared}.php`, `CHANGELOG.md`, `README.md`, docs. NEVER revert or stage them; admin-view staging uses the Task 5 hunk-isolation technique.
- **Rate-limit flakes**: browser suites one login each, 65s spacing; 429 during a run → rerun once after cooldown.
- **Subagent empties**: if an implementer returns an empty result, check `git log` + target files before re-dispatching (three occurrences last cycle; work may be complete or absent).
- **CurseForge pagination**: `sortField=2` (popularity) keeps result sets stable for assertions; empty results are valid (case 5).
- **Redirect host**: `edge.forgecdn.net` 302s to `mediafilez.forgecdn.net` — both allowlisted; Laravel HTTP client follows redirects by default, final-host check runs on the ORIGINAL url (allowlist check is on the pre-redirect URL — already handled: the check parses the provider-supplied url, and the CDN itself is the only redirector).

