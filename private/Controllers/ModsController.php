<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MarketplaceClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PathGuard;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Mod Manager — list/toggle/delete/upload jars under mods/.
 * Paths go through PathGuard with the ['mods'] jail; mutations audit first.
 */
class ModsController extends Controller
{
    private const JAIL = ['mods'];
    private const SUFFIX = '.disabled';

    public function index(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'mods', $server);
        if ($gate !== null) {
            return $gate;
        }

        $jars = [];
        try {
            try {
                $entries = $this->repo($server)->getDirectory('mods');
            } catch (DaemonConnectionException $e) {
                if ($e->getStatusCode() === 404) {
                    $entries = [];
                } else {
                    throw $e;
                }
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
                    'dir' => 'mods',
                    'size' => (int) ($entry['size'] ?? 0),
                    'modified' => (string) ($entry['modified'] ?? ''),
                    'enabled' => $enabled,
                ];
            }
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json([
            'ok' => true,
            'jars' => $jars,
            'detect' => $this->detect($server),
            'perms' => Shared::filePerms($request->user(), $server),
        ]);
    }

    /** Search Modrinth/CurseForge for mods (type=mod). */
    public function search(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'mods', $server);
        if ($gate !== null) {
            return $gate;
        }
        $q = mb_substr(trim((string) $request->input('q', '')), 0, 80);
        if ($q === '') {
            return response()->json(['error' => 'Empty search.'], 422);
        }
        $provider = (string) $request->input('provider', 'modrinth');
        if (!in_array($provider, ['modrinth', 'curseforge', 'all'], true)) {
            $provider = 'modrinth';
        }

        try {
            $res = MarketplaceClient::search($q, $provider, 'mod');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'Search failed.'], 502);
        }

        return response()->json([
            'ok' => true,
            'results' => $res['results'],
            'detect' => $this->detect($server),
        ]);
    }

    /**
     * Best-effort loader + MC-version detect: scan root + mods/ jar
     * names and the egg name for loader markers and version patterns.
     *
     * @return array<string, string>
     */
    private function detect(Server $server): array
    {
        $hay = '';
        try {
            foreach (['/', 'mods'] as $dir) {
                try {
                    foreach ($this->repo($server)->getDirectory($dir) as $entry) {
                        $hay .= ' ' . (string) ($entry['name'] ?? '');
                    }
                } catch (DaemonConnectionException $e) {
                    if ($e->getStatusCode() !== 404) {
                        throw $e;
                    }
                }
            }
        } catch (DaemonConnectionException $e) {
            $hay = '';
        }
        $hay .= ' ' . mb_strtolower((string) ($server->egg->name ?? ''));

        $loader = '';
        foreach (['fabric', 'quilt', 'neoforge', 'forge'] as $l) {
            if (str_contains($hay, $l)) {
                $loader = $l;
                break;
            }
        }

        /* match 1.x.y style first, then the mc26.3 style used by newer jars */
        $version = '';
        if (preg_match('/\b(1\.\d{1,2}(?:\.\d{1,2})?)\b/', $hay, $m)) {
            $version = $m[1];
        } elseif (preg_match('/\bmc(\d{2,3}(?:\.\d{1,2})?)\b/i', $hay, $m)) {
            $version = $m[1];
        }

        return ['loader' => $loader, 'version' => $version];
    }

    public function toggle(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'mods', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file update access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.mods')) {
            return response()->json(['error' => 'Rate limit reached. Try again later.'], 429);
        }

        $name = (string) $request->json('name', '');
        $base = $name;
        if (str_ends_with($base, self::SUFFIX)) {
            $base = substr($base, 0, -strlen(self::SUFFIX));
        }
        if (!str_ends_with($base, '.jar')) {
            $base .= '.jar';
        }
        try {
            $base = basename(app(PathGuard::class)->resolve('mods/' . $base, self::JAIL));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $from = 'mods/' . $base;
        $to = $from . self::SUFFIX;

        try {
            $listing = $this->repo($server)->getDirectory('mods');
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
            return response()->json(['error' => 'Mod jar not found.'], 404);
        }

        $disable = $existsEnabled;
        AddonGate::audit($user, $server, 'mods', 'toggle', $disable ? $from : $to, ['to' => $disable ? 'disabled' : 'enabled']);

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
        $gate = AddonGate::guard($request, 'mods', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'file.delete')) {
            return response()->json(['error' => 'You do not have file delete access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.mods')) {
            return response()->json(['error' => 'Rate limit reached. Try again later.'], 429);
        }

        $name = (string) $request->json('name', '');
        $confirm = (string) $request->json('confirm', '');
        try {
            $path = app(PathGuard::class)->resolve('mods/' . $name, self::JAIL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        if ($confirm !== basename($path)) {
            return response()->json(['error' => 'Confirmation does not match the file name.'], 422);
        }
        if (!str_ends_with($path, '.jar') && !str_ends_with($path, '.jar' . self::SUFFIX)) {
            return response()->json(['error' => 'Only mod jars can be deleted.'], 422);
        }

        AddonGate::audit($user, $server, 'mods', 'delete', $path);

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
        $gate = AddonGate::guard($request, 'mods', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'file.create')) {
            return response()->json(['error' => 'You do not have file create access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.mods')) {
            return response()->json(['error' => 'Rate limit reached. Try again later.'], 429);
        }

        $name = (string) $request->json('name', '');
        $contentB64 = (string) $request->json('content', '');
        if (!preg_match('/^[A-Za-z0-9._-]+\.jar$/', $name)) {
            return response()->json(['error' => 'File name must be a simple .jar name.'], 422);
        }
        $content = base64_decode($contentB64, true);
        if ($content === false) {
            return response()->json(['error' => 'File content is not valid base64.'], 422);
        }
        $maxBytes = ((int) ThemeSetting::get('addons.mods.max_upload_mib', 100)) * 1024 * 1024;
        if (strlen($content) > $maxBytes) {
            return response()->json(['error' => 'File exceeds the upload size limit.'], 422);
        }
        try {
            $path = app(PathGuard::class)->resolve('mods/' . $name, self::JAIL);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AddonGate::audit($user, $server, 'mods', 'upload', $path, ['bytes' => strlen($content)]);

        try {
            $this->repo($server)->putContent($path, $content);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json(['ok' => true, 'name' => basename($path)]);
    }

    private function repo(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }
}
