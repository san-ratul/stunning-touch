<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdPlacement;
use App\Models\Advertisement;
use App\Models\AuditLog;
use App\Models\Tool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AdvertisementController extends Controller
{
    public const PLACEMENT_KEYS = [
        'homepage_top' => 'Homepage Top',
        'homepage_content' => 'Homepage Content',
        'homepage_bottom' => 'Homepage Bottom',
        'category_top' => 'Category Top',
        'tool_before_content' => 'Tool Before Content',
        'tool_after_content' => 'Tool After Content',
        'tool_sidebar' => 'Tool Sidebar',
        'tool_bottom' => 'Tool Bottom',
        'blog_content' => 'Blog Content',
        'blog_sidebar' => 'Blog Sidebar',
        'blog_footer' => 'Blog Footer',
    ];

    public function index(): View
    {
        return view('admin.ads.index', [
            'ads' => Advertisement::query()->with('placement')->latest()->paginate(20),
            'placements' => AdPlacement::query()->withCount('advertisements')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.ads.form', [
            'ad' => new Advertisement(['provider' => 'custom', 'ad_type' => 'code', 'device_targeting' => 'all', 'is_active' => true]),
            'placements' => AdPlacement::query()->orderBy('name')->get(),
            'tools' => Tool::query()->active()->ordered()->get(['id', 'name', 'component_key']),
            'pageTypes' => $this->pageTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $ad = Advertisement::create($this->validated($request));
        AuditLog::record('ad.created', $ad, 'Created advertisement: '.$ad->name);
        $this->clearAdCache();

        return redirect()->route('admin.advertisements.index')->with('success', 'Advertisement created.');
    }

    public function edit(Advertisement $advertisement): View
    {
        return view('admin.ads.form', [
            'ad' => $advertisement,
            'placements' => AdPlacement::query()->orderBy('name')->get(),
            'tools' => Tool::query()->active()->ordered()->get(['id', 'name', 'component_key']),
            'pageTypes' => $this->pageTypes(),
        ]);
    }

    public function update(Request $request, Advertisement $advertisement): RedirectResponse
    {
        $advertisement->update($this->validated($request, $advertisement));
        AuditLog::record('ad.updated', $advertisement, 'Updated advertisement: '.$advertisement->name);
        $this->clearAdCache();

        return redirect()->route('admin.advertisements.index')->with('success', 'Advertisement updated.');
    }

    public function destroy(Advertisement $advertisement): RedirectResponse
    {
        AuditLog::record('ad.deleted', $advertisement, 'Deleted advertisement: '.$advertisement->name);
        $advertisement->delete();
        $this->clearAdCache();

        return redirect()->route('admin.advertisements.index')->with('success', 'Advertisement deleted.');
    }

    public function toggle(Advertisement $advertisement): RedirectResponse
    {
        $advertisement->update(['is_active' => ! $advertisement->is_active]);
        AuditLog::record('ad.toggled', $advertisement, 'Toggled advertisement: '.$advertisement->name);
        $this->clearAdCache();

        return back()->with('success', 'Advertisement updated.');
    }

    /**
     * Preview renders the ad inside a sandboxed iframe so admin browsers
     * never execute advertising JavaScript in the admin origin.
     */
    public function preview(Advertisement $advertisement)
    {
        $html = match ($advertisement->ad_type) {
            'banner' => sprintf(
                '<a href="%s" target="_blank" rel="noopener sponsored"><img src="%s" alt="%s" style="max-width:100%%;height:auto;"></a>',
                e($advertisement->destination_url ?? '#'),
                e($advertisement->image_path ? asset('storage/'.$advertisement->image_path) : ''),
                e($advertisement->image_alt ?? $advertisement->name)
            ),
            default => $advertisement->ad_code ?? '',
        };

        return response()->view('admin.ads.preview', ['ad' => $advertisement, 'html' => $html])
            // CSP blocks any script execution inside admin screens; ads render
            // their JavaScript only on public placements.
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; script-src 'none'; frame-src 'none'")
            ->header('X-Robots-Tag', 'noindex');
    }

    protected function pageTypes(): array
    {
        return [
            'home' => 'Homepage',
            'tools_index' => 'Tools Index',
            'category' => 'Tool Category',
            'tool' => 'Tool Page',
            'blog_index' => 'Blog Index',
            'blog_category' => 'Blog Category',
            'blog_post' => 'Blog Post',
            'static_page' => 'Static Page',
        ];
    }

    protected function validated(Request $request, ?Advertisement $ad = null): array
    {
        $validated = $request->validate([
            'ad_placement_id' => ['required', 'exists:ad_placements,id'],
            'name' => ['required', 'string', 'max:150'],
            'provider' => ['required', 'string', 'max:100'],
            'ad_type' => ['required', 'in:code,banner'],
            'ad_code' => ['nullable', 'string', 'max:100000'],
            'image' => ['nullable', 'image', 'max:2048'],
            'destination_url' => ['nullable', 'url', 'max:500'],
            'image_alt' => ['nullable', 'string', 'max:255'],
            'width' => ['nullable', 'integer', 'min:1', 'max:4000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:4000'],
            'target_pages' => ['nullable', 'array'],
            'target_pages.*' => ['string', 'max:50'],
            'target_tools' => ['nullable', 'array'],
            'target_tools.*' => ['string', 'max:100', 'exists:tools,component_key'],
            'device_targeting' => ['required', 'in:all,desktop,mobile,tablet'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('ads', 'public');
        }
        unset($validated['image']);

        if ($validated['ad_type'] === 'code') {
            $validated['ad_code'] = $validated['ad_code'] ?? ($ad?->ad_code);
            if (blank($validated['ad_code'])) {
                abort(422, 'Ad code is required for code-type advertisements.');
            }
        } elseif ($request->hasFile('image') === false && blank($ad?->image_path)) {
            abort(422, 'A banner image is required for banner-type advertisements.');
        }

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);
        $validated['priority'] = $validated['priority'] ?? 0;
        $validated['target_pages'] = $validated['target_pages'] ?? null;
        $validated['target_tools'] = $validated['target_tools'] ?? null;

        return $validated;
    }

    protected function clearAdCache(): void
    {
        foreach (array_keys(self::PLACEMENT_KEYS) as $key) {
            Cache::forget('ads_placement_'.$key);
        }
    }
}
