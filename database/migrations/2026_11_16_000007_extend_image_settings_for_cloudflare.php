<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_settings', function (Blueprint $table) {
            $table->string('account_id', 100)->nullable();
            $table->unsignedTinyInteger('steps')->default(4);
            $table->unsignedSmallInteger('width')->default(1024);
            $table->unsignedSmallInteger('height')->default(1024);
            $table->unsignedTinyInteger('daily_limit')->default(4);
            $table->unsignedTinyInteger('max_retries')->default(2);
            $table->unsignedSmallInteger('timeout_seconds')->default(120);
        });

        // One row per Cloudflare image-generation attempt (success or
        // failure). Only successful generations consume the daily limit;
        // quota_date is the UTC calendar day for quota accounting.
        Schema::create('image_generation_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->index();
            $table->string('model', 100)->nullable();
            $table->foreignId('blog_post_id')->nullable()->constrained('blog_posts')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->text('error_message')->nullable();
            $table->date('quota_date')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_generation_usage');

        Schema::table('image_settings', function (Blueprint $table) {
            $table->dropColumn([
                'account_id',
                'steps',
                'width',
                'height',
                'daily_limit',
                'max_retries',
                'timeout_seconds',
            ]);
        });
    }
};
