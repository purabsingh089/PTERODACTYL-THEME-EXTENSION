# AI Server Builder (orchestrator) — Implementation Plan

Spec: `.monkeycode-tmp-files/3e55d4e4-long-input-20261001-133001.txt` (30 sections).
Status of this doc: design contract for the incremental build. The dashboard-level
"create a new server from a prompt" builder already exists (`ServerBuilderController`);
this plan covers the **server-level** builder tab: analyze -> plan -> approve ->
execute -> health check -> natural-language iteration on an EXISTING server.

## 1. Project Audit (summary)

- Pterodactyl 1.x + Blueprint; extension id `primus`; routes at `/extensions/primus/*`
  in the `blueprint` middleware group (session/CSRF). No Laravel rate-limiter
  middleware — limits are DB-row-count based via `Shared::rateLimited()`.
- Existing gated capabilities the builder will REUSE (no duplication):
  - Plugin/mod search + install: `MarketplaceClient::search/versions/download`,
    install writes via Wings `DaemonFileRepository::putContent` into `plugins/`|`mods/`.
  - Plugin list/enable/disable/delete: `PluginsController` (jailed `['plugins','mods']`).
  - server.properties allowlist read/write: `PropertiesController` + `Shared::readProperties`
    / `writePropLine` (keys: gamemode, difficulty, pvp, max-players, view-distance, ...).
  - MOTD: `MotdController` (Java-egg gated, 59-char limit).
  - Server jar/image switch: `VersionsController` (`/addons/versions/jar|image`).
  - World backup (tar.gz via files API): `WorldsController::backup`.
  - Console commands: `DaemonCommandRepository::send` (denylist filtered), power via
    `/proxy/power` closure, server-side log tail: `PlayerStatsController::readLog()`
    (`logs/latest.log`, 512 KiB cap).
- AI: `MonkeyCodeClient::completeWith(feature, messages, userId, serverId, base, key, model)`
  with per-user creds from `primus_user_ai_credentials` (encrypted key, never returned).
  Strict-JSON outputs normalized via `Shared::normalizeAiJson`.
- Security pattern to replicate exactly:
  `AddonGate::guard()` -> `Shared::hasPerm()` -> `Shared::rateLimited()` ->
  `AddonGate::audit()` (returns id, `retarget()` after mutation) -> `PathGuard::resolve()`.
- Gaps to build new:
  1. Real Pterodactyl backups via `DaemonBackupRepository` (spec §21) — new wrapper.
  2. Build plan persistence + execution state machine (spec §6/§7/§18).
  3. Orchestrator that calls the existing services directly (server-side, not via HTTP
     re-entry into our own endpoints) — one PHP executor, not a JS loop over endpoints.
  4. Server-level "AI Builder" tab UI (spec §2, §8, §24 modes Ask/Build, §23 NL modify).

## 2. Architecture

```
dashboard wrapper (builder-tab.js, self-gated on /server/{id})
   |
   v
GET/POST /extensions/primus/server-builder/*      (auth; AddonGate 'builder' concept)
   |
ServerBuildController
   |-- BuildPlanner        (AI: description + server state -> strict-JSON plan)
   |-- BuildStateService   (gather server state: egg, jars, properties, motd, java)
   |-- BuildExecutor       (validates plan, runs steps, records progress, rollback)
   |     |-- BackupService (DaemonBackupRepository wrapper - NEW)
   |     |-- MarketplaceClient / PluginsController logic (jar install/enable/delete)
   |     |-- Shared::writePropLine (properties, MOTD)
   |     |-- DaemonCommandRepository (approved commands only)
   |-- BuildHealthCheck    (log tail scan + properties sanity -> report JSON)
   v
primus_server_builds (plan + progress + audit linkage)
```

Ask/Build modes (spec §24): Ask = planner runs with tools `get*` only and the response
is rendered as text (no plan/execution). Build = full plan -> approve -> execute.

## 3. Database Plan

Migration `2026_10_01_000002_create_primus_server_builds_table.php`:

```
primus_server_builds
  id              bigIncrements
  server_id       unsignedBigInteger, index
  user_id         unsignedBigInteger, index
  prompt          text
  mode            string(16)          ask|build
  plan            json nullable       (validated plan; null for ask)
  status          string(24) default 'planned'   planned|building|done|failed|cancelled
  progress        json nullable       {step, total, log:[{t, level, msg}]}
  backup_name     string(191) nullable (panel backup identifier for rollback)
  steps           json nullable       execution checklist with per-step status
  created_at / updated_at
  index [server_id, created_at]
```

Model `Models/ServerBuild.php` (`$timestamps = true`, casts plan/progress/steps to array).
Build history = rows per server (spec §18); cleanup: latest 20 kept per server (prune on create).

## 4. API Plan (all under `/extensions/primus`, `auth` middleware)

| Route | Method | Body/Params | Notes |
|---|---|---|---|
| `/server-builder/state?server=` | GET | server | gated; returns egg, software detect, jars, properties, motd, java flag, recent builds |
| `/server-builder/plan` | POST | `{server, prompt, mode}` | prompt 8..2000; AI rate `builder`; returns plan (build) or analysis (ask); persists row `planned` |
| `/server-builder/builds?server=` | GET | server | build history for that server |
| `/server-builder/execute` | POST | `{server, build}` | build row id; status must be `planned`; runs synchronously step-by-step, returns final progress+health; addon rate `addon.builder` |
| `/server-builder/rollback` | POST | `{server, build}` | restores `backup_name`; audited |
| `/server-builder/templates` | GET | - | static template list pre-filling prompts (spec §16) |

