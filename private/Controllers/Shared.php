<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AiRequestLog;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Shared guards + helpers for the AI controllers. Kept in the controller
 * namespace so routes.php can stay declarative.
 */
final class Shared
{
    public const EGG_FAMILY = '/(vanilla|paper|purpur|spigot|fabric|forge|spoon|quilt|minecraft)/i';
    public const EGG_EXCLUDE = '/bedrock/i';
    public const JAVA_MARKER = '/^(server-port|level-name|online-mode|max-players|view-distance|motd|server-ip)[[:space:]]*=/m';

    /**
     * Resolve a server that the acting user is actually allowed to touch:
     * ownership, subuser membership or root admin. Returns null otherwise.
     */
    public static function resolveAccessibleServer(string $serverId, User $user): ?Server
    {
        if ($serverId === '') {
            return null;
        }

        $server = Server::query()
            ->with(['egg', 'nest', 'node'])
            ->where(function ($query) use ($serverId) {
                $query->where('uuid', $serverId)
                    ->orWhere('uuidShort', $serverId);
            })
            ->first();

        if ($server === null) {
            return null;
        }

        if ($user->root_admin) {
            return $server;
        }
        if ($server->owner_id === $user->id) {
            return $server;
        }

        $member = $server->subusers()
            ->where('user_id', $user->id)
            ->exists();

        return $member ? $server : null;
    }

    /**
     * Sliding-window rate limit per user + feature. Features prefixed
     * "addon." are addon mutations and count their own AddonAudit rows
     * (addons.plugins.rate_limit_per_hour, default 60); AI features count
     * AiRequestLog rows (ai.rate_limit_per_hour, default 30).
     */
    public static function rateLimited(int $userId, string $feature): bool
    {
        if (str_starts_with($feature, 'addon.')) {
            $limit = (int) ThemeSetting::get('addons.rate_limit_per_hour', 60);
            if ($limit <= 0) {
                return false;
            }

            $recent = AddonAudit::query()
                ->where('user_id', $userId)
                ->where('addon', substr($feature, 6))
                ->where('action', '!=', 'list')
                ->where('created_at', '>=', now()->subHour())
                ->count();

            return $recent >= $limit;
        }

        $limit = (int) ThemeSetting::get('ai.rate_limit_per_hour', 30);
        if ($limit <= 0) {
            return false;
        }

        $recent = AiRequestLog::query()
            ->where('user_id', $userId)
            ->where('feature', $feature)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return $recent >= $limit;
    }

    /**
     * The model is instructed to answer with strict JSON. Providers still
     * occasionally wrap it in markdown fences or add prose; extract the JSON
     * object defensively and always return an array the frontend can render.
     */
    public static function normalizeAiJson(string $content): array
    {
        $cleaned = trim($content);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned);
        $cleaned = trim((string) preg_replace('/```$/', '', (string) $cleaned));

        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($cleaned, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Fall back to showing the raw text (never empty results).
        return [
            'cause' => '',
            'suggestion' => $content,
            'confidence' => 'medium',
            'command' => '',
            'config_change' => '',
        ];
    }

    public static function hasPerm(User $user, Server $server, string $perm): bool
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return true;
        }
        $subuser = $server->subusers()->where('user_id', $user->id)->first();

        return in_array($perm, (array) ($subuser?->permissions ?? []), true);
    }

    public static function fileRepo(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    public static function eggMatches(string $eggName): bool
    {
        $name = mb_strtolower($eggName);

        return preg_match(self::EGG_FAMILY, $name) === 1
            && preg_match(self::EGG_EXCLUDE, $name) === 0;
    }

    public static function isJava(string $eggName, string $properties): bool
    {
        return self::eggMatches($eggName) && preg_match(self::JAVA_MARKER, $properties) === 1;
    }

    /**
     * @return array{0: ?string, 1: ?\Illuminate\Http\JsonResponse} properties or null, error response or null
     */
    public static function readProperties(Server $server): array
    {
        try {
            return [self::fileRepo($server)->getContent('server.properties'), null];
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 404) {
                return [null, null];
            }
            $e->report();

            return [null, response()->json(['error' => 'Could not reach the Wings daemon for this node.'], 502)];
        }
    }

    public static function filePerms(User $user, Server $server): array
    {
        return [
            'canUpdate' => self::hasPerm($user, $server, 'file.update'),
            'canCreate' => self::hasPerm($user, $server, 'file.create'),
            'canDelete' => self::hasPerm($user, $server, 'file.delete'),
        ];
    }

    /**
     * Replace or append a single KEY=value line. All other bytes stay identical.
     */
    public static function writePropLine(string $content, string $key, string $value): string
    {
        $newLine = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';
        if (preg_match($pattern, $content)) {
            return (string) preg_replace_callback($pattern, fn () => $newLine, $content, 1);
        }
        $prefix = $content !== '' && substr($content, -1) !== "\n" ? "\n" : '';

        return $content . $prefix . $newLine . "\n";
    }
}
