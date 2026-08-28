<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User-defined theme presets (built-in presets live in ThemePresetManager).
 *
 * @property int $id
 * @property string $name
 * @property string $payload JSON blob of token overrides + appearance settings
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ThemePreset extends Model
{
    protected $table = 'primus_theme_presets';

    protected $fillable = ['name', 'payload'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function toExport(): array
    {
        return [
            'name' => $this->name,
            'settings' => is_array($this->payload) ? $this->payload : [],
        ];
    }

    public static function fromImport(array $entry): self
    {
        return static::query()->updateOrCreate(
            ['name' => mb_substr((string) ($entry['name'] ?? 'Imported preset'), 0, 64)],
            ['payload' => $entry['settings'] ?? []]
        );
    }
}
