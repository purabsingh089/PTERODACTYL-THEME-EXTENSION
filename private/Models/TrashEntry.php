<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier}\Models;

use Illuminate\Database\Eloquent\Model;

class TrashEntry extends Model
{
    public $timestamps = false;

    protected $table = 'primus_trash';

    protected $fillable = [
        'server_uuid',
        'original_path',
        'trash_name',
        'is_dir',
        'size',
        'deleted_by',
        'created_at',
        'restored_at',
        'purged_at',
    ];

    protected $casts = [
        'is_dir' => 'boolean',
        'size' => 'integer',
        'deleted_by' => 'integer',
        'created_at' => 'datetime',
        'restored_at' => 'datetime',
        'purged_at' => 'datetime',
    ];
}
