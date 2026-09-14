<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('primus_trash', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36);
            $table->string('original_path', 500);
            $table->string('trash_name', 255);
            $table->boolean('is_dir')->default(false);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('deleted_by');
            $table->timestamp('created_at')->index();
            $table->timestamp('restored_at')->nullable();
            $table->timestamp('purged_at')->nullable();

            $table->index(['server_uuid', 'purged_at']);
            $table->index(['purged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('primus_trash');
    }
};
