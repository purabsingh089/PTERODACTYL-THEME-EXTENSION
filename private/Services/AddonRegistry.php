<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * AddonRegistry — declarative manifests for every Primus addon.
 * Permissions are dotted strings verified against Pterodactyl's Permission constants.
 */
class AddonRegistry
{
    /** @return array<int, array<string, mixed>> every manifest */
    public static function all(): array
    {
        return [
            self::manifest('plugins'),
            self::manifest('mods'),
            self::manifest('worlds'),
            self::manifest('player-stats'),
            self::manifest('versions'),
            self::manifest('icons'),
            self::manifest('properties'),
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
                'title' => 'Plugin Installer',
                'description' => 'Browse, install and manage plugin jars with built-in search.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'plugins',
                'comingSoon' => false,
            ],
            'worlds' => [
                'id' => 'worlds',
                'title' => 'World Manager',
                'description' => 'List, switch, backup and delete Minecraft world folders.',
                'category' => 'management',
                'perms' => 'file.read',
                'icon' => 'worlds',
                'comingSoon' => false,
            ],
            'mods' => [
                'id' => 'mods',
                'title' => 'Mod Manager',
                'description' => 'Browse, install and manage Fabric, Forge and NeoForge mods with built-in search.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'mods',
                'comingSoon' => false,
            ],
            'trash' => [
                'id' => 'trash',
                'title' => 'Trash',
                'description' => 'File-manager delete interception and restore.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'trash',
                'comingSoon' => false,
                'internal' => true,
            ],
            'marketplace' => [
                'id' => 'marketplace',
                'title' => 'Marketplace',
                'description' => 'Internal search/install engine for the managers.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'marketplace',
                'comingSoon' => false,
                'internal' => true,
            ],
            'server-builder' => [
                'id' => 'server-builder',
                'title' => 'AI Server Builder',
                'description' => 'Describe your server; review a build plan; build it with safe tools.',
                'category' => 'management',
                'perms' => 'file.read',
                'icon' => 'builder',
                'comingSoon' => false,
                'internal' => true,
            ],
            'player-stats' => [
                'id' => 'player-stats',
                'title' => 'Player Stats',
                'description' => 'Online players, join history, sessions, actions and allocations.',
                'category' => 'management',
                'perms' => 'control.console',
                'icon' => 'players',
                'comingSoon' => false,
            ],
            'versions' => [
                'id' => 'versions',
                'title' => 'Version Manager',
                'description' => 'Switch the server jar and Docker image used on next start.',
                'category' => 'management',
                'perms' => 'startup.read',
                'icon' => 'versions',
                'comingSoon' => false,
            ],
            'icons' => [
                'id' => 'icons',
                'title' => 'Icon Manager',
                'description' => 'Upload or remove the Minecraft server-icon.png shown in the server list.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'icons',
                'comingSoon' => false,
            ],
            'properties' => [
                'id' => 'properties',
                'title' => 'Properties Manager',
                'description' => 'Edit safe Minecraft server.properties keys without touching ports or secrets.',
                'category' => 'files',
                'perms' => 'file.read',
                'icon' => 'properties',
                'comingSoon' => false,
            ],
        ];

        return $live[$id] ?? null;
    }
}
