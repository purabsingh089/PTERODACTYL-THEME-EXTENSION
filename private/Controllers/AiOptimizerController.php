<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MonkeyCodeClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PromptBuilder;

/**
 * AI Resource Optimizer — receives client-side summarized usage stats
 * (already aggregated from the console websocket) and returns plain-language
 * resource advice. Suggestions only; surface as a dismissible card.
 */
class AiOptimizerController extends Controller
{
    public function __construct(
        private MonkeyCodeClient $client,
        private PromptBuilder $prompts
    ) {
    }

    public function optimize(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        if (!ThemeSetting::get('ai.optimizer_enabled', true)) {
            return response()->json(['error' => 'The AI Optimizer has been disabled by the administrator.'], 403);
        }

        if (!$this->client->hasApiKey()) {
            return response()->json(['error' => 'AI is not configured yet. Add an API key in Admin → Extensions → Primus → AI.'], 422);
        }

        $server = Shared::resolveAccessibleServer((string) $request->json('server', ''), $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        $stats = $request->json('stats', []);
        if (!is_array($stats) || $stats === []) {
            return response()->json(['error' => 'No usage statistics were provided.'], 422);
        }

        // Whitelist + type-cast the summary so arbitrary arrays never reach the prompt builder.
        $clean = [
            'window_seconds' => (int) ($stats['window_seconds'] ?? 0),
            'cpu_avg' => (float) ($stats['cpu_avg'] ?? 0),
            'cpu_peak' => (float) ($stats['cpu_peak'] ?? 0),
            'cpu_limit_pct' => isset($stats['cpu_limit_pct']) ? (float) $stats['cpu_limit_pct'] : null,
            'mem_avg_bytes' => (int) ($stats['mem_avg_bytes'] ?? 0),
            'mem_peak_bytes' => (int) ($stats['mem_peak_bytes'] ?? 0),
            'mem_limit_bytes' => isset($stats['mem_limit_bytes']) ? (int) $stats['mem_limit_bytes'] : null,
        ];
        $clean['disk_used_bytes'] = (int) ($server->disk ?? 0) * 1024 * 1024;
        $clean['cpu_allocated_pct'] = (int) ($server->cpu ?? 0);
        $clean['mem_allocated_bytes'] = (int) ($server->memory ?? 0) * 1024 * 1024;

        if (Shared::rateLimited($user->id, 'optimize')) {
            return response()->json(['error' => 'Rate limit exceeded. Try again later.'], 429);
        }

        try {
            $result = $this->client->complete(
                'optimize',
                $this->prompts->optimizerMessages($clean, [
                    'egg' => $server->egg->name ?? 'unknown',
                    'nest' => $server->nest->name ?? 'unknown',
                ]),
                $user->id,
                $server->uuid
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $normalized = Shared::normalizeAiJson($result['content']);

        return response()->json([
            'suggestions' => $normalized['suggestion'] ?? ($normalized['cause'] ?? $result['content']),
            'model' => $result['model'],
        ]);
    }
}
