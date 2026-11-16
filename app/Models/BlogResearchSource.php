<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogResearchSource extends Model
{
    protected $fillable = [
        'blog_post_id',
        'ai_generation_id',
        'research_run_id',
        'url',
        'title',
        'snippet',
        'source_type',
        'query',
        'relevance_score',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
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

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class);
    }
}
