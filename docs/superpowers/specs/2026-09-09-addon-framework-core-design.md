# Addon Framework Core + Plugin Manager — Design Spec

**Status:** Approved (Approach A — Central AddonRegistry)
**Date:** 2026-09-09
**Supersedes:** none. First sub-project of the 12-addon framework decomposition.
**Follows:** `2026-09-07-motd-creator-design.md` (MOTD Creator stays standalone this cycle; it may migrate onto the registry later without changing its public API).

## 1. Goal

Ship the production core of the all-in-one addon framework: a central `AddonRegistry`, one security choke point (`AddonGate`), a path jail (`PathGuard`), a persistent audit log, a dedicated admin page for enable/disable + audit viewing, a client "Addons" subnav tab with an overlay hub — and the first real addon, the **Plugin Manager**, built entirely on the registry as the pilot for the 11 remaining managers + 6 AI tools that arrive as later sub-projects.

## 2. Scope

**In scope:**
- `AddonRegistry` service with declarative manifests; `plugins` manifest live, 11 future managers registered as `comingSoon` (visible roadmap cards, no fake buttons).
- `AddonGate` static helper: enabled-check + permission-check + audit-write in one call chain.
- `PathGuard` service: normalized path jail for every addon file operation.
- Migration `primus_addon_audit` table (intent-before-action audit rows).
- Backend: `GET/POST /addons/plugins{,/toggle,/delete,/upload}` (all gated).
- Client: `addons.js` + `addons.css` — Addons subnav tab, overlay hub, Plugin Manager panel (jar table, toggle, delete-with-confirm, drag-drop upload).
- Admin: dedicated Addons page (`admin/addons.blade.php`) — per-addon enable cards + filterable audit log viewer, new nav entry, existing admin styling.

**Out of scope (later sub-projects):** the 11 remaining managers — World, Mod, Player, Traffic, Advanced Console, Version, Icon, Trash Bin, Properties, MOTD (migration of the existing standalone feature), and AI-MOTD — plus all 6 AI tools and the AI approval-gate scaffolding.

## 3. Architecture

Every request flows:

```
auth middleware
  → AddonGate::check($addonId, $request, $server)
      → registry enabled? (ThemeSetting addons.<id>.enabled)
      → user can use? (root admin | owner | subuser with manifest perms)
  → controller
      → PathGuard::resolve(...) for every file path argument
      → mutating action: AddonGate::audit() BEFORE the Wings call
      → Wings repository call
```

- **Log-intent-then-act:** destructive actions write their audit row before the Wings mutation, so even a crashed action leaves a trace.
- **Never trust the frontend:** server id, file names, and every path are resolved/validated server-side via `Shared::resolveAccessibleServer` + `PathGuard`; the frontend is a thin view.
- **No API keys reach the browser; no AI this cycle.**

### 3.1 Data

- Registry state: `ThemeSetting` rows `addons.<id>.enabled` (default enabled for shipped addons; `plugins` defaults on). No new table.
- New migration `primus_addon_audit`: `id BIGINT PK`, `user_id INT`, `server_id INT`, `addon VARCHAR(32)`, `action VARCHAR(32)`, `target VARCHAR(255)`, `meta JSON NULL`, `created_at TIMESTAMP`, indexes on `(server_id, created_at)`, `(user_id, created_at)`, `(addon, created_at)`.

## 4. Backend components

### 4.1 AddonRegistry (Service)

Static manifest map; each entry:

```php
[
  'id' => 'plugins',
  'title' => 'Plugin Manager',
  'description' => 'List, enable, disable, upload and delete plugin/mod jars.',
  'category' => 'files',            // files | console | ai | management
  'perms' => 'file.read',           // dotted, verified against Permission.php
  'icon' => 'plugins',              // existing inline SVG set name
  'comingSoon' => false,
]
```

Future 11 managers registered with `comingSoon => true` (id/title/category only). `all()`, `enabled()`, `manifest($id)` accessors. Admin UI toggles write `ThemeSetting`.

### 4.2 AddonGate (static, Controllers namespace — same pattern as Shared)

- `enabled(string $addonId): bool`
- `canUse(User $user, Server $server, ?Subuser $subuser, string $addonId): bool` — root admin/owner always; subuser needs manifest perms (fallback `file.read`).
- `audit(User, Server, string $addon, string $action, string $target, array $meta = []): void` — writes the row.
- `check(...)` composite used by route closures: returns null on pass, or a `JsonResponse` (404 disabled / 403 forbidden) on fail — one choke point for error shape.