No streaming/SSE in v1: execute is synchronous with per-step progress persisted to the
row (UI polls `/server-builder/builds` or shows the final result). Live console (spec §22)
is the existing console tab — unchanged.

## 5. AI Tool Plan (safe tools, spec §9)

Executor performs the plan itself; the AI never gets free-form commands. Mapping
plan-actions -> existing primitives (all inside jailed paths / allowlists):

| Plan action | Tool primitive used |
|---|---|
| `install_plugin` | `MarketplaceClient::download` + `putContent` (providers/hosts allowlisted, PK sniff, size cap) |
| `enable_plugin` / `disable_plugin` | jar rename `.disabled` (PluginsController logic) |
| `remove_plugin` | jar delete (audit + confirm name match) |
| `set_property` | allowlisted keys only (PropertiesController::ALLOW/INTS) |
| `set_motd` | `MotdController` rules (59-char, no control chars, Java gate) |
| `switch_jar` | `VersionsController` rules (jar exists, `startup.update`) |
| `run_command` | only whitelisted startup-safe commands (`say`, `whitelist`, `op`...) via denylist-filtered `DaemonCommandRepository` |
| `backup` | `BackupService` (DaemonBackupRepository) |
| `restart` | `DaemonPowerRepository::send('restart')` with `control.restart` |

### Plan JSON contract (strict; validated server-side before anything runs)

```json
{
  "summary": "one sentence",
  "software": {"name": "Paper", "reason": "why"},
  "minecraft_version": "1.21.x",
  "plugins": [{"name": "EssentialsX", "action": "install|enable|disable|remove|keep",
               "provider": "modrinth", "project": "<modrinth id>", "version": "<version id>",
               "reason": "..."}],
  "properties": {"max-players": 30, "difficulty": "normal", "pvp": true},
  "motd": "Medieval Lifestyle SMP",
  "commands": [],
  "restart": true,
  "risks": ["..."],
  "health": ["what to check after build"]
}
```

Validation (Phase 4 executor pre-flight):
- plugin action `install` requires `project`+`version` that exist via
  `MarketplaceClient::versions` (compatibility check: game_versions contains MC version
  family; loader matches detected software) — else plan is rejected with 422.
- duplicate-functionality conflict detection (spec §20): if a plan installs a plugin whose
  detected category (economy/claims/jobs/quests/shops/ranks) already exists among installed
  jars (by name heuristics table), mark `conflicts` in response; the client must re-POST
  with `resolve: {plugin: "keep"|"replace"}` before execute proceeds.
- properties filtered through the allowlist; MOTD through the 59-char sanitizer; commands
  through the whitelist; unknown fields dropped.

## 6. Security Plan

- Every endpoint: `auth` middleware + `Shared::resolveAccessibleServer` (owner/root/
  subuser-with-perm only) + `AddonGate::guard($request,'builder', $server)` semantics
  (reuse pattern; builder is not in AddonRegistry's visible hub, so guard via explicit
  checks in-controller to avoid registry churn: auth -> server -> perms -> rate -> audit).
  Actually: register `builder` as internal manifest row (like marketplace/trash) so
  `AddonGate::guard()` and rate `addon.builder` work uniformly.
- Perms: state read needs `file.read`; execute needs `file.create|file.update` +
  `control.restart` when restart is in the plan; jar switch needs `startup.update`.
- Rate limits: `builder` (AI window, 30/h default) for plan; `addon.builder` (60/h
  default) for execute/rollback.
- Audit: every execute step recorded via `AddonGate::audit(..., 'builder', <action>,
  <target>, meta)`; plan row id in meta. Never store prompts/keys of other users.
- Credentials: per-user `UserAiCredential` only; key decrypted server-side for the
  completion call, never serialized, never logged (request logs store no content).
- Input hardening: prompt length cap; plan JSON re-validated (AI output is untrusted
  data); PathGuard for any path; command whitelist; no shell; CSRF via blueprint group;
  XSS: all UI text escaped through `P.util.esc`.
- Failure handling (spec §27): AI unavailable -> 503 with clean message; step failure ->
  status `failed` + failure reason + rollback offer; no fake success anywhere.

## 7. Implementation Phases

1. Migration + `ServerBuild` model.
2. `BuildStateService` (server state snapshot) + `BuildPlanner` (prompt builder +
   strict-JSON plan validation) — unit-testable pure logic: `PlanValidator`,
   `ConflictDetector`, `TemplateCatalog`.
3. `BackupService` (DaemonBackupRepository wrapper) + `BuildExecutor` (step machine).
4. `ServerBuildController` + routes + internal manifest entry.
5. UI `public/js/builder-tab.js` + `builder-tab.css`: server tab (icon+label), panel
   with templates, prompt box, Ask/Build toggle, plan review (edit plan = edit prompt
   re-plan; conflicts dialog keep/replace), execute progress checklist, health report,
   build history. Mobile: single column, collapsible steps.
6. Unit tests (`private/tests/build_plan_test.php`) for PlanValidator/ConflictDetector/
   prompt scaffolding; puppeteer verification of tab + ask flow (mock: no creds ->
   clean 422) + deploy to live.
7. CHANGELOG/README/conf.yml version notes.

## 8. Out of scope (v1, deliberate)

- SSE streaming progress (poll instead); TPS sampling (no server-side source);
  CurseForge install path already exists via MarketplaceClient and is reused as-is;
  mod loader auto-conversion; scheduled re-optimization loops.
