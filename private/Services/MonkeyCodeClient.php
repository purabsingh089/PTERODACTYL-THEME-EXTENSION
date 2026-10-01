<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AiRequestLog;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;

/**
 * Thin HTTP wrapper around the monkeycode-ai.net chat completions API.
 *
 * - Keys are read server-side only; they never reach the browser.
 * - Every completed call (success or failure) is recorded in the
 *   primus_ai_request_logs table for the usage dashboard.
 * - The model can be swapped per feature from the admin settings page.
 */
class MonkeyCodeClient
{
    public const DEFAULT_BASE_URL = 'https://monkeycode-ai.net/v1';

    /** Reference model ids for the default provider (not enforced — any model id works). */
    public const MODELS = [
        'deepseek-v4-flash' => 'DeepSeek V4 Flash — fast diagnostics',
        'qwen3.5-plus' => 'Qwen 3.5 Plus — deeper analysis',
        'qwen3.8' => 'Qwen 3.8 — deepest reasoning',
    ];

    /**
     * Perform a chat completion request and log the outcome.
     *
     * @param string $feature  fix|optimize|notes (drives model selection + logging)
     * @param array  $messages OpenAI-style messages [{role, content}, ...]
     * @param int    $userId   acting user, for logging/rate limiting
     *
     * @throws \RuntimeException on provider errors (translated by controllers)
     */
    public function complete(string $feature, array $messages, int $userId, ?string $serverId = null): array
    {
        return $this->completeWith(
            $feature,
            $messages,
            $userId,
            $serverId,
            $this->baseUrl(),
            $this->apiKey(),
            $this->modelFor($feature)
        );
    }

