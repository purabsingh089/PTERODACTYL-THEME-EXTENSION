<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AiRequestLog;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;

/**
 * Usage/quota dashboard data for administrators: call volume, per-model
 * breakdown, cost estimate (static per-model unit prices, configurable) and
 * the configured rate-limit status.
 */
class AiUsageController extends Controller
{
    /** Rough $/1M input tokens estimates used for the cost column only. */
    private const COST_PER_M_INPUT = [
        'deepseek-v4-flash' => 0.10,
        'qwen3.5-plus' => 0.40,
        'qwen3.8' => 0.80,
    ];

    public function summary(Request $request): JsonResponse
    {
        $days = max(1, min(30, (int) $request->query('days', 7)));
        $summary = AiRequestLog::usageSummary($days);

        $cost = 0.0;
        $perModel = [];
        foreach ($summary['by_model'] as $model => $calls) {
            $price = self::COST_PER_M_INPUT[$model] ?? 0.25;
            $share = $summary['calls'] > 0 ? $calls / $summary['calls'] : 0;
            $modelCost = $price * ($summary['prompt_tokens'] * $share) / 1_000_000;
            $perModel[$model] = [
                'calls' => $calls,
                'est_cost_usd' => round($modelCost, 4),
            ];
            $cost += $modelCost;
        }

        return response()->json([
            'window_days' => $days,
            'calls' => $summary['calls'],
            'prompt_tokens' => $summary['prompt_tokens'],
            'completion_tokens' => $summary['completion_tokens'],
            'avg_latency_ms' => $summary['avg_latency_ms'],
            'errors' => $summary['errors'],
            'est_cost_usd' => round($cost, 4),
            'by_model' => $perModel,
            'by_feature' => $summary['by_feature'],
            'rate_limit_per_hour' => (int) ThemeSetting::get('ai.rate_limit_per_hour', 30),
            'fixer_enabled' => (bool) ThemeSetting::get('ai.fixer_enabled', true),
            'optimizer_enabled' => (bool) ThemeSetting::get('ai.optimizer_enabled', true),
            'configured' => ThemeSetting::get('ai.api_key', '') !== '',
        ]);
    }
}
