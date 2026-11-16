@php
    /** @var \App\Models\Advertisement $ad */
@endphp
@php
    \App\Support\AdRenderer::recordImpression($ad->id);
@endphp

<div class="ad-slot my-6 flex min-h-[90px] items-center justify-center overflow-hidden rounded-xl text-center"
     data-ad-placement="{{ $placementKey }}" data-ad-id="{{ $ad->id }}" aria-label="Advertisement">
    @if($ad->ad_type === 'banner' && $ad->image_path)
        <a href="{{ $ad->destination_url ? route('ad.click', $ad) : '#' }}"
           target="_blank" rel="noopener sponsored nofollow"
           class="block">
            <img src="{{ asset('storage/'.$ad->image_path) }}"
                 alt="{{ $ad->image_alt ?: $ad->name }}"
                 width="{{ $ad->width }}" height="{{ $ad->height }}"
                 loading="lazy" class="max-h-[250px] w-auto max-w-full">
        </a>
    @else
        {!! $ad->ad_code !!}
    @endif
</div>
