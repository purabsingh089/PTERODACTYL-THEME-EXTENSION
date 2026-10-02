<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * Duplicate-functionality conflict detection (spec section 20).
 *
 * Heuristic category table: plugin names are matched against keyword
 * families so a plan that installs a second economy/claims/jobs plugin
 * while one is already present gets flagged for the keep/replace dialog.
 */
final class ConflictDetector
{
    private const CATEGORY_KEYWORDS = [
        'economy' => ['essentials', 'economy', 'econ', 'vault-econ', 'xconomy', 'coins', 'money', 'balance', 'eco', 'gems', 'currency'],
        'claims' => ['griefprevention', 'claims', 'claim', 'landclaim', 'protection', 'lands', 'redprotect', 'factions'],
        'jobs' => ['jobs', 'jobsreborn', 'profession', 'occupation'],
        'shops' => ['shop', 'chestshop', 'quickshop', 'playershops', 'market', 'trades'],
        'quests' => ['quest', 'mission', 'tasks', 'betonquest', 'beautyquests'],
        'ranks' => ['luckperms', 'permissions', 'ranks', 'rank', 'groupmanager', 'permissionex', 'pex'],
        'teleport' => ['essentials-tp', 'homes', 'sethome', 'tpa', 'warps', 'teleport'],
        'skyblock' => ['skyblock', 'acidisland', 'bskyblock', 'oneblock'],
    ];

    /**
     * @param array<int, array{name: string, action: string, project?: string, version?: string}> $planPlugins
     * @param array<int, string> $installedJars
     * @return array<int, array{plugin: string, category: string, existing: string}>
     */
    public static function detect(array $planPlugins, array $installedJars): array
    {
        $conflicts = [];
        foreach ($planPlugins as $entry) {
            if (($entry['action'] ?? '') !== 'install') {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $category = self::category($name);
            if ($category === null) {
                continue;
            }
            foreach ($installedJars as $jar) {
                $base = strtolower(preg_replace('/\.jar(\.disabled)?$/', '', (string) $jar) ?? (string) $jar);
                if ($base === '' ) {
                    continue;
                }
                if ($base === strtolower($name)) {
                    $conflicts[] = ['plugin' => $name, 'category' => 'duplicate', 'existing' => (string) $jar];
                    continue 2;
                }
                if (self::category($base) === $category) {
                    $conflicts[] = ['plugin' => $name, 'category' => $category, 'existing' => (string) $jar];
                    continue 2;
                }
            }
        }

        return $conflicts;
    }

    /** Keyword-family category of a plugin name, or null. */
    public static function category(string $name): ?string
    {
        $n = strtolower(trim($name));
        if ($n === '') {
            return null;
        }
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($n, $kw)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
