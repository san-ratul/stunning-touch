<?php

namespace App\Traits;

use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasSeoMeta
{
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    public function saveSeoMeta(array $data): void
    {
        $data = $this->filterSeoFields($data);

        if (empty($data)) {
            return;
        }

        $this->seo()->updateOrCreate([], $data);
    }

    protected function filterSeoFields(array $data): array
    {
        return collect($data)->only([
            'meta_title',
            'meta_description',
            'focus_keyword',
            'secondary_keywords',
            'canonical_url',
            'og_title',
            'og_description',
            'og_image',
            'twitter_title',
            'twitter_description',
            'twitter_image',
            'robots_index',
            'robots_follow',
            'schema_type',
            'schema_data',
        ])->filter(fn ($value) => $value !== null)->all();
    }
}
