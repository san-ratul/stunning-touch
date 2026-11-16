<?php

namespace App\Support;

use App\Models\AdPlacement;
use App\Models\Advertisement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Renders active, scheduled, targeted advertisements into public placements.
 * Ad code is admin-managed trusted HTML/JS and is rendered ONLY on public
 * pages, never inside admin screens. Failures never block page rendering.
 */
class AdRenderer
{
    public static function render(string $placementKey, string $pageType = 'other', ?string $toolKey = null, string $device = 'all'): ?View
    {
        try {
            $ads = self::activeAdsFor($placementKey)
                ->filter(fn (Advertisement $ad) => self::matches($ad, $pageType, $toolKey, $device))
                ->sortByDesc('priority')
                ->values();

            if ($ads->isEmpty()) {
                return null;
            }

            $ad = $ads->first();

            return view('partials.ad-slot', [
                'ad' => $ad,
                'placementKey' => $placementKey,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function activeAdsFor(string $placementKey): Collection
    {
        return Cache::remember(
            'ads_placement_'.$placementKey,
            now()->addMinutes(5),
            function () use ($placementKey) {
                $placement = AdPlacement::query()
                    ->where('key', $placementKey)
                    ->where('is_active', true)
                    ->first();

                if (! $placement) {
                    return collect();
                }

                return $placement->advertisements()
                    ->where('is_active', true)
                    ->orderByDesc('priority')
                    ->get()
                    ->filter(fn (Advertisement $ad) => $ad->isScheduledNow())
                    ->values();
            }
        );
    }

    protected static function matches(Advertisement $ad, string $pageType, ?string $toolKey, string $device): bool
    {
        if ($ad->device_targeting !== 'all' && $ad->device_targeting !== $device) {
            return false;
        }

        $pages = $ad->target_pages;

        if (is_array($pages) && $pages !== []) {
            if (! in_array($pageType, $pages, true)) {
                return false;
            }
        }

        $tools = $ad->target_tools;

        if (is_array($tools) && $tools !== []) {
            if ($toolKey === null || ! in_array($toolKey, $tools, true)) {
                return false;
            }
        }

        return true;
    }

    public static function recordImpression(int $adId): void
    {
        try {
            Advertisement::query()->whereKey($adId)->increment('impressions');
        } catch (\Throwable) {
        }
    }
}
