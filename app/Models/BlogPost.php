<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class BlogPost extends Model
{
    protected $fillable = [
        'blog_category_id',
        'user_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'featured_image_alt',
        'focus_keyword',
        'secondary_keywords',
        'seo_title',
        'meta_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_description',
        'robots_index',
        'robots_follow',
        'reading_time',
        'status',
        'source',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function blogCategory(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function getMetaTitleAttribute(): string
    {
        return $this->seo_title ?: $this->title;
    }

    public static function readingTimeFor(string $content): int
    {
        $words = str_word_count(strip_tags($content));

        return max(1, (int) ceil($words / 200));
    }

    /**
     * Suggest existing platform tools whose name/description naturally
     * matches keywords mentioned in an article.
     */
    public static function findToolsForKeywords(array $keywords): Collection
    {
        $tools = collect();

        foreach (array_slice($keywords, 0, 6) as $keyword) {
            if (mb_strlen($keyword) < 3) {
                continue;
            }

            Tool::query()
                ->active()
                ->with('category')
                ->where(fn ($q) => $q
                    ->where('name', 'like', "%{$keyword}%")
                    ->orWhere('short_description', 'like', "%{$keyword}%"))
                ->get()
                ->each(fn (Tool $tool) => $tools->put($tool->id, $tool));
        }

        return $tools->take(4)->values();
    }
}