### 4.3 PathGuard (Service)

`resolve(string $input, array $allowedRoots = []): string` — accepts a relative path, rejects: `..` segments, null bytes, absolute paths, backslashes, empty, resolved escapes; optional restriction to the given sub-root(s) (e.g. `['plugins', 'mods']` for the pilot). Returns the cleaned normalized relative path. Pure function — unit-testable via tinker without Wings.

### 4.4 PluginsController (pilot)

| Route | Perms | Behavior |
| --- | --- | --- |
| `GET /addons/plugins?server=` | gate + `file.read` | Lists `plugins/` + `mods/` via `getDirectory`; returns `{name, size, modified, enabled, dir}` per jar (`.jar.disabled` = disabled) |
| `POST /addons/plugins/toggle` | gate + `file.update` | Renames `X.jar` ↔ `X.jar.disabled` via `renameFiles`; audits `toggle` |
| `POST /addons/plugins/delete` | gate + `file.delete` | Requires server-side `confirm` field equal to the file name; `deleteFiles`; audits `delete` |
| `POST /addons/plugins/upload` | gate + `file.create` | `.jar` extension only, size cap (setting `addons.plugins.max_upload_mib`, default 100); audits `upload` |
| `GET /addons` | auth only | Hub payload: enabled+visible manifests for this server context (includes per-addon `canUse` for current user) |

Rate limit on the three mutating endpoints: `Shared::rateLimited($userId, 'addon.plugins')`, default 60/h.

## 5. Client components

- **`addons.js`** — page-scoped like `motd.js`: same subnav insertion pattern ("Addons" tab, after MOTD's), same `page:view` stale-tab removal. Overlay hub: fetches `GET addons?server=`, renders card grid; enabled+usable cards clickable → swap overlay body to that addon's panel; `comingSoon` render as locked cards. Plugin Manager panel: jar table (name, size, modified, state pill), per-row enable/disable toggle, delete with typed-confirm modal, drag-drop upload zone reusing `pr-dropzone` visual pattern. Read-only users see the table without action buttons.
- **`addons.css`** — token-driven (same conventions as `motd.css`); grid hub, table, pills, locked cards.
- **Admin page** — `admin/addons.blade.php` + nav entry: card per addon (toggle, title, description, category tag), audit log viewer (server/user/action filters, newest-first, paginated 50). Server-rendered Blade in the existing admin wrapper — no React changes.

## 6. Security review checklist

- [ ] All addon routes behind `auth` + `AddonGate`.
- [ ] PathGuard validates every path; no client-supplied absolute paths reach Wings.
- [ ] Permissions dotted and verified against live `Permission.php` constants.
- [ ] Audit row written before every destructive Wings call.
- [ ] Upload: `.jar` only, size-capped, random temp name, final path via PathGuard.
- [ ] Delete: server-side confirm-name + client typed confirmation.
- [ ] Rate limits on mutations.
- [ ] No secrets in any client payload; `GET /addons` exposes only manifest fields + canUse booleans.

## 7. Testing strategy

No test framework in repo — verification matches the MOTD cycle (curl + Puppeteer, PASS/FAIL, non-zero exit on failure):

1. **PathGuard tinker suite:** valid, `..`, absolute, null byte, backslash, jail-escape (`plugins/../server.properties`), empty — 8 cases.
2. **Gate suite (curl):** disabled addon 404 JSON; subuser without perms 403; enabled+owner 200; `GET /addons` shape.
3. **Plugins CRUD suite (curl):** seeded fixture jars (via tinker `putContent`), list, toggle → read-back `.jar.disabled`, toggle back, delete with/without confirm, upload (small text file disguised — expect rejection; real small jar fixture → accept), rate-limit smoke.
4. **Puppeteer UI suite:** Addons tab, hub cards (plugins live, coming-soon locked), panel open, toggle persists (read-back), delete confirm modal, upload dropzone, read-only subuser view (no action buttons), MOTD regression (tabs coexist), stale-tab removal on cross-server navigation.
5. **Admin suite:** page renders, toggles persist, audit rows appear for the actions performed above.

## 8. Rollout & packaging

- Files added under `private/Controllers`, `private/Services`, `private/Models`, `private/migrations`, `public/js|css`, `admin/`; wrapper gains 2 lines (addons.css + addons.js).
- Live deployment via the established sed/cp sync + `php artisan migrate` for the audit table; workspace keeps placeholders (`{identifier}`, `{webroot/public}`).
- `build.sh` → `dist/primus.blueprint` (dist never committed).
- Commit: only the framework files, repo message style.
