<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'provider',
        'endpoint',
        'api_key',
        'auth_mode',
        'basic_auth_user',
        'basic_auth_password',
        'model',
        'temperature',
        'max_tokens',
        'context_length',
        'timeout_seconds',
        'connect_timeout_seconds',
        'max_retries',
        'retry_backoff_ms',
        'keep_alive',
        'is_active',
    ];

    protected $hidden = [
        'api_key',
        'basic_auth_password',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'basic_auth_password' => 'encrypted',
            'temperature' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * The row the AI service actually uses: the enabled row when one exists,
     * otherwise the first saved row (reported as "saved but disabled").
     */
    public static function primary(): ?self
    {
        return static::query()->where('is_active', true)->first() ?? static::query()->first();
    }
}
