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

    /** @return int|null the created row id (null if the audit write failed) */
    public static function audit(
        User $user,
        Server $server,
        string $addon,
        string $action,
        string $target,
        array $meta = []
    ): ?int {
        try {
            return (int) AddonAudit::query()->create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'addon' => $addon,
                'action' => $action,
                'target' => $target,
                'meta' => $meta,
                'created_at' => now(),
            ])->getKey();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('primus addon audit write failed: ' . $e->getMessage());

            return null;
        }
    }

    /* finalize a provisional audit target (e.g. marketplace install rows
     * written before the download knows the final jailed file name) */
    public static function retarget(?int $auditId, string $target): void
    {
        if ($auditId === null) {
            return;
        }
        try {
            AddonAudit::query()->where('id', $auditId)->update(['target' => $target]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('primus addon audit retarget failed: ' . $e->getMessage());
        }
    }
}
