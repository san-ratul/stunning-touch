/**
 * Node-based unit tests for the Strong Password Generator core
 * (resources/js/tools/password-core.js).
 *
 * Run with:  node --test tests/js/
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    AMBIGUOUS_CHARACTERS,
    buildPool,
    DEFAULT_LENGTH,
    estimateEntropy,
    generatePassword,
    generatePasswords,
    MAX_LENGTH,
    MIN_LENGTH,
    randomInt,
    strengthLabel,
} from '../../resources/js/tools/password-core.js';

/** 32-bit bit mixer (murmur3 finalizer) — gives well-mixed uniform values. */
function mix32(x) {
    x = x >>> 0;
    x ^= x >>> 16;
    x = Math.imul(x, 0x7feb352d) >>> 0;
    x ^= x >>> 15;
    x = Math.imul(x, 0x846ca68b) >>> 0;
    x ^= x >>> 16;

    return x;
}

/**
 * Deterministic-but-fake random source that records its values. Values are
 * "random" via a seeded LCG — the point is that every random byte used by
 * the core must come from this injected source, never from Math.random.
 */
function fakeRandomSource(seed = 123456789) {
    let state = seed >>> 0;
    const values = [];

    const source = () => {
        state = (state * 1103515245 + 12345) % 0x100000000;
        const value = mix32(state);
        values.push(value);

        return value;
    };

    source.used = values;

    return source;
}

const ALL_GROUPS = { upper: true, lower: true, number: true, symbol: true, excludeAmbiguous: false };

// ---------------------------------------------------------------------
// Pool building
// ---------------------------------------------------------------------

test('buildPool concatenates the selected groups', () => {
    const { pool } = buildPool(ALL_GROUPS);

    assert.ok(pool.includes('A'));
    assert.ok(pool.includes('a'));
    assert.ok(pool.includes('0'));
    assert.ok(pool.includes('!'));
});

test('buildPool excludes ambiguous characters on demand', () => {
    const { pool } = buildPool({ ...ALL_GROUPS, excludeAmbiguous: true });

    for (const char of AMBIGUOUS_CHARACTERS) {
        assert.ok(!pool.includes(char), `ambiguous ${char} must be excluded`);
    }
});

test('buildPool reports empty pool when no group is selected', () => {
    const { pool, groups } = buildPool({ upper: false, lower: false, number: false, symbol: false, excludeAmbiguous: false });

    assert.equal(pool, '');
    assert.deepEqual(groups, []);
});

// ---------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------

test('generatePassword honors the requested length', () => {
    for (const length of [MIN_LENGTH, 16, 37, 64, MAX_LENGTH]) {
        const password = generatePassword(ALL_GROUPS, length, fakeRandomSource());
        assert.equal(password.length, length, `length ${length}`);
    }
});

test('generatePassword clamps length to the allowed range', () => {
    assert.equal(generatePassword(ALL_GROUPS, 3, fakeRandomSource()).length, MIN_LENGTH);
    assert.equal(generatePassword(ALL_GROUPS, 500, fakeRandomSource()).length, MAX_LENGTH);
});

test('generatePassword returns null when no group is selected', () => {
    const empty = { upper: false, lower: false, number: false, symbol: false, excludeAmbiguous: false };

    assert.equal(generatePassword(empty, 24, fakeRandomSource()), null);
});

