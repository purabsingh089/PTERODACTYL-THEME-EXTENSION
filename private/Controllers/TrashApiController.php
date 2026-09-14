<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\TrashService;
use Pterodactyl\Http\Controllers\Controller;

/**
 * TrashApiController — the file-trash.js fetch-patch reroutes the stock
 * file manager's delete call to trash/add, and the Files-page Trash panel
 * drives list/restore/destroy/empty. Every mutation gates on the trash
 * addon + file.update permission, is rate limited and audited before
 * any Wings call. trash/purge is the admin/cron entrypoint.
 */
class TrashApiController extends Controller
{
    public function listing(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'trash', $server);
        if ($gate !== null) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'entries' => TrashService::listing($server)->map(fn ($e) => [
                'id' => (int) $e->id,
                'name' => basename($e->original_path),
                'originalPath' => $e->original_path,
                'deletedAt' => $e->created_at?->toIso8601String(),
                'purgeInDays' => max(0, TrashService::RETENTION_DAYS - (int) now()->diffInDays($e->created_at)),
                'deletedBy' => (int) $e->deleted_by,
            ])->values(),
            'canRestore' => Shared::hasPerm($request->user(), $server, 'file.update'),
        ]);
    }

    public function add(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'trash', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file-update access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.trash')) {
            return response()->json(['error' => 'Too many trash operations this hour.'], 429);
        }

        $files = $request->input('files', []);
        if (!is_array($files) || $files === []) {
            return response()->json(['error' => 'No files provided.'], 422);
        }
        if (count($files) > 100) {
            return response()->json(['error' => 'Too many files at once (max 100).'], 422);
        }

        $names = array_map(fn ($f) => basename((string) $f), $files);
        $auditId = AddonGate::audit($user, $server, 'trash', 'delete', implode(',', $names) ?: 'files', ['count' => count($files)]);

        $res = TrashService::add($server, $user, $files);

        if ($res['moved'] === 0) {
            AddonGate::retarget($auditId, 'error');

            return response()->json(['error' => 'None of those files could be moved to trash.'], 422);
        }

        return response()->json(['ok' => true, 'moved' => $res['moved']]);
    }

    public function restore(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'trash', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file-update access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.trash')) {
            return response()->json(['error' => 'Too many trash operations this hour.'], 429);
        }

        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'Missing entry id.'], 422);
        }
        $name = TrashService::entryName($server, $id);
        if ($name === null) {
            return response()->json(['error' => 'Entry not found.'], 404);
        }

        AddonGate::audit($user, $server, 'trash', 'restore', $name, ['id' => $id]);

        try {
            $res = TrashService::restore($server, $id);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'Restore failed.'], 502);
        }

        return response()->json(['ok' => true, 'path' => $res['path']]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'trash', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file-update access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.trash')) {
            return response()->json(['error' => 'Too many trash operations this hour.'], 429);
        }

        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'Missing entry id.'], 422);
        }
        $name = TrashService::entryName($server, $id);
        if ($name === null) {
            return response()->json(['error' => 'Entry not found.'], 404);
        }
        $confirm = (string) $request->input('confirm', '');
        if ($confirm !== $name) {
            return response()->json(['error' => 'Type the file name to confirm.'], 422);
        }

        AddonGate::audit($user, $server, 'trash', 'destroy', $name, ['id' => $id]);

        try {
            TrashService::destroy($server, $id);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not delete the file.'], 502);
        }

        return response()->json(['ok' => true]);
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'trash', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        if (!Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file-update access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.trash')) {
            return response()->json(['error' => 'Too many trash operations this hour.'], 429);
        }

        AddonGate::audit($user, $server, 'trash', 'empty', 'trash');

        try {
            $n = TrashService::empty($server);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not empty the trash.'], 502);
        }

        return response()->json(['ok' => true, 'purged' => $n]);
    }

    /** Admin/cron entrypoint: purge entries past the retention window. */
    public function purge(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->root_admin) {
            return response()->json(['error' => 'Admin only.'], 403);
        }

        return response()->json(['ok' => true, 'purged' => TrashService::purge()]);
    }
}
