<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

/**
 * Clamp and coerce an AI server-spec into values ServerCreationService accepts.
 */
class ServerSpecNormalizer
{
    public const MEMORY_MIN = 128;
    public const MEMORY_MAX = 8192;
    public const DISK_MIN = 256;
    public const DISK_MAX = 32768;
    public const CPU_MAX = 400;

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   name: string,
     *   description: string,
     *   game: string,
     *   memory: int,
     *   disk: int,
     *   cpu: int,
     *   swap: int,
     *   io: int,
     *   start_on_completion: bool,
     *   database_limit: int,
     *   backup_limit: int,
     *   allocation_limit: int,
     *   summary: string,
     *   environment: array<string, string>
     * }
     */
    public static function normalize(array $raw): array
    {
        $game = self::scalarString($raw['game'] ?? '');
        $name = self::scalarString($raw['name'] ?? '');
        if ($name === '') {
            $name = $game !== '' ? (ucfirst($game) . ' server') : 'New server';
        }
        if (strlen($name) > 191) {
            $name = substr($name, 0, 191);
        }

        $memory = self::toMegabytes($raw['memory'] ?? 512, self::MEMORY_MIN, self::MEMORY_MAX);
        $disk = self::toMegabytes($raw['disk'] ?? 2048, self::DISK_MIN, self::DISK_MAX);
        $cpu = self::toInt($raw['cpu'] ?? 0);
        if ($cpu < 0) {
            $cpu = 0;
        }
        if ($cpu > self::CPU_MAX) {
            $cpu = self::CPU_MAX;
        }

        $swap = self::toInt($raw['swap'] ?? 0);
        if ($swap < -1) {
            $swap = 0;
        }

        $io = self::toInt($raw['io'] ?? 500);
        if ($io < 10) {
            $io = 500;
        }
        if ($io > 1000) {
            $io = 1000;
        }

        $env = [];
        if (isset($raw['environment']) && is_array($raw['environment'])) {
            foreach ($raw['environment'] as $key => $value) {
                if (is_string($key) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key) && is_scalar($value)) {
                    $env[$key] = substr((string) $value, 0, 191);
                }
            }
        }

        return [
            'name' => $name,
            'description' => substr(self::scalarString($raw['description'] ?? ''), 0, 500),
            'game' => $game,
            'memory' => $memory,
            'disk' => $disk,
            'cpu' => $cpu,
            'swap' => $swap,
            'io' => $io,
            'start_on_completion' => self::toBool($raw['start_on_completion'] ?? false),
            'database_limit' => max(0, min(8, self::toInt($raw['database_limit'] ?? 0))),
            'backup_limit' => max(0, min(8, self::toInt($raw['backup_limit'] ?? 0))),
            'allocation_limit' => max(1, min(8, self::toInt($raw['allocation_limit'] ?? 1))),
            'summary' => substr(self::scalarString($raw['summary'] ?? ''), 0, 400),
            'environment' => $env,
        ];
    }

    private static function toMegabytes(mixed $value, int $min, int $max): int
    {
        $mb = $min;
        if (is_string($value)) {
            $trim = trim($value);
            if (preg_match('/^(\d+(?:\.\d+)?)\s*(gb|g)$/i', $trim, $m)) {
                $mb = (int) round(((float) $m[1]) * 1024);
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(mb|m)?$/i', $trim, $m)) {
                $num = (float) $m[1];
                $mb = self::smallNumberAsGb($num);
            }
        } elseif (is_numeric($value)) {
            $mb = self::smallNumberAsGb((float) $value);
        }

        return max($min, min($max, $mb));
    }

    private static function smallNumberAsGb(float $num): int
    {
        if ($num > 0 && $num <= 32 && abs($num - round($num)) < 0.001) {
            return (int) round($num * 1024);
        }

        return (int) round($num);
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return 0;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
