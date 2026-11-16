@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <label class="label" for="cc-input">Enter your text</label>
    <textarea id="cc-input" rows="6" class="input resize-y" placeholder="Type or paste text to convert…" data-input></textarea>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <button type="button" class="btn-secondary" data-transform="upper">UPPERCASE</button>
        <button type="button" class="btn-secondary" data-transform="lower">lowercase</button>
        <button type="button" class="btn-secondary" data-transform="title">Title Case</button>
        <button type="button" class="btn-secondary" data-transform="sentence">Sentence case</button>
        <button type="button" class="btn-secondary" data-transform="capitalized">Capitalized Words</button>
        <button type="button" class="btn-secondary" data-transform="inverse">iNVERSE cASE</button>
    </div>

    <label class="label mt-6" for="cc-output">Result</label>
    <textarea id="cc-output" rows="6" class="input resize-y bg-brand-50/50" readonly placeholder="Converted text appears here…" data-output data-placeholder="Converted text appears here…"></textarea>

    <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" class="btn-primary" data-copy>Copy result</button>
        <button type="button" class="btn-danger" data-clear>Clear all</button>
    </div>
</div>
@endsection
