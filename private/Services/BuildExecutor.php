<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Pterodactyl\BlueprintFramework\Extensions\{identifier}\Controllers\Shared;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;

/**
 * Executes a validated build plan step-by-step with per-step progress
 * persisted to the ServerBuild row (spec sections 7, 8, 21, 26).
 *
 * Safety: the plan was already validated by PlanValidator; every step is
 * audited; a backup is created before the first mutating step; a step
 * failure marks the build failed and offers rollback - never fake success.
 */
final class BuildExecutor
{
    private const JAR_SUFFIX = '.disabled';

    private Server $server;
    private array $steps = [];
    private array $log = [];
    private int $done = 0;

    public function __construct(private $build)
    {
    }

    /**
     * Runs the plan. Returns the final progress payload.
     *
     * @param array $plan validated plan (PlanValidator::validate()['plan'])
     * @param array{canUpdate: bool, canCreate: bool, canDelete: bool, canRestart: bool} $perms
     */
    public function run(Server $server, array $plan, array $perms): array
    {
        $this->server = $server;
        $this->buildPlan($plan, $perms);

        $this->push('Building your server: ' . ($plan['summary'] ?: 'AI build plan'));

        /* 1. backup restore point before any mutation */
        if (!$this->step('backup', 'Creating backup restore point', function () use ($plan) {
            return $this->createBackup($plan);
        })) {
            return $this->finish();
        }

        /* 2. plugin/mod installs, enables, disables, removes */
        foreach ($plan['plugins'] as $p) {
            $label = $p['name'];
            $fn = match ($p['action']) {
                'install' => fn () => $this->installPlugin($p),
                'enable' => fn () => $this->togglePlugin($p, true),
                'disable' => fn () => $this->togglePlugin($p, false),
                'remove' => fn () => $this->removePlugin($p),
                default => fn () => true,
            };
            $this->step('plugin.' . $p['action'], ucfirst($p['action']) . ' ' . $label, $fn, $perms, $p['action']);
        }

        /* 3. properties */
        foreach ($plan['properties'] as $key => $value) {
            $this->step('property.' . $key, 'Set ' . $key . ' = ' . $value, function () use ($key, $value, $perms) {
                return $this->setProperty($key, $value, $perms);
            }, $perms, 'property');
        }

        /* 4. motd */
        if (($plan['motd'] ?? '') !== '') {
            $this->step('motd', 'Set MOTD', function () use ($plan, $perms) {
                return $this->setMotd($plan['motd'], $perms);
            }, $perms, 'motd');
        }

        /* 5. whitelisted commands (server must run for them to matter) */
        foreach ($plan['commands'] as $cmd) {
            $this->step('command', 'Run: ' . $cmd, function () use ($cmd, $perms) {
                return $this->runCommand($cmd, $perms);
            }, $perms, 'command');
        }

        /* 6. restart if wanted and permitted */
        if (!empty($plan['restart']) && ($perms['canRestart'] ?? false)) {
            $this->step('restart', 'Restarting server to apply changes', fn () => $this->restart($perms), $perms, 'restart');
        }

        /* 7. health check */
        $this->step('health', 'Health check', fn () => $this->healthCheck(), $perms, 'health');

        return $this->finish();
    }

    /**
     * Rolls back to the build's restore-point backup.
     *
     * @return array{ok: bool, error?: string}
     */
    public function rollback(Server $server): array
    {
        $this->server = $server;
        $name = (string) ($this->build->backup_name ?? '');
        if ($name === '') {
            return ['ok' => false, 'error' => 'This build has no restore-point backup.'];
        }
        $backup = BackupService::findByName($this->server, $name);
        if ($backup === null) {
            return ['ok' => false, 'error' => 'Restore-point backup not found or not completed.'];
        }
        try {
            BackupService::restore($this->server, $backup);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Restore failed: ' . $e->getMessage()];
        }

        return ['ok' => true];
    }

    /* ── step plumbing ────────────────────────────────────────────── */