    /**
     * Chat completion against an explicit OpenAI-compatible endpoint.
     * Used by the AI Server Builder so each user can supply their own
     * base URL, key and model without touching the global Fixer key.
     */
    public function completeWith(
        string $feature,
        array $messages,
        int $userId,
        ?string $serverId,
        string $baseUrl,
        string $apiKey,
        string $model
    ): array {
        $model = trim($model);
        if ($model === '') {
            $model = $this->modelFor($feature);
        }
        $base = self::normalizeBaseUrl($baseUrl);
        $started = microtime(true);

        $response = null;
        $status = 'ok';

        try {
            $pending = Http::withToken($apiKey)
                ->timeout($this->timeoutSeconds())
                ->connectTimeout(10)
                ->acceptJson()
                ->post($base . '/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => (float) ThemeSetting::get('ai.temperature', $feature === 'builder' ? 0.3 : 0.2),
                    'max_tokens' => (int) ThemeSetting::get('ai.max_tokens', $feature === 'builder' ? 1200 : 900),
                ]);

            if ($pending->status() === 429) {
                $status = 'rate_limited';
                throw new \RuntimeException('The AI provider is currently throttling requests. Try again shortly.');
            }

            if (!$pending->successful()) {
                $status = 'error';
                throw new \RuntimeException('AI provider returned HTTP ' . $pending->status() . '.');
            }

            $payload = $pending->json();
            $content = $payload['choices'][0]['message']['content'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                $status = 'error';
                throw new \RuntimeException('The AI provider returned an empty response.');
            }

            $this->logRequest($feature, $userId, $serverId, $model, [
                'prompt_tokens' => (int) ($payload['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($payload['usage']['completion_tokens'] ?? 0),
            ], $status, $started);

            return [
                'content' => trim($content),
                'model' => $model,
            ];
        } catch (\RuntimeException $e) {
            $this->logRequest($feature, $userId, $serverId, $model, [], $status, $started);
            throw $e;
        } catch (\Throwable $e) {
            $this->logRequest($feature, $userId, $serverId, $model, [], 'error', $started);
            Log::warning('[primus] monkeycode request failed: ' . $e->getMessage());
            throw new \RuntimeException('Could not reach the AI provider. Check network connectivity and the ai.base_url setting.');
        }
    }

    /**
     * Return the model id configured for the feature. Any OpenAI-compatible
     * model id is accepted — including custom ones typed in the admin page.
     * When no model is stored, the first id reported by the provider's own
     * catalog is used; the built-in ids below are only a last resort when
     * the catalog cannot be reached.
     */
    public function modelFor(string $feature): string
    {
        $defaults = [
            'fix' => 'deepseek-v4-flash',
            'optimize' => 'qwen3.5-plus',
            'notes' => 'deepseek-v4-flash',
            'aimotd' => 'deepseek-v4-flash',
            'builder' => 'deepseek-v4-flash',
        ];

        $configured = trim((string) ThemeSetting::get('ai.models.' . $feature, ''));

        if ($configured !== '') {
            return $configured;
        }

        try {
            $catalog = $this->availableModels()['models'];
            if ($catalog !== []) {
                return $catalog[0];
            }
        } catch (\Throwable $e) {
            // no key, unreachable provider, or no /models endpoint — fall through
        }

        return $defaults[$feature] ?? 'deepseek-v4-flash';
    }

    /**
     * Ask the provider (OpenAI-compatible GET /models) which models it offers.
     * Used by the admin "detect available models" action so presets can be
     * replaced with the real catalog of the configured provider.
     *
     * Returns ['models' => string[], 'warning' => string]. A 401 is a hard
     * key error; a 403/404 (e.g. a CDN/WAF bot check on the endpoint) is
     * reported as a soft warning because the key can still be valid for chat.
     *
     * @param string|null $baseUrl explicit base URL (unsaved admin input); stored value otherwise
     * @param string|null $apiKey  explicit key (unsaved admin input); stored value otherwise
     *
     * @throws \RuntimeException on auth/network failures
     */
    public function availableModels(?string $baseUrl = null, ?string $apiKey = null): array
    {
        $base = self::normalizeBaseUrl($baseUrl !== null && $baseUrl !== '' ? $baseUrl : ThemeSetting::get('ai.base_url', self::DEFAULT_BASE_URL));
        $key = $apiKey !== null && trim($apiKey) !== '' ? trim($apiKey) : $this->apiKey();

        if ($key === '') {
            throw new \RuntimeException('No API key available to query the model catalog. Configure one first.');
        }

        $response = Http::withToken($key)
            ->timeout(15)
            ->connectTimeout(10)
            ->acceptJson()
            ->get($base . '/models');

        if ($response->status() === 401) {
            throw new \RuntimeException('The provider rejected the API key (HTTP 401). Check the key in the Provider section.');
        }
        if ($response->status() === 403 || $response->status() === 404) {
            return [
                'models' => [],
                'warning' => 'This provider did not return its model list (HTTP ' . $response->status()
                    . '). The API key may still work for chat — type a model id manually below.',
            ];
        }
        if (!$response->successful()) {
            throw new \RuntimeException('The provider returned HTTP ' . $response->status() . ' while listing models.');
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $entries = $payload['data'] ?? ($payload['models'] ?? (is_array($payload) && array_is_list($payload) ? $payload : []));

        $ids = [];
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? null) : (is_string($entry) ? $entry : null);
                if (is_string($id) && trim($id) !== '') {
                    $ids[] = trim($id);
                }
            }
        }

        return ['models' => array_values(array_unique($ids)), 'warning' => ''];
    }

    public static function normalizeBaseUrl($url): string
    {
        $url = rtrim((string) $url, '/');

        return preg_match('#^https?://#', $url) ? $url : self::DEFAULT_BASE_URL;
    }

    public function baseUrl(): string
    {
        return self::normalizeBaseUrl(ThemeSetting::get('ai.base_url', self::DEFAULT_BASE_URL));
    }

    public function apiKey(): string
    {
        return (string) ThemeSetting::get('ai.api_key', '');
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function timeoutSeconds(): int
    {
        return (int) ThemeSetting::get('ai.timeout', 30);
    }

    protected function logRequest(
        string $feature,
        int $userId,
        ?string $serverId,
        string $model,
        array $tokens,
        string $status,
        float $started
    ): void {
        try {
            AiRequestLog::query()->create([
                'user_id' => $userId,
                'server_id' => $serverId,
                'feature' => $feature,
                'model' => $model,
                'prompt_tokens' => $tokens['prompt_tokens'] ?? 0,
                'completion_tokens' => $tokens['completion_tokens'] ?? 0,
                'status' => $status,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the AI feature itself.
            Log::debug('[primus] failed writing ai request log: ' . $e->getMessage());
        }
    }
}
