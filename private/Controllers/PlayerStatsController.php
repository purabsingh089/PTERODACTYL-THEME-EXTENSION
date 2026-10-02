<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Player Stats — online players, join totals, sessions and a live feed
 * parsed from logs/latest.log, plus player actions (kick/ban/op/...)
 * via jailed console commands and the server's allocations with notes.
 * Absorbs the retired Players/Traffic managers.
 */
class PlayerStatsController extends Controller
{
    private const NAME_RE = '/^[A-Za-z0-9_]{1,16}$/';
    private const ACTIONS = [
        'kick' => 'kick %s %s',
        'ban' => 'ban %s %s',
        'pardon' => 'pardon %s',
        'op' => 'op %s',
        'deop' => 'deop %s',
        'whitelist-add' => 'whitelist add %s',
        'whitelist-remove' => 'whitelist remove %s',
    ];

    public function index(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'player-stats', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();

        $perms = [
            'canCommand' => Shared::hasPerm($user, $server, 'control.console'),
            'canUpdateNotes' => Shared::hasPerm($user, $server, 'allocation.update'),
        ];

        [$properties, $err] = Shared::readProperties($server);
        if ($err !== null) {
            return $err;
        }
        $java = $properties !== null && Shared::isJava((string) ($server->egg->name ?? ''), $properties);
        if (!$java) {
            return response()->json(['ok' => true, 'java' => false, 'running' => false, 'perms' => $perms]);
        }

        $running = ($server->status ?? '') === 'running';
        $repo = Shared::fileRepo($server);

        $log = $this->readLog($repo);
        [$stats, $feed] = $this->parseLog($log);
        $online = $running ? $this->probeOnline($repo, $server) : [];

        /* ops/whitelist/bans from Players Manager for the action chips */
        $ops = $this->readNames($repo, 'ops.json');
        $whitelist = $this->readNames($repo, 'whitelist.json');
        $bans = $this->readBans($repo);

        $rows = [];
        foreach ($server->allocations()->orderBy('port')->get() as $a) {
            $rows[] = [
                'id' => (int) $a->id,
                'ip' => (string) $a->ip,
                'alias' => (string) ($a->ip_alias ?? ''),
                'port' => (int) $a->port,
                'notes' => (string) ($a->notes ?? ''),
                'primary' => (int) $a->id === (int) $server->allocation_id,
            ];
        }

        return response()->json([
            'ok' => true,
            'java' => true,
            'running' => $running,
            'online' => $online,
            'totals' => $stats['totals'],
            'players' => $stats['players'],
            'feed' => $feed,
            'ops' => $ops,
            'whitelist' => $whitelist,
            'bans' => $bans,
            'allocations' => $rows,
            'perms' => $perms,
        ]);
    }