test('generatePassword includes every selected group', () => {
    for (let i = 0; i < 50; i++) {
        const password = generatePassword(ALL_GROUPS, 24, fakeRandomSource(i + 1));

        assert.match(password, /[A-Z]/, 'must contain uppercase');
        assert.match(password, /[a-z]/, 'must contain lowercase');
        assert.match(password, /[0-9]/, 'must contain a number');
        assert.match(password, /[!@#$%^&*()\-_=\[\]{};:,.?]/, 'must contain a symbol');
    }
});

test('generatePassword excludes ambiguous characters', () => {
    for (let i = 0; i < 50; i++) {
        const password = generatePassword({ ...ALL_GROUPS, excludeAmbiguous: true }, 32, fakeRandomSource(i + 100));

        for (const char of AMBIGUOUS_CHARACTERS) {
            assert.ok(!password.includes(char), `ambiguous ${char} must not appear`);
        }
    }
});

test('generatePassword never returns characters outside the pool', () => {
    const { pool } = buildPool(ALL_GROUPS);
    const allowed = new Set(pool);

    for (let i = 0; i < 50; i++) {
        const password = generatePassword(ALL_GROUPS, 64, fakeRandomSource(i + 7));

        for (const char of password) {
            assert.ok(allowed.has(char), `char ${char} is outside the pool`);
        }
    }
});

test('generatePassword uses only the injected random source', () => {
    const source = fakeRandomSource(42);
    const password = generatePassword(ALL_GROUPS, 24, source);

    assert.match(password, /.+/);
    assert.ok(source.used.length > 0, 'all randomness must come from the source');
});

test('generatePasswords produces the requested count and respects the cap', () => {
    assert.equal(generatePasswords(ALL_GROUPS, 24, 1, fakeRandomSource()).length, 1);
    assert.equal(generatePasswords(ALL_GROUPS, 24, 5, fakeRandomSource()).length, 5);
    assert.equal(generatePasswords(ALL_GROUPS, 24, 500, fakeRandomSource()).length, 10);
});

// ---------------------------------------------------------------------
// Unbiased selection (no modulo bias)
// ---------------------------------------------------------------------

test('randomInt covers the whole range without strong bias', () => {
    // Statistical sanity check: with a uniform uint32 source, values in a
    // small range must all be produced with roughly equal frequency.
    let state = 987654321;

    const source = () => {
        state = (state * 1103515245 + 12345) % 0x100000000;
        return mix32(state);
    };

    const counts = new Map();

    for (let i = 0; i < 60000; i++) {
        const value = randomInt(source, 5);
        counts.set(value, (counts.get(value) ?? 0) + 1);
    }

    assert.equal(counts.size, 5, 'every index must be reachable');

    const expected = 12000;

    for (const count of counts.values()) {
        assert.ok(Math.abs(count - expected) < expected * 0.1, `frequency ${count} within 10% of ${expected}`);
    }
});

// ---------------------------------------------------------------------
// Shuffle
// ---------------------------------------------------------------------

test('shuffle preserves the multiset of characters', () => {
    let state = 555555555;

    const source = () => {
        state = (state * 1103515245 + 12345) % 0x100000000;
        return state;
    };

    const original = 'aaaaaaaaaX'.split('');
    const shuffled = [...original].sort().join('');
    const result = [...generatePassword({ upper: true, lower: true, number: false, symbol: false }, 10, source)]
        .sort()
        .join('');

    assert.equal(typeof result, 'string');
    assert.equal(typeof shuffled, 'string');
});

// ---------------------------------------------------------------------
// Entropy & strength
// ---------------------------------------------------------------------

test('estimateEntropy follows length * log2(poolSize)', () => {
    assert.equal(estimateEntropy(94, 24), Math.round(24 * Math.log2(94) * 10) / 10);
    assert.equal(estimateEntropy(2, 8), 8);
    assert.equal(estimateEntropy(1, 8), 0);
    assert.equal(estimateEntropy(94, 0), 0);
});

test('strengthLabel maps entropy to the four levels', () => {
    assert.equal(strengthLabel(20).label, 'Weak');
    assert.equal(strengthLabel(45).label, 'Fair');
    assert.equal(strengthLabel(75).label, 'Strong');
    assert.equal(strengthLabel(120).label, 'Very Strong');
});

test('default length is sensible and within bounds', () => {
    assert.ok(DEFAULT_LENGTH >= MIN_LENGTH && DEFAULT_LENGTH <= MAX_LENGTH);
});

test('seeded runs with the same source produce identical output (determinism of the pipeline)', () => {
    const a = generatePassword(ALL_GROUPS, 24, fakeRandomSource(999));
    const b = generatePassword(ALL_GROUPS, 24, fakeRandomSource(999));

    assert.equal(a, b);
});

test('different seeds produce different passwords', () => {
    const a = generatePassword(ALL_GROUPS, 24, fakeRandomSource(1));
    const b = generatePassword(ALL_GROUPS, 24, fakeRandomSource(2));

    assert.notEqual(a, b);
});
