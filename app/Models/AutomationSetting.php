<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationSetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'frequency',
        'preferred_time',
        'timezone',
        'categories',
        'topics',
        'keywords',
        'auto_publish',
        'last_run_at',
        'posts_per_day',
        'publish_slots',
        'min_word_count',
        'max_word_count',
        'duplicate_threshold',
        'featured_image_mode',
        'research_enabled',
        'max_concurrent_generations',
        'max_generation_continuations',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auto_publish' => 'boolean',
            'research_enabled' => 'boolean',
            'categories' => 'array',
            'topics' => 'array',
            'publish_slots' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create([
            'frequency' => 'daily',
            'posts_per_day' => 3,
            'publish_slots' => ['06:00', '12:00', '18:00', '21:00'],
            'min_word_count' => 900,
            'max_word_count' => 2000,
            'duplicate_threshold' => 85,
            'featured_image_mode' => 'branded',
            'research_enabled' => true,
            'max_concurrent_generations' => 1,
        ]);
    }
}
