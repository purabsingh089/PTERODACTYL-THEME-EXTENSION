<?php

namespace {appcontext}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value storage for theme configuration.
 * Values are JSON-encoded text so arrays (footer links, overrides) survive
 * roundtrips without extra tables.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ThemeSetting extends Model
{
    protected $table = 'primus_theme_settings';

    protected $fillable = ['key', 'value'];

    public $timestamps = ['updated_at'];

    protected $hidden = [];

    /**
     * Fetch a setting value, JSON-decoded. Returns $default when missing or
     * when the stored value cannot be decoded.
     */
    public static function get(string $key, $default = null)
    {
        $row = static::query()->where('key', $key)->first();
        if ($row === null || $row->value === null || $row->value === '') {
            return $default;
        }

        $decoded = json_decode($row->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /**
     * Persist a setting value (JSON-encoded).
     */
    public static function set(string $key, $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    public static function remove(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }

    /**
     * Return every stored setting as a plain key => decoded-value array.
     */
    public static function allAsArray(): array
    {
        $out = [];
        foreach (static::query()->get() as $row) {
            $decoded = json_decode((string) $row->value, true);
            $out[$row->key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
        }

        return $out;
    }
}
