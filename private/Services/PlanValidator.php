<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * Validates and sanitizes AI-generated build plans (spec sections 6, 14, 20).
 *
 * The AI output is untrusted data: every field is re-checked server-side
 * before the executor may touch a server. Properties are filtered through
 * the same allowlist/ranges as the Properties Manager; MOTD follows the
 * MOTD Creator rules; commands pass a strict whitelist; install entries
 * must carry marketplace coordinates.
 */
final class PlanValidator
{
    /** Mirrors PropertiesController ALLOW/INTS. */
    private const PROPERTIES = [
        'gamemode' => ['survival', 'creative', 'adventure', 'spectator'],
        'difficulty' => ['peaceful', 'easy', 'normal', 'hard'],
        'pvp' => ['true', 'false'],
        'hardcore' => ['true', 'false'],
        'online-mode' => ['true', 'false'],
        'spawn-monsters' => ['true', 'false'],
        'spawn-animals' => ['true', 'false'],
        'spawn-npcs' => ['true', 'false'],
        'allow-nether' => ['true', 'false'],
        'allow-flight' => ['true', 'false'],
        'enable-command-block' => ['true', 'false'],
        'white-list' => ['true', 'false'],
        'enforce-whitelist' => ['true', 'false'],
        'level-type' => ['minecraft:normal', 'minecraft:flat', 'minecraft:large_biomes', 'minecraft:amplified', 'default', 'flat', 'largebiomes', 'amplified'],
    ];

    private const INT_PROPERTIES = [
        'max-players' => [1, 200],
        'view-distance' => [2, 32],
        'simulation-distance' => [2, 32],
        'spawn-protection' => [0, 256],
        'player-idle-timeout' => [0, 1440],
        'max-world-size' => [1, 29999984],
    ];

    private const ACTIONS = ['install', 'enable', 'disable', 'remove', 'keep'];
    private const PROVIDERS = ['modrinth', 'curseforge'];

    /** Console commands the executor may ever run, by prefix. */
    public const COMMAND_WHITELIST = [
        'say ', 'whitelist add ', 'whitelist remove ', 'whitelist on', 'whitelist off',
        'op ', 'deop ', 'ban ', 'pardon ', 'kick ', 'difficulty ', 'gamemode ',
        'defaultgamemode ', 'time set ', 'weather ', 'gamerule keepInventory ',
        'gamerule doDaylightCycle ', 'gamerule mobGriefing ', 'gamerule pvp ',
        'gamerule doFireTick ', 'gamerule maxTickTime ', 'gamerule spawnProtection ',
        'gamerule functionCommandLimit ', 'gamerule playersSleepingPercentage ',
        'advancement grant ', 'advancement revoke ', 'scoreboard ', 'tellraw @a ',
        'title @a ', 'effect give @a ', 'recipe give @a ', 'xp add @a ',
    ];

    /**
     * @return array{ok: bool, error?: string, plan: array}
     */
    public static function validate(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['ok' => false, 'error' => 'Plan is not an object.', 'plan' => []];
        }

        $summary = isset($raw['summary']) ? trim((string) $raw['summary']) : '';
        $pluginResult = self::plugins($raw['plugins'] ?? null);
        if (is_string($pluginResult)) {
            return ['ok' => false, 'error' => $pluginResult, 'plan' => []];
        }
        $plugins = $pluginResult;
        $properties = self::properties($raw['properties'] ?? null);
        $motd = self::motd($raw['motd'] ?? null);
        $commands = self::commands($raw['commands'] ?? null);

        if ($plugins === [] && $properties === [] && $motd === '' && $commands === []) {
            return ['ok' => false, 'error' => 'Plan has no actionable content.', 'plan' => []];
        }

        $plan = [
            'summary' => mb_substr($summary, 0, 500),
            'software' => self::software($raw['software'] ?? null),
            'minecraft_version' => isset($raw['minecraft_version']) ? mb_substr(trim((string) $raw['minecraft_version']), 0, 32) : '',
            'plugins' => $plugins,
            'properties' => $properties,
            'motd' => $motd,
            'commands' => $commands,
            'restart' => !empty($raw['restart']),
            'risks' => self::strings($raw['risks'] ?? null, 8),
            'health' => self::strings($raw['health'] ?? null, 12),
        ];