    public function command(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'player-stats', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'control.console')) {
            return response()->json(['error' => 'You do not have console access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.player-stats')) {
            return response()->json(['error' => 'Too many player operations this hour.'], 429);
        }

        $action = (string) $request->json('action', '');
        $name = (string) $request->json('name', '');
        $reason = (string) $request->json('reason', '');
        if (!isset(self::ACTIONS[$action])) {
            return response()->json(['error' => 'Unknown player action.'], 422);
        }
        if (!preg_match(self::NAME_RE, $name)) {
            return response()->json(['error' => 'Invalid player name.'], 422);
        }
        if (preg_match('/[\p{C}\\\\]/u', $reason) || mb_strlen($reason) > 80) {
            return response()->json(['error' => 'Invalid reason.'], 422);
        }
        if (($server->status ?? '') !== 'running') {
            return response()->json(['error' => 'Server must be online to send player commands.'], 422);
        }
        $reason = $reason === '' ? 'Primus' : $reason;

        $template = self::ACTIONS[$action];
        $cmd = str_contains($template, '%s %s')
            ? sprintf($template, $name, $reason)
            : sprintf($template, $name);

        AddonGate::audit($user, $server, 'player-stats', $action, $name, ['command' => $cmd]);

        try {
            app(DaemonCommandRepository::class)->setServer($server)->send($cmd);
        } catch (DaemonConnectionException $e) {
            if ($e->getStatusCode() === 502) {
                return response()->json(['error' => 'Server must be online in order to send commands.'], 502);
            }
            $e->report();

            return response()->json(['error' => 'Could not reach the Wings daemon for this server.'], 502);
        }

        return response()->json(['ok' => true, 'action' => $action, 'name' => $name]);
    }

    public function notes(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'player-stats', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'allocation.update')) {
            return response()->json(['error' => 'You do not have allocation update access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.player-stats')) {
            return response()->json(['error' => 'Too many operations this hour.'], 429);
        }

        $id = (int) $request->json('id', 0);
        $notes = (string) $request->json('notes', '');
        if (mb_strlen($notes) > 256) {
            return response()->json(['error' => 'Notes must be 256 characters or fewer.'], 422);
        }
        if (preg_match('/[\p{C}]/u', $notes)) {
            return response()->json(['error' => 'Notes may not contain control characters.'], 422);
        }

        $alloc = Allocation::query()
            ->where('id', $id)
            ->where('server_id', $server->id)
            ->first();
        if ($alloc === null) {
            return response()->json(['error' => 'Allocation not found.'], 404);
        }

        AddonGate::audit($user, $server, 'player-stats', 'notes', (string) $alloc->ip . ':' . $alloc->port, ['notes' => $notes]);
        $alloc->notes = $notes === '' ? null : $notes;
        $alloc->save();

        return response()->json(['ok' => true, 'id' => $alloc->id, 'notes' => (string) ($alloc->notes ?? '')]);
    }

    /* ── internals ─────────────────────────────────────────────── */

    private function readLog(DaemonFileRepository $repo): string
    {
        try {
            return $repo->getContent('logs/latest.log', 512 * 1024);
        } catch (DaemonConnectionException $e) {
            return '';
        }
    }

    /**
     * Parse vanilla/Paper join/leave/chat/death lines. Log lines carry
     * no dates in v1, so first/last-seen are null and session playtime
     * counts open sessions as running-since-join.
     *
     * @return array{0: array{totals: array<string,int>, players: array<int, array<string,mixed>>}, 1: array<int, array<string,mixed>>}
     */
    private function parseLog(string $log): array
    {
        $players = [];
        $feed = [];
        $totalJoins = 0;
        $open = [];

        foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
            /* strip the [12:34:56] [thread/INFO] prefix */
            $text = (string) preg_replace('/^\[[0-9:]{8}\]\s*\[[^\]]+\]\s*:\s*/u', '', $line);
            if ($text === $line && preg_match('/^\[[0-9:]{8}\]\s*([^:]*):\s*(.*)$/u', $line, $m)) {
                $text = $m[2];
            }

            if (preg_match('/\b([A-Za-z0-9_]{1,16})\s+joined the game\b/u', $text, $m)) {
                $name = $m[1];
                $totalJoins++;
                $players[$name]['joins'] = ($players[$name]['joins'] ?? 0) + 1;
                $players[$name]['sessions'] = ($players[$name]['sessions'] ?? 0) + 1;
                $open[$name] = true;
                $feed[] = ['kind' => 'join', 'name' => $name, 'text' => mb_substr($text, 0, 140)];
            } elseif (preg_match('/\b([A-Za-z0-9_]{1,16})\s+left the game\b/u', $text, $m)) {
                $name = $m[1];
                unset($open[$name]);
                $players[$name]['joins'] = $players[$name]['joins'] ?? 0;
                $feed[] = ['kind' => 'leave', 'name' => $name, 'text' => mb_substr($text, 0, 140)];
            } elseif (preg_match('/^<([A-Za-z0-9_]{1,16})>\s*(.*)$/u', $text, $m)) {
                $feed[] = ['kind' => 'chat', 'name' => $m[1], 'text' => mb_substr($m[2], 0, 140)];
            } elseif (preg_match('/\b([A-Za-z0-9_]{1,16})\s+died\b/iu', $text, $m)) {
                $feed[] = ['kind' => 'death', 'name' => $m[1], 'text' => mb_substr($text, 0, 140)];
            }
        }

        $out = [];
        foreach ($players as $name => $p) {
            $out[] = [
                'name' => (string) $name,
                'joins' => (int) ($p['joins'] ?? 0),
                'sessions' => (int) ($p['sessions'] ?? 0),
                'online' => isset($open[$name]),
            ];
        }
        usort($out, fn ($a, $b) => $b['joins'] <=> $a['joins'] ?: strcmp((string) $a['name'], (string) $b['name']));

        return [
            [
                'totals' => [
                    'uniquePlayers' => count($players),
                    'totalJoins' => $totalJoins,
                    'onlineNow' => count($open),
                ],
                'players' => array_slice($out, 0, 100),
            ],
            array_slice($feed, -50),
        ];
    }

    /**
     * Best-effort online probe: send `list`, wait briefly, then parse
     * the player list from the tail of the log. Server offline, missing
     * log or unparseable output all degrade to [].
     *
     * @return array<int, string>
     */
    private function probeOnline(DaemonFileRepository $repo, $server): array
    {
        try {
            app(DaemonCommandRepository::class)->setServer($server)->send('list');
        } catch (\Throwable $e) {
            return [];
        }
        usleep(700000);

        $tail = mb_substr($this->readLog($repo), -4000);
        if (!preg_match('/There are (\d+) of a max(?:imum)? of \d+ players? online(?:[^:]*:)?\s*([A-Za-z0-9_, ]*)$/u', $tail, $m)) {
            return [];
        }
        $names = array_filter(array_map('trim', explode(',', (string) ($m[2] ?? ''))));
        $names = array_values(array_unique($names));
        $clean = [];
        foreach ($names as $n) {
            if (preg_match(self::NAME_RE, (string) $n)) {
                $clean[] = (string) $n;
            }
        }

        return $clean;
    }

    private function readNames(DaemonFileRepository $repo, string $file): array
    {
        try {
            $raw = $repo->getContent($file, 256 * 1024);
        } catch (DaemonConnectionException $e) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            $n = (string) ($row['name'] ?? '');
            if (preg_match(self::NAME_RE, $n)) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    private function readBans(DaemonFileRepository $repo): array
    {
        try {
            $raw = $repo->getContent('banned-players.json', 256 * 1024);
        } catch (DaemonConnectionException $e) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            $n = (string) ($row['name'] ?? '');
            if (!preg_match(self::NAME_RE, $n)) {
                continue;
            }
            $out[] = [
                'name' => $n,
                'reason' => mb_substr((string) ($row['reason'] ?? ''), 0, 80),
                'created' => (string) ($row['created'] ?? ''),
            ];
        }

        return $out;
    }
}
