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
