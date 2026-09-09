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

    private function guard(): PathGuard
    {
        return app(PathGuard::class);
    }

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

        // `name` is the jar's base name without state suffix — normalise it
        // (strip .disabled when re-enabling, add .jar when the client sends
        // just the base like "Example") so both "Example" and "Example.jar" toggle.
        $base = $name;
        if (str_ends_with($base, self::SUFFIX)) {
            $base = substr($base, 0, -strlen(self::SUFFIX));
        }
        if (!str_ends_with($base, '.jar')) {
            $base .= '.jar';
        }
        try {
            $base = basename($this->guard()->resolve($dir . '/' . $base, self::JAIL));
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
            $path = $this->guard()->resolve($dir . '/' . $name, self::JAIL);
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

        $maxBytes = ((int) \Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting::get('addons.plugins.max_upload_mib', 100)) * 1024 * 1024;        if (strlen($content) > $maxBytes) {
            return response()->json(['error' => 'File exceeds the upload size limit.'], 422);
        }

        try {
            $path = $this->guard()->resolve($dir . '/' . $name, self::JAIL);
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
