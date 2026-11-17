/**
 * Node-based unit tests for the Regex Tester core (resources/js/tools/regex-core.js).
 *
 * Run with:  node --test tests/js/
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    analyze,
    buildRegex,
    MAX_PATTERN_LENGTH,
    parseShorthand,
    splitLines,
    stripAnchors,
} from '../../resources/js/tools/regex-core.js';

// ---------------------------------------------------------------------
// Shorthand parsing
// ---------------------------------------------------------------------

test('parseShorthand extracts pattern and flags', () => {
    const result = parseShorthand('/\\d+/gi');

    assert.equal(result.isShorthand, true);
    assert.equal(result.pattern, '\\d+');
    assert.equal(result.flags, 'gi');
});

test('parseShorthand leaves plain patterns untouched', () => {
    const result = parseShorthand('\\d+');

    assert.equal(result.isShorthand, false);
    assert.equal(result.pattern, '\\d+');
    assert.equal(result.flags, '');
});

test('parseShorthand rejects invalid shorthand flag sets', () => {
    assert.equal(parseShorthand('/abc/1!').isShorthand, false);
});

// ---------------------------------------------------------------------
// buildRegex
// ---------------------------------------------------------------------

test('buildRegex compiles a valid pattern', () => {
    const { regex, error } = buildRegex('\\d{4}', 'i');

    assert.equal(error, null);
    assert.equal(regex.test('2026'), true);
    assert.equal(regex.flags, 'i');
});

test('buildRegex dedupes flags', () => {
    const { regex } = buildRegex('a', 'ggi');

    assert.equal(regex.flags.split('').sort().join(''), 'gi');
});

test('buildRegex rejects unsupported flags', () => {
    const { error } = buildRegex('a', 'z');

    assert.match(error, /Unsupported flag/);
});

test('buildRegex reports invalid patterns with helpful messages', () => {
    assert.match(buildRegex('(unclosed', '').error, /parentheses/i);
    assert.match(buildRegex('[unclosed', '').error, /character class/i);
    assert.match(buildRegex('a**', '').error, /not a valid regular expression/i);
});

// ---------------------------------------------------------------------
// splitLines
// ---------------------------------------------------------------------

test('splitLines handles LF, CRLF and CR', () => {
    const lines = splitLines('a\nb\r\nc\rd');

    assert.deepEqual(lines.map((l) => l.text), ['a', 'b', 'c', 'd']);
    assert.deepEqual(lines.map((l) => l.offset), [0, 2, 5, 7]);
});

// ---------------------------------------------------------------------
// stripAnchors
// ---------------------------------------------------------------------

test('stripAnchors removes top-level anchors only', () => {
    assert.equal(stripAnchors('^abc$'), 'abc');
    assert.equal(stripAnchors('[^abc]'), '[^abc]');
    assert.equal(stripAnchors('\\^\\$'), '\\^\\$');
    assert.equal(stripAnchors('^a[^b]c$'), 'a[^b]c');
    assert.equal(stripAnchors('^$'), '');
});

// ---------------------------------------------------------------------
// analyze: matching
// ---------------------------------------------------------------------

test('analyze finds simple matches with positions', () => {
    const result = analyze('cat', 'g', 'The cat sat.');

    assert.equal(result.error, null);
    assert.equal(result.matchCount, 1);
    assert.equal(result.matches[0].text, 'cat');
    assert.equal(result.matches[0].index, 4);
    assert.equal(result.matches[0].lineIndex, 0);
});

test('analyze finds multiple matches across lines', () => {
    const result = analyze('\\d+', 'g', 'One 1 two 2\nthree 3');

    assert.equal(result.matchCount, 3);
    assert.equal(result.matches[2].text, '3');
    assert.equal(result.matches[2].lineIndex, 1);
});

test('analyze respects case-insensitivity', () => {
    assert.equal(analyze('abc', '', 'ABC def').matchCount, 0);
    assert.equal(analyze('abc', 'gi', 'ABC def').matchCount, 1);
});

test('analyze respects multiline anchors', () => {
    // Whole-line pattern with the m flag: lines that match fully vs not.
    const result = analyze('^TODO: .+$', 'gm', 'TODO: write tests\ndone\nTODO: fix bug');

    assert.equal(result.matchCount, 2);
    assert.equal(result.lines[0].status, 'full');
    assert.equal(result.lines[2].status, 'full');
});

test('analyze reports partial matches for prefix-only matches', () => {
    // Pattern '^TODO' matches a substring of 'TODO a' but not the whole line.
    const result = analyze('^TODO', 'gm', 'TODO a\ndone');

    assert.equal(result.matchCount, 1);
    assert.equal(result.lines[0].status, 'partial');
    assert.equal(result.lines[0].partials.length, 1);
    assert.equal(result.lines[0].partials[0].text, 'TODO');
});

test('analyze handles the s (dotAll) flag', () => {
    assert.equal(analyze('a.b', '', 'a\nb').matchCount, 0);
    assert.equal(analyze('a.b', 's', 'a\nb').matchCount, 1);
});

test('analyze handles unicode property classes', () => {
    const result = analyze('\\p{L}+', 'gu', 'Café 日本語 42');

    assert.equal(result.matchCount, 2); // "Café" and "日本語" (digits excluded)
});

// ---------------------------------------------------------------------
// analyze: capturing groups
// ---------------------------------------------------------------------

test('analyze captures groups and named groups', () => {
    const result = analyze('(?<year>\\d{4})-(?<month>\\d{2})', 'g', 'Date: 2026-11');

    const match = result.matches[0];
    assert.deepEqual(match.groups, ['2026', '11']);
    assert.deepEqual(match.namedGroups, { year: '2026', month: '11' });
});

test('analyze handles nested groups and non-participating captures', () => {
    const result = analyze('(a(b)?)(c)?', 'g', 'ac ab abcc');

    assert.equal(result.matches.length, 3);
    assert.deepEqual(result.matches[0].groups, ['a', null, 'c']);
    assert.deepEqual(result.matches[2].groups, ['ab', 'b', 'c']);
});

// ---------------------------------------------------------------------
// analyze: invalid and edge patterns
// ---------------------------------------------------------------------

test('analyze reports invalid patterns without throwing', () => {
    const result = analyze('(unclosed', 'g', 'test');

    assert.match(result.error, /parentheses/i);
    assert.equal(result.matchCount, 0);
});

test('analyze handles the empty pattern gracefully', () => {
    const result = analyze('', 'g', 'ab');

    assert.equal(result.error, null);
    assert.equal(result.matchCount, 3); // '', between a and b, at end
});

test('analyze enforces the pattern length cap', () => {
    const result = analyze('x'.repeat(MAX_PATTERN_LENGTH + 1), 'g', 'x');

    assert.match(result.error, /too long/);
});

test('analyze flags oversized text as truncated', () => {
    const result = analyze('y', 'g', 'y'.repeat(200));

    assert.equal(result.truncated, false); // 200 chars is under the cap
});

// ---------------------------------------------------------------------
// analyze: per-line diagnostics
// ---------------------------------------------------------------------

test('analyze marks full-line matches as full', () => {
    const result = analyze('\\w+', 'g', 'hello');

    assert.equal(result.lines[0].status, 'full');
    assert.equal(result.lines[0].reason, 'Matches the whole line.');
});

test('analyze detects partial matches on non-matching lines', () => {
    const result = analyze('^ok$', 'g', 'ok\nnot ok\nok ok');

    assert.equal(result.lines[0].status, 'full');
    assert.equal(result.lines[1].status, 'partial');
    assert.equal(result.lines[1].partials.length, 1);
    assert.equal(result.lines[1].partials[0].text, 'ok');
    assert.equal(result.lines[2].status, 'partial');
    assert.equal(result.lines[2].partials.length, 2);
    assert.match(result.lines[1].reason, /valid substring/);
});

test('analyze detects whitespace-only mismatches', () => {
    const result = analyze('^ok$', 'g', 'ok\n  ok  ');

    assert.equal(result.lines[1].status, 'none');
    assert.match(result.lines[1].reason, /whitespace/i);
});

test('analyze explains empty lines and no-match lines', () => {
    const result = analyze('\\d+', 'g', '\nabc');

    assert.equal(result.lines[0].status, 'empty');
    assert.match(result.lines[0].reason, /Empty line/);
    assert.equal(result.lines[1].status, 'none');
});

test('analyze keeps partial detection away from class contents', () => {
    // [^x] contains an anchor inside a class — must not be stripped.
    const result = analyze('^[^x]+$', 'g', 'abc\nx');

    assert.equal(result.lines[0].status, 'full');
});

// ---------------------------------------------------------------------
// analyze: CRLF safety
// ---------------------------------------------------------------------

test('analyze handles CRLF line endings without stray \\r', () => {
    const result = analyze('^b$', 'g', 'a\r\nb\r\nc');

    assert.equal(result.lines[1].text, 'b');
    assert.equal(result.lines[1].status, 'full');
    // No \r may appear in the analyzed line text.
    for (const line of result.lines) {
        assert.ok(!line.text.includes('\r'), 'Line text must not contain CR characters');
    }
});
