/**
 * Regex Tester core logic — pure, dependency-free, framework-free.
 *
 * This module is shared between three consumers:
 *  - the Web Worker (regex-worker.js) that runs analysis off the main thread,
 *  - the tool UI (regex-tester.js) for light helpers,
 *  - Node-based unit tests (tests/js/regex-core.test.mjs).
 *
 * Behavioral goals (original implementation, inspired by the audited
 * reference tool): live global matching, per-line diagnostics with
 * full/partial/no-match states, helpful mismatch reasons, CRLF-safe line
 * splitting, and safe handling of invalid patterns.
 */

export const MAX_PATTERN_LENGTH = 500;
export const MAX_TEXT_LENGTH = 100_000;
export const MAX_COLLECTED_MATCHES = 5000;

export const SUPPORTED_FLAGS = ['g', 'i', 'm', 's', 'u', 'y'];

/**
 * Parse the `/pattern/flags` shorthand. Returns the input unchanged
 * (with isShorthand=false) when it is not valid shorthand.
 */
export function parseShorthand(input) {
    const value = String(input ?? '');
    const trimmed = value.trim();
    const shorthand = /^\/(.+)\/([a-z]*)$/.exec(trimmed);

    if (shorthand && shorthand[1] !== '' && [...shorthand[2]].every((f) => SUPPORTED_FLAGS.includes(f))) {
        return { pattern: shorthand[1], flags: shorthand[2], isShorthand: true };
    }

    return { pattern: value, flags: '', isShorthand: false };
}

/**
 * Compile a pattern. Returns { regex, error }. `error` carries a helpful,
 * safe message — the raw engine message is never required by the UI.
 */
export function buildRegex(pattern, flags = '') {
    if (typeof pattern !== 'string') {
        return { regex: null, error: 'The pattern must be text.' };
    }

    if (pattern.length > MAX_PATTERN_LENGTH) {
        return { regex: null, error: `The pattern is too long (maximum ${MAX_PATTERN_LENGTH} characters).` };
    }

    const unknown = [...flags].filter((f) => !SUPPORTED_FLAGS.includes(f));

    if (unknown.length > 0) {
        return { regex: null, error: `Unsupported flag(s): ${[...new Set(unknown)].join(', ')}` };
    }

    const unique = [...new Set(flags)].join('');

    try {
        return { regex: new RegExp(pattern, unique), error: null };
    } catch {
        const hint = diagnosePatternError(pattern);
        return { regex: null, error: hint };
    }
}

/** Heuristic hints for the most common pattern syntax mistakes. */
function diagnosePatternError(pattern) {
    if (countUnescaped(pattern, '(') !== countUnescaped(pattern, ')')) {
        return 'Unbalanced parentheses — every "(" needs a matching ")".';
    }

    if (countUnescaped(pattern, '[') !== countUnescaped(pattern, ']')) {
        return 'Unclosed character class — every "[" needs a matching "]".';
    }

    if (/(\*\+|\+\*|\?\*|\?\+)/.test(pattern)) {
        return 'A quantifier cannot follow another quantifier directly.';
    }

    return 'This is not a valid regular expression. Check quantifiers, parentheses and character classes.';
}

function countUnescaped(pattern, char) {
    let count = 0;
    let escaped = false;

    for (const current of pattern) {
        if (escaped) {
            escaped = false;
        } else if (current === '\\') {
            escaped = true;
        } else if (current === char) {
            count += 1;
        }
    }

    return count;
}

/**
 * Remove top-level ^ and $ anchors (outside character classes, not escaped)
 * to build the "relaxed" pattern used for partial-match detection.
 */
export function stripAnchors(pattern) {
    let result = '';
    let inClass = false;
    let escaped = false;

    for (let i = 0; i < pattern.length; i++) {
        const ch = pattern[i];

        if (escaped) {
            result += ch;
            escaped = false;
        } else if (ch === '\\') {
            result += ch;
            escaped = true;
        } else if (ch === '[' && !inClass) {
            inClass = true;
            result += ch;
        } else if (ch === ']' && inClass) {
            inClass = false;
            result += ch;
        } else if ((ch === '^' || ch === '$') && !inClass) {
            // Drop the anchor (including a quantifier directly after ^ or $,
            // which would otherwise be a syntax error after removal).
            const next = pattern[i + 1];

            if (next && '+*?'.includes(next)) {
                i += 1;
            }
        } else {
            result += ch;
        }
    }

    return result;
}

/**
 * Split text into lines with absolute offsets, handling \n, \r\n and \r.
 *
 * @returns {list<{index: number, offset: number, text: string}>}
 */
export function splitLines(text) {
    const lines = [];
    let offset = 0;
    let start = 0;

    for (let i = 0; i < text.length; i++) {
        const ch = text[i];

        if (ch === '\n') {
            lines.push({ index: lines.length, offset: start, text: text.slice(start, i) });
            start = i + 1;
        } else if (ch === '\r') {
            const isCrlf = text[i + 1] === '\n';
            lines.push({ index: lines.length, offset: start, text: text.slice(start, i) });
            i = isCrlf ? i + 1 : i;
            start = i + 1;
        }
    }

    lines.push({ index: lines.length, offset: start, text: text.slice(start) });

    return lines;
}

