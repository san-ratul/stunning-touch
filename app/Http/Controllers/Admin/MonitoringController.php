<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\PipelineRun;
use App\Models\ResearchRun;
use App\Services\Ai\AiProviderService;
use App\Support\BlogMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function index(): View
    {
        return view('admin.monitoring', [
            'metrics' => BlogMetrics::all(),
            'pipelineRuns' => PipelineRun::query()->with('blogPost')->latest('created_at')->limit(30)->get(),
            'researchRuns' => ResearchRun::query()->latest('created_at')->limit(20)->get(),
            'generations' => AiGeneration::query()->with('blogPost')->latest('created_at')->limit(20)->get(),
            'health' => $this->cachedHealth(),
        ]);
    }

    public function refreshHealth(AiProviderService $ai): RedirectResponse
    {
        Cache::forget('ai:health');

        $health = $ai->modelAvailability();
        Cache::put('ai:health', $health, now()->addMinutes(5));

        return redirect()->route('admin.monitoring.index')->with(
            $health['success'] ? 'success' : 'error',
            $health['message']
        );
    }

    /**
     * Cached provider/model health so the page stays fast; AI endpoints can
     * take seconds on loaded CPU instances. Never exposes credentials.
     */
    protected function cachedHealth(): array
    {
        return Cache::remember('ai:health', now()->addMinutes(5), function () {
            $service = app(AiProviderService::class);

            if (! $service->isConfigured()) {
                return ['success' => false, 'message' => 'AI provider is not configured.', 'models' => []];
            }

            try {
                return $service->modelAvailability();
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'Health check failed: '.$e->getMessage(), 'models' => []];
            }
        });
    }
}
