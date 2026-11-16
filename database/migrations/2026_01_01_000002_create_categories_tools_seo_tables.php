<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('icon', 100)->nullable();
            // Not unique on purpose: several public tools may share one
            // registered, safe functional component.
            $table->string('component_key', 100)->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->string('how_to_title', 255)->nullable();
            $table->json('how_to_steps')->nullable();
            $table->json('faq')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('focus_keyword', 255)->nullable();
            $table->string('secondary_keywords', 500)->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->string('og_title', 255)->nullable();
            $table->string('og_description', 500)->nullable();
            $table->string('og_image', 500)->nullable();
            $table->string('twitter_title', 255)->nullable();
            $table->string('twitter_description', 500)->nullable();
            $table->string('twitter_image', 500)->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->string('schema_type', 100)->nullable();
            $table->json('schema_data')->nullable();
            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id']);
            $table->index('seoable_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('tools');
        Schema::dropIfExists('categories');
    }
};
