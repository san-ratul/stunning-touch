<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\StaticPage;
use App\Models\Tool;
use App\Traits\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SeoController extends Controller
{
    /**
     * [class, title field, label, uses morph seo relation?]
     * Blog posts keep their SEO in dedicated columns (single-table design).
     */
    private const TYPES = [
        'tool' => [Tool::class, 'name', 'Tools', true],
        'category' => [Category::class, 'name', 'Tool Categories', true],
        'blog_post' => [BlogPost::class, 'title', 'Blog Posts', false],
        'blog_category' => [BlogCategory::class, 'name', 'Blog Categories', true],
        'static_page' => [StaticPage::class, 'title', 'Static Pages', true],
    ];

    public function index(Request $request): View
    {
        $entities = collect();

        foreach (self::TYPES as $type => [$class, $titleField]) {
            $class::query()
                ->orderBy($titleField)
                ->get()
                ->each(fn ($entity) => $entities->push([
                    'type' => $type,
                    'id' => $entity->id,
                    'title' => $entity->getAttribute($titleField),
                    'seo' => $this->seoFor($entity, $type),
                ]));
        }

        return view('admin.seo.index', ['entities' => $entities]);
    }

    public function edit(Request $request, string $type, int $id): View
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        [$class, $titleField] = self::TYPES[$type];
        $entity = $class::query()->findOrFail($id);
        $seo = $this->seoFor($entity, $type);

        return view('admin.seo.form', [
            'type' => $type,
            'typeLabel' => self::TYPES[$type][2],
            'entity' => $entity,
            'titleField' => $titleField,
            'seo' => $seo,
        ]);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        [$class] = self::TYPES[$type];
        $entity = $class::query()->findOrFail($id);

        $data = $this->validated($request);
        $this->saveSeo($entity, $type, $data);

        AuditLog::record('seo.updated', $entity, 'Updated SEO metadata for '.$type.' #'.$id);

        return redirect()->route('admin.seo.index')->with('success', 'SEO metadata saved.');
    }

    /**
     * Uniform SEO attribute access: morph relation (via HasSeoMeta) or
     * blog post columns exposed as a plain object.
     */
    protected function seoFor(Model $entity, string $type)
    {
        if (self::TYPES[$type][3] ?? false) {
            return $entity->seo;
        }

        // Blog post columns
        return (object) [
            'meta_title' => $entity->seo_title,
            'meta_description' => $entity->meta_description,
            'focus_keyword' => $entity->focus_keyword,
            'secondary_keywords' => $entity->secondary_keywords,
            'canonical_url' => $entity->canonical_url,
            'og_title' => $entity->og_title,
            'og_description' => $entity->og_description,
            'og_image' => $entity->og_image,
            'twitter_title' => $entity->twitter_title,
            'twitter_description' => $entity->twitter_description,
            'twitter_image' => null,
            'robots_index' => $entity->robots_index,
            'robots_follow' => $entity->robots_follow,
            'schema_type' => 'Article',
            'schema_data' => null,
        ];
    }

    protected function saveSeo(Model $entity, string $type, array $data): void
    {
        if (self::TYPES[$type][3] ?? false) {
            $entity->seo()->updateOrCreate([], $data);

            return;
        }

        $entity->update([
            'focus_keyword' => $data['focus_keyword'],
            'secondary_keywords' => $data['secondary_keywords'],
            'seo_title' => $data['meta_title'],
            'meta_description' => $data['meta_description'],
            'canonical_url' => $data['canonical_url'],
            'og_title' => $data['og_title'],
            'og_description' => $data['og_description'],
            'og_image' => $data['og_image'],
            'twitter_title' => $data['twitter_title'],
            'twitter_description' => $data['twitter_description'],
            'robots_index' => $data['robots_index'],
            'robots_follow' => $data['robots_follow'],
        ]);
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'secondary_keywords' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:500'],
            'twitter_title' => ['nullable', 'string', 'max:255'],
            'twitter_description' => ['nullable', 'string', 'max:500'],
            'twitter_image' => ['nullable', 'string', 'max:500'],
            'robots_index' => ['nullable', 'boolean'],
            'robots_follow' => ['nullable', 'boolean'],
            'schema_type' => ['nullable', 'string', 'max:100'],
            'schema_data' => ['nullable', 'array'],
        ]);

        $data['robots_index'] = (bool) ($data['robots_index'] ?? false);
        $data['robots_follow'] = (bool) ($data['robots_follow'] ?? false);

        return $data;
    }
}
