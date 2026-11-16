<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('auth_mode', 20)->default('bearer');
            $table->string('basic_auth_user', 255)->nullable();
            $table->text('basic_auth_password')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(900);
            $table->unsignedInteger('connect_timeout_seconds')->default(15);
            $table->unsignedTinyInteger('max_retries')->default(2);
            $table->unsignedInteger('retry_backoff_ms')->default(2000);
            $table->unsignedInteger('context_length')->default(16384);
            $table->string('keep_alive', 50)->default('10m');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn([
                'auth_mode',
                'basic_auth_user',
                'basic_auth_password',
                'timeout_seconds',
                'connect_timeout_seconds',
                'max_retries',
                'retry_backoff_ms',
                'context_length',
                'keep_alive',
            ]);
        });
    }
};
