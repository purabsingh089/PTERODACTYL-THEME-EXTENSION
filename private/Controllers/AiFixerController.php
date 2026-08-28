<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\LogSanitizer;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MonkeyCodeClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PromptBuilder;

/**
 * AI Server Fixer — analyzes sanitized console output and returns a
 * structured diagnosis. Never auto-applies anything.
 */
class AiFixerController extends Controller
{
    public function __construct(
        private MonkeyCodeClient $client,
        private PromptBuilder $prompts,
        private LogSanitizer $sanitizer
    ) {
    }

    public function diagnose(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        if (!ThemeSetting::get('ai.fixer_enabled', true)) {
            return response()->json(['error' => 'The AI Fixer has been disabled by the administrator.'], 403);
        }

        if (!$this->client->hasApiKey()) {
            return response()->json(['error' => 'AI is not configured yet. Add an API key in Admin → Extensions → Primus → AI.'], 422);
        }

        $server = Shared::resolveAccessibleServer((string) $request->json('server', ''), $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        $snippet = (string) $request->json('snippet', '');
        if (trim($snippet) === '') {
            return response()->json(['error' => 'No console output was captured.'], 422);
        }

        if (Shared::rateLimited($user->id, 'fix')) {
            return response()->json(['error' => 'Rate limit exceeded. Try again in a few minutes.'], 429);
        }

        $sanitized = $this->sanitizer->sanitize($snippet);
        $context = [
            'egg' => $server->egg->name ?? 'unknown',
            'nest' => $server->nest->name ?? 'unknown',
        ];

        try {
            $result = $this->client->complete(
                'fix',
                $this->prompts->fixerMessages($sanitized, $context),
                $user->id,
                $server->uuid
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(Shared::normalizeAiJson($result['content']));
    }
}
