<?php

/**
 * Primus web routes — Blueprint loads this file inside an
 * /extensions/primus prefix with the 'blueprint' middleware group
 * (session, cookies, CSRF). Authentication is added per-group below.
 */

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;

use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AiFixerController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AiOptimizerController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AiUsageController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\ExportImportController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\SettingsController;

// Public runtime configuration consumed by the client bundle.
// No secrets here — see SettingsController::public().
Route::get('/settings.json', [SettingsController::class, 'public']);

// AI features — require an authenticated panel session.
Route::middleware(['auth'])->group(function () {
    Route::post('/ai/fix', [AiFixerController::class, 'diagnose']);
    Route::post('/ai/optimize', [AiOptimizerController::class, 'optimize']);
});

// Admin-only settings management (CSRF token is verified by the blueprint group).
Route::middleware(['auth', AdminAuthenticate::class])->group(function () {
    Route::post('/admin/save', [SettingsController::class, 'save']);
    Route::post('/admin/preset/apply', [SettingsController::class, 'applyPreset']);
    Route::post('/admin/preset/save', [SettingsController::class, 'savePreset']);
    Route::post('/admin/reset', [SettingsController::class, 'reset']);
    Route::get('/admin/presets/export', [ExportImportController::class, 'export']);
    Route::post('/admin/presets/import', [ExportImportController::class, 'import']);
    Route::get('/admin/usage', [AiUsageController::class, 'summary']);
    Route::post('/admin/ai/models', [SettingsController::class, 'models']);
});

// Tiny power-signal proxy used by the hover quick-actions on the server list
// (restart without leaving the dashboard). Requires a valid session and a
// server the acting user owns or is subuser on.
Route::middleware(['auth'])->post('/proxy/power', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    $server = Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\Shared::resolveAccessibleServer(
        (string) $request->json('server', ''),
        $user
    );

    if ($server === null) {
        return response()->json(['error' => 'Server not found or not accessible.'], 404);
    }

    $signal = (string) $request->json('signal', '');
    if (!in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
        return response()->json(['error' => 'Invalid power signal.'], 422);
    }

    // Subusers need the control-power permission; owners/admins always pass.
    if (!$user->root_admin && $server->owner_id !== $user->id) {
        $subuser = $server->subusers()->where('user_id', $user->id)->first();
        if ($subuser === null || !in_array('control-start', $subuser->permissions, true)
            && !in_array('control-stop', $subuser->permissions, true)
            && !in_array('control-restart', $subuser->permissions, true)) {
            return response()->json(['error' => 'You do not have power control access to this server.'], 403);
        }
    }

    try {
        app(\Pterodactyl\Repositories\Wings\DaemonPowerRepository::class)
            ->setServer($server)
            ->send($signal);
    } catch (\Throwable $e) {
        report($e);

        return response()->json(['error' => 'Could not reach the Wings daemon for this node.'], 502);
    }

    return response()->json(['ok' => true, 'signal' => $signal]);
});
