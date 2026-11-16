@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <label class="label" for="b64-input">Text or Base64</label>
    <textarea id="b64-input" rows="6" class="input resize-y font-mono text-[13px]" placeholder="Paste text to encode, or Base64 to decode…" data-input spellcheck="false"></textarea>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button type="button" class="btn-primary" data-encode>Encode →</button>
        <button type="button" class="btn-secondary" data-decode>← Decode</button>
        <label class="ml-2 flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" class="h-4 w-4 rounded accent-brand-600" data-urlsafe>
            URL-safe output
        </label>
    </div>

    <p class="mt-3 min-h-5 font-medium" data-status aria-live="polite"></p>

    <label class="label mt-2" for="b64-output">Result</label>
    <textarea id="b64-output" rows="6" class="input resize-y bg-brand-50/40 font-mono text-[13px]" readonly data-output spellcheck="false"></textarea>

    <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" class="btn-primary" data-copy>Copy result</button>
        <button type="button" class="btn-danger" data-clear>Clear</button>
    </div>
</div>
@endsection
