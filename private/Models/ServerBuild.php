<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A persisted AI build plan plus its execution state for one server.
 *
 * @property int $id
 * @property int $server_id
 * @property int $user_id
 * @property string $prompt
 * @property string $mode
 * @property array|null $plan
 * @property string $status
 * @property array|null $progress
 * @property array|null $steps
 * @property string|null $backup_name
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class ServerBuild extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_BUILDING = 'building';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'primus_server_builds';

    protected $fillable = [
        'server_id', 'user_id', 'prompt', 'mode', 'plan',
        'status', 'progress', 'steps', 'backup_name',
    ];

    protected $casts = [
        'server_id' => 'int',
        'user_id' => 'int',
        'plan' => 'array',
        'progress' => 'array',
        'steps' => 'array',
    ];

    /** Latest-first build history for one server, pruned to 20 rows. */
    public static function historyFor(int $serverId, int $limit = 20): array
    {
        return static::query()
            ->where('server_id', $serverId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** Keeps the newest N rows per server; returns ids removed. */
    public static function pruneFor(int $serverId, int $keep = 20): int
    {
        $ids = static::query()
            ->where('server_id', $serverId)
            ->orderByDesc('id')
            ->skip(max(0, $keep))
            ->limit(100)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return static::query()->whereIn('id', $ids)->delete();
    }
}
