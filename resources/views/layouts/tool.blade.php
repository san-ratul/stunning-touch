@extends('layouts.public')

@section('content')
    <div class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs ?? []])

        <div class="grid gap-10 lg:grid-cols-[1fr_300px]">
            <div class="min-w-0">
                <header class="mb-6">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-md">
                            <x-icon name="{{ $tool->icon ?: 'tools' }}" class="h-6 w-6"/>
                        </span>
                        <div>
                            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $tool->name }}</h1>
                            @if($tool->short_description)
                                <p class="mt-1 text-slate-600">{{ $tool->short_description }}</p>
                            @endif
                        </div>
                    </div>
                </header>

                @if($ads['before'] ?? null)
                    {!! $ads['before'] !!}
                @endif

                <div class="card p-5 sm:p-8">
                    @yield('tool')
                </div>

                @if($ads['after'] ?? null)
                    {!! $ads['after'] !!}
                @endif

                @if($tool->how_to_steps && count($tool->how_to_steps) > 0)
                    <section class="mt-10" aria-labelledby="how-to">
                        <h2 id="how-to" class="text-xl font-bold text-slate-900">{{ $tool->how_to_title ?: 'How to use this tool' }}</h2>
                        <ol class="mt-4 space-y-3">
                            @foreach($tool->how_to_steps as $i => $step)
                                <li class="flex gap-3">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-600/10 text-sm font-bold text-brand-700">{{ $i + 1 }}</span>
                                    <p class="text-slate-700">{{ $step }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @if($tool->description)
                    <section class="mt-10" aria-labelledby="about-tool">
                        <h2 id="about-tool" class="text-xl font-bold text-slate-900">About the {{ $tool->name }}</h2>
                        <div class="prose-article">{!! $tool->description !!}</div>
                    </section>
                @endif

                @if($tool->faq && count($tool->faq) > 0)
                    <section class="mt-10" aria-labelledby="faq-heading">
                        <h2 id="faq-heading" class="text-xl font-bold text-slate-900">Frequently Asked Questions</h2>
                        <div class="mt-4 space-y-3" x-data="{ open: null }">
                            @foreach($tool->faq as $i => $item)
                                <div class="card overflow-hidden">
                                    <button type="button"
                                            class="faq-trigger flex w-full items-center justify-between gap-4 px-5 py-4 text-left font-medium text-slate-900 hover:bg-slate-50"
                                            @click="open = (open === {{ $i }} ? null : {{ $i }})"
                                            :aria-expanded="open === {{ $i }}">
                                        {{ $item['q'] }}
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                                        </svg>
                                    </button>
                                    <div x-show="open === {{ $i }}" x-cloak x-collapse>
                                        <p class="px-5 pb-4 text-sm leading-relaxed text-slate-600">{{ $item['a'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($ads['bottom'] ?? null)
                    {!! $ads['bottom'] !!}
                @endif

                @if($relatedTools->isNotEmpty())
                    <section class="mt-12" aria-labelledby="related-heading">
                        <h2 id="related-heading" class="text-xl font-bold text-slate-900">Related Tools</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            @foreach($relatedTools as $related)
                                <x-tool-card :tool="$related"/>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="hidden lg:block">
                <div class="sticky top-24 space-y-6">
                    @if($ads['sidebar'] ?? null)
                        {!! $ads['sidebar'] !!}
                    @endif
                    <div class="card p-5">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-900">More in {{ $category?->name ?? 'Tools' }}</h2>
                        <ul class="mt-3 space-y-2.5">
                            @foreach(\App\Models\Tool::query()->active()->where('category_id', $tool->category_id)->whereKeyNot($tool->getKey())->ordered()->limit(6)->get(['name', 'slug']) as $sideTool)
                                <li>
                                    <a href="{{ url('/'.$sideTool->slug) }}" class="text-sm text-slate-600 hover:text-brand-600">{{ $sideTool->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @if($registryConfig['client_side'] ?? true)
                        <div class="card bg-gradient-to-br from-brand-600/5 to-accent-500/5 p-5">
                            <p class="text-sm font-semibold text-slate-900">🔒 Private processing</p>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">All processing happens locally in your browser. Your files never leave your device.</p>
                        </div>
                    @else
                        <div class="card bg-gradient-to-br from-brand-600/5 to-accent-500/5 p-5">
                            <p class="text-sm font-semibold text-slate-900">🛡️ Safe by design</p>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">Requests are strictly validated, rate-limited, and credentials are never stored or logged.</p>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    </div>
@endsection
