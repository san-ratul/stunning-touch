<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchSetting extends Model
{
    protected $fillable = [
        'enabled',
        'provider',
        'api_key',
        'rss_feeds',
        'trusted_domains',
        'sources_per_source',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'rss_feeds' => 'array',
            'trusted_domains' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create(['enabled' => true]);
    }
}
