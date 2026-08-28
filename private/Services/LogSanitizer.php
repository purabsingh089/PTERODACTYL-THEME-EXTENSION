<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * Removes sensitive values from console output before it is handed to the
 * external AI provider. Defense in depth: the sanitizer intentionally errs
 * on the side of over-redacting.
 */
class LogSanitizer
{
    /** Hard cap of characters sent to the provider. */
    public const MAX_CHARS = 48000;

    public function sanitize(string $log): string
    {
        $log = str_replace("\r\n", "\n", $log);
        $log = $this->truncate($log);

        $replacements = [
            // ── key=value secrets (password=..., token=..., api_key...) ──
            '/\b(password|passwd|pwd|token|secret|api[_-]?key|apikey|auth|credential|access[_-]?key|client[_-]?secret)\s*[=:]\s*("[^"]*"|\'[^\']*\'|\S+)/i'
                => '$1=[MASKED]',

            // ── bearer / basic auth headers ──────────────────────────────
            '/\b(bearer|basic|token)\s+[A-Za-z0-9\-._~+\/=]{12,}/i' => '$1 [MASKED]',

            // ── JWTs ─────────────────────────────────────────────────────
            '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{4,}\b/' => '[MASKED_JWT]',

            // ── minecraft server.properties / RCON keys ──────────────────
            '/\b(rcon\.password|query\.port|enable-rcon)\s*=.*/i' => '$1=[MASKED]',

            // ── common provider API key formats ──────────────────────────
            '/\b(ptla_|ptlp_|sk-)[A-Za-z0-9\-_]{16,}\b/i' => '[MASKED_KEY]',
            '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/i' => '[MASKED_TOKEN]',
            '/\bxox[abpsr]-[A-Za-z0-9\-]{8,}\b/i' => '[MASKED_TOKEN]',

            // ── hex blobs ─ 32+ so we never redact ordinary timestamps ──
            '/\b[0-9a-fA-F]{32,}\b/' => '[MASKED_HEX]',
            '/\b0x[0-9a-fA-F]+\b/' => '[MASKED_HEX]',

            // ── UUIDs (account ids / server keys) ────────────────────────
            '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/' => '[UUID]',

            // ── emails ───────────────────────────────────────────────────
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[EMAIL]',

            // ── IPv4 (with optional port) ────────────────────────────────
            '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b(?::\d{1,5})?/' => '[IP]',

            // ── IPv6 (bracketed, long and compressed forms) ──────────────
            // They run before the generic hex blob rule and require enough
            // hex groups so `[HH:MM:SS]` timestamps stay untouched.
            '/\[[0-9a-fA-F:]{2,}\](?::\d{1,5})?/' => '[MASKED_IP6]',
            '/\b[0-9a-fA-F]{1,4}(?::[0-9a-fA-F]{0,4}){3,}\b/' => '[MASKED_IP6]',
            '/\b[0-9a-fA-F]{1,4}::[0-9a-fA-F]{1,4}(%[a-zA-Z0-9]+)?\b/' => '[MASKED_IP6]',

            // ── embedded credentials inside URLs ─────────────────────────
            '/https?:\/\/(\S+)(@(?:[\w.\-])+:[\w.\-]+)/i' => 'https://[MASKED_AUTH_URL]',

            // ── database connection strings ──────────────────────────────
            '/\b(mysql|postgres|redis|mongodb):\/\/[^\s"\']+/i' => '$1://[MASKED_CONN]',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $log = preg_replace($pattern, $replacement, $log);
        }

        return $log;
    }

    /** Keep the tail of very long logs; diagnostics live at the end. */
    protected function truncate(string $log): string
    {
        if (strlen($log) <= self::MAX_CHARS) {
            return $log;
        }

        return "[...earlier lines truncated...]\n" . substr($log, -self::MAX_CHARS + 40);
    }
}
