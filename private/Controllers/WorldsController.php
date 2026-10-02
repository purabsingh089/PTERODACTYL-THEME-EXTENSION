<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * World Manager addon — list, switch (level-name), backup and delete
 * Minecraft world folders. World identity is a single jailed path
 * segment; server.properties is a hardcoded path (never client
 * supplied); every mutation is audited before the Wings call.
 */
class WorldsController extends Controller
{
    /** Top-level dirs that are never worlds. */
    private const SKIP = [
        'plugins', 'mods', 'config', 'cache', 'libraries', 'versions', 'logs',
        'crash-reports', 'dumps', 'defaultconfigs', 'kubejs', 'scripts',
        '.cache', 'tmp', 'temp',
    ];

    /** Single-segment world names only: no slash, no .., no leading dot. */
    private const NAME_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** Egg-name tokens implying a Java Minecraft egg (names are human strings). */
    private const EGG_FAMILY = '/(vanilla|paper|purpur|spigot|fabric|forge|spoon|quilt|minecraft)/i';

    /** Eggs matching this are never Java Minecraft. */
    private const EGG_EXCLUDE = '/bedrock/i';

    /** At least one of these keys must exist for a Java properties file. */
    private const JAVA_MARKER = '/^(server-port|level-name|online-mode|max-players|view-distance|motd|server-ip)[[:space:]]*=/m';

    /** Never probe more than this many candidate dirs for level.dat. */
    private const CANDIDATE_CAP = 40;

    /** DIM suffixes grouped under their overworld. */
    private const DIM_SUFFIXES = ['_nether', '_the_end'];

    public function index(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'worlds', $server);
        if ($gate !== null) {
            return $gate;
        }

        $perms = $this->perms($request->user(), $server);

