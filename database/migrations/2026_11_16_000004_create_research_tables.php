<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->string('topic', 500);
            $table->string('query', 500)->nullable();
            $table->string('status', 20)->default('completed')->index();
            $table->unsignedInteger('sources_found')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_research_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->nullable()->constrained('blog_posts')->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->foreignId('research_run_id')->nullable()->constrained('research_runs')->nullOnDelete();
            $table->string('url', 500);
            $table->string('title', 500);
            $table->text('snippet')->nullable();
            $table->string('source_type', 30)->index();
            $table->string('query', 500)->nullable();
            $table->unsignedTinyInteger('relevance_score')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('research_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('provider', 30)->default('none');
            $table->text('api_key')->nullable();
            $table->json('rss_feeds')->nullable();
            $table->json('trusted_domains')->nullable();
            $table->unsignedTinyInteger('sources_per_source')->default(4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_settings');
        Schema::dropIfExists('blog_research_sources');
        Schema::dropIfExists('research_runs');
    }
};
