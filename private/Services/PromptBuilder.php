<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

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

    public function aimotdMessages(string $prompt, ?array $context): array
    {
        $system = <<<'PROMPT'
You are the AI MOTD writer of a Minecraft server hosting panel.
Write a single-line Java Minecraft MOTD using legacy section-sign codes (§0-§f, §l, §o, §n, §m, §k, §r).
Rules:
- STRICT JSON only, no markdown fences: { "motd": "<string>", "style": "<one word>" }
- Visible characters after stripping § codes MUST be 1–59.
- No control characters, no backslashes, no newlines, no JSON MOTD objects.
- Tasteful, not spammy. Do not invent server IPs or URLs.
PROMPT;
        $user = "Server context:\n" . $this->contextBlock($context) .
            "\n\nOwner request (may be empty — invent a tasteful SMP MOTD):\n" .
            ($prompt === '' ? '(none)' : $prompt);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param  array<int, array{name?:string, nest?:string}>  $eggs
     */
    public function builderMessages(string $prompt, array $eggs): array
    {
        $lines = [];
        foreach ($eggs as $egg) {
            $name = trim((string) ($egg['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $nest = trim((string) ($egg['nest'] ?? ''));
            $lines[] = '- ' . $name . ($nest !== '' ? ' [nest: ' . $nest . ']' : '');
        }
        $catalog = $lines !== [] ? implode("\n", $lines) : '(no eggs installed on this panel)';

        $system = <<<'PROMPT'
You are the AI Server Builder of a Pterodactyl game-server panel.
Turn the owner's request into a create-server specification. Rules:
- STRICT JSON only, no markdown fences, using exactly these keys:
  {
    "name": "<1-191 chars>",
    "description": "<short, may be empty>",
    "game": "<short keyword matching an installed egg: paper, minecraft, vanilla, rust, ...>",
    "memory": <integer megabytes>,
    "disk": <integer megabytes>,
    "cpu": <integer percent, 0 means unlimited>,
    "swap": 0,
    "start_on_completion": false,
    "summary": "<one sentence of what you chose and why>"
  }
- game MUST correspond to one of the installed eggs listed in the user message.
- Prefer conservative resources: memory 512-2048, disk 2048-8192, cpu 0-100 unless the owner asked for more.
- Never invent eggs, nodes, IPs, or allocations.
- If the request is not a game server, still pick the closest egg and say so in summary.
PROMPT;

        $user = "Installed eggs:\n" . $catalog . "\n\nOwner request:\n" . $prompt;

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
