<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Admin\Extensions\primus;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\AddonGate;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\AddonRegistry;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MonkeyCodeClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\ThemePresetManager;

/**
 * Custom admin controller for the Primus extension.
 * Blueprint dispatches here for /admin/extensions/primus.
 */
class primusExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintAdminLibrary $blueprint,
        private ThemePresetManager $presets
    ) {
    }

    public function index(Request $request): View
    {
        return $this->view->make('admin.extensions.primus.index')->with([
            'blueprint' => $this->blueprint,
            'version' => '{version}',
            'identifier' => '{identifier}',
            'active_preset' => $this->presets->active(),
            'presets' => $this->presets->all(),
            'settings' => [
                'appearance' => (array) ThemeSetting::get('appearance', []),
                'overrides' => (array) ThemeSetting::get('overrides', []),
                'footer' => ThemeSetting::get('footer', ['enabled' => true, 'label' => '', 'links' => []]),
                'announcements' => ThemeSetting::get('announcements', ['text' => '']),
                'ai' => [
                    'api_key_configured' => ThemeSetting::get('ai.api_key', '') !== '',
                    'base_url' => ThemeSetting::get('ai.base_url', MonkeyCodeClient::DEFAULT_BASE_URL),
                    'models' => [
                        'fix' => (string) ThemeSetting::get('ai.models.fix', ''),
                        'optimize' => (string) ThemeSetting::get('ai.models.optimize', ''),
                        'notes' => (string) ThemeSetting::get('ai.models.notes', ''),
                    ],
                    'rate_limit_per_hour' => (int) ThemeSetting::get('ai.rate_limit_per_hour', 30),
                    'fixer_enabled' => (bool) ThemeSetting::get('ai.fixer_enabled', true),
                    'optimizer_enabled' => (bool) ThemeSetting::get('ai.optimizer_enabled', true),
                ],
                'shortcuts' => ThemeSetting::get('shortcuts', ['hint' => true]),
                'quickactions' => ThemeSetting::get('quickactions', ['enabled' => true]),
            ],
            'addons' => array_map(function ($m) {
                return [
                    'id' => $m['id'],
                    'title' => $m['title'],
                    'description' => $m['description'],
                    'category' => $m['category'],
                    'enabled' => AddonGate::enabled((string) $m['id']),
                    'comingSoon' => (bool) $m['comingSoon'],
                ];
            }, AddonRegistry::all()),
            // Left joins: framework audit rows carry server_id 0 (no server),
            // which an inner join would silently drop. created_at is a raw
            // string (AddonAudit has $timestamps=false, no date cast) and
            // legacy rows may hold zero-dates — render those defensively.
            'audit' => AddonAudit::query()
                ->leftJoin('users', 'users.id', '=', 'primus_addon_audit.user_id')
                ->leftJoin('servers', 'servers.id', '=', 'primus_addon_audit.server_id')
                ->orderByDesc('primus_addon_audit.id')
                ->limit(50)
                ->get(['primus_addon_audit.*', 'users.email as user_email', 'servers.name as server_name'])
                ->map(fn ($r) => [
                    'when' => $r->created_at && ($ts = strtotime((string) $r->created_at)) && $ts > 0
                        ? \Carbon\Carbon::createFromTimestamp($ts)->diffForHumans()
                        : '—',
                    'addon' => $r->addon,
                    'action' => $r->action,
                    'target' => $r->target,
                    'user' => $r->user_email ?? '—',
                    'server' => $r->server_name ?? '—',
                ]),
        ]);
    }

    /**
     * Settings persist through the validated XHR endpoint
     * (/extensions/primus/admin/save); direct POSTs are a no-op redirect so
     * accidental double submissions cannot double-fire mutations.
     */
    public function post(Request $request)
    {
        return redirect('/admin/extensions/primus');
    }
}
