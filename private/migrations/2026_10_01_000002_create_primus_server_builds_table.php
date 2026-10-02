<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('primus_server_builds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->text('prompt');
            $table->string('mode', 16)->default('build');
            $table->json('plan')->nullable();
            $table->string('status', 24)->default('planned');
            $table->json('progress')->nullable();
            $table->json('steps')->nullable();
            $table->string('backup_name', 191)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('primus_server_builds');
    }
};
