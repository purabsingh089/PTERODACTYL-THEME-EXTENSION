<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\AddonAudit;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\ThemeSetting;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models\UserAiCredential;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\EggMatcher;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\MonkeyCodeClient;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\PromptBuilder;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services\ServerSpecNormalizer;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\ServerCreationService;

/**
 * AI Server Builder — dashboard overlay. Users store their own
 * OpenAI-compatible credentials, preview a spec from a prompt, then
 * create a server via ServerCreationService (owner = acting user).
 */
class ServerBuilderController extends Controller
{
    public function __construct(
        private MonkeyCodeClient $client,
        private PromptBuilder $prompts,
        private ServerCreationService $creation,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->featureGuard()) {
            return $denied;
        }

        $row = UserAiCredential::query()->where('user_id', $user->id)->first();

        return response()->json([
            'ok' => true,
            'enabled' => true,
            'configured' => $row !== null && $row->apiKey() !== '',
            'base_url' => $row?->base_url ?? MonkeyCodeClient::DEFAULT_BASE_URL,
            'model' => $row?->model ?? '',
            'has_key' => $row !== null && $row->apiKey() !== '',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function saveCredentials(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->featureGuard()) {
            return $denied;
        }

        $base = trim((string) $request->json('base_url', ''));
        if ($base === '' || !preg_match('#^https?://#', $base)) {
            return response()->json(['error' => 'A valid http(s) API base URL is required.'], 422);
        }
        $base = MonkeyCodeClient::normalizeBaseUrl($base);
        if (strlen($base) > 255) {
            return response()->json(['error' => 'API base URL is too long.'], 422);
        }

        $model = trim((string) $request->json('model', ''));
        if (strlen($model) > 128) {
            return response()->json(['error' => 'Model id is too long.'], 422);
        }

        $key = trim((string) $request->json('api_key', ''));
        $row = UserAiCredential::query()->where('user_id', $user->id)->first();

        if ($key === '' || $key === '••••••••') {
            if ($row === null || $row->apiKey() === '') {
                return response()->json(['error' => 'An API key is required.'], 422);
            }
        } elseif (strlen($key) > 512) {
            return response()->json(['error' => 'API key is too long.'], 422);
        }

        if ($row === null) {
            $row = new UserAiCredential(['user_id' => $user->id]);
        }
        $row->base_url = $base;
        $row->model = $model;
        if ($key !== '' && $key !== '••••••••') {
            $row->setApiKey($key);
        }
        $row->save();

        return response()->json([
            'ok' => true,
            'configured' => true,
            'base_url' => $row->base_url,
            'model' => $row->model,
            'has_key' => true,
        ]);
    }

    public function models(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->featureGuard()) {
            return $denied;
        }

        $row = UserAiCredential::query()->where('user_id', $user->id)->first();
        $base = trim((string) $request->json('base_url', ''));
        if ($base === '' || !preg_match('#^https?://#', $base)) {
            $base = $row?->base_url ?? MonkeyCodeClient::DEFAULT_BASE_URL;
        }
        $key = trim((string) $request->json('api_key', ''));
        if ($key === '' || $key === '••••••••') {
            $key = $row?->apiKey() ?? '';
        }
        if ($key === '') {
            return response()->json(['error' => 'Save an API key first, then detect models.', 'models' => []], 422);
        }

        try {
            $catalog = $this->client->availableModels($base, $key);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'models' => []], 200);
        }

        return response()->json([
            'models' => $catalog['models'],
            'warning' => $catalog['warning'] !== '' ? $catalog['warning'] : null,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->featureGuard()) {
            return $denied;
        }

        $prompt = trim((string) $request->json('prompt', ''));
        if (strlen($prompt) < 8) {
            return response()->json(['error' => 'Describe the server you want in at least a short sentence.'], 422);
        }
        if (strlen($prompt) > 2000) {
            return response()->json(['error' => 'Prompt is too long (max 2000 characters).'], 422);
        }

        if (Shared::rateLimited($user->id, 'builder')) {
            return response()->json(['error' => 'Rate limit exceeded. Try again in a few minutes.'], 429);
        }

        $creds = $this->requireCreds($user->id);
        if ($creds instanceof JsonResponse) {
            return $creds;
        }
        if (trim((string) $creds->model) === '') {
            return response()->json(['error' => 'Detect models and pick one before planning a server.'], 422);
        }

        $eggs = $this->eggCatalog();
        if ($eggs === []) {
            return response()->json(['error' => 'This panel has no eggs installed, so a server cannot be planned.'], 422);
        }

        try {
            $result = $this->client->completeWith(
                'builder',
                $this->prompts->builderMessages($prompt, $eggs),
                $user->id,
                null,
                $creds->base_url,
                $creds->apiKey(),
                $creds->model
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $decoded = Shared::normalizeAiJson($result['content']);
        $spec = ServerSpecNormalizer::normalize($decoded);
        $match = EggMatcher::match($spec['game'] !== '' ? $spec['game'] : $prompt, $eggs);
        if ($match === null) {
            $match = EggMatcher::match($eggs[0]['name'] ?? 'minecraft', $eggs);
        }
        if ($match === null) {
            return response()->json(['error' => 'Could not match the request to an installed egg.'], 422);
        }

        return response()->json([
            'ok' => true,
            'model' => $result['model'],
            'spec' => $spec,
            'egg' => [
                'id' => $match['egg_id'],
                'nest_id' => $match['nest_id'],
                'name' => $match['name'],
                'nest' => $match['nest'],
            ],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->featureGuard()) {
            return $denied;
        }
        if (Shared::rateLimited($user->id, 'addon.builder')) {
            return response()->json(['error' => 'Rate limit exceeded. Try again in a few minutes.'], 429);
        }

        $eggId = (int) $request->json('egg_id', 0);
        $egg = Egg::query()->with(['nest', 'variables'])->find($eggId);
        if ($egg === null) {
            return response()->json(['error' => 'Choose a valid egg from the preview before creating.'], 422);
        }

        $spec = ServerSpecNormalizer::normalize([
            'name' => $request->json('name', ''),
            'description' => $request->json('description', ''),
            'memory' => $request->json('memory', 512),
            'disk' => $request->json('disk', 2048),
            'cpu' => $request->json('cpu', 0),
            'swap' => $request->json('swap', 0),
            'start_on_completion' => $request->json('start_on_completion', false),
            'game' => $egg->name,
        ]);

        $images = is_array($egg->docker_images) ? $egg->docker_images : [];
        $image = $images !== [] ? (string) reset($images) : '';
        if ($image === '') {
            return response()->json(['error' => 'The selected egg has no Docker image configured.'], 422);
        }

        $environment = [];
        foreach ($egg->variables as $variable) {
            $environment[$variable->env_variable] = (string) ($variable->default_value ?? '');
        }

        $data = [
            'name' => $spec['name'],
            'description' => $spec['description'],
            'owner_id' => $user->id,
            'egg_id' => $egg->id,
            'nest_id' => $egg->nest_id,
            'image' => $image,
            'startup' => (string) $egg->startup,
            'environment' => $environment,
            'memory' => $spec['memory'],
            'swap' => $spec['swap'],
            'disk' => $spec['disk'],
            'io' => $spec['io'],
            'cpu' => $spec['cpu'],
            'skip_scripts' => false,
            'start_on_completion' => $spec['start_on_completion'],
            'database_limit' => $spec['database_limit'],
            'allocation_limit' => $spec['allocation_limit'],
            'backup_limit' => $spec['backup_limit'],
            'oom_disabled' => true,
        ];

        $deployment = new DeploymentObject();
        $deployment->setDedicated(false);
        $deployment->setLocations([]);
        $deployment->setPorts([]);

        $auditId = AddonGate::audit(
            $user,
            (new Server())->forceFill(['id' => 0]),
            'builder',
            'create',
            $spec['name']
        );

        try {
            $server = $this->creation->handle($data, $deployment);
        } catch (NoViableNodeException $e) {
            return response()->json(['error' => 'No node has enough free memory or disk for this plan. Lower the resources and try again.'], 422);
        } catch (NoViableAllocationException $e) {
            return response()->json(['error' => 'No free allocation (IP and port) is available on a viable node.'], 422);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json(['error' => is_string($first) ? $first : 'The server specification failed validation.'], 422);
        } catch (DaemonConnectionException $e) {
            $e->report();

            return response()->json(['error' => 'The panel saved the server but could not reach Wings. Check the node and try again from Admin.'], 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Server creation failed. Check node capacity and try a smaller plan.'], 500);
        }

        if ($auditId !== null) {
            AddonAudit::query()->where('id', $auditId)->update([
                'server_id' => $server->id,
                'target' => $server->uuidShort,
            ]);
        }

        return response()->json([
            'ok' => true,
            'server' => [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'identifier' => $server->uuidShort,
                'name' => $server->name,
                'url' => '/server/' . $server->uuidShort,
            ],
        ]);
    }

    private function featureGuard(): ?JsonResponse
    {
        if (!ThemeSetting::get('ai.builder_enabled', true)) {
            return response()->json(['error' => 'The AI Server Builder has been disabled by the administrator.'], 403);
        }

        return null;
    }

    private function requireCreds(int $userId): UserAiCredential|JsonResponse
    {
        $row = UserAiCredential::query()->where('user_id', $userId)->first();
        if ($row === null || $row->apiKey() === '') {
            return response()->json(['error' => 'Save your AI provider URL and API key first.'], 422);
        }

        return $row;
    }

    /**
     * @return array<int, array{id:int, nest_id:int, name:string, nest:string, description:string}>
     */
    private function eggCatalog(): array
    {
        return Egg::query()
            ->with('nest')
            ->orderBy('name')
            ->get()
            ->map(static function (Egg $egg): array {
                return [
                    'id' => $egg->id,
                    'nest_id' => $egg->nest_id,
                    'name' => (string) $egg->name,
                    'nest' => (string) ($egg->nest->name ?? ''),
                    'description' => (string) ($egg->description ?? ''),
                ];
            })
            ->all();
    }
}
