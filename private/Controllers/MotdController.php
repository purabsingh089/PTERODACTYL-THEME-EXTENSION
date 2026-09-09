<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * MOTD Creator — read/write the `motd=` line of a Java Minecraft
 * server's server.properties via Wings file access.
 *
 * Two-stage "is this really Java Minecraft?" gate:
 *   1. Egg-name family tokens (DB only, no Wings call).
 *   2. Java-properties sniff on server.properties (one Wings read,
 *      reused as the current-motd fetch).
 *
 * The file is never created; a missing file means "not a Minecraft
 * server" (enabled:false on read, 422 on save).
 */
class MotdController extends Controller
{
    /** Egg-name tokens implying a Java Minecraft egg (names are human strings). */
    private const EGG_FAMILY = '/(vanilla|paper|purpur|spigot|fabric|forge|spoon|quilt|minecraft)/i';

    /** Eggs matching this are never Java Minecraft. */
    private const EGG_EXCLUDE = '/bedrock/i';

    /** At least one of these keys must exist for a Java properties file. */
    private const JAVA_MARKER = '/^(server-port|level-name|online-mode|max-players|view-distance|motd|server-ip)[[:space:]]*=/m';

    /** Vanilla server lists break past this many rendered characters. */
    public const LIMIT = 59;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $server = Shared::resolveAccessibleServer((string) $request->query('server', ''), $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        $eggName = (string) ($server->egg->name ?? '');
        $isRunning = ($server->status ?? '') === 'running';
        $subuser = $this->subuser($server, $user);
        $canWrite = $this->canWrite($user, $server, $subuser);
        $canRestart = $this->canPower($user, $server, $subuser);

        if (!$this->eggMatches($eggName)) {
            return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'egg');
        }

        try {
            $content = $this->fileRepo($server)->getContent('server.properties');
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() !== 404) {
                $e->report();

                return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'unreachable',
                    'Could not reach the Wings daemon for this node.');
            }

            return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'missing');
        }

        if (!preg_match(self::JAVA_MARKER, $content)) {
            return $this->disabled($eggName, $isRunning, $canWrite, $canRestart, 'non-java');
        }

        return response()->json([
            'enabled' => true,
            'egg' => $eggName,
            'motd' => $this->currentMotd($content),
            'running' => $isRunning,
            'canRestart' => $canRestart,
            'canWrite' => $canWrite,
            'limit' => self::LIMIT,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $server = Shared::resolveAccessibleServer((string) $request->json('server', ''), $user);
        if ($server === null) {
            return response()->json(['error' => 'Server not found or not accessible.'], 404);
        }

        $subuser = $this->subuser($server, $user);
        if (!$this->canWrite($user, $server, $subuser)) {
            return response()->json(['error' => 'You do not have file access to this server.'], 403);
        }

        $motd = $request->json('motd');
        if (!is_string($motd) || mb_strlen($motd) < 1) {
            return response()->json(['error' => 'MOTD cannot be empty.'], 422);
        }

        $visible = preg_replace('/§./us', '', $motd);
        if (mb_strlen($visible) > self::LIMIT) {
            return response()->json(['error' => 'MOTD is too long (max ' . self::LIMIT . ' rendered characters).'], 422);
        }

        if (preg_match('/[\p{C}\\\\]/u', $motd)) {
            return response()->json(['error' => 'MOTD may not contain control characters or backslashes.'], 422);
        }

        if (!$this->eggMatches((string) ($server->egg->name ?? ''))) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        try {
            $content = $this->fileRepo($server)->getContent('server.properties');
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 404) {
                return response()->json(['error' => 'Minecraft properties file not found on this server.'], 422);
            }
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        if (!preg_match(self::JAVA_MARKER, $content)) {
            return response()->json(['error' => 'This server has no Minecraft properties file.'], 422);
        }

        try {
            $this->fileRepo($server)->putContent('server.properties', $this->writeMotdLine($content, $motd));
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json([
            'ok' => true,
            'running' => ($server->status ?? '') === 'running',
            'restartable' => $this->canPower($user, $server, $subuser),
        ]);
    }

    private function fileRepo(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    private function eggMatches(string $eggName): bool
    {
        $name = mb_strtolower($eggName);

        return preg_match(self::EGG_FAMILY, $name) === 1
            && preg_match(self::EGG_EXCLUDE, $name) === 0;
    }

    private function currentMotd(string $content): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (str_starts_with($line, 'motd=')) {
                return substr($line, 5);
            }
        }

        return '';
    }

    /**
     * Replace the first `motd=` line in place, or append as the final
     * line. Every other line stays byte-for-byte identical.
     */
    private function writeMotdLine(string $content, string $motd): string
    {
        $newLine = 'motd=' . $motd;

        if (preg_match('/^motd=/m', $content)) {
            return (string) preg_replace_callback(
                '/^motd=[^\r\n]*/m',
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

    private function subuser(Server $server, User $user): ?Subuser
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return null;
        }

        return $server->subusers()->where('user_id', $user->id)->first();
    }

    private function canWrite(User $user, Server $server, ?Subuser $subuser): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }

        return in_array('file.update', (array) ($subuser?->permissions ?? []), true);
    }

    private function canPower(User $user, Server $server, ?Subuser $subuser): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }
        $perms = (array) ($subuser?->permissions ?? []);

        return in_array('control.start', $perms, true)
            || in_array('control.stop', $perms, true)
            || in_array('control.restart', $perms, true);
    }

    private function disabled(
        string $eggName,
        bool $running,
        bool $canWrite,
        bool $canRestart,
        string $reason,
        ?string $error = null
    ): JsonResponse {
        $payload = [
            'enabled' => false,
            'egg' => $eggName,
            'motd' => '',
            'running' => $running,
            'canRestart' => $canRestart,
            'canWrite' => $canWrite,
            'limit' => self::LIMIT,
            'reason' => $reason,
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }

        return response()->json($payload);
    }
}
