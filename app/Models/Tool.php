<?php

namespace App\Models;

use App\Traits\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tool extends Model
{
    use HasSeoMeta;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'short_description',
        'description',
        'icon',
        'component_key',
        'is_active',
        'is_featured',
        'is_archived',
        'display_order',
        'how_to_title',
        'how_to_steps',
        'faq',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_archived' => 'boolean',
            'how_to_steps' => 'array',
            'faq' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ToolUsage::class);
    }

    public function usageCount(): int
    {
        return (int) cache()->remember(
            'tool_usage_count_'.$this->id,
            now()->addMinutes(10),
            fn () => $this->usages()->where('success', true)->count()
        );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_archived', false);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function url(): string
    {
        return url('/'.$this->slug);
    }
}
