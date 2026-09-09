<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Intent-before-action audit rows for every destructive addon operation.
 *
 * @property int $id
 * @property int $user_id
 * @property int $server_id
 * @property string $addon
 * @property string $action
 * @property string $target
 * @property array|null $meta
 * @property \Carbon\Carbon $created_at
 */
class AddonAudit extends Model
{
    public $timestamps = false;

    protected $table = 'primus_addon_audit';

    protected $fillable = ['user_id', 'server_id', 'addon', 'action', 'target', 'meta'];

    protected $casts = [
        'user_id' => 'int',
        'server_id' => 'int',
        'meta' => 'array',
    ];
}
