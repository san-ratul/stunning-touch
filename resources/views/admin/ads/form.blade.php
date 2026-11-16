@extends('layouts.admin')

@section('title', $ad->exists ? 'Edit Advertisement' : 'Create Advertisement')

@section('content')
    @php
        $knownProviders = ['adsense', 'monetag', 'adsterra', 'propellerads', 'ezoic', 'infolinks', 'revcontent', 'adblade'];
        $currentProvider = old('provider', $ad->provider);
        $providerPreset = in_array($currentProvider, $knownProviders, true) ? $currentProvider : 'custom';
        $providerName = $providerPreset === 'custom' ? $currentProvider : '';
    @endphp

    <form method="POST"
          action="{{ $ad->exists ? route('admin.advertisements.update', $ad) : route('admin.advertisements.store') }}"
          enctype="multipart/form-data"
          x-data="{ type: '{{ old('ad_type', $ad->ad_type) }}', provider: '{{ old('provider_preset', $providerPreset) }}', providerName: '{{ old('provider_name', $providerName) }}' }"
          class="grid gap-6 xl:grid-cols-3">
        @csrf
        @if($ad->exists) @method('PUT') @endif

        <input type="hidden" name="provider" :value="provider === 'custom' ? (providerName.trim() || 'custom') : provider">

        <div class="space-y-6 xl:col-span-2">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Advertisement</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="name">Internal name *</label>
                        <input id="name" name="name" value="{{ old('name', $ad->name) }}" required class="input" maxlength="150">
                    </div>
                    <div>
                        <label class="label" for="ad_placement_id">Ad placement *</label>
                        <select id="ad_placement_id" name="ad_placement_id" required class="input">
                            @forelse($placements as $placement)
                                <option value="{{ $placement->id }}" {{ old('ad_placement_id', $ad->ad_placement_id) == $placement->id ? 'selected' : '' }}>
                                    {{ $placement->name }} — {{ $placement->key }}
                                </option>
                            @empty
                                <option value="">No placements configured</option>
                            @endforelse
                        </select>
                        @if($placements->isEmpty())
                            <p class="mt-1 text-xs text-red-600">⚠ No ad placements exist. Re-run the database seeder to create them.</p>
                        @endif
                    </div>
                    <div>
                        <label class="label" for="provider">Provider / network *</label>
                        <select id="provider" name="provider_preset" class="input" x-model="provider">
                            <option value="adsense">Google AdSense</option>
                            <option value="monetag">Monetag</option>
                            <option value="adsterra">Adsterra</option>
                            <option value="propellerads">PropellerAds</option>
                            <option value="ezoic">Ezoic</option>
                            <option value="infolinks">Infolinks</option>
                            <option value="revcontent">RevContent</option>
                            <option value="adblade">AdBlade</option>
                            <option value="custom">Custom / Other network</option>
                        </select>
                    </div>
                    <div x-show="provider === 'custom'" x-cloak x-transition.opacity.duration.150ms>
                        <label class="label" for="provider_name">Custom provider name *</label>
                        <input id="provider_name" name="provider_name" x-model="providerName" class="input"
                               placeholder="e.g. my-ad-network" maxlength="100">
                    </div>
                    <div>
                        <label class="label" for="ad_type">Ad type *</label>
                        <select id="ad_type" name="ad_type" class="input" x-model="type">
                            <option value="code" {{ old('ad_type', $ad->ad_type) === 'code' ? 'selected' : '' }}>Ad code / HTML + JS</option>
                            <option value="banner" {{ old('ad_type', $ad->ad_type) === 'banner' ? 'selected' : '' }}>Image banner</option>
                        </select>
                    </div>
                    <div x-show="type === 'code'" x-cloak class="sm:col-span-2">
                        <label class="label" for="ad_code">Ad code (HTML + JavaScript from your network)</label>
                        <textarea id="ad_code" name="ad_code" rows="10" class="input font-mono text-xs scroll-thin"
                                  placeholder="Paste the ad network's code snippet here, e.g.&#10;<script type='text/javascript' src='//network.example/ad.js'></script>">{{ old('ad_code', $ad->ad_code) }}</textarea>
                        <p class="mt-1.5 text-xs text-slate-500">
                            ⚠ Trusted admin input — rendered only on public placements, never inside admin screens.
                        </p>
                    </div>
                    <div x-show="type === 'banner'" x-cloak class="sm:col-span-2 space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40">
                        <div>
                            <label class="label" for="image">Banner image</label>
                            <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" class="input file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-1.5 file:text-white">
                            @if($ad->image_path)
                                <p class="mt-2 text-xs text-slate-500">Current: <a href="{{ asset('storage/'.$ad->image_path) }}" target="_blank" class="text-brand-600">{{ $ad->image_path }}</a></p>
                            @endif
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="label" for="destination_url">Destination URL</label>
                                <input id="destination_url" name="destination_url" type="url" value="{{ old('destination_url', $ad->destination_url) }}" class="input" placeholder="https://…">
                            </div>
                            <div>
                                <label class="label" for="image_alt">Alt text</label>
                                <input id="image_alt" name="image_alt" value="{{ old('image_alt', $ad->image_alt) }}" class="input">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="label" for="width">Width</label>
                                    <input id="width" type="number" name="width" min="1" value="{{ old('width', $ad->width) }}" class="input">
                                </div>
                                <div>
                                    <label class="label" for="height">Height</label>
                                    <input id="height" type="number" name="height" min="1" value="{{ old('height', $ad->height) }}" class="input">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Targeting</h2>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="device_targeting">Device targeting</label>
                        <select id="device_targeting" name="device_targeting" class="input">
                            @foreach(['all' => 'All devices', 'desktop' => 'Desktop only', 'mobile' => 'Mobile only', 'tablet' => 'Tablet only'] as $value => $label)
                                <option value="{{ $value }}" {{ old('device_targeting', $ad->device_targeting) === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Page types (empty = all pages)</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($pageTypes as $key => $label)
                                <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                                    <input type="checkbox" name="target_pages[]" value="{{ $key }}" class="h-3.5 w-3.5 rounded accent-brand-600" {{ in_array($key, old('target_pages', $ad->target_pages ?? [])) ? 'checked' : '' }}>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="label">Limit to tools (empty = all tools)</label>
                        <select name="target_tools[]" multiple class="input h-32">
                            @foreach($tools as $tool)
                                <option value="{{ $tool->component_key }}" {{ in_array($tool->component_key, old('target_tools', $ad->target_tools ?? [])) ? 'selected' : '' }}>{{ $tool->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="starts_at">Starts at</label>
                            <input id="starts_at" type="datetime-local" name="starts_at" value="{{ old('starts_at', $ad->starts_at?->format('Y-m-d\TH:i')) }}" class="input">
                        </div>
                        <div>
                            <label class="label" for="ends_at">Ends at</label>
                            <input id="ends_at" type="datetime-local" name="ends_at" value="{{ old('ends_at', $ad->ends_at?->format('Y-m-d\TH:i')) }}" class="input">
                        </div>
                    </div>
                    <div>
                        <label class="label" for="priority">Priority (higher shows first)</label>
                        <input id="priority" type="number" name="priority" min="0" max="1000" value="{{ old('priority', $ad->priority ?: 0) }}" class="input">
                    </div>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_active', $ad->exists ? $ad->is_active : true) ? 'checked' : '' }}>
                        Active
                    </label>
                </div>
            </div>

            <button type="submit" class="btn-accent btn-lg w-full">{{ $ad->exists ? 'Save Changes' : 'Create Advertisement' }}</button>
        </div>
    </form>
@endsection
