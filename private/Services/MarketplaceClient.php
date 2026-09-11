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
        self::assertType($type);

        /* "all" fans out to both providers (each leg cached on its own,
         * so a missing CurseForge key never poisons Modrinth results). */
        if ($provider === 'all') {
            /* Interleave up to 10 from each provider so a globally-popular
             * Modrinth hit list cannot drown CurseForge (and vice versa). */
            $buckets = [];
            $firstError = null;
            foreach (['modrinth', 'curseforge'] as $p) {
                try {
                    $buckets[$p] = array_slice(self::searchOne($q, $p, $type), 0, 10);
                } catch (\Throwable $e) {
                    $buckets[$p] = [];
                    $firstError = $firstError ?? $e;
                }
            }
            $results = [];
            $n = max(count($buckets['modrinth'] ?? []), count($buckets['curseforge'] ?? []));
            for ($i = 0; $i < $n; $i++) {
                if (isset($buckets['modrinth'][$i])) {
                    $results[] = $buckets['modrinth'][$i];
                }
                if (isset($buckets['curseforge'][$i])) {
                    $results[] = $buckets['curseforge'][$i];
                }
            }
            if (!$results && $firstError !== null) {
                throw $firstError;
            }

            return ['results' => $results];
        }

        self::assertProvider($provider);

        return ['results' => self::searchOne($q, $provider, $type)];
    }

    private static function searchOne(string $q, string $provider, string $type): array
    {
        return self::cached('s.' . $provider . '.' . $type . '.' . md5($q), function () use ($q, $provider, $type) {
            $results = [];
            if ($provider === 'modrinth') {
                $facet = urlencode(json_encode([["project_type:$type"]]));
                $json = self::mrGet('/search?query=' . urlencode($q) . "&facets=$facet&limit=20");
                foreach ($json['hits'] ?? [] as $h) {
                    $results[] = [
                        'provider' => $provider,
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
                        'provider' => $provider,
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

            return $results;
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
            /* configuration error, not a provider outage: 422 with the
             * admin-page hint (spec §4.3), never 502 */
            throw new \InvalidArgumentException('CurseForge API key is not configured. Set it on the Addons admin page.');
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