        try {
            $properties = $this->fileRepo($server)->getContent('server.properties');
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 404) {
                return response()->json(['ok' => true, 'java' => false, 'worlds' => [], 'current' => null, 'perms' => $perms]);
            }
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this node.'], 502);
        }

        if (!$this->java((string) ($server->egg->name ?? ''), $properties)) {
            return response()->json(['ok' => true, 'java' => false, 'worlds' => [], 'current' => null, 'perms' => $perms]);
        }

        try {
            $snapshot = $this->detectWorlds($server, $properties);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json($snapshot + ['perms' => $perms]);
    }

    public function switch(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'worlds', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!$this->hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You do not have file update access to this server.'], 403);
        }

        $name = (string) ($request->json('name') ?? '');
        if (!$this->jailed($name)) {
            return response()->json(['error' => 'Invalid world name.'], 422);
        }

        [$properties, $snapshot, $err] = $this->snapshot($server);
        if ($err !== null) {
            return $err;
        }
        if ($properties === null) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        foreach ($snapshot['worlds'] as $w) {
            if ($w['name'] !== $name) {
                continue;
            }
            if ($name === $snapshot['current']) {
                return response()->json(['ok' => true, 'current' => $name, 'restart' => true]);
            }

            AddonGate::audit($user, $server, 'worlds', 'switch', 'server.properties',
                ['from' => $snapshot['current'], 'to' => $name]);

            try {
                $this->fileRepo($server)->putContent('server.properties', $this->writeLevelLine($properties, $name));
            } catch (DaemonConnectionException $e) {
                $e->report();

                return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
            }

            return response()->json(['ok' => true, 'current' => $name, 'restart' => true]);
        }

        return response()->json(['error' => 'World not found.'], 422);
    }

    public function backup(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'worlds', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!$this->hasPerm($user, $server, 'file.create')) {
            return response()->json(['error' => 'You do not have file create access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.worlds')) {
            return response()->json(['error' => 'Too many world operations this hour.'], 429);
        }

        $name = (string) ($request->json('name') ?? '');
        if (!$this->jailed($name)) {
            return response()->json(['error' => 'Invalid world name.'], 422);
        }

        [$properties, $snapshot, $err] = $this->snapshot($server);
        if ($err !== null) {
            return $err;
        }
        if ($properties === null) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        foreach ($snapshot['worlds'] as $w) {
            if ($w['name'] !== $name) {
                continue;
            }

            AddonGate::audit($user, $server, 'worlds', 'backup', $name, ['dims' => $w['dims']]);

            set_time_limit(900);
            try {
                $archive = $this->fileRepo($server)->compressFiles('/', array_merge([$name], $w['dims']));
            } catch (DaemonConnectionException $e) {
                $e->report();

                return response()->json(['error' => 'Archiving the world failed.'], 502);
            }

            return response()->json(['ok' => true, 'archive' => (string) ($archive['name'] ?? '')]);
        }

        return response()->json(['error' => 'World not found.'], 422);
    }

    public function delete(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'worlds', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!$this->hasPerm($user, $server, 'file.delete')) {
            return response()->json(['error' => 'You do not have file delete access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.worlds')) {
            return response()->json(['error' => 'Too many world operations this hour.'], 429);
        }

        $name = (string) ($request->json('name') ?? '');
        $confirm = (string) ($request->json('confirm') ?? '');
        if (!$this->jailed($name)) {
            return response()->json(['error' => 'Invalid world name.'], 422);
        }
        if ($confirm !== $name) {
            return response()->json(['error' => 'Confirmation does not match the world name.'], 422);
        }

        [$properties, $snapshot, $err] = $this->snapshot($server);
        if ($err !== null) {
            return $err;
        }
        if ($properties === null) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        if ($name === $snapshot['current']) {
            return response()->json(['error' => 'Cannot delete the active world. Switch away first.'], 409);
        }

        foreach ($snapshot['worlds'] as $w) {
            if ($w['name'] !== $name) {
                continue;
            }

            AddonGate::audit($user, $server, 'worlds', 'delete', $name, ['dims' => $w['dims']]);

            try {
                $this->fileRepo($server)->deleteFiles('/', array_merge([$name], $w['dims']));
            } catch (DaemonConnectionException $e) {
                $e->report();

                return response()->json(['error' => 'Deleting the world failed.'], 502);
            }

            return response()->json(['ok' => true]);
        }

        return response()->json(['error' => 'World not found.'], 422);
    }

    /* ── helpers ─────────────────────────────────────────────────── */

    /**
     * Shared pre-flight for mutations: reads server.properties and the
     * world snapshot. Returns [properties|null, snapshot|null, error].
     * properties null + error null means "non-Java" (caller 422s).
     */
    private function snapshot(Server $server): array
    {
        try {
            $properties = $this->fileRepo($server)->getContent('server.properties');
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 404) {
                return [null, null, response()->json(['error' => 'This server has no Minecraft properties file.'], 422)];
            }
            $e->report();

            return [null, null, response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502)];
        }

        if (!$this->java((string) ($server->egg->name ?? ''), $properties)) {
            return [null, null, null];
        }

        try {
            return [$properties, $this->detectWorlds($server, $properties), null];
        } catch (DaemonConnectionException $e) {
            $e->report();

            return [null, null, response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502)];
        }
    }

    private function fileRepo(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    /** Egg + properties sniff — both must say Java Minecraft. */
    private function java(string $eggName, string $properties): bool
    {
        $name = mb_strtolower($eggName);

        return preg_match(self::EGG_FAMILY, $name) === 1
            && preg_match(self::EGG_EXCLUDE, $name) === 0
            && preg_match(self::JAVA_MARKER, $properties) === 1;
    }

    /**
     * Root listing → world rows with DIM grouping, active flag and
     * current level-name. Bounded by CANDIDATE_CAP.
     */
    private function detectWorlds(Server $server, string $properties): array
    {
        $root = $this->fileRepo($server)->getDirectory('/');
        $current = $this->currentLevel($properties);

        $candidates = [];
        foreach ($root as $entry) {
            if (!empty($entry['file']) || empty($entry['name'])) {
                continue; // files are never worlds
            }
            $name = (string) $entry['name'];
            if (in_array($name, self::SKIP, true) || !preg_match(self::NAME_RE, $name)) {
                continue;
            }
            $candidates[$name] = $entry;
            if (count($candidates) >= self::CANDIDATE_CAP) {
                break;
            }
        }

        $worlds = [];
        foreach ($candidates as $name => $entry) {
            if (!$this->hasLevelDat($server, $name)) {
                continue;
            }
            if ($this->dimOf($name) !== null && isset($candidates[$this->dimOf($name)])) {
                continue; // DIM of a listed overworld — grouped, never its own row
            }

            $dims = [];
            foreach (self::DIM_SUFFIXES as $suffix) {
                $dim = $name . $suffix;
                if (isset($candidates[$dim]) && $this->hasLevelDat($server, $dim)) {
                    $dims[] = $dim;
                }
            }

            $worlds[] = [
                'name' => $name,
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => (string) ($entry['modified'] ?? ''),
                'active' => $name === $current,
                'dims' => $dims,
            ];
        }

        usort($worlds, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return ['ok' => true, 'java' => true, 'worlds' => $worlds, 'current' => $current];
    }

    private function hasLevelDat(Server $server, string $dir): bool
    {
        try {
            $listing = $this->fileRepo($server)->getDirectory('/' . $dir);
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
        foreach ($listing as $f) {
            if (!empty($f['file']) && (string) ($f['name'] ?? '') === 'level.dat') {
                return true;
            }
        }

        return false;
    }

    /** Parse level-name= from server.properties (default: world). */
    private function currentLevel(string $properties): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $properties) as $line) {
            if (str_starts_with($line, 'level-name=')) {
                return substr($line, 11);
            }
        }

        return 'world';
    }

    /**
     * Replace the first `level-name=` line in place, or append as the
     * final line. Every other line stays byte-for-byte identical.
     */
    private function writeLevelLine(string $content, string $name): string
    {
        $newLine = 'level-name=' . $name;

        if (preg_match('/^level-name=/m', $content)) {
            return (string) preg_replace_callback(
                '/^level-name=[^\r\n]*/m',
                function () use ($newLine): string {
                    return $newLine;
                },
                $content,
                1
            );
        }

        $prefix = $content !== '' && substr($content, -1) !== "\n" ? "\n" : '';

        return $content . $prefix . $newLine . "\n";
    }

    /** Name jail: single segment, no skip-set, not a DIM suffix. */
    private function jailed(string $name): bool
    {
        if ($name === '' || mb_strlen($name) > 64 || !preg_match(self::NAME_RE, $name)) {
            return false;
        }
        if (in_array($name, self::SKIP, true)) {
            return false;
        }
        foreach (self::DIM_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && mb_strlen($name) > mb_strlen($suffix)) {
                return false; // DIM names are never overworlds
            }
        }

        return true;
    }

    /** If $name is a DIM folder (X_nether / X_the_end), return overworld X. */
    private function dimOf(string $name): ?string
    {
        foreach (self::DIM_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && mb_strlen($name) > mb_strlen($suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return null;
    }

    private function perms(\Pterodactyl\Models\User $user, Server $server): array
    {
        return [
            'canUpdate' => $this->hasPerm($user, $server, 'file.update'),
            'canCreate' => $this->hasPerm($user, $server, 'file.create'),
            'canDelete' => $this->hasPerm($user, $server, 'file.delete'),
        ];
    }

    /* hasPerm: copy the private method verbatim from PluginsController
     * (root_admin / owner always pass; subuser needs the dotted perm). */
    private function hasPerm(\Pterodactyl\Models\User $user, Server $server, string $perm): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }
        $subuser = $server->subusers()->where('user_id', $user->id)->first();

        return in_array($perm, (array) ($subuser?->permissions ?? []), true);
    }
}
