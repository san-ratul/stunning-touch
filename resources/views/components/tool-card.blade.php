@php
    /** @var \App\Models\Tool $tool */
@endphp

<a href="{{ $tool->url() }}"
   class="card card-hover group flex flex-col gap-3 p-5"
   @if(!empty($featured)) data-featured @endif>
    <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600/10 to-accent-500/10 text-brand-600 ring-1 ring-brand-600/10 transition-colors group-hover:from-brand-600/20 group-hover:to-accent-500/20">
            <x-icon name="{{ $tool->icon ?: 'tools' }}" class="h-5 w-5"/>
        </span>
        <h3 class="font-semibold text-slate-900 group-hover:text-brand-700">{{ $tool->name }}</h3>
    </div>
    <p class="text-sm leading-relaxed text-slate-600">{{ $tool->short_description }}</p>
    <span class="mt-auto inline-flex items-center gap-1 text-sm font-medium text-brand-600">
        Use tool
        <x-icon name="chevron-right" class="h-4 w-4 transition-transform group-hover:translate-x-0.5"/>
    </span>
</a>
