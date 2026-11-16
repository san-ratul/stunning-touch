<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\AutomationSetting;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\QualityReport;
use App\Services\Ai\AiProviderService;
use App\Services\Ai\BlogGeneratorService;
use App\Services\Ai\DuplicateDetectorService;
use App\Services\Ai\ImageService;
use App\Services\Ai\KeywordService;
use App\Services\Research\ResearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Manual blog generation from the admin panel.
 *
 * Reuses the existing generation stack (research, keywords, generator,
 * validator, generation lock). The article is always stored as a draft:
 * manual generation never auto-publishes, regardless of the automation
 * auto-publish setting.
 */
class ManualBlogGenerationController extends Controller
{
    public function create(AiProviderService $ai): View
    {
        return view('admin.blog.generate', [
            'automation' => AutomationSetting::current(),
            'categories' => BlogCategory::query()->orderBy('name')->get(['id', 'name']),
            'providerState' => $ai->configurationState(),
            'result' => session('generation_result'),
        ]);
    }

    public function store(
        Request $request,
        AiProviderService $ai,
        ResearchService $research,
        KeywordService $keywords,
        DuplicateDetectorService $duplicates,
        BlogGeneratorService $generator,
        ImageService $images,
    ): RedirectResponse {
        $automation = AutomationSetting::current();

        $data = $request->validate([
            'topic' => ['required', 'string', 'min:8', 'max:400'],
            'focus_keyword' => ['required', 'string', 'max:100'],
            'secondary_keywords' => ['nullable', 'string', 'max:500'],
            'target_word_count' => [
                'nullable',
                'integer',
                'min:'.max(1, (int) ($automation->min_word_count ?: 900)),
                'max:'.max(1, (int) ($automation->max_word_count ?: 2000)),
            ],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'research' => ['nullable', 'boolean'],
            'blog_category' => ['nullable', 'integer', 'exists:blog_categories,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        // Only the saved, enabled provider may be used — never browser input.
        $state = $ai->configurationState();

        if (! $state['exists'] || ! $state['enabled']) {
            return back()->withInput()->with('error', 'AI provider is disabled. Enable it in AI Provider settings first.');
        }

        if (! $state['configured']) {
            return back()->withInput()->with('error', 'AI provider is incomplete. Missing: '.implode(', ', $state['missing']).'.');
        }

        // Duplicate screening with an explicit bypass for the admin.
        $analysis = $duplicates->analyze(
            title: (string) $data['topic'],
            focusKeyword: (string) $data['focus_keyword'],
            threshold: $automation->duplicate_threshold,
        );

        if ($analysis['duplicate'] && ! (bool) ($data['force'] ?? false)) {
            return back()->withInput()->with(
                'warning',
                'A similar post already exists: "'.($analysis['best_match']['title'] ?? '').'" ('.implode('; ', $analysis['best_match']['reasons'] ?? []).'). Change the topic, or tick "Generate anyway" to proceed.'
            );
        }

        // Research (optional, admin choice; global toggle respected).
        $researchResults = collect();
        $researchRunId = null;
        $researchUsed = false;

        if ((bool) ($data['research'] ?? false) && (bool) $automation->research_enabled) {
            $outcome = $research->researchForTopic((string) $data['topic']);
            $researchResults = $outcome['results'];
            $researchRunId = $outcome['run']->id;
            $researchUsed = $researchResults->isNotEmpty();
        }

        $secondaryInput = collect(preg_split('/[,;]+/', (string) ($data['secondary_keywords'] ?? '')))
            ->map(fn ($k) => trim($k))
            ->filter()
            ->values()
            ->all();

        $keywordData = $keywords->suggest((string) $data['topic'], $researchResults, $data['secondary_keywords'] ?? null);
        $secondary = array_values(array_unique(array_merge($secondaryInput, $keywordData['secondary'])));

        $preferredCategory = null;
        if (filled($data['blog_category'] ?? null)) {
            $preferredCategory = BlogCategory::query()->find($data['blog_category'])?->name;
        }

        // Manual generation never auto-publishes, regardless of the saved
        // auto-publish setting — override in memory only.
        $manualAutomation = clone $automation;
        $manualAutomation->auto_publish = false;

        $result = $generator->generateForTopic((string) $data['topic'], $manualAutomation, [
            'research' => $researchResults,
            'research_run_id' => $researchRunId,
            'focus_keyword' => (string) $data['focus_keyword'],
            'secondary_keywords' => array_slice($secondary, 0, 6),
            'min_word_count' => $automation->min_word_count,
            'max_word_count' => $automation->max_word_count,
            'duplicate_threshold' => $automation->duplicate_threshold,
            'research_required' => false,
            'instructions' => $data['instructions'] ?? null,
            'target_word_count' => $data['target_word_count'] ?? null,
            'preferred_category' => $preferredCategory,
        ]);

        if (! ($result['success'] ?? false)) {
            AuditLog::record('post.manual_generation_failed', null, 'Manual generation failed: '.Str::limit((string) $result['message'], 300), ['topic' => Str::limit((string) $data['topic'], 200)]);

            return back()->withInput()->with('error', (string) $result['message']);
        }

        $post = BlogPost::query()->findOrFail($result['post_id']);
        $report = QualityReport::query()->where('blog_post_id', $post->id)->latest('id')->first();
        $generation = AiGeneration::query()->where('blog_post_id', $post->id)->latest('id')->first();
        $researchSources = BlogResearchSource::query()->where('blog_post_id', $post->id)->count();

        // Featured image per the configured mode (Cloudflare → local fallback).
        // Image failures never affect the article.
        $image = $images->forPostWithReport($post, (string) ($automation->featured_image_mode ?: ImageService::MODE_BRANDED));

        $qualityNote = $report !== null && ! $report->passed
            ? ' Quality check failed: '.Str::limit($report->violations[0] ?? 'unknown issue', 160)
            : '';

        AuditLog::record('post.manual_generated', $post, 'Manual AI generation: '.$post->title, [
            'research_used' => $researchUsed,
            'image_provider' => $image['provider'],
        ]);

        return redirect()
            ->route('admin.blog.generate.create')
            ->with([
                'success' => 'Article generated and saved as a draft for review — manual generation never auto-publishes.'.$qualityNote,
                'generation_result' => [
                    'post_id' => $post->id,
                    'title' => $post->title,
                    'status' => $post->status,
                    'passed' => $report?->passed,
                    'violations' => $report?->violations ?? [],
                    'warnings' => $report?->warnings ?? [],
                    'word_count' => $report?->word_count,
                    'prompt_tokens' => $generation?->prompt_tokens,
                    'completion_tokens' => $generation?->completion_tokens,
                    'research_sources' => $researchSources,
                    'research_used' => $researchUsed,
                    'image_provider' => $image['provider'],
                    'image_attached' => $image['media'] !== null,
                    'image_warning' => $image['message'],
                ],
            ]);
    }

    /**
     * Explicit administrator-initiated regeneration of an article's featured
     * image (bypasses the one-image-per-article guard).
     */
    public function regenerateImage(Request $request, BlogPost $post, ImageService $images): RedirectResponse
    {
        $automation = AutomationSetting::current();
        $image = $images->forPostWithReport($post, (string) ($automation->featured_image_mode ?: ImageService::MODE_BRANDED), force: true);

        AuditLog::record('post.image_regenerated', $post, 'Regenerated featured image: '.($image['media']?->file_name ?? 'none'), [
            'provider' => $image['provider'],
        ]);

        return redirect()
            ->route('admin.blog.posts.edit', $post->id)
            ->with(
                ($image['media'] !== null) ? 'success' : 'error',
                $image['media'] !== null
                    ? 'Featured image regenerated using '.($image['provider'] === 'cloudflare' ? 'Cloudflare FLUX.1' : 'the local branded cover').'.'
                    : 'Image regeneration failed: '.($image['message'] ?? 'unknown error')
            );
    }
}
