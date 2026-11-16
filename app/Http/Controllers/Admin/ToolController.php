<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Tool;
use App\Support\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ToolController extends Controller
{
    public function index(Request $request): View
    {
        $tools = Tool::query()
            ->with('category')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->category))
            ->ordered()
            ->paginate(20)
            ->withQueryString();

        return view('admin.tools.index', [
            'tools' => $tools,
            'categories' => Category::query()->ordered()->get(),
            'registryKeys' => ToolRegistry::keys(),
        ]);
    }

    public function create(): View
    {
        return view('admin.tools.form', [
            'tool' => new Tool,
            'categories' => Category::query()->ordered()->get(),
            'registryKeys' => ToolRegistry::keys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $tool = Tool::create($data);
        AuditLog::record('tool.created', $tool, 'Created tool: '.$tool->name);

        return redirect()->route('admin.tools.index')->with('success', 'Tool created.');
    }

    public function edit(Tool $tool): View
    {
        return view('admin.tools.form', [
            'tool' => $tool,
            'categories' => Category::query()->ordered()->get(),
            'registryKeys' => ToolRegistry::keys(),
        ]);
    }

    public function update(Request $request, Tool $tool): RedirectResponse
    {
        $data = $this->validated($request, $tool);
        $tool->update($data);
        AuditLog::record('tool.updated', $tool, 'Updated tool: '.$tool->name);

        return redirect()->route('admin.tools.index')->with('success', 'Tool updated.');
    }

    public function destroy(Tool $tool): RedirectResponse
    {
        AuditLog::record('tool.deleted', $tool, 'Deleted tool: '.$tool->name);
        $tool->delete();

        return redirect()->route('admin.tools.index')->with('success', 'Tool deleted.');
    }

    public function toggleActive(Tool $tool): RedirectResponse
    {
        $tool->update(['is_active' => ! $tool->is_active]);
        AuditLog::record('tool.toggled', $tool, 'Toggled active state for: '.$tool->name);

        return back()->with('success', 'Tool updated.');
    }

    public function archive(Tool $tool): RedirectResponse
    {
        $tool->update(['is_archived' => ! $tool->is_archived]);
        AuditLog::record('tool.archived', $tool, 'Toggled archive state for: '.$tool->name);

        return back()->with('success', 'Tool updated.');
    }

    protected function validated(Request $request, ?Tool $tool = null): array
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tools,slug'.($tool ? ','.$tool->id : '')],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'icon' => ['nullable', 'string', 'max:100'],
            'component_key' => ['required', 'string', 'max:100', 'in:'.implode(',', ToolRegistry::keys())],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_archived' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'how_to_title' => ['nullable', 'string', 'max:255'],
            'how_to_steps' => ['nullable', 'array', 'max:20'],
            'how_to_steps.*' => ['nullable', 'string', 'max:1000'],
            'faq' => ['nullable', 'array', 'max:20'],
            'faq.*.q' => ['nullable', 'string', 'max:500'],
            'faq.*.a' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach (['is_active', 'is_featured', 'is_archived'] as $flag) {
            $validated[$flag] = (bool) ($validated[$flag] ?? false);
        }

        $validated['display_order'] = $validated['display_order'] ?? 0;

        if (empty($validated['how_to_steps'])) {
            $validated['how_to_steps'] = null;
        }

        if (empty($validated['faq'])) {
            $validated['faq'] = null;
        } else {
            $validated['faq'] = collect($validated['faq'])->filter(fn ($item) => filled($item['q'] ?? null) && filled($item['a'] ?? null))->values()->all() ?: null;
        }

        return $validated;
    }
}
