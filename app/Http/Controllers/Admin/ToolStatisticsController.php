<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use App\Models\ToolUsage;
use Illuminate\View\View;

class ToolStatisticsController extends Controller
{
    public function index(): View
    {
        $days = (int) request()->query('range', '30') === 7 ? 7 : ((int) request()->query('range', '30') === 90 ? 90 : 30);
        $since = now()->subDays($days)->startOfDay();

        $totals = ToolUsage::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total, SUM(success) as success, SUM(NOT success) as failed, COUNT(DISTINCT visitor_id) as unique_users')
            ->first();

        $perTool = Tool::query()
            ->withCount(['usages as total' => fn ($q) => $q->where('created_at', '>=', $since)])
            ->withCount(['usages as success' => fn ($q) => $q->where('created_at', '>=', $since)->where('success', true)])
            ->withCount(['usages as failed' => fn ($q) => $q->where('created_at', '>=', $since)->where('success', false)])
            ->withCount(['usages as unique_users' => fn ($q) => $q->where('created_at', '>=', $since)->selectRaw('COUNT(DISTINCT visitor_id)')])
            ->orderByDesc('total')
            ->get();

        $daily = collect(range(min($days, 30) - 1, 0))->map(function ($daysAgo) use ($days) {
            $step = max(1, (int) ceil(min($days, 30) / 30));
            if ($step > 1 && $daysAgo % $step !== 0) {
                return null;
            }
            $day = now()->subDays($daysAgo)->toDateString();

            return [
                'date' => $day,
                'total' => ToolUsage::query()->whereDate('created_at', $day)->count(),
                'success' => ToolUsage::query()->whereDate('created_at', $day)->where('success', true)->count(),
            ];
        })->filter()->values();

        return view('admin.tool-statistics.index', [
            'totals' => $totals,
            'perTool' => $perTool,
            'daily' => $daily,
            'range' => $days,
            'successRate' => $totals->total > 0 ? round(($totals->success / $totals->total) * 100, 1) : 100.0,
        ]);
    }
}
