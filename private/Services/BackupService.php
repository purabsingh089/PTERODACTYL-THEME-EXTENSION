<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Services;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Services\Backups\InitiateBackupService;

/**
 * Thin wrapper over the panel's own backup services so the builder can
 * create a restore point before executing a plan (spec section 21).
 */
final class BackupService
{
    /**
     * @throws \Throwable when the panel refuses (limit reached, daemon down)
     */
    public static function create(Server $server, string $name): Backup
    {
        return app(InitiateBackupService::class)
            ->setIgnoredFiles([])
            ->handle($server, $name, true);
    }

    /**
     * Restores a successful local backup through the wings repository,
     * mirroring the stock client restore flow.
     *
     * @throws \Throwable
     */
    public static function restore(Server $server, Backup $backup): void
    {
        if (!$backup->is_successful || $backup->disk === Backup::ADAPTER_AWS_S3) {
            throw new \RuntimeException('Only successful local backups can be restored by the builder.');
        }
        $server->forceFill(['status' => Server::STATUS_RESTORING_BACKUP])->save();
        try {
            app(DaemonBackupRepository::class)
                ->setServer($server)
                ->restore($backup, null, false);
        } catch (\Throwable $e) {
            $server->forceFill(['status' => null])->save();
            throw $e;
        }
    }

    /** @throws \Throwable */
    public static function delete(Server $server, Backup $backup): void
    {
        app(DeleteBackupService::class)->handle($backup);
    }

    /** Most recent successful backup of a server, or null. */
    public static function latest(Server $server): ?Backup
    {
        /** @var Backup|null $b */
        $b = Backup::query()
            ->where('server_id', $server->id)
            ->where('is_successful', true)
            ->orderByDesc('id')
            ->first();

        return $b;
    }

    /** Finds a builder-created backup by exact name. */
    public static function findByName(Server $server, string $name): ?Backup
    {
        /** @var Backup|null $b */
        $b = Backup::query()
            ->where('server_id', $server->id)
            ->where('name', $name)
            ->where('is_successful', true)
            ->first();

        return $b;
    }
}
