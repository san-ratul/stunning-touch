<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 100)->default('openai-compatible');
            $table->string('endpoint', 500)->nullable();
            $table->text('api_key')->nullable();
            $table->string('model', 100)->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 500)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->foreignId('blog_post_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('frequency', 20)->default('daily');
            $table->string('preferred_time', 5)->default('09:00');
            $table->string('timezone', 50)->default('Asia/Dhaka');
            $table->json('categories')->nullable();
            $table->json('topics')->nullable();
            $table->string('keywords', 500)->nullable();
            $table->boolean('auto_publish')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('static_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_pages');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('automation_settings');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('ai_settings');
    }
};
