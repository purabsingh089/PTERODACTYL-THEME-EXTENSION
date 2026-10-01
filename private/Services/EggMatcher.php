<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * Pick the closest panel egg for a free-text game string from the AI spec.
 */
class EggMatcher
{
    private const ALIASES = [
        'mc' => 'minecraft',
        'paper' => 'paper',
        'purpur' => 'purpur',
        'spigot' => 'spigot',
        'bukkit' => 'spigot',
        'vanilla' => 'vanilla',
        'fabric' => 'fabric',
        'forge' => 'forge',
        'neoforge' => 'forge',
        'quilt' => 'quilt',
        'rust' => 'rust',
        'valheim' => 'valheim',
        'terraria' => 'terraria',
        'csgo' => 'counter',
        'cs2' => 'counter',
    ];

    /**
     * @param  array<int, array{id?:int, nest_id?:int, name?:string, nest?:string, description?:string}>  $eggs
     * @return array{egg_id:int, nest_id:int, name:string, nest:string, score:int}|null
     */
    public static function match(string $game, array $eggs): ?array
    {
        $query = strtolower(trim($game));
        if ($query === '' || $eggs === []) {
            return null;
        }

        $tokens = preg_split('/[^a-z0-9]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $expanded = [];
        foreach ($tokens as $token) {
            $expanded[] = self::ALIASES[$token] ?? $token;
            $expanded[] = $token;
        }
        $expanded = array_values(array_unique(array_filter($expanded)));
        if ($expanded === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($eggs as $egg) {
            $name = strtolower((string) ($egg['name'] ?? ''));
            $nest = strtolower((string) ($egg['nest'] ?? ''));
            $desc = strtolower((string) ($egg['description'] ?? ''));
            $score = 0;

            foreach ($expanded as $token) {
                if ($token === '') {
                    continue;
                }
                if ($name === $token) {
                    $score += 100;
                }
                if ($name !== '' && str_contains($name, $token)) {
                    $score += 50;
                }
                if ($nest !== '' && str_contains($nest, $token)) {
                    $score += 20;
                }
                if ($desc !== '' && str_contains($desc, $token)) {
                    $score += 10;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $egg;
            }
        }

        if ($best === null || $bestScore <= 0) {
            return null;
        }

        return [
            'egg_id' => (int) ($best['id'] ?? 0),
            'nest_id' => (int) ($best['nest_id'] ?? 0),
            'name' => (string) ($best['name'] ?? ''),
            'nest' => (string) ($best['nest'] ?? ''),
            'score' => $bestScore,
        ];
    }
}
