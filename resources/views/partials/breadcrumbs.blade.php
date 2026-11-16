@php
    $items = $breadcrumbs ?? [];
@endphp

@if(count($items) > 0 || (isset($pageType) && in_array($pageType, ['tool', 'category', 'blog_post', 'blog_category', 'static_page'])))
    <nav aria-label="Breadcrumb" class="mb-6">
        <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
            <li class="flex items-center gap-1.5">
                <a href="{{ route('home') }}" class="hover:text-brand-600">Home</a>
            </li>
            @foreach($items as $item)
                <li class="flex items-center gap-1.5">
                    <x-icon name="chevron-right" class="h-3.5 w-3.5 text-slate-300"/>
                    @if(!empty($item['url']))
                        <a href="{{ $item['url'] }}" class="hover:text-brand-600">{{ \Illuminate\Support\Str::limit($item['label'], 50) }}</a>
                    @else
                        <span class="text-slate-700 font-medium" aria-current="page">{{ \Illuminate\Support\Str::limit($item['label'], 60) }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
