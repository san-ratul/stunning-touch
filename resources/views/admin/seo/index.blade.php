@extends('layouts.admin')

@section('title', 'SEO Manager')

@section('content')
    <p class="mb-4 max-w-2xl text-sm text-slate-600">
        Manage per-page SEO metadata: titles, descriptions, keywords, canonical URLs, Open Graph, Twitter cards,
        robots directives and schema types. Every entry maps to a real page.
    </p>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[700px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Page</th>
                        <th class="px-4 py-3 font-semibold">Type</th>
                        <th class="px-4 py-3 font-semibold">Meta Title</th>
                        <th class="px-4 py-3 font-semibold">Robots</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($entities as $entity)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 font-semibold text-slate-900">{{ \Illuminate\Support\Str::limit($entity['title'], 60) }}</td>
                            <td class="px-4 py-3"><span class="badge-slate">{{ ucwords(str_replace('_', ' ', $entity['type'])) }}</span></td>
                            <td class="px-4 py-3 max-w-[280px] truncate text-slate-600">
                                {{ $entity['seo']?->meta_title ?: \Illuminate\Support\Str::of($entity['title'])->lower()->title() }}
                            </td>
                            <td class="px-4 py-3">
                                @if(($entity['seo']?->robots_index ?? true) && ($entity['seo']?->robots_follow ?? true))
                                    <span class="badge-green">index, follow</span>
                                @else
                                    <span class="badge-amber">{{ ($entity['seo']?->robots_index ?? true) ? 'index' : 'noindex' }}, {{ ($entity['seo']?->robots_follow ?? true) ? 'follow' : 'nofollow' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.seo.edit', [$entity['type'], $entity['id']]) }}" class="btn-secondary px-2.5 py-1 text-xs">Edit SEO</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
