<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageGenerationUsage extends Model
{
    protected $table = 'image_generation_usage';

    protected $fillable = [
        'provider',
        'model',
        'blog_post_id',
        'status',
        'error_message',
        'quota_date',
    ];

    protected function casts(): array
    {
        return [
            'quota_date' => 'date',
        ];
    }

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }
}
