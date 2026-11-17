/**
 * Node-based unit tests for the regex pattern explainer
 * (resources/js/tools/regex-explain.js).
 *
 * Run with:  node --test tests/js/
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import { explainPattern } from '../../resources/js/tools/regex-explain.js';

function tokens(pattern) {
    return explainPattern(pattern);
}

function types(pattern) {
    return tokens(pattern).map((t) => t.type);
}

test('literal runs are collapsed into one token', () => {
    const list = tokens('abc');

    assert.deepEqual(list.map((t) => t.text), ['abc']);
    assert.equal(list[0].type, 'literal');
});

test('anchors and dot are recognized', () => {
    assert.deepEqual(types('^a.$'), ['anchor', 'literal', 'dot', 'anchor']);
});

test('shorthand escapes are explained', () => {
    const list = tokens('\\d\\w\\s\\D\\W\\S');

    assert.equal(list.length, 6);
    assert.equal(list[0].type, 'escape');
    assert.match(list[0].description, /digit/i);
    assert.match(list[2].description, /whitespace/i);
});

test('escaped literals are literal', () => {
    const list = tokens('\\.\\*');

    assert.deepEqual(list.map((t) => t.text), ['\\.', '\\*']);
    assert.match(list[0].description, /literal character/);
});

test('character classes are grouped as one unit', () => {
    const list = tokens('[a-z0-9_]');

    assert.equal(list.length, 1);
    assert.equal(list[0].type, 'class');
    assert.equal(list[0].text, '[a-z0-9_]');
    assert.match(list[0].description, /Character class/);
});

test('negated classes are described as negated', () => {
    const list = tokens('[^abc]');

    assert.match(list[0].description, /EXCEPT/);
});

test('unclosed classes are flagged invalid', () => {
    const list = tokens('[abc');

    assert.match(list[0].description, /Unclosed|invalid/i);
});

test('quantifiers bind to the previous token', () => {
    const list = tokens('a+');

    assert.equal(list[1].type, 'quantifier');
    assert.match(list[1].description, /one or more times/);
    assert.match(list[1].description, /greedy/);
});

test('quantifiers after groups reference the group', () => {
    const list = tokens('(ab)+');

    const quantifier = list.find((t) => t.type === 'quantifier');
    assert.ok(quantifier, 'a quantifier token must exist');
    assert.match(quantifier.description, /preceding group/);
});

test('lazy quantifiers are marked lazy', () => {
    const list = tokens('a*?');

    assert.match(list[1].description, /lazy/);
});

test('bounded quantifiers are parsed', () => {
    const list = tokens('a{2,5}');

    assert.equal(list[1].type, 'quantifier');
    assert.match(list[1].description, /between 2 and 5/);
});

test('quantifier after an unbound token falls back to target description', () => {
    const list = tokens('a?');

    assert.equal(list[1].type, 'quantifier');
    assert.match(list[1].description, /optional/);
});

test('groups: capturing, non-capturing and named', () => {
    assert.match(tokens('(')[0].description, /Capturing group/);
    assert.match(tokens('(?:')[0].description, /Non-capturing group/);
    assert.match(tokens('(?<year>')[0].description, /Named capturing group/);
});

test('lookarounds are recognized', () => {
    assert.match(tokens('(?=')[0].description, /Positive lookahead/);
    assert.match(tokens('(?!')[0].description, /Negative lookahead/);
    assert.match(tokens('(?<=')[0].description, /Positive lookbehind/);
    assert.match(tokens('(?<!')[0].description, /Negative lookbehind/);
});

test('alternation and group end are labeled', () => {
    const list = tokens('a|b)');

    assert.deepEqual(list.map((t) => t.type), ['literal', 'alternation', 'literal', 'group-end']);
});

test('backreferences are explained', () => {
    const list = tokens('\\1\\k<name>');

    assert.equal(list[0].type, 'backreference');
    assert.equal(list[1].type, 'backreference');
    assert.match(list[1].description, /named group/);
});

test('unsupported syntax is clearly flagged, not mislabeled', () => {
    const list = tokens('(?<x');

    assert.ok(list.some((t) => t.type === 'unsupported'));
});
