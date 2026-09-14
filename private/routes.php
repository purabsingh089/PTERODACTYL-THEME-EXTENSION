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
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\ServerCardsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\MotdController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\SettingsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AddonsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\PluginsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\MarketplaceController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\WorldsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\ModsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\PlayerStatsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\VersionsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\IconsController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\TrashController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\TrashApiController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\PropertiesController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\MotdAddonController;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AiMotdController;

// Public runtime configuration consumed by the client bundle.
// No secrets here — see SettingsController::public().
Route::get('/settings.json', [SettingsController::class, 'public']);

// Server card banners (dashboard grid + settings page image picker).
Route::middleware(['auth'])->group(function () {
    Route::post('/server-cards/images', [ServerCardsController::class, 'images']);
    Route::post('/server-cards/image', [ServerCardsController::class, 'setImage']);
});

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
        $perms = $subuser ? (array) $subuser->permissions : [];
        $canPower = in_array('control.start', $perms, true)
            || in_array('control.stop', $perms, true)
            || in_array('control.restart', $perms, true);
        if ($subuser === null || !$canPower) {
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

// MOTD Creator — read/write the motd= line of server.properties.
// GET gates on egg + file sniff; POST additionally requires file.update.
Route::middleware(['auth'])->group(function () {
    Route::get('/motd', [MotdController::class, 'index']);
    Route::post('/motd', [MotdController::class, 'save']);
    Route::post('/motd/ai/generate', [MotdController::class, 'aiGenerate']);
});

// Addon framework — hub + Plugin Manager (pilot). All gated through
// AddonGate inside the controllers; the admin toggle is root-admin only.
Route::middleware(['auth'])->group(function () {
    Route::get('/addons', [AddonsController::class, 'index']);
    Route::get('/addons/plugins', [PluginsController::class, 'index']);
    Route::post('/addons/plugins/toggle', [PluginsController::class, 'toggle']);
    Route::post('/addons/plugins/delete', [PluginsController::class, 'delete']);
    Route::post('/addons/plugins/upload', [PluginsController::class, 'upload']);
    Route::get('/addons/plugins/search', [PluginsController::class, 'search']);
    Route::get('/addons/marketplace/search', [MarketplaceController::class, 'search']);
    Route::get('/addons/marketplace/versions', [MarketplaceController::class, 'versions']);
    Route::post('/addons/marketplace/install', [MarketplaceController::class, 'install']);
    Route::get('/addons/worlds', [WorldsController::class, 'index']);
    Route::post('/addons/worlds/switch', [WorldsController::class, 'switch']);
    Route::post('/addons/worlds/backup', [WorldsController::class, 'backup']);
    Route::post('/addons/worlds/delete', [WorldsController::class, 'delete']);
    Route::get('/addons/mods', [ModsController::class, 'index']);
    Route::post('/addons/mods/toggle', [ModsController::class, 'toggle']);
    Route::post('/addons/mods/delete', [ModsController::class, 'delete']);
    Route::post('/addons/mods/upload', [ModsController::class, 'upload']);
    Route::get('/addons/mods/search', [ModsController::class, 'search']);
    Route::get('/addons/versions', [VersionsController::class, 'index']);
    Route::post('/addons/versions/jar', [VersionsController::class, 'jar']);
    Route::post('/addons/versions/image', [VersionsController::class, 'image']);
    Route::get('/addons/icons', [IconsController::class, 'index']);
    Route::post('/addons/icons/upload', [IconsController::class, 'upload']);
    Route::post('/addons/icons/delete', [IconsController::class, 'delete']);
    Route::get('/addons/properties', [PropertiesController::class, 'index']);
    Route::post('/addons/properties/save', [PropertiesController::class, 'save']);
});

// Player Stats — online, joins, sessions, feed, actions, allocations.
Route::middleware(['auth'])->group(function () {
    Route::get('/player-stats', [PlayerStatsController::class, 'index']);
    Route::post('/player-stats/command', [PlayerStatsController::class, 'command']);
    Route::post('/player-stats/notes', [PlayerStatsController::class, 'notes']);
});

// Trash — the file-trash.js fetch-patch reroutes stock file-manager
// deletes to trash/add; the Files-page panel drives the rest.
Route::middleware(['auth'])->group(function () {
    Route::get('/trash/list', [TrashApiController::class, 'listing']);
    Route::post('/trash/add', [TrashApiController::class, 'add']);
    Route::post('/trash/restore', [TrashApiController::class, 'restore']);
    Route::post('/trash/destroy', [TrashApiController::class, 'destroy']);
    Route::post('/trash/empty', [TrashApiController::class, 'emptyTrash']);
});

Route::middleware(['auth', AdminAuthenticate::class])->group(function () {
    Route::post('/addons/admin/toggle', [AddonsController::class, 'toggle']);
    Route::post('/addons/admin/marketplace/keys', [MarketplaceController::class, 'saveKeys']);
    Route::post('/trash/purge', [TrashApiController::class, 'purge']);
});
