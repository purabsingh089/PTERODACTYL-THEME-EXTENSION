<?php

namespace {appcontext}\Services;

/**
 * Builds the structured prompts sent to monkeycode-ai.net.
 * System prompts pin the model to structured outputs so the frontend can
 * render pretty reports instead of raw prose.
 */
class PromptBuilder
{
    public function fixerMessages(string $sanitizedLog, ?array $context): array
    {
        $system = <<<'PROMPT'
You are the AI Server Fixer of a game server hosting panel (Pterodactyl).
You receive the tail of a server's console log. Diagnose the problem and help
the host fix it. Rules:
- Be concise and technical; assume the reader is a server administrator.
- NEVER invent errors that are not visible in the log. If the log looks
  healthy, say so and mention the most likely causes of common complaints.
- Never suggest destructive actions (deleting worlds, dropping databases)
  without an explicit warning line that must be kept.
- Respond with STRICT JSON only, no markdown fences, using exactly these keys:
  {
    "confidence": "high" | "medium" | "low",
    "cause": "<one paragraph: the likely root cause>",
    "suggestion": "<step-by-step plain text fix>",
    "command": "<exact command to run in console or terminal, or empty string>",
    "config_change": "<file + setting to change, or empty string>"
  }
PROMPT;

        $user = "Server context:\n" . $this->contextBlock($context) .
            "\n\nConsole log (most recent lines last, secrets already redacted):\n" .
            $sanitizedLog;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    public function optimizerMessages(array $stats, ?array $context): array
    {
        $system = <<<'PROMPT'
You are the AI Resource Optimizer of a game server hosting panel (Pterodactyl).
You receive summarized CPU / memory usage of a server over the last minutes.
Give cost-efficient resource advice. Rules:
- Be concise; 2-4 short bullet-style sentences maximum.
- Only recommend downgrades when usage is clearly and consistently low.
- Mention JVM flag tuning (e.g. Aikar's flags) only when the egg name hints
  at a Java game (Minecraft).
- Never claim certainty you cannot support; flag short sample windows.
- Respond with STRICT JSON only, no markdown fences:
  { "suggestion": "<plain text advice>" }
PROMPT;

        $user = "Server context:\n" . $this->contextBlock($context) .
            "\n\nUsage summary:\n" . json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    protected function contextBlock(?array $context): string
    {
        if (empty($context)) {
            return '- egg / software: unknown';
        }

        $lines = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) && $value !== null && $value !== '') {
                $lines[] = '- ' . $key . ': ' . $value;
            }
        }

        return $lines ? implode("\n", $lines) : '- egg / software: unknown';
    }
}
