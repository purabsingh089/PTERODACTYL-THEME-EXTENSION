# Primus UX Consolidation — Design

Date: 2026-09-14
Status: Approved (user: "Continue or don't stop until you builded")
Build mode: sequential, no pause (user instruction from prior batch applies)

## Problem

The 13-card Addons hub has redundancy and misplacement:

- **Marketplace** duplicates install capability soon to live inside Plugin
  Installer and Mod Manager. Three addons occupy the jars-in-`plugins//mods/`
  space; Mod Manager is an exact subset of Plugin Manager's listing today.
- **Trash Bin** only lists root archives. It does not intercept the stock file
  manager's direct-to-Wings deletes, so "trash" protects nothing.
- **Advanced Console** is a separate panel while the real console page gets
  no command history, presets, or output filtering.
- **Traffic Manager** shows allocations (nothing to do with traffic).
- **MOTD** exists as three UIs (subnav tab, hub panel, AI panel) writing the
  same `motd=` line.

## Goals

1. File-manager deletions move to trash (DB-tracked, 14-day auto-purge),
   restorable from a Trash UI inside the Files page.
2. Stock console upgraded in place: history, preset bar, output filter,
   player-count chip.
3. Traffic Manager becomes Player Stats: online players, join totals,
   sessions, live feed, player actions, allocations section.
4. Mod Manager + Plugin Installer get built-in search/install
   (Modrinth/CurseForge) with auto-detected modloader/MC-version filters,
   user-overridable.
5. Single MOTD UI: subnav tab gains the AI-generate button; hub cards retire.
6. Hub shrinks 13 → 7 cards; retired controllers/routes removed.

## Non-Goals

- Custom terminal replacement (xterm stays).
- Server plugin for stats (log parsing + `list` only).
- Trash for non-file deletions (worlds/plugins/mods delete paths adopt
  trash-move; power actions unchanged).
- New admin panels beyond the existing extension toggle list.

## Architecture

### Hub after redesign (7 cards)

Removed (6): Marketplace, MOTD Manager, AI MOTD, Trash Bin, Advanced
Console, Traffic Manager. Merged (1): Player Manager actions fold into
Player Stats. Remaining cards:

| Card | Perms | Source |
|---|---|---|
| Plugin Installer | file.read | PluginsController + search |
| Mod Manager | file.read | ModsController + search |
| World Manager | file.read | unchanged |
| Player Stats | control.console + allocation.read | new PlayerStatsController (absorbs Players/Traffic) |
| Version Manager | startup.read | unchanged |
| Icon Manager | file.read | unchanged |
| Properties Manager | file.read | unchanged |

### Trash system

- Table `primus_trash`: id, server_uuid, original_path, trash_name,
  is_dir, size, deleted_by, deleted_at, restore_of (nullable self-ref for
  restores), purged_at (nullable).
