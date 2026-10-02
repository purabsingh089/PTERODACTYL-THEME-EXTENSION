<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ServerBuild;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\UserAiCredential;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\BackupService;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\BuildExecutor;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\BuildStateService;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\ConflictDetector;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MonkeyCodeClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PlanValidator;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\TemplateCatalog;
use Pterodactyl\Http\Controllers\Controller;
use Throwable;

/**
 * AI Server Builder (server tab): analyze an existing server, produce a
 * strict-JSON build plan, approve it, execute it with safe tools, and
 * iterate with natural language follow-ups (Ask/Build modes).
 */
class ServerBuildController extends Controller
{
    public function __construct(private MonkeyCodeClient $client)
    {
    }

    /** GET /server-builder/state?server= */
    public function state(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'server-builder', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'file.read')) {
            return response()->json(['error' => 'You do not have file read access to this server.'], 403);
        }

        $snap = BuildStateService::snapshot($server);
        $history = array_map(fn (ServerBuild $b) => $this->buildSummary($b), ServerBuild::historyFor((int) $server->id));

        return response()->json([
            'ok' => true,
            'egg' => $snap['egg'],
            'software' => $snap['software'],
            'java' => $snap['java'],
            'jars' => $snap['jars'],
            'properties' => $snap['properties'],
            'motd' => $snap['motd'],
            'running' => $snap['running'],
            'templates' => TemplateCatalog::all(),
            'history' => $history,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** GET /server-builder/templates */
    public function templates(): JsonResponse
    {
        return response()->json(['ok' => true, 'templates' => TemplateCatalog::all()]);
    }

    /**
     * POST /server-builder/plan  {server, prompt, mode}
     * mode=build -> validated plan; mode=ask -> read-only analysis text.
     */
    public function plan(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'server-builder', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'file.read')) {
            return response()->json(['error' => 'You do not have file read access to this server.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'builder')) {
            return response()->json(['error' => 'Too many AI requests this hour.'], 429);
        }

        $prompt = trim((string) $request->json('prompt', ''));
        if (mb_strlen($prompt) < 8 || mb_strlen($prompt) > 2000) {
            return response()->json(['error' => 'Describe the change in 8 to 2000 characters.'], 422);
        }
        $mode = $request->json('mode', 'build') === 'ask' ? 'ask' : 'build';

        $creds = UserAiCredential::query()->where('user_id', (int) $user->id)->first();
        if ($creds === null || $creds->apiKey() === '') {
            return response()->json(['error' => 'Save your AI provider URL and API key first.'], 422);
        }

        $snap = BuildStateService::snapshot($server);
        $messages = $this->planMessages($prompt, $mode, $snap);

        try {
            $result = $this->client->completeWith(
                'builder',
                $messages,
                (int) $user->id,
                (string) $server->uuidShort,
                $creds->base_url,
                $creds->apiKey(),
                $creds->model
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        if ($mode === 'ask') {
            /* read-only analysis: no plan, no execution */
            $build = new ServerBuild([
                'server_id' => $server->id,
                'user_id' => $user->id,
                'prompt' => $prompt,
                'mode' => 'ask',
                'status' => ServerBuild::STATUS_DONE,
                'plan' => null,
                'progress' => ['log' => [], 'answer' => mb_substr($result['content'], 0, 4000)],
                'steps' => null,
            ]);
            $build->save();
            ServerBuild::pruneFor((int) $server->id);

            return response()->json([
                'ok' => true,
                'mode' => 'ask',
                'model' => $result['model'],
                'answer' => mb_substr($result['content'], 0, 4000),
                'build_id' => $build->id,
            ]);
        }

        $decoded = Shared::normalizeAiJson($result['content']);
        $validated = PlanValidator::validate($decoded);
        if (!$validated['ok']) {
            return response()->json(['error' => 'The AI produced an invalid plan: ' . $validated['error']], 422);
        }
        $plan = $validated['plan'];

        $conflicts = ConflictDetector::detect($plan['plugins'], $snap['jars']);
        if ($conflicts !== [] && $request->json('resolve') === null) {
            return response()->json([
                'ok' => true,
                'needs_resolution' => true,
                'conflicts' => $conflicts,
                'plan' => $plan,
            ]);
        }

        $build = new ServerBuild([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'prompt' => $prompt,
            'mode' => 'build',
            'status' => ServerBuild::STATUS_PLANNED,
            'plan' => $plan,
            'progress' => null,
            'steps' => null,
        ]);
        $build->save();
        ServerBuild::pruneFor((int) $server->id);

        return response()->json([
            'ok' => true,
            'mode' => 'build',
            'model' => $result['model'],
            'plan' => $plan,
            'conflicts' => $conflicts,
            'build_id' => $build->id,
        ]);
    }

    /**
     * POST /server-builder/execute  {server, build}
     * Synchronous step machine; persists progress; audited per step.
     */
    public function execute(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'server-builder', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (Shared::rateLimited((int) $user->id, 'addon.builder')) {
            return response()->json(['error' => 'Too many build operations this hour.'], 429);
        }

        $buildId = (int) $request->json('build', 0);
        $build = ServerBuild::query()->where('id', $buildId)->where('server_id', $server->id)->first();
        if ($build === null) {
            return response()->json(['error' => 'Build plan not found for this server.'], 404);
        }
        if ($build->status !== ServerBuild::STATUS_PLANNED) {
            return response()->json(['error' => 'This build was already executed or is not in planned state.'], 422);
        }
        if (!Shared::hasPerm($user, $server, 'file.create') || !Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You need file create and update access to execute a build.'], 403);
        }

        $build->status = ServerBuild::STATUS_BUILDING;
        $build->save();

        $perms = [
            'canUpdate' => Shared::hasPerm($user, $server, 'file.update'),
            'canCreate' => Shared::hasPerm($user, $server, 'file.create'),
            'canDelete' => Shared::hasPerm($user, $server, 'file.delete'),
            'canRestart' => Shared::hasPerm($user, $server, 'control.restart'),
        ];

        AddonGate::audit($user, $server, 'server-builder', 'execute', 'build:' . $build->id, [
            'prompt' => mb_substr($build->prompt, 0, 300),
            'plan' => $build->plan,
        ]);

        set_time_limit(600);
        $executor = new BuildExecutor($build);
        $progress = $executor->run($server, $build->plan ?? [], $perms);

        $build->progress = $progress;
        $build->steps = $progress['steps'] ?? null;
        $build->status = ($progress['failed'] ?? false) ? ServerBuild::STATUS_FAILED : ServerBuild::STATUS_DONE;
        $build->save();

        return response()->json([
            'ok' => true,
            'status' => $build->status,
            'backup' => $build->backup_name,
            'progress' => $progress,
        ]);
    }

    /** POST /server-builder/rollback {server, build} */
    public function rollback(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'server-builder', $server);
        if ($gate !== null) {
            return $gate;
        }
        $user = $request->user();
        if (!Shared::hasPerm($user, $server, 'file.update')) {
            return response()->json(['error' => 'You need file update access to roll back.'], 403);
        }
        if (Shared::rateLimited((int) $user->id, 'addon.builder')) {
            return response()->json(['error' => 'Too many build operations this hour.'], 429);
        }

        $buildId = (int) $request->json('build', 0);
        $build = ServerBuild::query()->where('id', $buildId)->where('server_id', $server->id)->first();
        if ($build === null) {
            return response()->json(['error' => 'Build not found for this server.'], 404);
        }

        AddonGate::audit($user, $server, 'server-builder', 'rollback', 'build:' . $build->id, [
            'backup' => $build->backup_name,
        ]);

        $executor = new BuildExecutor($build);
        $result = $executor->rollback($server);
        if (!$result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }

        $build->status = ServerBuild::STATUS_CANCELLED;
        $build->save();

        return response()->json(['ok' => true, 'restored' => $build->backup_name]);
    }

    /** GET /server-builder/builds?server= */
    public function builds(Request $request): JsonResponse
    {
        $server = null;
        $gate = AddonGate::guard($request, 'server-builder', $server);
        if ($gate !== null) {
            return $gate;
        }

        return response()->json([
            'ok' => true,
            'builds' => array_map(fn (ServerBuild $b) => $this->buildDetail($b), ServerBuild::historyFor((int) $server->id)),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function buildSummary(ServerBuild $b): array
    {
        return [
            'id' => $b->id,
            'mode' => $b->mode,
            'status' => $b->status,
            'prompt' => mb_substr($b->prompt, 0, 160),
            'summary' => $b->plan['summary'] ?? '',
            'created_at' => (string) $b->created_at,
        ];
    }

    private function buildDetail(ServerBuild $b): array
    {
        return array_merge($this->buildSummary($b), [
            'plan' => $b->plan,
            'steps' => $b->steps,
            'progress' => $b->progress,
            'backup' => $b->backup_name,
        ]);
    }

    /**
     * Strict-JSON plan prompt grounded in the live server snapshot
     * (incremental builds: the AI sees what is installed already).
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function planMessages(string $prompt, string $mode, array $snap): array
    {
        $jarList = $snap['jars'] !== [] ? implode(', ', $snap['jars']) : '(none installed)';
        $props = $snap['properties'];
        $propLines = [];
        foreach (['gamemode', 'difficulty', 'pvp', 'max-players', 'view-distance', 'simulation-distance', 'motd'] as $k) {
            if (isset($props[$k])) {
                $propLines[] = $k . '=' . $props[$k];
            }
        }

        $system = 'You are the AI Server Builder for a Minecraft server management panel. '
            . ($mode === 'ask'
                ? 'MODE ASK: answer the user question about their server in plain text. You cannot change anything. Keep it under 300 words, actionable and specific. NEVER output JSON.'
                : 'MODE BUILD: you MUST respond with ONLY a strict JSON object, no markdown fences, no commentary.')
            . "\n\nCurrent server state:\n"
            . '- egg: ' . ($snap['egg'] ?: 'unknown') . "\n"
            . '- software: ' . $snap['software'] . "\n"
            . '- java: ' . ($snap['java'] ? 'yes' : 'no') . "\n"
            . '- installed plugin jars: ' . $jarList . "\n"
            . '- properties: ' . (implode('; ', $propLines) ?: '(unreadable)') . "\n"
            . '- server running: ' . ($snap['running'] ? 'yes' : 'no') . "\n\n";

        if ($mode !== 'ask') {
            $system .= <<<'TXT'
JSON schema (all keys required, omit nothing):
{ "summary": "<one sentence>",
  "software": {"name": "<Paper|Purpur|Fabric|Forge|Vanilla>", "reason": "<why>"},
  "minecraft_version": "<e.g. 1.21.x>",
  "plugins": [ {"name": "<jar name without .jar>", "action": "install|enable|disable|remove|keep",
                "provider": "modrinth|curseforge", "project": "<modrinth project id>", "version": "<modrinth version id>",
                "type": "plugin|mod", "reason": "<why>"} ],
  "properties": { "max-players": 30, "difficulty": "normal", "pvp": true, "view-distance": 10,
                  "gamemode": "survival", "hardcore": false, "spawn-protection": 0,
                  "simulation-distance": 8, "white-list": false, "allow-flight": false },
  "motd": "<<=59 chars visible>",
  "commands": ["<optional whitelisted command like say ... or whitelist add Name>"],
  "restart": true,
  "risks": ["<risk note>"],
  "health": ["<post-build check>"] }

Rules:
- INCREMENTAL: plugins already installed (see jar list) must use action keep, or enable/disable/remove only if the user asks. Only propose installs for genuinely missing functionality.
- install entries MUST use real Modrinth project ids and version ids you are confident exist. If unsure, use action keep and note it in risks. NEVER invent ids.
- properties keys allowed: max-players, view-distance, simulation-distance, spawn-protection, player-idle-timeout, max-world-size (ints), gamemode, difficulty, pvp, hardcore, online-mode, spawn-monsters, spawn-animals, spawn-npcs, allow-nether, allow-flight, enable-command-block, white-list, enforce-whitelist, level-type.
- motd: max 59 visible characters, no newlines.
- commands: only say/whitelist/op/deop/ban/pardon/kick/gamerule style. NEVER stop/restart shell commands.
- Do not propose changing docker images, ports, allocations, or files outside plugins/.
TXT;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ];
    }
}
