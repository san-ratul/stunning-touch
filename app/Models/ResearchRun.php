<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchRun extends Model
{
    protected $fillable = [
        'ai_generation_id',
        'topic',
        'query',
        'status',
        'sources_found',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
        ];
    }

    public function aiGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BlogResearchSource::class);
    }
}
