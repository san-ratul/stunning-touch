@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}" x-data="{ showFlags: false, showExplain: false }">

    {{-- Pattern input --}}
    <div>
        <div class="flex items-baseline justify-between gap-3">
            <label class="label" for="rt-pattern">Regular expression</label>
            <button type="button" class="text-xs font-medium text-brand-600 hover:text-brand-700"
                    @click="showFlags = !showFlags" :aria-expanded="showFlags">
                Flags reference
            </button>
        </div>
        <input type="text" id="rt-pattern" class="input font-mono" placeholder="\\d{4}-\\d{2}-\\d{2} or /pattern/flags" data-pattern autocomplete="off" spellcheck="false">
        <p class="mt-1.5 text-xs text-slate-500">
            Tip: paste <code class="rounded bg-slate-100 px-1 font-mono">/pattern/flags</code> shorthand and the flags are detected automatically. Patterns are processed entirely in your browser.
        </p>

        <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Regex flags">
            @foreach([
                ['g', 'g · Global'],
                ['i', 'i · Ignore case'],
                ['m', 'm · Multiline'],
                ['s', 's · Dot all'],
                ['u', 'u · Unicode'],
                ['y', 'y · Sticky'],
            ] as [$flag, $label])
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 transition-colors has-checked:border-brand-500 has-checked:bg-brand-50 has-checked:text-brand-700">
                    <input type="checkbox" class="h-3.5 w-3.5 accent-brand-600" data-flag="{{ $flag }}" {{ $flag === 'g' ? 'checked' : '' }}>
                    {{ $label }}
                </label>
            @endforeach
        </div>

        {{-- Flags reference (collapsible) --}}
        <div x-show="showFlags" x-cloak x-collapse class="mt-3">
            <div class="overflow-hidden rounded-xl border border-slate-200">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">Flag</th>
                            <th scope="col" class="px-4 py-2.5">Meaning</th>
                            <th scope="col" class="px-4 py-2.5">Example</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="px-4 py-2 font-mono font-bold text-brand-700">g</td><td class="px-4 py-2 text-slate-600">Global — find all matches instead of stopping after the first.</td><td class="px-4 py-2 font-mono text-xs text-slate-500">/cat/g</td></tr>
                        <tr><td class="px-4 py-2 font-mono font-bold text-brand-700">i</td><td class="px-4 py-2 text-slate-600">Ignore case — case-insensitive matching.</td><td class="px-4 py-2 font-mono text-xs text-slate-500">/hello/i</td></tr>
                        <tr><td class="px-4 py-2 font-mono font-bold text-brand-700">m</td><td class="px-4 py-2 text-slate-600">Multiline — ^ and $ match at line boundaries.</td><td class="px-4 py-2 font-mono text-xs text-slate-500">/^TODO:/gm</td></tr>
                        <tr><td class="px-4 py-2 font-mono font-bold text-brand-700">s</td><td class="px-4 py-2 text-slate-600">Dot all — . also matches line terminators.</td><td class="px-4 py-2 font-mono text-xs text-slate-500">/a.*b/s</td></tr>
                        <tr><td class="px-4 py-2 font-mono font-bold text-brand-700">u</td><td class="px-4 py-2 text-slate-600">Unicode — full Unicode matching and \\p{…} property classes.</td><td class="px-4 py-2 font-mono text-xs text-slate-500">/\\p{L}+/gu</td></tr>
                        <tr><td class="px-4 py-2 font-mono font-bold text-brand-700">y</td><td class="px-4 py-2 text-slate-600">Sticky — matches only at the lastIndex position.</td><td class="px-4 py-2 font-mono text-xs text-slate-500">/ab/y</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Test string --}}
    <div class="mt-6">
        <label class="label" for="rt-test">Test string</label>
        <textarea id="rt-test" rows="8" class="input resize-y font-mono text-sm" placeholder="Paste the text you want to test against…" data-test spellcheck="false"></textarea>
    </div>

    {{-- Actions --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button type="button" class="btn-secondary" data-copy-regex>Copy regex</button>
        <button type="button" class="btn-danger" data-clear-pattern>Clear pattern</button>
        <button type="button" class="btn-danger" data-clear-all>Clear all</button>
    </div>

    {{-- Samples --}}
    <div class="mt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sample patterns</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach(['Emails', 'Dates (YYYY-MM-DD)', 'Numbers', 'Anchored lines', 'Hex colors', 'Unicode'] as $sample)
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:border-brand-300 hover:text-brand-700" data-sample="{{ $sample }}">{{ $sample }}</button>
            @endforeach
        </div>
    </div>

    {{-- Error banner --}}
    <div class="mt-6 hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700" role="alert" data-error></div>

    {{-- Empty state --}}
    <div class="mt-8 rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center" data-empty>
        <p class="font-medium text-slate-600">Enter a pattern and a test string</p>
        <p class="mt-1 text-sm text-slate-500">Matches, per-line diagnostics and a pattern explanation appear here as you type. Nothing is ever sent to a server.</p>
    </div>

    {{-- Results --}}
    <div class="mt-8" data-results hidden>
        <div class="grid grid-cols-3 gap-3" data-stats hidden>
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-center">
                <p class="text-2xl font-extrabold tabular-nums text-brand-700" data-count-matches>0</p>
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">Total matches</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-center">
                <p class="text-2xl font-extrabold tabular-nums text-emerald-600" data-count-full-lines>0</p>
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">Full line matches</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-center">
                <p class="text-2xl font-extrabold tabular-nums text-orange-500" data-count-partial-lines>0</p>
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">Partial lines</p>
            </div>
        </div>
        <p class="mt-2 hidden text-xs text-amber-600" data-truncated>Large input — analysis was limited to the first 100,000 characters.</p>

        {{-- Match list --}}
        <section class="mt-6" aria-labelledby="rt-matches-heading">
            <h3 id="rt-matches-heading" class="text-sm font-bold uppercase tracking-wide text-slate-900">Matches</h3>
            <ul class="mt-2 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200" data-matches></ul>
        </section>

        {{-- Per-line diagnostics --}}
        <section class="mt-6" aria-labelledby="rt-lines-heading">
            <h3 id="rt-lines-heading" class="text-sm font-bold uppercase tracking-wide text-slate-900">Per-line diagnostics</h3>
            <div class="mt-2 space-y-2" data-lines></div>
        </section>
    </div>

    {{-- Pattern explanation --}}
    <section class="mt-8" aria-labelledby="rt-explain-heading" data-explanation-panel hidden>
        <div class="flex items-baseline justify-between gap-3">
            <h3 id="rt-explain-heading" class="text-sm font-bold uppercase tracking-wide text-slate-900">Pattern explanation</h3>
            <button type="button" class="text-xs font-medium text-brand-600 hover:text-brand-700" @click="showExplain = !showExplain" :aria-expanded="showExplain">
                Show / hide
            </button>
        </div>
        <ul class="mt-2" x-show="showExplain" x-collapse data-explanation></ul>
        <p class="mt-2 text-xs text-slate-400" x-show="!showExplain">The explanation is generated in your browser as you type the pattern.</p>
    </section>

    {{-- Quick reference --}}
    <section class="mt-8" aria-labelledby="rt-cheatsheet-heading">
        <h3 id="rt-cheatsheet-heading" class="text-sm font-bold uppercase tracking-wide text-slate-900">Quick reference</h3>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
            <div class="overflow-hidden rounded-xl border border-slate-200">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">.</td><td class="px-3 py-2 text-slate-600">Any character except line breaks</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">\\d \\w \\s</td><td class="px-3 py-2 text-slate-600">Digit, word character, whitespace</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">\\D \\W \\S</td><td class="px-3 py-2 text-slate-600">Negated versions of the above</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">[abc] [a-z]</td><td class="px-3 py-2 text-slate-600">Character class / range</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">[^abc]</td><td class="px-3 py-2 text-slate-600">Any character NOT in the class</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">^ $</td><td class="px-3 py-2 text-slate-600">Start / end anchors</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="overflow-hidden rounded-xl border border-slate-200">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">* + ?</td><td class="px-3 py-2 text-slate-600">0+, 1+ and 0-or-1 times (greedy)</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">*? +? ??</td><td class="px-3 py-2 text-slate-600">Lazy versions of the quantifiers</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">{2,5}</td><td class="px-3 py-2 text-slate-600">Between 2 and 5 repetitions</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">(…) (?:…)</td><td class="px-3 py-2 text-slate-600">Capturing / non-capturing group</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">(?&lt;name&gt;…)</td><td class="px-3 py-2 text-slate-600">Named capturing group</td></tr>
                        <tr><td class="px-3 py-2 font-mono text-xs font-bold text-brand-700">(?=…) (?&lt;=…)</td><td class="px-3 py-2 text-slate-600">Lookahead / lookbehind assertion</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