/** Explanation for why a line did not fully match. */
function lineReason(line, fullMatched, partialCount, trimmedMatched) {
    if (line.text === '') {
        return fullMatched ? 'Matches the whole line.' : 'Empty line.';
    }

    if (fullMatched) {
        return 'Matches the whole line.';
    }

    if (partialCount > 0) {
        return partialCount === 1
            ? 'Contains a valid substring, but the full pattern does not match the whole line (partial match).'
            : `Contains a valid substring ${partialCount} times, but the full pattern does not match the whole line (partial matches).`;
    }

    if (trimmedMatched) {
        return 'Leading or trailing whitespace prevents a full line match.';
    }

    return 'No match in this line.';
}

/**
 * Full analysis pipeline. All regular expressions are created here; the
 * caller (worker) enforces the time budget.
 *
 * @returns {{
 *   error: string|null,
 *   matchCount: number,
 *   truncated: boolean,
 *   matches: list<object>,
 *   lines: list<object>,
 *   fullLineMatches: number,
 *   partialLines: number,
 * }}
 */
export function analyze(pattern, flags, text) {
    const compiled = buildRegex(pattern, flags);

    if (compiled.error !== null) {
        return {
            error: compiled.error,
            matchCount: 0,
            truncated: false,
            matches: [],
            lines: [],
            fullLineMatches: 0,
            partialLines: 0,
        };
    }

    const truncated = text.length > MAX_TEXT_LENGTH;
    const source = truncated ? text.slice(0, MAX_TEXT_LENGTH) : text;

    const allLines = splitLines(source);

    // Global scan for the full-text match list.
    const scanFlags = [...new Set(flags.replace(/y/g, '') + (flags.includes('g') ? '' : 'g'))].join('');
    const scanRegex = new RegExp(pattern, scanFlags);

    const matches = [];
    let matchCount = 0;

    for (const match of source.matchAll(scanRegex)) {
        matchCount += 1;

        if (matches.length < MAX_COLLECTED_MATCHES) {
            matches.push({
                text: match[0],
                index: match.index,
                end: match.index + match[0].length,
                groups: match.slice(1).map((g) => (g === undefined ? null : g)),
                namedGroups: match.groups ? { ...match.groups } : null,
                lineIndex: null,
            });
        }

        if (matchCount >= MAX_COLLECTED_MATCHES * 2) {
            break;
        }
    }

    // Assign each match to a line (matches cannot span lines unless the
    // pattern itself matches newlines; attribute them to the first line).
    let cursor = 0;

    for (const match of matches) {
        while (cursor < allLines.length - 1 && match.index >= allLines[cursor + 1].offset) {
            cursor += 1;
        }

        match.lineIndex = allLines[cursor].index;
    }

    // Per-line diagnostics.
    const fullTestFlags = [...new Set(flags.replace(/[gy]/g, ''))].join('');
    let fullRegex = null;
    let trimmedRegex = null;
    let relaxedRegex = null;

    try {
        fullRegex = new RegExp(`^(?:${pattern})$`, fullTestFlags);

        const relaxed = stripAnchors(pattern);

        if (/\S/.test(source)) {
            // Whitespace trap detection uses the anchor-stripped form so
            // anchored patterns are also recognized after trimming.
            if (relaxed !== '') {
                trimmedRegex = new RegExp(`^(?:\\s*)(?:${relaxed})(?:\\s*)$`, fullTestFlags);
            }
        }

        if (relaxed !== pattern && relaxed !== '') {
            relaxedRegex = new RegExp(relaxed, [...new Set(scanFlags)].join(''));
        }
    } catch {
        // Anchored test forms failing to compile: fall back gracefully.
        fullRegex = null;
        trimmedRegex = null;
        relaxedRegex = null;
    }

    let fullLineMatches = 0;
    let partialLines = 0;

    const lines = allLines.map((line) => {
        const fullMatched = fullRegex ? fullRegex.test(line.text) : false;
        const trimmedMatched = !fullMatched && trimmedRegex ? trimmedRegex.test(line.text) : false;

        // Partial detection is only meaningful when whitespace does not
        // already explain the mismatch.
        const partials = [];

        if (fullRegex && !fullMatched && !trimmedMatched && relaxedRegex) {
            for (const occurrence of line.text.matchAll(relaxedRegex)) {
                partials.push({ index: occurrence.index, text: occurrence[0] });

                if (partials.length >= 20) {
                    break;
                }
            }
        }

        if (fullMatched) {
            fullLineMatches += 1;
        } else if (partials.length > 0) {
            partialLines += 1;
        }

        return {
            index: line.index,
            offset: line.offset,
            text: line.text,
            status: fullMatched
                ? 'full'
                : (line.text === '' ? 'empty' : partials.length > 0 ? 'partial' : 'none'),
            matchCount: fullMatched ? 1 : 0,
            partials,
            reason: lineReason(line, fullMatched, partials.length, trimmedMatched),
        };
    });

    // Highlight spans for the UI: full-text scan matches per line.
    const spansByLine = new Map();

    for (const match of matches) {
        const spans = spansByLine.get(match.lineIndex) ?? [];
        spans.push({ index: match.index, end: match.end, text: match.text, kind: 'full' });
        spansByLine.set(match.lineIndex, spans);
    }

    for (const line of lines) {
        line.spans = (spansByLine.get(line.index) ?? []).sort((a, b) => a.index - b.index);
    }

    return {
        error: null,
        matchCount,
        truncated,
        matches,
        lines,
        fullLineMatches,
        partialLines,
    };
}
