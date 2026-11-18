/**
 * Regex Tester UI.
 *
 * - Live analysis via a Web Worker with a time-budget watchdog: a
 *   catastrophic pattern gets its worker terminated instead of freezing
 *   the tab; the next input transparently re-spawns the worker.
 * - All rendering uses DOM textContent — no HTML injection anywhere.
 * - Everything is processed locally; no network requests are made.
 */
import { debounce } from './helpers.js';
import { trackToolUsage } from '../analytics';
import { analyze, MAX_TEXT_LENGTH, parseShorthand } from './regex-core.js';
import { explainPattern } from './regex-explain.js';

const WORKER_TIME_BUDGET_MS = 1500;
const DEBOUNCE_MS = 250;

const SAMPLES = [
    { label: 'Emails', pattern: '[\\w.+-]+@[\\w-]+\\.[\\w.]+', flags: 'g', text: 'Contact jane.doe@example.com or sales+team@shop.co.uk.\nInvalid: not-an-email@@example\nAnother: john@localhost' },
    { label: 'Dates (YYYY-MM-DD)', pattern: '(?<year>\\d{4})-(?<month>\\d{2})-(?<day>\\d{2})', flags: 'g', text: 'Born 1990-05-12\nDeadline 2027-01-31\nBad format 12/31/2027\nRange 2026-11-17 to 2027-01-31' },
    { label: 'Numbers', pattern: '\\b\\d+(?:\\.\\d+)?\\b', flags: 'g', text: 'Prices: 19.99, 42, 1,000\nVersion 3.14 in use\nNo digits here!' },
    { label: 'Anchored lines', pattern: '^TODO: .+$', flags: 'gm', text: 'TODO: write tests\nDone: write tests\nTODO: fix bug\n  TODO: indented (whitespace!)\nTODO:' },
    { label: 'Hex colors', pattern: '#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\\b', flags: 'g', text: '#fff and #AABBCC are valid\n#12345 too short, #GGGGGG invalid\nBackground: #00ff00aa8' },
    { label: 'Unicode', pattern: '\\p{L}+', flags: 'gu', text: 'Café, naïve, résumé\n日本語 and ελληνικά\nNumbers 123 stay out' },
];

