<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineRun extends Model
{
    protected $table = 'blog_pipeline_runs';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'slot_date',
        'slot',
        'status',
        'step',
        'completed_steps',
        'topic',
        'topic_source',
        'research_run_id',
        'ai_generation_id',
        'blog_post_id',
        'published',
        'attempts',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'completed_steps' => 'array',
            'published' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    /**
     * Live publication state: the stored flag reflects the moment the run
     * finished, but admins may publish a draft afterwards — always trust
     * the post's current status when it exists.
     */
    public function getIsPublishedAttribute(): bool
    {
        if ($this->relationLoaded('blogPost')) {
            return ($this->blogPost?->status ?? ($this->published ? 'published' : 'draft')) === 'published';
        }

        if ($this->blog_post_id !== null) {
            return optional($this->blogPost()->first())->status === 'published';
        }

        return (bool) $this->published;
    }

    public function aiGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class);
    }

    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class);
    }

    public function markStep(string $step): void
    {
        $this->update([
            'step' => $step,
            'completed_steps' => array_values(array_unique(array_merge(
                (array) ($this->completed_steps ?? []),
                [$step]
            ))),
        ]);
    }
}
