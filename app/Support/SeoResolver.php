<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves per-page SEO metadata with layered fallbacks:
 * entity SEO meta -> model fields -> site defaults.
 */
class SeoResolver
{
    public static function siteName(): string
    {
        return SiteSetting::get('site_name', config('app.name', 'Stunning Touch'));
    }

    public static function tagline(): string
    {
        return SiteSetting::get('site_tagline', 'Your Everyday Tools, All in One Place.');
    }

    public static function forModel(?Model $model): array
    {
        $seo = $model?->relationLoaded('seo') ? $model->seo : $model?->seo;

        return [
            'title' => $seo?->meta_title ?: ($model?->getAttribute('seo_title') ?: null),
            'description' => $seo?->meta_description ?: ($model?->getAttribute('meta_description') ?: ($model?->short_description ?? null)),
            'canonical' => $seo?->canonical_url ?: ($model?->canonical_url ?? null),
            'og_title' => $seo?->og_title ?: ($model?->og_title ?? null),
            'og_description' => $seo?->og_description ?: ($model?->og_description ?? null),
            'og_image' => $seo?->og_image ?: ($model?->og_image ?? null),
            'twitter_title' => $seo?->twitter_title ?: ($model?->twitter_title ?? null),
            'twitter_description' => $seo?->twitter_description ?: ($model?->twitter_description ?? null),
            'twitter_image' => $seo?->twitter_image ?? null,
            'robots_index' => $seo?->robots_index ?? true,
            'robots_follow' => $seo?->robots_follow ?? true,
            'schema_type' => $seo?->schema_type,
            'schema_data' => $seo?->schema_data,
            'focus_keyword' => $seo?->focus_keyword ?? null,
            'secondary_keywords' => $seo?->secondary_keywords ?? null,
        ];
    }

    public static function normalize(?array $data, string $fallbackTitle, ?string $fallbackDescription = null): array
    {
        $title = trim((string) ($data['title'] ?? ''));

        return [
            'title' => $title !== '' ? $title : $fallbackTitle,
            'description' => $data['description'] ?? $fallbackDescription,
            'canonical' => $data['canonical'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image' => $data['og_image'] ?? null,
            'twitter_title' => $data['twitter_title'] ?? null,
            'twitter_description' => $data['twitter_description'] ?? null,
            'twitter_image' => $data['twitter_image'] ?? null,
            'robots_index' => $data['robots_index'] ?? true,
            'robots_follow' => $data['robots_follow'] ?? true,
            'schema_type' => $data['schema_type'] ?? null,
            'schema_data' => $data['schema_data'] ?? null,
            'focus_keyword' => $data['focus_keyword'] ?? null,
            'secondary_keywords' => $data['secondary_keywords'] ?? null,
        ];
    }

    public static function fullTitle(string $pageTitle): string
    {
        $siteName = self::siteName();

        return str_contains($pageTitle, $siteName) ? $pageTitle : $pageTitle.' | '.$siteName;
    }

    public static function absoluteUrl(?string $pathOrUrl): ?string
    {
        if (blank($pathOrUrl)) {
            return null;
        }

        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($pathOrUrl, '/');
    }
}
