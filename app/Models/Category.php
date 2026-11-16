<?php

namespace App\Models;

use App\Traits\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasSeoMeta;

    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'description',
        'icon',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class)->orderBy('display_order')->orderBy('name');
    }

    public function activeTools(): HasMany
    {
        return $this->tools()->where('is_active', true)->where('is_archived', false);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }
}
