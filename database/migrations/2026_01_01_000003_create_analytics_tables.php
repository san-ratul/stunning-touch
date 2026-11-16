<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_hash', 64)->unique();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->string('page_url', 500);
            $table->string('page_type', 50)->nullable()->index();
            $table->string('referrer', 500)->nullable();
            $table->string('referrer_domain', 255)->nullable()->index();
            $table->string('device_type', 20)->nullable()->index();
            $table->string('browser', 50)->nullable()->index();
            $table->string('country_code', 5)->nullable()->index();
            $table->timestamp('visited_at')->index();
        });

        Schema::create('tool_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 50)->default('run');
            $table->boolean('success')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tool_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('tool_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);

            $table->unique(['tool_id', 'date']);
        });

        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('visitors')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('tool_usage')->default(0);
            $table->unsignedInteger('tool_success')->default(0);
            $table->unsignedInteger('tool_failed')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
        Schema::dropIfExists('tool_usage_daily');
        Schema::dropIfExists('tool_usage');
        Schema::dropIfExists('page_views');
        Schema::dropIfExists('visitors');
    }
};
