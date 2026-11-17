<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\ContactMessage;
use App\Models\PageView;
use App\Models\Tool;
use App\Models\ToolUsage;
use App\Models\Visitor;
use App\Support\BlogMetrics;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $since = now()->subDays(30)->startOfDay();

        $pageViews30 = PageView::query()->where('visited_at', '>=', $since)->count();
        $unique30 = PageView::query()->where('visited_at', '>=', $since)->distinct('visitor_id')->count('visitor_id');
        $totalVisitors = Visitor::query()->count();
        $toolUsage = ToolUsage::query()->where('created_at', '>=', $since);
        $toolUsageCount = (clone $toolUsage)->count();
        $toolSuccess = (clone $toolUsage)->where('success', true)->count();

        // 14-day page view + tool usage trend
        $trend = collect(range(13, 0))->map(function ($daysAgo) {
            $day = now()->subDays($daysAgo)->toDateString();

            return [
                'date' => $day,
                'views' => PageView::query()->whereDate('visited_at', $day)->count(),
                'usage' => ToolUsage::query()->whereDate('created_at', $day)->count(),
            ];
        });

        return view('admin.dashboard', [
            'cards' => [
                'total_visitors' => $totalVisitors,
                'unique_visitors_30d' => $unique30,
                'page_views_30d' => $pageViews30,
                'tool_usage_30d' => $toolUsageCount,
                'tool_success_30d' => $toolSuccess,
                'active_tools' => Tool::query()->active()->count(),
                'published_posts' => BlogPost::query()->published()->count(),
                'scheduled_posts' => BlogPost::query()->where('status', 'scheduled')->count(),
                'unread_messages' => ContactMessage::query()->where('is_read', false)->count(),
            ],
            'automationToday' => BlogMetrics::today(),
            'automationTarget' => AutomationSetting::current()->posts_per_day ?? 3,
            'trend' => $trend,
            'topTools' => Tool::query()
                ->withCount(['usages as usage_count' => fn ($q) => $q->where('created_at', '>=', $since)])
                ->orderByDesc('usage_count')
                ->limit(5)
                ->get(),
            'recentUsage' => ToolUsage::query()->with('tool')->latest('created_at')->limit(8)->get(),
        ]);
    }
}
