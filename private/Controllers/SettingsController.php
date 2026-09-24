<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\ThemePresetManager;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MonkeyCodeClient;

class SettingsController extends Controller
{
    public function __construct(
        private ThemePresetManager $presets,
        private MonkeyCodeClient $client,
    ) {
    }

    /**
     * Runtime configuration consumed by the client bundle. NEVER includes the
     * API key or any other secret — those stay server-side and are only used
     * by the PHP AI controllers.
     */
    public function public(): JsonResponse
    {
        $appearance = $this->appearance();
        $footer = (array) ThemeSetting::get('footer', ['enabled' => true, 'label' => '', 'links' => []]);
        $announcements = (array) ThemeSetting::get('announcements', ['text' => '']);

        return response()->json([
            'version' => '{version}',
            'active_preset' => $this->presets->active(),
            'overrides' => (array) ThemeSetting::get('overrides', []),
            'appearance' => $appearance,
            'footer' => $footer,
            'announcements' => [
                'text' => (string) ($announcements['text'] ?? ''),
                'markdown' => true,
            ],
            'shortcuts' => (array) ThemeSetting::get('shortcuts', ['hint' => true]),
            'quickactions' => (array) ThemeSetting::get('quickactions', ['enabled' => true]),
            'ai' => [
                'fixer_enabled' => (bool) ThemeSetting::get('ai.fixer_enabled', true),
                'optimizer_enabled' => (bool) ThemeSetting::get('ai.optimizer_enabled', true),
                'models' => [
                    'fix' => (string) ThemeSetting::get('ai.models.fix', ''),
                    'optimize' => (string) ThemeSetting::get('ai.models.optimize', ''),
                ],
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Ask the AI provider for its model catalog (OpenAI-compatible GET /models).
     * Accepts unsaved base_url / api_key so an admin can check a provider
     * before pressing Save. The key is only used for this single request and
     * is never stored or returned.
     */
    public function models(Request $request): JsonResponse
    {
        $data = is_array($request->json()->all()) ? $request->json()->all() : [];

        $base = trim((string) ($data['base_url'] ?? ''));
        if ($base === '' || !preg_match('#^https?://#', $base)) {
            $base = MonkeyCodeClient::DEFAULT_BASE_URL;
        }

        $key = trim((string) ($data['api_key'] ?? ''));
        if ($key === '' || $key === '••••••••') {
            $key = null;
        }

        try {
            $catalog = $this->client->availableModels($base, $key);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'models' => []], 200);
        }

        return response()->json([
            'models' => $catalog['models'],
            'warning' => $catalog['warning'] !== '' ? $catalog['warning'] : null,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Persist customizer settings. Only whitelisted groups/keys are accepted;
     * everything else is dropped so a crafted payload cannot write arbitrary
     * keys into the settings table.
     */
    public function save(Request $request): JsonResponse
    {
        $data = is_array($request->json()->all()) ? $request->json()->all() : [];

        // ── overrides: only "--" custom properties with short scalar values ──
        if (isset($data['overrides']) && is_array($data['overrides'])) {
            $clean = [];
            foreach ($data['overrides'] as $property => $value) {
                if (
                    is_string($property)
                    && str_starts_with($property, '--')
                    && strlen($property) <= 64
                    && is_scalar($value)
                    && strlen((string) $value) <= 512
                ) {
                    $clean[$property] = (string) $value;
                }
            }
            ThemeSetting::set('overrides', $clean);
        }

        // ── appearance (single group key) ──
        if (isset($data['appearance']) && is_array($data['appearance'])) {
            $allowed = ['theme', 'vibrance', 'radius', 'shadow', 'white_label', 'logo_url', 'favicon_url', 'font_heading', 'font_body', 'font_mono', 'layout', 'sidebar_collapsed', 'container', 'power_position'];
            $appearance = $this->appearance();
            foreach ($data['appearance'] as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }
                if (in_array($key, ['theme'], true)) {
                    $appearance[$key] = in_array($value, ['dark', 'light'], true) ? $value : $appearance[$key];
                } elseif (in_array($key, ['vibrance'], true)) {
                    $appearance[$key] = in_array($value, ['subtle', 'normal', 'vivid'], true) ? $value : $appearance[$key];
                } elseif (in_array($key, ['layout'], true)) {
                    $appearance[$key] = in_array($value, ['sidebar', 'topbar'], true) ? $value : $appearance[$key];
                } elseif (in_array($key, ['container'], true)) {
                    $appearance[$key] = in_array($value, ['flush', 'boxed'], true) ? $value : $appearance[$key];
                } elseif (in_array($key, ['power_position'], true)) {
                    $appearance[$key] = in_array($value, ['sidebar', 'header', 'floating'], true) ? $value : $appearance[$key];
                } elseif (in_array($key, ['white_label', 'sidebar_collapsed'], true)) {
                    $appearance[$key] = (bool) $value;
                } elseif (in_array($key, ['radius', 'shadow'], true)) {
                    $appearance[$key] = is_numeric($value) ? (float) $value : $appearance[$key];
                } else {
                    $appearance[$key] = is_scalar($value) ? (string) $value : '';
                }
            }
            ThemeSetting::set('appearance', $appearance);
        }

        // ── remaining grouped settings ──
        $this->saveGroup($data, 'footer', ['enabled', 'label', 'links']);
        $this->saveGroup($data, 'announcements', ['text']);
        $this->saveGroup($data, 'shortcuts', ['hint']);
        $this->saveGroup($data, 'quickactions', ['enabled']);

        // ── AI settings: never return the key, only accept it ──
        if (isset($data['ai']) && is_array($data['ai'])) {
            $ai = $data['ai'];
            if (isset($ai['api_key']) && is_string($ai['api_key']) && trim($ai['api_key']) !== '' && trim($ai['api_key']) !== '••••••••') {
                ThemeSetting::set('ai.api_key', trim($ai['api_key']));
            }
            if (isset($ai['base_url']) && is_string($ai['base_url'])) {
                $url = trim($ai['base_url']);
                if (preg_match('#^https?://#', $url)) {
                    ThemeSetting::set('ai.base_url', $url);
                }
            }
            if (isset($ai['rate_limit_per_hour']) && is_numeric($ai['rate_limit_per_hour'])) {
                ThemeSetting::set('ai.rate_limit_per_hour', max(1, min(500, (int) $ai['rate_limit_per_hour'])));
            }
            foreach (['fixer_enabled', 'optimizer_enabled'] as $toggle) {
                if (isset($ai[$toggle])) {
                    ThemeSetting::set('ai.' . $toggle, (bool) $ai[$toggle]);
                }
            }
            if (isset($ai['models']) && is_array($ai['models'])) {
                foreach (['fix', 'optimize', 'notes'] as $feature) {
                    if (array_key_exists($feature, $ai['models'])) {
                        // Null arrives when the field was emptied: Laravel's
                        // ConvertEmptyStringsToNull turns '' into null before we see it.
                        $raw = $ai['models'][$feature];
                        ThemeSetting::set('ai.models.' . $feature, is_string($raw) ? trim($raw) : '');
                    }
                }
            }
        }

        return response()->json(['saved' => true, 'active_preset' => $this->presets->active()]);
    }

    public function applyPreset(Request $request): JsonResponse
    {
        $id = (string) $request->json('preset', '');
        $applied = $this->presets->apply($id !== '' ? $id : null);

        return $applied
            ? response()->json(['applied' => $id !== '' ? $id : $this->presets->active()])
            : response()->json(['error' => 'Unknown preset.'], 422);
    }

    public function savePreset(Request $request): JsonResponse
    {
        $name = trim((string) $request->json('name', ''));
        if ($name === '') {
            return response()->json(['error' => 'A preset name is required.'], 422);
        }
        if (mb_strlen($name) > 64) {
            return response()->json(['error' => 'Preset name is too long (max 64 characters).'], 422);
        }

        $preset = $this->presets->saveCustom($name);

        return response()->json(['saved' => true, 'id' => 'user:' . $preset->id, 'name' => $preset->name]);
    }

    public function reset(): JsonResponse
    {
        $this->presets->reset();
        $this->presets->apply(ThemePresetManager::DEFAULT_PRESET);

        return response()->json(['reset' => true, 'active_preset' => $this->presets->active()]);
    }

    /** Return appearance with sane defaults merged in. */
    protected function appearance(): array
    {
        $defaults = [
            'theme' => 'dark',
            'vibrance' => 'normal',
            'radius' => 1,
            'shadow' => 1,
            'white_label' => false,
            'logo_url' => '',
            'favicon_url' => '',
            'font_heading' => '',
            'font_body' => '',
            'font_mono' => '',
            'layout' => 'sidebar',
            'sidebar_collapsed' => false,
            'container' => 'flush',
            'power_position' => 'sidebar',
        ];

        return array_merge($defaults, (array) ThemeSetting::get('appearance', []));
    }

    /** Merge an incoming subset of a group with its stored value. */
    protected function saveGroup(array $data, string $group, array $allowedKeys): void
    {
        if (!isset($data[$group]) || !is_array($data[$group])) {
            return;
        }
        $stored = (array) ThemeSetting::get($group, []);
        foreach ($data[$group] as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }
            // footer links must be an array of link objects.
            if ($key === 'links') {
                $stored[$key] = is_array($value) ? $value : [];
            } else {
                $stored[$key] = $value;
            }
        }
        ThemeSetting::set($group, $stored);
    }
}
