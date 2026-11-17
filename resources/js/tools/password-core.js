/**
 * Strong Password Generator core — pure logic shared with the UI and with
 * Node-based unit tests.
 *
 * Randomness is injected as a function so the UI passes the real
 * crypto.getRandomValues-backed helper while tests can verify that every
 * byte actually comes from the injected source (and that Math.random is
 * never touched).
 */

export const MIN_LENGTH = 8;
export const MAX_LENGTH = 128;
export const DEFAULT_LENGTH = 24;
export const MAX_PASSWORDS = 10;

export const AMBIGUOUS_CHARACTERS = ['0', 'O', 'l', 'I'];

export const CHARACTER_GROUPS = {
    upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
    lower: 'abcdefghijklmnopqrstuvwxyz',
    number: '0123456789',
    symbol: '!@#$%^&*()-_=+[]{};:,.?',
};

/**
 * Build the usable character pool.
 *
 * @param {{upper: boolean, lower: boolean, number: boolean, symbol: boolean, excludeAmbiguous: boolean}} options
 * @returns {{pool: string, groups: list<string>, poolSize: number}}
 */
export function buildPool(options) {
    const selected = [];

    for (const key of ['upper', 'lower', 'number', 'symbol']) {
        if (!options[key]) continue;

        const chars = CHARACTER_GROUPS[key];
        const filtered = options.excludeAmbiguous
            ? [...chars].filter((c) => !AMBIGUOUS_CHARACTERS.includes(c)).join('')
            : chars;

        if (filtered !== '') {
            selected.push(filtered);
        }
    }

    const pool = selected.join('');

    return { pool, groups: selected, poolSize: [...new Set(pool)].length };
}

/**
 * Unbiased random integer in [0, maxExclusive) using rejection sampling.
 * Never uses modulo of the raw random value, so there is no modulo bias.
 */
export function randomInt(randomSource, maxExclusive) {
    if (maxExclusive <= 1) {
        return 0;
    }

    const limit = Math.floor(0x100000000 / maxExclusive) * maxExclusive;

    while (true) {
        const value = randomSource();

        if (value >= 0 && value < limit) {
            return value % maxExclusive;
        }
    }
}

/**
 * Cryptographic Fisher-Yates shuffle (in place).
 */
export function shuffle(array, randomSource) {
    for (let i = array.length - 1; i > 0; i--) {
        const j = randomInt(randomSource, i + 1);
        [array[i], array[j]] = [array[j], array[i]];
    }

    return array;
}

/**
 * Generate one password.
 *
 * Guarantees at least one character from every selected group, fills the
 * remainder from the full pool, then crypto-shuffles so group positions
 * are not predictable.
 *
 * @returns {string|null} null when no character group is selected
 */
export function generatePassword(options, length, randomSource) {
    const { pool, groups } = buildPool(options);

    if (pool === '' || groups.length === 0) {
        return null;
    }

    const targetLength = Math.min(Math.max(Math.floor(length), MIN_LENGTH), MAX_LENGTH);
    const characters = [];

    // One guaranteed representative per selected group.
    for (const group of groups) {
        characters.push(group[randomInt(randomSource, group.length)]);
    }

    // Remainder from the full pool.
    while (characters.length < targetLength) {
        characters.push(pool[randomInt(randomSource, pool.length)]);
    }

    return shuffle(characters, randomSource).join('');
}

/**
 * Generate several distinct-ish passwords.
 */
export function generatePasswords(options, length, count, randomSource) {
    const targetCount = Math.min(Math.max(Math.floor(count), 1), MAX_PASSWORDS);
    const passwords = [];

    for (let i = 0; i < targetCount; i++) {
        const password = generatePassword(options, length, randomSource);

        if (password === null) {
            return [];
        }

        passwords.push(password);
    }

    return passwords;
}

/**
 * Estimated entropy in bits for a uniformly random selection from a pool
 * of `poolSize` characters with `length` picks: length * log2(poolSize).
 */
export function estimateEntropy(poolSize, length) {
    if (poolSize <= 1 || length <= 0) {
        return 0;
    }

    return Math.round(length * Math.log2(poolSize) * 10) / 10;
}

/**
 * Strength label from estimated entropy (never claims unbreakability).
 */
export function strengthLabel(entropy) {
    if (entropy < 35) {
        return { label: 'Weak', level: 1 };
    }

    if (entropy < 60) {
        return { label: 'Fair', level: 2 };
    }

    if (entropy < 90) {
        return { label: 'Strong', level: 3 };
    }

    return { label: 'Very Strong', level: 4 };
}
