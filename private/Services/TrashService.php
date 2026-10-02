<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\Shared;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\TrashEntry;

/**
 * TrashService — the only code that moves files between the server tree
 * and .primus-trash/. Every caller (file-manager delete intercept, addon
 * delete paths, restore/destroy/empty UI, hourly purge cron) goes through
 * here so behavior is uniform: name jail, Wings move, DB row. Auditing
 * and rate limiting stay with the controllers.
 */
class TrashService
{
    public const TRASH_DIR = '.primus-trash';
    public const RETENTION_DAYS = 14;

    private const NAME_RE = '/^[A-Za-z0-9_.-]+$/';

    /** @return array{moved: int, entries: array<int, TrashEntry>} */
    public static function add(Server $server, User $user, array $files): array
    {
        /* 1. Jail + normalize: relative path, every segment NAME_RE,
         * never anything inside the trash dir itself. The stock file
         * manager sends multi-segment paths (plugins/x.jar), so nested
         * paths are allowed — but "..", absolute paths and hidden
         * trash-dir references are not. */
        $valid = [];
        foreach ($files as $raw) {
            $path = trim((string) $raw, '/');
            if ($path === '') {
                continue;
            }
            $segments = explode('/', $path);
            $ok = true;
            foreach ($segments as $seg) {
                if (preg_match(self::NAME_RE, $seg) !== 1) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok || in_array(self::TRASH_DIR, $segments, true)) {
                continue;
            }
            $valid[$path] = true;
        }
        $valid = array_keys($valid);
        if ($valid === []) {
            return ['moved' => 0, 'entries' => []];
        }

        /* 2. Verify existence (and capture is_dir/size) with one
         * directory listing per distinct parent. The daemon does not
         * report failures for renames of missing files, so this is the
         * only reliable guard against phantom rows. */
        $byParent = [];
        foreach ($valid as $path) {
            $parent = dirname($path);
            $byParent[$parent === '.' ? '' : $parent][] = $path;
        }

        $existing = [];
        foreach ($byParent as $parent => $paths) {
            try {
                $names = [];
                foreach (Shared::fileRepo($server)->getDirectory('/' . $parent) as $entry) {
                    if (!empty($entry['name'])) {
                        $names[(string) $entry['name']] = $entry;
                    }
                }
            } catch (\Throwable $e) {
                continue; // unreadable parent: skip the whole group
            }
            foreach ($paths as $path) {
                $name = basename($path);
                if (isset($names[$name])) {
                    $existing[] = [$path, $names[$name]];
                }
            }
        }
        if ($existing === []) {
            return ['moved' => 0, 'entries' => []];
        }

        /* 3. Move verified entries into the trash dir. */
        self::ensureTrashDir($server);
        $moved = 0;
        $entries = [];
        foreach ($existing as [$path, $meta]) {
            $name = basename($path);
            $trashName = time() . '-' . bin2hex(random_bytes(3)) . '-' . $name;

            try {
                Shared::fileRepo($server)->renameFiles('/', [
                    ['from' => $path, 'to' => self::TRASH_DIR . '/' . $trashName],
                ]);
            } catch (\Throwable $e) {
                continue;
            }

            $entries[] = TrashEntry::create([
                'server_uuid' => $server->uuid,
                'original_path' => '/' . $path,
                'trash_name' => $trashName,
                'is_dir' => empty($meta['file']),
                'size' => (int) ($meta['size'] ?? 0),
                'deleted_by' => $user->id,
                'created_at' => now(),
            ]);
            $moved++;
        }

        return ['moved' => $moved, 'entries' => $entries];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TrashEntry> */
    public static function listing(Server $server)
    {
        return TrashEntry::query()
            ->where('server_uuid', $server->uuid)
            ->whereNull('purged_at')
            ->whereNull('restored_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return array{path: string} */
    public static function restore(Server $server, int $id): array
    {
        $entry = self::entry($server, $id);
        $target = trim($entry->original_path, '/');

        if ($target === '' || preg_match(self::NAME_RE, basename($target)) !== 1) {
            throw new \RuntimeException('Entry has an unusable original path.');
        }

        /* verify the trash copy still exists (daemon will not report
         * failures for renames of missing files) */
        try {
            $found = false;
            foreach (Shared::fileRepo($server)->getDirectory('/' . self::TRASH_DIR) as $e) {
                if ((string) ($e['name'] ?? '') === $entry->trash_name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \RuntimeException('The trashed file is gone from the server.');
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach the Wings daemon.');
        }

        try {
            Shared::fileRepo($server)->renameFiles('/', [
                ['from' => self::TRASH_DIR . '/' . $entry->trash_name, 'to' => $target],
            ]);
        } catch (\Throwable $e) {
            /* target may already exist — fall back to a suffixed name */
            $target = 'restored-' . basename($target);
            Shared::fileRepo($server)->renameFiles('/', [
                ['from' => self::TRASH_DIR . '/' . $entry->trash_name, 'to' => $target],
            ]);
        }

        $entry->update(['restored_at' => now()]);

        return ['path' => '/' . $target];
    }

    public static function destroy(Server $server, int $id): void
    {
        $entry = self::entry($server, $id);
        Shared::fileRepo($server)->deleteFiles('/' . self::TRASH_DIR, [$entry->trash_name]);
        $entry->update(['purged_at' => now()]);
    }

    public static function empty(Server $server): int
    {
        $n = 0;
        foreach (self::listing($server) as $entry) {
            try {
                self::destroy($server, $entry->id);
                $n++;
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $n;
    }

    /** Cron entrypoint: purge entries older than the retention window. */
    public static function purge(): int
    {
        $cut = now()->subDays(self::RETENTION_DAYS);
        $n = 0;

        TrashEntry::query()
            ->whereNull('purged_at')
            ->whereNull('restored_at')
            ->where('created_at', '<', $cut)
            ->chunkById(50, function ($rows) use (&$n) {
                foreach ($rows as $entry) {
                    try {
                        $server = Server::query()->where('uuid', $entry->server_uuid)->first();
                        if ($server) {
                            Shared::fileRepo($server)->deleteFiles('/' . self::TRASH_DIR, [$entry->trash_name]);
                        }
                        $entry->update(['purged_at' => now()]);
                        $n++;
                    } catch (\Throwable $e) {
                        /* missing file or dead daemon: drop the row anyway */
                        $entry->update(['purged_at' => now()]);
                    }
                }
            });

        return $n;
    }

    /** Display name of a live entry, or null when absent. */
    public static function entryName(Server $server, int $id): ?string
    {
        try {
            return basename(self::entry($server, $id)->original_path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function entry(Server $server, int $id): TrashEntry
    {
        $entry = TrashEntry::query()
            ->where('server_uuid', $server->uuid)
            ->whereNull('purged_at')
            ->whereNull('restored_at')
            ->find($id);

        if (!$entry) {
            throw new \RuntimeException('Trash entry not found.');
        }

        return $entry;
    }

    /** Best-effort create of the hidden trash directory (idempotent). */
    private static function ensureTrashDir(Server $server): void
    {
        try {
            Shared::fileRepo($server)->putContent(self::TRASH_DIR . '/.keep', '');
        } catch (\Throwable $e) {
            // already exists or daemon hiccup — the rename will tell us
        }
    }
}
