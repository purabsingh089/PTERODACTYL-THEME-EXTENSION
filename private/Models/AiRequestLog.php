<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log for every AI provider request. Used for abuse monitoring,
 * rate-limit accounting and the admin usage dashboard.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $server_id
 * @property string $feature fix|optimize|notes
 * @property string $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property string $status ok|error|rate_limited
 * @property int $latency_ms
 * @property \Illuminate\Support\Carbon $created_at
 */
class AiRequestLog extends Model
{
    protected $table = 'primus_ai_request_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'server_id',
        'feature',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'status',
        'latency_ms',
        'created_at',
    ];

    /**
     * Aggregate stats for the usage dashboard.
     */
    public static function usageSummary(int $days = 7): array
    {
        $since = now()->subDays($days);

        $totals = static::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->selectRaw('AVG(latency_ms) as avg_latency')
            ->first();

        $byModel = static::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('model, COUNT(*) as calls')
            ->groupBy('model')
            ->pluck('calls', 'model')
            ->all();

        $byFeature = static::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('feature, COUNT(*) as calls')
            ->groupBy('feature')
            ->pluck('calls', 'feature')
            ->all();

        $errors = static::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', ['error', 'rate_limited'])
            ->count();

        return [
            'calls' => (int) ($totals->calls ?? 0),
            'prompt_tokens' => (int) ($totals->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($totals->completion_tokens ?? 0),
            'avg_latency_ms' => (int) round($totals->avg_latency ?? 0),
            'errors' => (int) $errors,
            'by_model' => $byModel,
            'by_feature' => $byFeature,
        ];
    }
}
