<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * AddonRegistry — declarative manifests for every Primus addon.
 * 'plugins' is live this cycle; the rest are visible roadmap cards
 * (comingSoon) implemented by later sub-projects. Permissions are
 * dotted strings verified against Pterodactyl's Permission constants.
 */
class AddonRegistry
{
    /** @return array<int, array<string, mixed>> every manifest */
    public static function all(): array
    {
        return [
            self::manifest('plugins'),
            self::manifest('worlds'),
            self::manifest('mods'),
            self::manifest('players'),
            self::manifest('traffic'),
            self::manifest('console'),
            self::manifest('versions'),
            self::manifest('icons'),
            self::manifest('trash'),
            self::manifest('properties'),
            self::manifest('motd'),
            self::manifest('aimotd'),
        ];
    }

    /** @return array<string, array<string, mixed>> id => manifest */
    public static function manifests(): array
    {
        $out = [];
        foreach (self::all() as $manifest) {
            $out[$manifest['id']] = $manifest;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function manifest(string $id): ?array
    {
        $live = [
            'plugins' => [
                'id' => 'plugins',
                'title' => 'Plugin Manager',
                'description' => 'List, enable, disable, upload and delete plugin & mod jars.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'plugins',
                'comingSoon' => false,
            ],
        ];
        $soon = [
            'worlds' => ['World Manager', 'management'],
            'mods' => ['Mod Manager', 'files'],
            'players' => ['Player Manager', 'management'],
            'traffic' => ['Traffic Manager', 'management'],
            'console' => ['Advanced Console', 'console'],
            'versions' => ['Version Manager', 'management'],
            'icons' => ['Icon Manager', 'files'],
            'trash' => ['Trash Bin', 'files'],
            'properties' => ['Properties Manager', 'files'],
            'motd' => ['MOTD Manager', 'files'],
            'aimotd' => ['AI MOTD', 'ai'],
        ];
        if (isset($live[$id])) {
            return $live[$id];
        }
        if (isset($soon[$id])) {
            return [
                'id' => $id,
                'title' => $soon[$id][0],
                'description' => 'Coming in a future Primus update.',
                'category' => $soon[$id][1],
                'perms' => 'file.read',
                'icon' => $id,
                'comingSoon' => true,
            ];
        }

        return null;
    }
}