- Intercept: `public/js/file-trash.js` wraps `window.fetch` on
  `/server/{id}/files` pages. Calls to `/api/client/servers/{id}/files/delete`
  (the React file manager's DELETE) are captured; JS instead POSTs
  `trash/add` with the file list; backend moves each entry into
  `.primus-trash/` via Wings rename (jail-checked), inserts DB rows, returns
  `{ok, moved}`. JS resolves the original request with a synthetic Response
  so the React UI refreshes its listing; toast shows "Moved to trash — Undo".
- Trash UI: toolbar button in the file-manager header (injected via
  MutationObserver, existing pattern) opens a side panel: item list (name,
  original path, deleted-by, age/purge countdown), Restore / Delete forever /
  Empty trash. Restore moves back via Wings rename; conflicts (path exists)
  restore to `restored-{name}` suffix.
- Purge: hourly cron (existing extension cron slot) deletes DB rows older
  than 14 days from Wings + DB. Missing Wings files are dropped silently.
- All trash mutations: AddonGate::guard('trash'), perms file.update,
  rateLimited, audit before Wings.
- Addon delete paths (plugins/mods/worlds) call the same `trash/add`
  service so behavior is uniform.
- Stock file manager delete API shape: Pterodactyl sends
  `POST /api/client/servers/{id}/files/delete` with `{ root, files[] }` —
  the intercept reroutes this exact request.

### Console upgrade

`public/js/console-upgrade.js` on `/server/{id}` (console root page):

- Command history: ↑/↓ through past commands (localStorage per server),
  persisted last 100.
- Preset bar above the stock input: `list`, `save-all`, `time set day`,
  `time set night`, `clear`, `weather clear` chips + 5 custom slots
  (localStorage). Chip click sends via stock input; deny-list (stop/restart/
  end/halt/shutdown/kill-server) blocked with error toast (parity with the
  retired console addon).
- Output filter box in terminal chrome: substring match highlights rows,
  non-matches dim. Clears on Escape.
- Player-count chip in terminal chrome: updates from `socket:stats` frames
  when a players field exists; falls back to periodic `list` parse off the
  xterm buffer when the server is running (resource-graphs.js already wraps
  the socket + xterm DOM).
- Existing Primus charts/power styling untouched.

### Player Stats (PlayerStatsController)

GET `player-stats?server=` returns:

- `online`: running now — parse the xterm? No: backend sends `list` via
  DaemonCommandRepository on each open (rate-limited to one per 60s),
  parses the newest `list` output from the tail of `logs/latest.log` read
  through Wings. Names filtered through NAME_RE.
- `joins`: parse `logs/latest.log` join (`joined the game`) and leave
  (`left the game`) lines: total unique joins, per-player counts,
  first/last seen.
- `sessions`: join→leave pairing; per-player session count + durations;
  aggregate playtime.
- `feed`: last 50 chat/death/join/leave lines.
- `players`: merged cards — stats (when known), online state, action
  buttons (kick/ban/pardon/op/deop/whitelist) gated by perms + running.
- `allocations`: allocation list + `canUpdate` (perm allocation.update)
  merged from TrafficController; notes POST endpoint reused.
- Log-rotation: `latest.log` only (v1) — documented limitation.
- Offline: `{ok, running:false, ...}` — UI shows empty-state, actions
  disabled (existing javaEmpty pattern).
- Non-Java servers: `{ok, java:false}` — same empty state.

### Mod Manager / Plugin Installer search

- Shared `MarketplaceClient` service (unchanged engine).
- Search GET `mods/search` / `plugins/search`: `{q, loader?, version?,
  source?}`. Mods default loader/version from auto-detect; plugins
  (Paper/Spigot/Purpur) detect from `server.properties`/startup. Override
  chips per design.
- Auto-detect: scan root + mods/ jar names for
  `fabric|quilt|neoforge|forge` + MC version regex; fallback: startup
  variables (SERVER_JARFILE etc.); default: no filter.
- Install POST reuses Marketplace install pipeline (version pick modal →
  install) with `into` = mods/ or plugins/.
- Installed lists unchanged (toggle/upload). Deletes now route through
  trash service. Plugin Installer keeps BOTH plugins/ + mods/ listing
  removed — v1 splits: Plugin Installer = plugins/ only; Mod Manager =
  mods/ only (resolves the overlap).
- Search UI: "Search" + "Installed" tabs in each manager panel.

### MOTD merge

- `public/js/motd.js` gains "Generate with AI": prompt textarea → POST
  `motd/ai/generate` → preview renders suggestion (not applied) → user
  clicks Apply (existing save path) or Discard.
- Endpoint lives in MotdController; AiMotdController retires.
- Hub cards for motd + aimotd removed; `MotdAddonController` retires.

### Backend cleanup

- AddonRegistry: 13 → 9 manifests (see list). Admin toggle syncs.
- Retire: MarketplaceController card (service stays), TrashController
  (superseded by TrashService), ConsoleAddonController, MotdAddonController,
  AiMotdController, TrafficController (merged into PlayerStatsController),
  PlayersController (merged into PlayerStatsController).
- New: PlayerStatsController, TrashService (+ migration), cron purge.
- routes.php: remove retired endpoints; add trash/*, player-stats,
  mods/search, plugins/search, motd/ai/generate.
- CSS: trash panel, preset bar, filter box, stats styles appended to
  addons.css/widgets.css; console pieces to console.css (new small file) or
  widgets.css.

## Data Flow

Delete: React FM → fetch-patch → POST trash/add → AddonGate → Wings
rename into .primus-trash/ → DB row → audit → toast/Undo.
Restore: panel → POST trash/restore → gate → Wings rename back → row
  marked restored (purged_at=null, restore_of set) → listing refresh.
Stats open: panel → GET player-stats → gate → log parse + optional `list`
  send → response → render.

## Error Handling

- Wings rename failure on trash-add: 502-style error surfaced, no DB row.
- Restore conflict: suffix fallback `restored-{name}`; if that fails, error.
- Log file missing: stats return zeros, feed empty, UI notes "No log data".
- Intercept JS: if the stock FM API shape doesn't match (future
  Pterodactyl update), deletes pass through untouched (fail-open to stock
  behavior) with a console.warn — trash UI still works for entries created
  via addon paths.

## Testing

- API suite (curl + CSRF, existing pattern): trash add/restore/delete/
  empty/purge-cron; player-stats (offline + Java + non-Java); mods/plugins
  search + install; motd ai generate + apply; console preset deny-list.
- Puppeteer: Files page delete → toast → trash panel → restore; console
  history/presets/filter; manager Search tabs; MOTD AI flow; hub 9 cards.
- Regression: worlds/plugins/properties/version/icons panels unchanged;
  rate limits + gates still enforce (403/429 cases).

## Rollout / Compatibility

- Migration runs via extension install/update mechanism.
- JS cache-bust bump; wrapper gains file-trash.js + console-upgrade.js.
- Old trash rows (none exist — feature new) N/A.
- Live deploy to `.blueprint/extensions/primus/` per established process.
