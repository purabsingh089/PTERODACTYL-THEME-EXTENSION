<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Illuminate\Support\Facades\File;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemePreset;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;

/**
 * Built-in + user preset management.
 *
 * Built-in presets ship as JSON files inside the private data directory
 * ({root/data}/presets/*.json). User presets live in the database.
 * Applying a preset copies its token overrides into the "overrides" settings
 * key (rendered as CSS custom properties on load) and, when provided, merges
 * its appearance section into the "appearance" group.
 */
class ThemePresetManager
{
    /** Default preset applied on first install / reset. */
    public const DEFAULT_PRESET = 'midnight';

    /** @return array<string, array> built-ins keyed by preset id */
    public function builtIns(): array
    {
        $dir = base_path('.blueprint/extensions/{identifier}/private/presets');
        $out = [];

        if (!File::isDirectory($dir)) {
            return $out;
        }

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $decoded = json_decode(File::get($file->getPathname()), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $out[(string) ($decoded['id'] ?? pathinfo($file->getFilename(), PATHINFO_FILENAME))] = $decoded;
            }
        }

        return $out;
    }

    /** Merge built-ins + saved user presets for the admin selector. */
    public function all(): array
    {
        $presets = [];

        foreach ($this->builtIns() as $id => $preset) {
            $presets[$id] = array_merge(['built_in' => true], $preset);
        }

        foreach (ThemePreset::query()->orderBy('created_at')->get() as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $presets['user:' . $row->id] = [
                'id' => 'user:' . $row->id,
                'name' => $row->name,
                'built_in' => false,
                'overrides' => $payload['overrides'] ?? [],
                'appearance' => $payload['appearance'] ?? null,
            ];
        }

        // Guarantee every preset card gets gradient swatch colors.
        foreach ($presets as $id => $preset) {
            $overrides = $preset['overrides'] ?? [];
            if (!isset($preset['swatch'])) {
                $presets[$id]['swatch'] = [
                    'a' => $overrides['--pr-accent'] ?? '#0050b8',
                    'b' => $overrides['--pr-accent-strong'] ?? '#1e6fe0',
                ];
            }
        }

        return $presets;
    }

    /**
     * Apply a preset: writes sanitized overrides into settings and merges the
     * appearance section. Returns false when the id is unknown.
     */
    public function apply(?string $presetId): bool
    {
        $presetId = $presetId !== null && $presetId !== '' ? $presetId : self::DEFAULT_PRESET;

        if (str_starts_with($presetId, 'user:')) {
            $row = ThemePreset::query()->find((int) substr($presetId, 5));
            if ($row === null) {
                return false;
            }
            $payload = is_array($row->payload) ? $row->payload : [];
            $overrides = $payload['overrides'] ?? [];
            $appearance = $payload['appearance'] ?? null;
        } else {
            $builtIns = $this->builtIns();
            if (!isset($builtIns[$presetId])) {
                if ($presetId !== self::DEFAULT_PRESET && !isset($builtIns[self::DEFAULT_PRESET])) {
                    return false;
                }
                $presetId = isset($builtIns[$presetId]) ? $presetId : self::DEFAULT_PRESET;
            }
            $preset = $builtIns[$presetId] ?? [];
            $overrides = $preset['overrides'] ?? [];
            $appearance = $preset['appearance'] ?? null;
        }

        // Only accept CSS custom properties as overrides (defense in depth).
        $clean = [];
        foreach ((array) $overrides as $property => $value) {
            if (is_string($property) && str_starts_with($property, '--') && is_scalar($value) && strlen((string) $value) <= 512) {
                $clean[$property] = (string) $value;
            }
        }

        ThemeSetting::set('overrides', $clean);

        if (is_array($appearance) && $appearance !== []) {
            $stored = (array) ThemeSetting::get('appearance', []);
            $allowed = ['theme', 'vibrance', 'radius', 'shadow', 'white_label', 'logo_url', 'favicon_url', 'font_heading', 'font_body', 'font_mono'];
            foreach ($appearance as $key => $value) {
                if (in_array($key, $allowed, true) && is_scalar($value)) {
                    $stored[$key] = $value;
                }
            }
            ThemeSetting::set('appearance', $stored);
        }

        ThemeSetting::set('active_preset', $presetId);

        return true;
    }

    public function active(): string
    {
        $active = (string) ThemeSetting::get('active_preset', self::DEFAULT_PRESET);

        return $active !== '' ? $active : self::DEFAULT_PRESET;
    }

    /** Persist the current customizer state as a named user preset. */
    public function saveCustom(string $name): ThemePreset
    {
        $name = mb_substr(trim($name), 0, 64);

        return ThemePreset::query()->updateOrCreate(
            ['name' => $name !== '' ? $name : 'Untitled preset'],
            [
                'payload' => [
                    'overrides' => (array) ThemeSetting::get('overrides', []),
                    'appearance' => (array) ThemeSetting::get('appearance', []),
                ],
            ]
        );
    }

    public function reset(): void
    {
        ThemeSetting::remove('overrides');
        ThemeSetting::remove('appearance');
        ThemeSetting::remove('active_preset');
    }

    /** Every saved preset (or the current config) ready for export. */
    public function exportAll(): array
    {
        $presets = array_map(
            fn (ThemePreset $p) => $p->toExport(),
            ThemePreset::query()->get()->all()
        );

        if ($presets === []) {
            $presets = [
                [
                    'name' => 'Custom theme',
                    'settings' => [
                        'overrides' => (array) ThemeSetting::get('overrides', []),
                        'appearance' => (array) ThemeSetting::get('appearance', []),
                    ],
                ],
            ];
        }

        return [
            'type' => 'primus.presets',
            'exported_at' => now()->toIso8601String(),
            'presets' => array_values($presets),
        ];
    }

    /** Import presets previously exported; returns import count. */
    public function importAll(array $payload): int
    {
        $list = $payload['presets'] ?? $payload;
        if (!is_array($list)) {
            return 0;
        }

        $count = 0;
        foreach ($list as $entry) {
            if (!is_array($entry) || !is_array($entry['settings'] ?? null)) {
                continue;
            }
            ThemePreset::fromImport([
                'name' => (string) ($entry['name'] ?? 'Imported preset'),
                'settings' => $entry['settings'],
            ]);
            $count++;
        }

        return $count;
    }
}