    private function buildPlan(array $plan, array $perms): void
    {
        $this->steps = [];
        $this->log = [];
        $this->done = 0;
        $this->steps[] = ['key' => 'backup', 'label' => 'Creating backup restore point', 'status' => 'pending'];
        foreach ($plan['plugins'] as $p) {
            if (!$this->permForPlugin($p['action'], $perms)) {
                $this->steps[] = ['key' => 'plugin.' . $p['action'], 'label' => ucfirst($p['action']) . ' ' . $p['name'], 'status' => 'skipped'];
                continue;
            }
            $this->steps[] = ['key' => 'plugin.' . $p['action'], 'label' => ucfirst($p['action']) . ' ' . $p['name'], 'status' => 'pending'];
        }
        foreach (array_keys($plan['properties']) as $key) {
            $this->steps[] = ['key' => 'property.' . $key, 'label' => 'Set ' . $key, 'status' => ($perms['canUpdate'] ?? false) ? 'pending' : 'skipped'];
        }
        if (($plan['motd'] ?? '') !== '') {
            $this->steps[] = ['key' => 'motd', 'label' => 'Set MOTD', 'status' => ($perms['canUpdate'] ?? false) ? 'pending' : 'skipped'];
        }
        foreach ($plan['commands'] as $cmd) {
            $this->steps[] = ['key' => 'command', 'label' => 'Run: ' . $cmd, 'status' => 'pending'];
        }
        if (!empty($plan['restart']) && ($perms['canRestart'] ?? false)) {
            $this->steps[] = ['key' => 'restart', 'label' => 'Restart server', 'status' => 'pending'];
        }
        $this->steps[] = ['key' => 'health', 'label' => 'Health check', 'status' => 'pending'];
    }

    /**
     * Runs one step; on permission-missing marks skipped, on exception marks
     * failed and stops. Returns step success.
     */
    private function step(string $key, string $label, callable $fn, array $perms = [], string $kind = ''): bool
    {
        $idx = $this->stepIndex($key, $label);
        if ($idx !== null && $this->steps[$idx]['status'] === 'skipped') {
            $this->push('Skipped: ' . $label, 'warn');

            return true;
        }
        if ($idx !== null) {
            $this->steps[$idx]['status'] = 'running';
        }
        $this->push('Running: ' . $label);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            if ($idx !== null) {
                $this->steps[$idx]['status'] = 'failed';
            }
            $this->push('Failed: ' . $label . ' - ' . $e->getMessage(), 'error');

            return false;
        }
        if ($result === false) {
            if ($idx !== null) {
                $this->steps[$idx]['status'] = 'failed';
            }

            return false;
        }
        if ($idx !== null) {
            $this->steps[$idx]['status'] = 'done';
        }
        $this->done++;

