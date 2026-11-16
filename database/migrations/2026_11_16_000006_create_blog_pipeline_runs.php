<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->date('slot_date')->nullable()->index();
            $table->string('slot', 5)->nullable()->index();
            $table->string('status', 20)->default('running')->index();
            $table->string('step', 40)->nullable();
            $table->json('completed_steps')->nullable();
            $table->string('topic', 500)->nullable();
            $table->string('topic_source', 30)->nullable();
            $table->foreignId('research_run_id')->nullable()->constrained('research_runs')->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->foreignId('blog_post_id')->nullable()->constrained('blog_posts')->nullOnDelete();
            $table->boolean('published')->default(false);
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_pipeline_runs');
    }
};
