<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AiRequestLog;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;

/**
 * Shared guards + helpers for the AI controllers. Kept in the controller
 * namespace so routes.php can stay declarative.
 */
final class Shared
{
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
}