        return ['ok' => true, 'plan' => $plan];
    }

    private static function software(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $name = isset($raw['name']) ? mb_substr(trim((string) $raw['name']), 0, 64) : '';

        return $name === '' ? [] : [
            'name' => $name,
            'reason' => isset($raw['reason']) ? mb_substr(trim((string) $raw['reason']), 0, 300) : '',
        ];
    }

    /**
     * @return array<int, array>|string plugin list, or an error string when
     *                                  an install entry is invalid (fail-closed)
     */
    private static function plugins(mixed $raw): array|string
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 24) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $action = (string) ($entry['action'] ?? '');
            if ($name === '' || $name !== strip_tags($name) || preg_match('/[\/\\\\]|\.\./', $name)) {
                return 'Plan contains an invalid plugin name.';
            }
            if (!in_array($action, self::ACTIONS, true)) {
                continue;
            }
            $item = [
                'name' => mb_substr($name, 0, 120),
                'action' => $action,
                'reason' => isset($entry['reason']) ? mb_substr(trim((string) $entry['reason']), 0, 300) : '',
            ];
            if ($action === 'install') {
                $provider = (string) ($entry['provider'] ?? 'modrinth');
                if (!in_array($provider, self::PROVIDERS, true)) {
                    $provider = 'modrinth';
                }
                $project = trim((string) ($entry['project'] ?? ''));
                $version = trim((string) ($entry['version'] ?? ''));
                if ($project === '' || $version === '') {
                    return 'Plan contains an install entry without marketplace coordinates.';
                }
                if (!preg_match('/^[A-Za-z0-9._~-]{1,120}$/', $project) || !preg_match('/^[A-Za-z0-9._~-]{1,120}$/', $version)) {
                    return 'Plan contains an invalid marketplace coordinate.';
                }
                $item['provider'] = $provider;
                $item['project'] = $project;
                $item['version'] = $version;
                $item['type'] = ($entry['type'] ?? 'plugin') === 'mod' ? 'mod' : 'plugin';
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function properties(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (isset(self::INT_PROPERTIES[$key])) {
                $allowed = self::INT_PROPERTIES[$key];
                if (!is_int($value) && !is_float($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
                    continue;
                }
                $n = (int) $value;
                if ($n < $allowed[0] || $n > $allowed[1]) {
                    continue;
                }
                $out[$key] = $n;
                continue;
            }
            if (!array_key_exists($key, self::PROPERTIES)) {
                continue;
            }
            $allowed = self::PROPERTIES[$key];
            $v = is_bool($value) ? ($value ? 'true' : 'false') : strtolower(trim((string) $value));
            if (!in_array($v, $allowed, true)) {
                continue;
            }
            $out[$key] = $v;
        }

        return $out;
    }

    /** MOTD rules follow MotdController: max 59 rendered chars, no control chars. */
    private static function motd(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $motd = trim($raw);
        if ($motd === '' || $motd !== strip_tags($motd)) {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $motd)) {
            return '';
        }
        $rendered = preg_replace('/§./', '', $motd) ?? $motd;
        if (mb_strlen($rendered) > 59) {
            return '';
        }

        return $motd;
    }

    private static function commands(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 10) as $cmd) {
            if (!is_string($cmd)) {
                continue;
            }
            $cmd = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $cmd) ?? '');
            if ($cmd === '' || mb_strlen($cmd) > 200) {
                continue;
            }
            foreach (self::COMMAND_WHITELIST as $prefix) {
                if (str_starts_with(strtolower($cmd), strtolower($prefix))) {
                    $out[] = $cmd;
                    break;
                }
            }
        }

        return $out;
    }

    private static function strings(mixed $raw, int $max): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, $max) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = mb_substr(trim($item), 0, 300);
            }
        }

        return $out;
    }
}
