<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityReport extends Model
{
    protected $fillable = [
        'blog_post_id',
        'ai_generation_id',
        'passed',
        'violations',
        'warnings',
        'checks',
        'word_count',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'violations' => 'array',
            'warnings' => 'array',
            'checks' => 'array',
        ];
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function aiGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class);
    }
}
