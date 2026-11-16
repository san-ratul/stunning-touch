<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageSetting extends Model
{
    protected $fillable = [
        'provider',
        'endpoint',
        'api_key',
        'account_id',
        'model',
        'size',
        'steps',
        'width',
        'height',
        'daily_limit',
        'max_retries',
        'timeout_seconds',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'steps' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'daily_limit' => 'integer',
            'max_retries' => 'integer',
            'timeout_seconds' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create(['provider' => 'none']);
    }

    public function isCloudflare(): bool
    {
        return ($this->provider ?? 'none') === 'cloudflare';
    }
}
