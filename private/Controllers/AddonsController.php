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
        // forceFill: Server::$fillable excludes id, so plain mass-assign
        // would leave server_id null and trip the NOT NULL audit constraint.
        AddonGate::audit($user, (new Server())->forceFill(['id' => 0]), 'framework', $enabled ? 'enable' : 'disable', $addon);

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
