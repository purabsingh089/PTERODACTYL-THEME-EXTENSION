<?php

namespace {appcontext}\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use {appcontext}\Models\AiRequestLog;
use {appcontext}\Models\ThemeSetting;

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

    /** Suggested models shipped in the admin dropdowns (allow-lists). */
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
        $model = $this->modelFor($feature);
        $started = microtime(true);

        $response = null;
        $status = 'ok';

        try {
            $pending = Http::withToken($this->apiKey())
                ->timeout($this->timeoutSeconds())
                ->connectTimeout(10)
                ->acceptJson()
                ->post($this->baseUrl() . '/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => (float) ThemeSetting::get('ai.temperature', 0.2),
                    'max_tokens' => (int) ThemeSetting::get('ai.max_tokens', 900),
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

    /** @return string a model id from MODELS (validated against the allow-lists) */
    public function modelFor(string $feature): string
    {
        $defaults = [
            'fix' => 'deepseek-v4-flash',
            'optimize' => 'qwen3.5-plus',
            'notes' => 'deepseek-v4-flash',
        ];

        $configured = (string) ThemeSetting::get('ai.models.' . $feature, $defaults[$feature] ?? 'deepseek-v4-flash');

        return array_key_exists($configured, self::MODELS) ? $configured : ($defaults[$feature] ?? 'deepseek-v4-flash');
    }

    public function baseUrl(): string
    {
        $url = rtrim((string) ThemeSetting::get('ai.base_url', self::DEFAULT_BASE_URL), '/');

        return preg_match('#^https?://#', $url) ? $url : self::DEFAULT_BASE_URL;
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
