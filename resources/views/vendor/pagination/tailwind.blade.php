@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
        <p class="text-sm text-slate-600 dark:text-slate-400">
            <span class="font-semibold text-slate-900 dark:text-slate-200">{{ $paginator->firstItem() }}</span>
            –
            <span class="font-semibold text-slate-900 dark:text-slate-200">{{ $paginator->lastItem() }}</span>
            of
            <span class="font-semibold text-slate-900 dark:text-slate-200">{{ $paginator->total() }}</span>
            {{ $paginator->perPage() === 1 ? 'result' : 'results' }}
        </p>

        <ul class="flex items-center gap-1">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <li class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-400 dark:border-slate-700 dark:text-slate-600" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                    <x-icon name="chevron-left" class="h-4 w-4"/>
                </li>
            @else
                <li>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}"
                       class="flex rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                        <x-icon name="chevron-left" class="h-4 w-4"/>
                    </a>
                </li>
            @endif

            {{-- Pagination Elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <li class="px-2 py-1.5 text-sm text-slate-400 dark:text-slate-600">{{ $element }}</li>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white" aria-current="page">{{ $page }}</li>
                        @else
                            <li>
                                <a href="{{ $url }}"
                                   class="flex rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                                    {{ $page }}
                                </a>
                            </li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <li>
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}"
                       class="flex rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                        <x-icon name="chevron-right" class="h-4 w-4"/>
                    </a>
                </li>
            @else
                <li class="cursor-not-allowed rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-400 dark:border-slate-700 dark:text-slate-600" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                    <x-icon name="chevron-right" class="h-4 w-4"/>
                </li>
            @endif
        </ul>
    </nav>
@endif
