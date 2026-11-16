<?php

namespace App\Console\Commands;

use App\Models\AnalyticsDaily;
use App\Models\PageView;
use App\Models\SiteSetting;
use App\Models\ToolUsage;
use Illuminate\Console\Command;

class AggregateAnalytics extends Command
{
    protected $signature = 'analytics:aggregate {--date= : Specific Y-m-d date to aggregate} {--cleanup : Also prune old raw rows}';

    protected $description = 'Aggregate daily analytics and tool usage into summary tables';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();

        $from = $date.' 00:00:00';
        $to = $date.' 23:59:59';

        $views = PageView::query()->whereBetween('visited_at', [$from, $to])->count();
        $visitors = PageView::query()->whereBetween('visited_at', [$from, $to])->distinct('visitor_id')->count('visitor_id');
        $usage = ToolUsage::query()->whereBetween('created_at', [$from, $to]);
        $usageCount = (clone $usage)->count();
        $success = (clone $usage)->where('success', true)->count();

        AnalyticsDaily::query()->updateOrCreate(
            ['date' => $date],
            [
                'visitors' => $visitors,
                'page_views' => $views,
                'tool_usage' => $usageCount,
                'tool_success' => $success,
                'tool_failed' => $usageCount - $success,
            ]
        );

        $this->info("Aggregated {$date}: {$views} views, {$visitors} visitors, {$usageCount} tool runs.");

        if ($this->option('cleanup')) {
            $retention = max(7, (int) SiteSetting::get('analytics_note', 90));
            $cutoff = now()->subDays($retention);

            $deletedViews = PageView::query()->where('visited_at', '<', $cutoff)->delete();
            $deletedUsage = ToolUsage::query()->where('created_at', '<', $cutoff)->delete();
            $deletedVisitors = VisitorCleanup::pruneOrphans();

            $this->info("Cleanup: removed {$deletedViews} page views, {$deletedUsage} usage rows, {$deletedVisitors} orphan visitors.");
        }

        return self::SUCCESS;
    }
}