export function init(el) {
    const patternInput = el.querySelector('[data-pattern]');
    const testInput = el.querySelector('[data-test]');
    const flagBoxes = [...el.querySelectorAll('[data-flag]')];
    const clearPattern = el.querySelector('[data-clear-pattern]');
    const clearAll = el.querySelector('[data-clear-all]');
    const sampleButtons = [...el.querySelectorAll('[data-sample]')];
    const copyRegex = el.querySelector('[data-copy-regex]');

    const errorBox = el.querySelector('[data-error]');
    const resultsBox = el.querySelector('[data-results]');
    const emptyBox = el.querySelector('[data-empty]');
    const statsBox = el.querySelector('[data-stats]');
    const countMatches = el.querySelector('[data-count-matches]');
    const countLines = el.querySelector('[data-count-full-lines]');
    const countPartials = el.querySelector('[data-count-partial-lines]');
    const matchesList = el.querySelector('[data-matches]');
    const linesList = el.querySelector('[data-lines]');
    const truncatedNote = el.querySelector('[data-truncated]');
    const explainList = el.querySelector('[data-explanation]');
    const explanationPanel = el.querySelector('[data-explanation-panel]');

    let worker = null;
    let workerSeq = 0;
    let watchdog = null;
    let lastRenderedSeq = -1;

    const schedule = debounce(run, DEBOUNCE_MS);

    patternInput.addEventListener('input', schedule);
    testInput.addEventListener('input', schedule);

    for (const box of flagBoxes) {
        box.addEventListener('change', schedule);
    }

    clearPattern.addEventListener('click', () => {
        patternInput.value = '';
        schedule();
        patternInput.focus();
    });

    clearAll.addEventListener('click', () => {
        patternInput.value = '';
        testInput.value = '';
        for (const box of flagBoxes) {
            box.checked = ['g'].includes(box.dataset.flag);
        }
        schedule();
    });

    for (const button of sampleButtons) {
        button.addEventListener('click', () => {
            const sample = SAMPLES.find((s) => s.label === button.dataset.sample);
            if (!sample) return;
            patternInput.value = sample.pattern;
            testInput.value = sample.text;
            for (const box of flagBoxes) {
                box.checked = sample.flags.includes(box.dataset.flag);
            }
            schedule();
        });
    }

    copyRegex.addEventListener('click', async (e) => {
        await navigator.clipboard.writeText(currentPattern().pattern);
        flash(e.currentTarget, 'Copied!');
    });

    function currentFlags() {
        return flagBoxes.filter((b) => b.checked).map((b) => b.dataset.flag).join('');
    }

    function currentPattern() {
        const value = patternInput.value;
        const shorthand = parseShorthand(value);

        if (shorthand.isShorthand) {
            return shorthand;
        }


        return { pattern: value, flags: '' };
    }

    function run() {
        const shorthand = currentPattern();
        const flags = (shorthand.flags !== '' ? shorthand.flags : currentFlags());
        const text = testInput.value;

        renderExplanation(shorthand.pattern);

        if (text.length > MAX_TEXT_LENGTH) {
            showError(`The test string is too long (maximum ${MAX_TEXT_LENGTH.toLocaleString()} characters). Everything after the limit is ignored.`);
            return;
        }

        if (shorthand.pattern === '' && text === '') {
            showEmpty();
            return;
        }

        dispatch({ pattern: shorthand.pattern, flags, text });
    }

    function dispatch(job) {
        const seq = ++workerSeq;

        const w = worker ?? createWorker();
        worker = w;

        if (!w) {
            // Workers unavailable: synchronous fallback with a hard input cap.
            try {
                lastRenderedSeq = seq;
                render(analyze(job.pattern, job.flags, job.text));
            } catch {
                showError('The pattern could not be processed safely in this browser.');
            }

            return;
        }

        // Watchdog: a stuck worker is terminated, not allowed to freeze the page.
        clearTimeout(watchdog);
        watchdog = setTimeout(() => {
            if (worker) {
                worker.terminate();
                worker = null;
            }

            if (seq > lastRenderedSeq) {
                lastRenderedSeq = seq;
                showError('This pattern took too long to evaluate (possible catastrophic backtracking) and was stopped. Try simplifying the pattern or reducing the input size.');
            }
        }, WORKER_TIME_BUDGET_MS);

        w.onmessage = (event) => {
            if (event.data.id !== seq || seq <= lastRenderedSeq) return;
            clearTimeout(watchdog);
            lastRenderedSeq = seq;

            if (event.data.ok) {
                render(event.data.result);
            } else {
                showError(event.data.error ?? 'The pattern could not be processed.');
            }
        };

        w.postMessage({ id: seq, ...job });
    }

    function createWorker() {
        try {
            const w = new Worker(new URL('./regex-worker.js', import.meta.url), { type: 'module' });
            w.onerror = () => {
                w.terminate();
                if (w === worker) worker = null;
            };

            return w;
        } catch {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Rendering (all via textContent / DOM creation — no HTML injection)
    // ------------------------------------------------------------------

    function render(result) {
        if (result.error) {
            showError(result.error);
            renderLines(result.lines, false);
            trackToolUsage(el.dataset.tool, false);
            return;
        }

        hide(errorBox);
        show(resultsBox);
        hide(emptyBox);
        show(statsBox);
        trackToolUsage(el.dataset.tool, true);

        countMatches.textContent = result.matchCount.toLocaleString();
        countLines.textContent = result.fullLineMatches.toLocaleString();
        countPartials.textContent = result.partialLines.toLocaleString();
        truncatedNote.hidden = !result.truncated;

        renderMatches(result.matches);
        renderLines(result.lines, true);
    }

    function renderMatches(matches) {
        matchesList.textContent = '';

        if (matches.length === 0) {
            const li = document.createElement('li');
            li.className = 'px-3 py-2 text-sm text-slate-500';
            li.textContent = 'No matches in the test string.';
            matchesList.appendChild(li);

            return;
        }

        for (const match of matches.slice(0, 200)) {
            const li = document.createElement('li');
            li.className = 'flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-sm';

            const badge = document.createElement('span');
            badge.className = 'rounded-md bg-brand-50 px-2 py-0.5 font-mono text-xs font-semibold text-brand-700 break-all';
            badge.textContent = match.text === '' ? '(empty match)' : match.text;
            li.appendChild(badge);

            const position = document.createElement('span');
            position.className = 'text-xs text-slate-500';
            position.textContent = `index ${match.index}`;
            li.appendChild(position);

            if (match.groups && match.groups.some((g) => g !== null)) {
                const groups = document.createElement('span');
                groups.className = 'text-xs text-slate-500';
                groups.textContent = 'groups: ' + match.groups.map((g, i) => `$${i + 1}=${g === null ? '—' : g}`).join(' ');
                li.appendChild(groups);
            }

            if (match.namedGroups) {
                const named = Object.entries(match.namedGroups)
                    .filter(([, value]) => value !== undefined)
                    .map(([name, value]) => `${name}=${value}`);

                if (named.length > 0) {
                    const span = document.createElement('span');
                    span.className = 'text-xs text-accent-600';
                    span.textContent = named.join(' ');
                    li.appendChild(span);
                }
            }

            matchesList.appendChild(li);
        }
    }

    function renderLines(lines, active) {
        linesList.textContent = '';

        if (!active) {
            return;
        }

        lines.forEach((line) => {
            const row = document.createElement('div');
            row.className = 'rounded-xl border p-3 ' + lineBorderClass(line.status);

            const header = document.createElement('div');
            header.className = 'flex flex-wrap items-center gap-2';

            const label = document.createElement('span');
            label.className = 'text-xs font-bold uppercase tracking-wide ' + lineTextClass(line.status);
            label.textContent = { full: 'Full match', partial: 'Partial match', none: 'No match', empty: 'Empty line' }[line.status];
            header.appendChild(label);

            const lineNo = document.createElement('span');
            lineNo.className = 'text-xs text-slate-400';
            lineNo.textContent = `Line ${line.index + 1}`;
            header.appendChild(lineNo);

            const reason = document.createElement('span');
            reason.className = 'text-xs text-slate-500';
            reason.textContent = line.reason;
            header.appendChild(reason);

            row.appendChild(header);

            if (line.text !== '') {
                const content = document.createElement('div');
                content.className = 'mt-2 whitespace-pre-wrap break-all font-mono text-sm leading-relaxed text-slate-800';
                content.appendChild(buildHighlight(line));
                row.appendChild(content);
            }

            linesList.appendChild(row);
        });
    }

    function buildHighlight(line) {
        const fragment = document.createDocumentFragment();
        const spans = [...line.partials.map((p) => ({ index: line.offset + p.index, end: line.offset + p.index + p.text.length, kind: 'partial' })),
            ...line.spans.map((s) => ({ index: s.index, end: s.end, kind: 'full' }))]
            .sort((a, b) => a.index - b.index || b.end - a.end);

        // Merge overlapping spans, full beats partial on ties.
        const merged = [];

        for (const span of spans) {
            const last = merged[merged.length - 1];

            if (last && span.index < last.end) {
                if (span.kind === 'full' && last.kind === 'partial' && span.index === last.index && span.end >= last.end) {
                    merged[merged.length - 1] = span;
                }

                continue;
            }

            merged.push(span);
        }

        const lineStart = line.offset;
        const lineEnd = line.offset + line.text.length;
        let cursor = lineStart;

        for (const span of merged) {
            const start = Math.max(cursor, span.index);
            const end = Math.min(lineEnd, span.end);

            if (start > cursor) {
                fragment.appendChild(document.createTextNode(line.text.slice(cursor - lineStart, start - lineStart)));
            }

            if (end > start) {
                const mark = document.createElement('mark');
                mark.className = span.kind === 'full' ? 'rounded bg-yellow-200/70 px-0.5 text-slate-900' : 'rounded bg-orange-200/70 px-0.5 text-slate-900';
                mark.textContent = line.text.slice(start - lineStart, end - lineStart);
                fragment.appendChild(mark);
            }

            cursor = Math.max(cursor, end);
        }

        if (cursor < lineEnd) {
            fragment.appendChild(document.createTextNode(line.text.slice(cursor - lineStart)));
        }

        return fragment;
    }

    function renderExplanation(pattern) {
        explainList.textContent = '';

        if (pattern.trim() === '') {
            explanationPanel.hidden = true;

            return;
        }

        explanationPanel.hidden = false;
        const tokens = explainPattern(pattern);

        for (const token of tokens) {
            const li = document.createElement('li');
            li.className = 'flex flex-wrap items-baseline gap-x-3 py-1.5 border-b border-slate-100 last:border-0';

            const code = document.createElement('code');
            code.className = 'rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-800 break-all ' + tokenClass(token.type);
            code.textContent = token.text;
            li.appendChild(code);

            const description = document.createElement('span');
            description.className = token.type === 'unsupported' ? 'text-sm font-medium text-rose-600' : 'text-sm text-slate-600';
            description.textContent = token.description;
            li.appendChild(description);

            explainList.appendChild(li);
        }
    }

    function tokenClass(type) {
        return {
            'group-start': 'bg-sky-100 text-sky-800',
            'group-end': 'bg-sky-100 text-sky-800',
            lookaround: 'bg-violet-100 text-violet-800',
            class: 'bg-emerald-100 text-emerald-800',
            quantifier: 'bg-amber-100 text-amber-800',
            anchor: 'bg-rose-100 text-rose-700',
            alternation: 'bg-rose-100 text-rose-700',
            backreference: 'bg-indigo-100 text-indigo-800',
            unsupported: 'bg-rose-100 text-rose-700',
            escape: 'bg-emerald-100 text-emerald-800',
            dot: 'bg-emerald-100 text-emerald-800',
        }[type] ?? 'bg-slate-100 text-slate-800';
    }

    function lineBorderClass(status) {
        return {
            full: 'border-emerald-200 bg-emerald-50/50',
            partial: 'border-orange-200 bg-orange-50/50',
            none: 'border-slate-200',
            empty: 'border-slate-100 bg-slate-50/50',
        }[status];
    }

    function lineTextClass(status) {
        return {
            full: 'text-emerald-700',
            partial: 'text-orange-600',
            none: 'text-slate-500',
            empty: 'text-slate-400',
        }[status];
    }

    function showError(message) {
        errorBox.textContent = message;
        show(errorBox);
        hide(resultsBox);
        show(emptyBox);
        hide(statsBox);
        trackToolUsage(el.dataset.tool, false);
    }

    function showEmpty() {
        hide(errorBox);
        hide(resultsBox);
        show(emptyBox);
        hide(statsBox);
        explanationPanel.hidden = true;
    }

    function show(node) {
        node.hidden = false;
    }

    function hide(node) {
        node.hidden = true;
    }

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => {
            btn.textContent = original;
        }, 1200);
    }

    // Initial render for server-provided defaults (empty by default).
    run();
}
