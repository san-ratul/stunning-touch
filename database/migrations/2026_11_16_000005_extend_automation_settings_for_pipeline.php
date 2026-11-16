<?php

use App\Models\AutomationSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('posts_per_day')->default(3);
            $table->json('publish_slots')->nullable();
            $table->unsignedInteger('min_word_count')->default(900);
            $table->unsignedInteger('max_word_count')->default(2000);
            $table->unsignedTinyInteger('duplicate_threshold')->default(85);
            $table->string('featured_image_mode', 30)->default('branded');
            $table->boolean('research_enabled')->default(true);
            $table->unsignedTinyInteger('max_concurrent_generations')->default(1);
        });

        AutomationSetting::query()->update([
            'publish_slots' => json_encode(['06:00', '12:00', '18:00', '21:00']),
        ]);

        Schema::create('image_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('none');
            $table->string('endpoint', 500)->nullable();
            $table->text('api_key')->nullable();
            $table->string('model', 100)->nullable();
            $table->string('size', 20)->default('1792x1024');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_settings');

        Schema::table('automation_settings', function (Blueprint $table) {
            $table->dropColumn([
                'posts_per_day',
                'publish_slots',
                'min_word_count',
                'max_word_count',
                'duplicate_threshold',
                'featured_image_mode',
                'research_enabled',
                'max_concurrent_generations',
            ]);
        });
    }
};
