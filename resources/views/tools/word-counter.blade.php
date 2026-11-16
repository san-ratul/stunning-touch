@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <label class="label" for="wc-input">Type or paste your text</label>
    <textarea id="wc-input" rows="10" class="input resize-y font-normal" placeholder="Start typing or paste your text here…" data-input></textarea>

    <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" data-copy>Copy text</button>
        <button type="button" class="btn-danger" data-clear>Clear</button>
    </div>

    <div class="mt-6" data-stats hidden>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['words', 'Words'],
                ['chars', 'Characters'],
                ['chars-nospace', 'Chars (no spaces)'],
                ['sentences', 'Sentences'],
                ['paragraphs', 'Paragraphs'],
                ['reading', 'Reading time'],
                ['lines', 'Lines'],
                ['longest', 'Longest word'],
            ] as [$key, $label])
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-center">
                    <p class="text-2xl font-extrabold tabular-nums text-brand-700" data-count-{{ $key }}>0</p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <p class="mt-6 text-center text-sm text-slate-500" data-empty>Statistics appear here as you type.</p>
</div>
@endsection