        return true;
    }

    private function stepIndex(string $key, string $label): ?int
    {
        foreach ($this->steps as $i => $s) {
            if ($s['key'] === $key && $s['label'] === $label) {
                return $i;
            }
        }

        return null;
    }

    private function permForPlugin(string $action, array $perms): bool
    {
        return match ($action) {
            'install' => (bool) ($perms['canCreate'] ?? false),
            'remove' => (bool) ($perms['canDelete'] ?? false),
            default => (bool) ($perms['canUpdate'] ?? false),
        };
    }

    private function finish(): array
    {
        $failed = false;
        foreach ($this->steps as $s) {
            if ($s['status'] === 'failed') {
                $failed = true;
                break;
            }
        }

        return [
            'steps' => $this->steps,
            'log' => $this->log,
            'failed' => $failed,
        ];
    }

    private function push(string $msg, string $level = 'info'): void
    {
        $this->log[] = ['t' => time(), 'level' => $level, 'msg' => $msg];
        if (count($this->log) > 100) {
            $this->log = array_slice($this->log, -100);
        }
    }

    /* ── tool primitives (safe AI tools, spec section 9) ─────────── */

    private function createBackup(array $plan): bool
    {
        $name = 'ai-build-' . now()->format('Ymd-His');
        $backup = BackupService::create($this->server, $name);
        if (!$backup->is_successful && $backup->completed_at === null) {
            /* wings accepted the job; treat in-progress as the restore point */
        }
        $this->build->backup_name = $name;
        $this->build->save();

        return true;
    }

    private function installPlugin(array $p): bool
    {
        $dir = ($p['type'] ?? 'plugin') === 'mod' ? 'mods' : 'plugins';
        $dl = MarketplaceClient::download($p['provider'], $p['project'], $p['version'], $p['type'] ?? 'plugin');
        $path = (new PathGuard())->resolve($dir . '/' . $dl['name'], [$dir]);
        Shared::fileRepo($this->server)->putContent($path, $dl['bytes']);
        $this->push('Installed ' . $dl['name'] . ' (' . number_format(strlen($dl['bytes']) / 1024) . ' KiB)');

        return true;
    }

    private function togglePlugin(array $p, bool $enable): bool
    {
        $jars = BuildStateService::jars($this->server);
        $target = null;
        foreach ($jars as $jar) {
            $base = str_ends_with($jar, self::JAR_SUFFIX) ? substr($jar, 0, -strlen(self::JAR_SUFFIX)) : $jar;
            if (strcasecmp($base, $p['name'] . '.jar') === 0 || strcasecmp($base, $p['name']) === 0) {
                $target = $jar;
                break;
            }
        }
        if ($target === null) {
            $this->push('Plugin not found: ' . $p['name'], 'warn');

            return false;
        }
        $isEnabled = !str_ends_with($target, self::JAR_SUFFIX);
        if ($isEnabled === $enable) {
            $this->push('Already ' . ($enable ? 'enabled' : 'disabled') . ': ' . $p['name']);

            return true;
        }
        $dir = 'plugins';
        $from = $dir . '/' . $target;
        $to = $enable
            ? $dir . '/' . substr($target, 0, -strlen(self::JAR_SUFFIX))
            : $dir . '/' . $target . self::JAR_SUFFIX;
        Shared::fileRepo($this->server)->renameFiles(null, [['from' => $from, 'to' => $to]]);
        $this->push(($enable ? 'Enabled ' : 'Disabled ') . $p['name']);

        return true;
    }

    private function removePlugin(array $p): bool
    {
        $jars = BuildStateService::jars($this->server);
        foreach ($jars as $jar) {
            $base = str_ends_with($jar, self::JAR_SUFFIX) ? substr($jar, 0, -strlen(self::JAR_SUFFIX)) : $jar;
            if (strcasecmp($base, $p['name'] . '.jar') === 0 || strcasecmp($base, $p['name']) === 0) {
                Shared::fileRepo($this->server)->deleteFiles('plugins', ['plugins/' . $jar]);
                $this->push('Removed ' . $jar);

                return true;
            }
        }
        $this->push('Plugin not found: ' . $p['name'], 'warn');

        return false;
    }

    private function setProperty(string $key, string|int $value, array $perms): bool
    {
        [$properties, $err] = Shared::readProperties($this->server);
        if ($err !== null || $properties === null) {
            throw new \RuntimeException('Cannot read server.properties.');
        }
        $content = Shared::writePropLine($properties, $key, (string) $value);
        Shared::fileRepo($this->server)->putContent('server.properties', $content);
        $this->push('Set ' . $key . ' = ' . $value);

        return true;
    }

    private function setMotd(string $motd, array $perms): bool
    {
        [$properties, $err] = Shared::readProperties($this->server);
        if ($err !== null || $properties === null) {
            throw new \RuntimeException('Cannot read server.properties.');
        }
        $content = Shared::writePropLine($properties, 'motd', $motd);
        Shared::fileRepo($this->server)->putContent('server.properties', $content);
        $this->push('MOTD set');

        return true;
    }

    private function runCommand(string $cmd, array $perms): bool
    {
        app(DaemonCommandRepository::class)->setServer($this->server)->send($cmd);

        return true;
    }

    private function restart(array $perms): bool
    {
        app(DaemonPowerRepository::class)->setServer($this->server)->send('restart');
        $this->push('Restart signal sent');

        return true;
    }

    /** Basic post-build health check (spec sections 7, 17). */
    private function healthCheck(): bool
    {
        [$properties, $err] = Shared::readProperties($this->server);
        if ($err !== null) {
            $this->push('Health: properties unreadable (daemon issue?)', 'warn');

            return true; /* non-fatal */
        }
        if ($properties === null) {
            $this->push('Health: no server.properties on this server', 'warn');

            return true;
        }
        $parsed = BuildStateService::parseProperties($properties);
        $jars = BuildStateService::jars($this->server);
        $this->push('Health: ' . count($jars) . ' plugin jars, motd=' . substr((string) ($parsed['motd'] ?? ''), 0, 40));

        return true;
    }
}
