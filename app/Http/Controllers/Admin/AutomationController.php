<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AutomationSetting;
use App\Models\BlogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AutomationController extends Controller
{
    public function edit(): View
    {
        return view('admin.ai.automation', [
            'automation' => AutomationSetting::current(),
            'blogCategories' => BlogCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'frequency' => ['required', 'in:daily,weekly'],
            'preferred_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'exists:blog_categories,id'],
            'topics' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'auto_publish' => ['nullable', 'boolean'],
            'featured_image_mode' => ['nullable', 'in:none,branded,external_api,cloudflare'],
            'posts_per_day' => ['nullable', 'integer', 'min:1', 'max:4'],
            'publish_slots' => ['nullable', 'string', 'max:200'],
            'min_word_count' => ['nullable', 'integer', 'min:200', 'max:5000'],
            'max_word_count' => ['nullable', 'integer', 'min:200', 'max:10000'],
            'duplicate_threshold' => ['nullable', 'integer', 'min:50', 'max:100'],
            'research_enabled' => ['nullable', 'boolean'],
            'max_concurrent_generations' => ['nullable', 'integer', 'min:1', 'max:4'],
            'max_generation_continuations' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? false);
        $data['auto_publish'] = (bool) ($data['auto_publish'] ?? false);
        $data['research_enabled'] = (bool) ($data['research_enabled'] ?? false);
        $data['topics'] = array_values(array_filter(array_map('trim', explode("\n", (string) ($data['topics'] ?? '')))));
        $data['categories'] = $data['categories'] ?? [];
        $data['featured_image_mode'] = $data['featured_image_mode'] ?? 'branded';

        // Publishing slots: comma/space-separated HH:MM list, capped at 4.
        $slots = collect(preg_split('/[,\s]+/', (string) ($data['publish_slots'] ?? '')))
            ->map(fn ($slot) => trim($slot))
            ->filter(fn ($slot) => preg_match('/^\d{2}:\d{2}$/', (string) $slot) === 1)
            ->unique()
            ->sort()
            ->take(4)
            ->values();

        $data['publish_slots'] = $slots->isNotEmpty() ? $slots->all() : ['06:00', '12:00', '18:00', '21:00'];

        $data['posts_per_day'] = min(4, max(1, (int) ($data['posts_per_day'] ?? 3)));
        $data['min_word_count'] = (int) ($data['min_word_count'] ?? 900);
        $data['max_word_count'] = max($data['min_word_count'], (int) ($data['max_word_count'] ?? 2000));
        $data['duplicate_threshold'] = (int) ($data['duplicate_threshold'] ?? 85);
        $data['max_concurrent_generations'] = max(1, (int) ($data['max_concurrent_generations'] ?? 1));
        $data['max_generation_continuations'] = min(3, max(0, (int) ($data['max_generation_continuations'] ?? 2)));

        $automation = AutomationSetting::current();
        $automation->update($data);

        AuditLog::record('automation.updated', null, 'Updated blog automation settings');

        return redirect()->route('admin.automation.edit')->with('success', 'Automation settings saved.');
    }
}
