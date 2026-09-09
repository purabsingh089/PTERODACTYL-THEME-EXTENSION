<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('primus_addon_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id');
            $table->string('addon', 32);
            $table->string('action', 32);
            $table->string('target', 255);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['server_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['addon', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('primus_addon_audit');
    }
};
