<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Advertisement extends Model
{
    protected $fillable = [
        'ad_placement_id',
        'name',
        'provider',
        'ad_type',
        'ad_code',
        'image_path',
        'destination_url',
        'image_alt',
        'width',
        'height',
        'target_pages',
        'target_tools',
        'device_targeting',
        'starts_at',
        'ends_at',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_pages' => 'array',
            'target_tools' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class);
    }

    public function isScheduledNow(): bool
    {
        $now = now();

        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }
}
