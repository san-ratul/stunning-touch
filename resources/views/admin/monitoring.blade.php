@extends('layouts.admin')

@section('title', 'AI Monitoring')

@section('content')
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach([
            ['Total Generations', number_format($metrics['total_generations']), 'sparkles', 'from-brand-600/10 to-accent-500/10 text-brand-600'],
            ['Successful', number_format($metrics['successful_generations']), 'check', 'from-emerald-500/10 to-emerald-500/5 text-emerald-600'],
            ['Failed', number_format($metrics['failed_generations']), 'x', 'from-red-500/10 to-red-500/5 text-red-600'],
            ['Awaiting Review', number_format($metrics['posts_awaiting_review']), 'clock', 'from-amber-500/10 to-amber-500/5 text-amber-600'],
            ['Validation Failures', number_format($metrics['validation_failures']), 'shield', 'from-orange-500/10 to-orange-500/5 text-orange-600'],
        ] as [$label, $value, $icon, $tone])
            <div class="card p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br {{ $tone }}">
                        <x-icon name="{{ $icon }}" class="h-4 w-4"/>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-extrabold tabular-nums text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-bold text-slate-900">AI Provider Health</h2>
                <form method="POST" action="{{ route('admin.monitoring.refresh') }}">
                    @csrf
                    <button type="submit" class="btn-secondary px-3 py-1.5 text-xs">Re-check</button>
                </form>
            </div>
            <p class="mt-3 text-sm">
                <span class="mr-2 inline-block h-2.5 w-2.5 rounded-full {{ $health['success'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                {{ $health['message'] }}
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Prompt tokens</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ number_format($metrics['prompt_tokens_total']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Completion tokens</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ number_format($metrics['completion_tokens_total']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Avg generation time</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ $metrics['avg_generation_seconds'] !== null ? $metrics['avg_generation_seconds'].' s' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Research sources / article</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ $metrics['avg_research_sources_per_article'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Auto-published</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ number_format($metrics['auto_published_posts']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Manually published</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ number_format($metrics['manual_published_posts']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Pipeline failures</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ number_format($metrics['pipeline_failures']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Retries used</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ number_format(max(0, $metrics['retry_count'])) }}</dd>
                </div>
            </dl>
        </div>

        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Image generation (Cloudflare)</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Provider</dt>
                    <dd class="mt-0.5 font-bold text-slate-900">{{ $metrics['image_provider'] === 'cloudflare' ? 'Cloudflare Workers AI' : ucfirst($metrics['image_provider']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Model</dt>
                    <dd class="mt-0.5 font-bold text-slate-900 break-all">{{ $metrics['image_model'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Today (UTC)</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ $metrics['image_success_today'] }} / {{ $metrics['image_daily_limit'] }} generated</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Remaining allowance</dt>
                    <dd class="mt-0.5 font-bold tabular-nums text-slate-900">{{ $metrics['image_remaining_today'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Failed attempts today</dt>
                    <dd class="mt-0.5 font-bold tabular-nums {{ $metrics['image_failed_today'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ $metrics['image_failed_today'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Last success</dt>
                    <dd class="mt-0.5 font-bold text-slate-900">{{ $metrics['image_last_success_at'] ?? 'never' }}</dd>
                </div>
            </dl>
            @if(($metrics['image_recent_errors'] ?? []) !== [])
                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Recent image errors</p>
                <ul class="mt-1 space-y-1 text-xs text-red-600">
                    @foreach($metrics['image_recent_errors'] as $error)
                        <li>{{ $error['at'] }} — {{ $error['message'] }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3 text-xs text-slate-500">Usage counts successful generations against the configured daily limit (UTC quota day). Cloudflare's actual remaining free allocation is account-dependent and is shown here as an estimate only.</p>
        </div>

        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Daily publishing progress</h2>
            <p class="mt-3 text-sm text-slate-600">
                Target: <strong>{{ $metrics['posts_per_day_target'] }}</strong> article(s) per day
                (maximum 4; quality gates may publish fewer rather than lowering standards).
            </p>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <div class="card overflow-hidden">
            <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Pipeline execution history</h2>
            @if($pipelineRuns->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No pipeline runs yet.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach($pipelineRuns as $run)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-slate-800">{{ $run->topic ?? '—' }}</p>
                                <span class="{{ match ($run->status) { 'completed' => 'badge-green', 'failed' => 'badge-red', default => 'badge-amber' } }}">{{ ucfirst($run->status) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $run->slot_date?->format('M j') }} {{ $run->slot }} · attempt {{ $run->attempts }}
                                · source: {{ $run->topic_source ?? '—' }}
                                @if($run->blog_post_id)
                                    · <a href="{{ route('admin.blog.posts.edit', $run->blog_post_id) }}" class="text-brand-600">Open post</a>
                                @endif
                            </p>
                            @if($run->error_message)
                                <p class="mt-1 text-xs text-red-600">{{ \Illuminate\Support\Str::limit($run->error_message, 180) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card overflow-hidden">
                <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Research history</h2>
                @if($researchRuns->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No research runs yet.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($researchRuns as $run)
                            <li class="px-5 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="truncate text-slate-800">{{ $run->topic }}</p>
                                    <span class="{{ match ($run->status) { 'completed' => 'badge-green', 'partial' => 'badge-amber', default => 'badge-red' } }}">{{ ucfirst($run->status) }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $run->created_at->format('M j, H:i') }} · {{ $run->sources_found }} source(s)</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="card overflow-hidden">
                <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Recent generations</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($generations as $generation)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-slate-800">{{ $generation->topic ?? '—' }}</p>
                                <span class="{{ match ($generation->status) { 'completed' => 'badge-green', 'failed' => 'badge-red', default => 'badge-amber' } }}">{{ ucfirst($generation->status) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $generation->created_at->format('M j, H:i') }}
                                @if($generation->prompt_tokens || $generation->completion_tokens)
                                    · {{ $generation->prompt_tokens ?? '—' }}/{{ $generation->completion_tokens ?? '—' }} tokens
                                @endif
                                @if($generation->blog_post_id)
                                    · <a href="{{ route('admin.blog.posts.edit', $generation->blog_post_id) }}" class="text-brand-600">Open draft</a>
                                @endif
                            </p>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-slate-500">No generations yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
