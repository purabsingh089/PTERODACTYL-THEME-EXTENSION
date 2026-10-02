<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\Shared;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * Server state snapshot for the AI builder: egg/software detection,
 * installed jars, properties, motd, java flag. Read-only primitives
 * reused from Shared; every accessor degrades gracefully.
 */
final class BuildStateService
{
    /**
     * @return array{
     *   egg: string, software: string, java: bool,
     *   jars: array<int, string>, properties: array<string, string>,
     *   motd: string, running: bool
     * }
     */
    public static function snapshot(Server $server): array
    {
        $eggName = (string) ($server->egg->name ?? '');
        $out = [
            'egg' => $eggName,
            'software' => self::software($eggName),
            'java' => false,
            'jars' => [],
            'properties' => [],
            'motd' => '',
            'running' => ($server->status ?? '') === 'running',
        ];

        [$properties, $err] = Shared::readProperties($server);
        if ($err === null && $properties !== null) {
            $out['java'] = Shared::isJava($eggName, $properties);
            $out['properties'] = self::parseProperties($properties);
            $out['motd'] = (string) ($out['properties']['motd'] ?? '');
        }

        $out['jars'] = self::jars($server);

        return $out;
    }

    /** Best-effort software name from the egg name. */
    public static function software(string $eggName): string
    {
        $n = strtolower($eggName);
        foreach (['purpur', 'paper', 'spigot', 'bukkit', 'fabric', 'forge', 'neoforge', 'vanilla', 'mohist', 'arclight', 'quilt'] as $name) {
            if (str_contains($n, $name)) {
                return ucfirst($name);
            }
        }

        return str_contains($n, 'mine') || $n === 'mc' ? 'Minecraft' : ($eggName !== '' ? $eggName : 'Unknown');
    }

    /** @return array<int, string> jar file names in plugins/ (any state). */
    public static function jars(Server $server): array
    {
        try {
            $entries = Shared::fileRepo($server)->getDirectory('plugins');
        } catch (DaemonConnectionException $e) {
            return [];
        } catch (\Throwable $e) {
            return [];
        }
        $names = [];
        foreach ($entries as $entry) {
            if (empty($entry['file']) || empty($entry['name'])) {
                continue;
            }
            $name = (string) $entry['name'];
            if (str_ends_with($name, '.jar') || str_ends_with($name, '.jar.disabled')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return array<string, string> */
    public static function parseProperties(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[$k] = $v;
        }

        return $out;
    }
}
