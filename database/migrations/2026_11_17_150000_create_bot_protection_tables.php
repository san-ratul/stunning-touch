<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_traffic_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->string('category', 32);
            $table->string('bot_name', 64)->default('');
            $table->string('endpoint_group', 32);
            $table->string('action', 32);
            $table->unsignedInteger('requests')->default(0);

            $table->unique(['date', 'hour', 'category', 'bot_name', 'endpoint_group', 'action'], 'bot_metrics_bucket_unique');
            $table->index('date');
            $table->index(['category', 'date']);
            $table->index(['endpoint_group', 'date']);
        });

        Schema::create('bot_event_log', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->string('category', 32);
            $table->string('bot_name', 64)->default('');
            $table->string('endpoint_group', 32);
            $table->string('action', 32);
            $table->string('reason', 64);
            $table->unsignedInteger('requests')->default(0);
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['date', 'hour', 'category', 'bot_name', 'endpoint_group', 'action', 'reason'], 'bot_event_bucket_unique');
            $table->index('date');
            $table->index(['action', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_event_log');
        Schema::dropIfExists('bot_traffic_metrics');
    }
};
