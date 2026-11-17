/**
 * Regex pattern explainer — a real tokenizer over the supported JavaScript
 * regex syntax.
 *
 * Unlike the audited reference implementation (a character-switch with
 * misleading quantifier binding and opaque character classes), this walks
 * the pattern as a grammar: character classes are grouped as units,
 * quantifiers are bound to the token they modify, groups, named groups and
 * lookarounds are recognized, and unsupported syntax is clearly flagged
 * instead of silently mislabelled.
 *
 * Pure string analysis only — no RegExp execution, safe on the main thread.
 */

/** @returns {list<{type: string, text: string, description: string, quantifier?: string}>} */
export function explainPattern(pattern) {
    const tokens = [];
    let i = 0;

    const push = (type, text, description, extra = {}) => {
        tokens.push({ type, text, description, ...extra });
    };

    let literalRun = '';

    const flushLiterals = () => {
        if (literalRun !== '') {
            push('literal', literalRun, `Matches the literal text ${quote(literalRun)}`);
            literalRun = '';
        }
    };

    while (i < pattern.length) {
        const ch = pattern[i];

        if (ch === '\\') {
            flushLiterals();
            const escape = readEscape(pattern, i);
            push(escape.type, escape.text, escape.description);
            i += escape.length;
        } else if (ch === '[') {
            flushLiterals();
            const cls = readClass(pattern, i);
            push(cls.negated ? 'class' : 'class', cls.text, cls.description);
            i += cls.length;
        } else if (ch === '(') {
            flushLiterals();
            const group = readGroup(pattern, i);
            push(group.type, group.text, group.description);
            i += group.length;
        } else if (ch === ')') {
            flushLiterals();
            push('group-end', ')', 'End of group.');
            i += 1;
        } else if (ch === '|') {
            flushLiterals();
            push('alternation', '|', 'Alternation — matches either the expression before or after this bar.');
            i += 1;
        } else if (ch === '^') {
            flushLiterals();
            push('anchor', '^', 'Asserts the start of the string (or the start of a line with the m flag).');
            i += 1;
        } else if (ch === '$') {
            flushLiterals();
            push('anchor', '$', 'Asserts the end of the string (or the end of a line with the m flag).');
            i += 1;
        } else if (ch === '.') {
            flushLiterals();
            push('dot', '.', 'Matches any single character except line terminators (also matches them with the s flag).');
            i += 1;
        } else if ('+*?'.includes(ch) || ch === '{') {
            flushLiterals();
            const quantifier = readQuantifier(pattern, i);

            if (quantifier === null) {
                literalRun += ch;
                i += 1;
            } else {
                const previous = tokens[tokens.length - 1];
                const target = previous ? describeTarget(previous) : 'the pattern start';
                push('quantifier', quantifier.text, quantifier.description.replace('{target}', target), {
                    bindsTo: previous ? previous.type : null,
                });
                i += quantifier.length;
            }
        } else {
            literalRun += ch;
            i += 1;
        }
    }

    flushLiterals();

    return tokens;
}

function quote(text) {
    return "'" + text.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

function describeTarget(token) {
    if (!token) {
        return 'the pattern start';
    }

    switch (token.type) {
        case 'group-start':
            return 'the whole group';
        case 'group-end':
            return 'the preceding group';
        case 'class':
            return 'the character class';
        case 'escape':
            return token.text.startsWith('\\b') || token.text === '\\B'
                ? 'the boundary assertion'
                : 'the escaped token';
        case 'anchor':
            return 'the anchor';
        case 'dot':
            return 'the dot';
        default:
            return 'the preceding token';
    }
}

const SHORTHAND_CLASSES = {
    d: 'Matches any digit (0-9).',
    D: 'Matches any character that is not a digit.',
    w: 'Matches any word character (letters, digits and underscore).',
    W: 'Matches any character that is not a word character.',
    s: 'Matches any whitespace character (spaces, tabs, newlines).',
    S: 'Matches any character that is not whitespace.',
};

function readEscape(pattern, start) {
    const next = pattern[start + 1];

    if (next === undefined) {
        return { type: 'escape', text: '\\', description: 'A trailing backslash — this is not a valid escape.', length: 1 };
    }

    if (SHORTHAND_CLASSES[next]) {
        return { type: 'escape', text: '\\' + next, description: SHORTHAND_CLASSES[next], length: 2 };
    }

    if (next === 'b') {
        return { type: 'escape', text: '\\b', description: 'Word boundary assertion (zero-width).', length: 2 };
    }

    if (next === 'B') {
        return { type: 'escape', text: '\\B', description: 'Not a word boundary assertion (zero-width).', length: 2 };
    }

    if (next === 'n' || next === 'r' || next === 't' || next === 'f' || next === 'v' || next === '0') {
        const names = { n: 'newline', r: 'carriage return', t: 'tab', f: 'form feed', v: 'vertical tab', 0: 'null character' };

        return { type: 'escape', text: '\\' + next, description: `Matches the ${names[next]} character.`, length: 2 };
    }

    if (next === 'x' && /^[0-9a-fA-F]{2}/.test(pattern.slice(start + 2))) {
        return { type: 'escape', text: pattern.slice(start, start + 4), description: 'Matches the character with the given hexadecimal code point.', length: 4 };
    }

    if (next === 'u') {
        if (pattern[start + 2] === '{') {
            const end = pattern.indexOf('}', start + 3);
            if (end !== -1) {
                const text = pattern.slice(start, end + 1);

                return { type: 'escape', text, description: 'Unicode code point escape — matches the character at that code point.', length: text.length };
            }

            return { type: 'unsupported', text: '\\u{', description: 'Unclosed Unicode code point escape — this pattern is invalid.', length: 3 };
        }

        if (/^[0-9a-fA-F]{4}/.test(pattern.slice(start + 2))) {
            return { type: 'escape', text: pattern.slice(start, start + 6), description: 'Unicode escape — matches the character at that code point.', length: 6 };
        }

        return { type: 'unsupported', text: '\\u', description: 'Incomplete Unicode escape (requires 4 hex digits or {…}).', length: 2 };
    }

    if (next === 'p' || next === 'P') {
        if (pattern[start + 2] === '{') {
            const end = pattern.indexOf('}', start + 3);

            if (end !== -1) {
                const text = pattern.slice(start, end + 1);

                return {
                    type: 'escape',
                    text,
                    description: next === 'p' ? 'Unicode property class — matches characters with that property (requires the u flag).' : 'Negated Unicode property class (requires the u flag).',
                    length: text.length,
                };
            }
        }

        return { type: 'unsupported', text: '\\' + next, description: 'Unicode property escape without {…} — invalid without the u flag and braces.', length: 2 };
    }

    if (next === 'k' && pattern[start + 2] === '<') {
        const end = pattern.indexOf('>', start + 3);

        if (end !== -1) {
            const name = pattern.slice(start + 3, end);
            const text = pattern.slice(start, end + 1);

            return { type: 'backreference', text, description: `Backreference to the named group "${name}".`, length: text.length };
        }
    }

    if (/[1-9]/.test(next) && next !== '0') {
        const digits = /^\d+/.exec(pattern.slice(start + 1))[0];
        const text = '\\' + digits;

        return { type: 'backreference', text, description: `Backreference — matches the same text captured by group #${digits}.`, length: text.length };
    }

    return { type: 'escape', text: '\\' + next, description: `Matches the literal character ${quote(next)}.`, length: 2 };
}

function readClass(pattern, start) {
    let i = start + 1;
    let negated = false;

    if (pattern[i] === '^') {
        negated = true;
        i += 1;
    }

    const bodyStart = i;

    while (i < pattern.length) {
        const ch = pattern[i];

        if (ch === '\\') {
            i += 2;
        } else if (ch === ']') {
            break;
        } else {
            i += 1;
        }
    }

    const closed = i < pattern.length;
    const body = pattern.slice(bodyStart, closed ? i : pattern.length);
    const text = pattern.slice(start, closed ? i + 1 : pattern.length);

    if (!closed) {
        return { negated, text, description: 'Unclosed character class — this pattern is invalid.', length: text.length };
    }

    const ranges = countRanges(body);
    const members = summarizeClassBody(body);

    let description = negated
        ? `Negated character class — matches any single character EXCEPT ${members}`
        : `Character class — matches any single character of ${members}`;

    if (ranges > 0) {
        description += ` (includes ${ranges} range${ranges === 1 ? '' : 's'})`;
    }

    return { negated, text, description, length: text.length };
}

function countRanges(body) {
    let count = 0;
    let i = 0;

    while (i < body.length) {
        if (body[i] === '\\') {
            i += 2;
            continue;
        }

        if (body[i + 1] === '-' && body[i + 2] !== undefined && body[i + 2] !== ']') {
            count += 1;
            i += 3;
        } else {
            i += 1;
        }
    }

    return count;
}

function summarizeClassBody(body) {
    const literals = body.replace(/\\[dDwWsS]/g, '').replace(/\\./g, '');

    const parts = [];

    if (/\\d/.test(body)) {
        parts.push('digits (\\d)');
    }

    if (/\\w/.test(body)) {
        parts.push('word characters (\\w)');
    }

    if (/\\s/.test(body)) {
        parts.push('whitespace (\\s)');
    }

    const cleaned = literals
        .replace(/\\[dDwWsS]/g, '')
        .replace(/\\[^dDwWsS]/g, (m) => m[1]);

    if (cleaned !== '') {
        parts.push(quote(cleaned));
    }

    if (parts.length === 0) {
        return 'the listed characters';
    }

    return parts.join(' and ');
}

function readGroup(pattern, start) {
    if (pattern[start + 1] !== '?') {
        return { type: 'group-start', text: '(', description: 'Capturing group — saves the matched text for backreferences (\\1) and replacement patterns.', length: 1 };
    }

    const two = pattern.slice(start, start + 3);

    if (two === '(?:') {
        return { type: 'group-start', text: '(?:', description: 'Non-capturing group — groups the pattern without saving a capture.', length: 3 };
    }

    if (two === '(?=') {
        return { type: 'lookaround', text: '(?=', description: 'Positive lookahead — asserts the following pattern matches ahead, without consuming characters.', length: 3 };
    }

    if (two === '(?!') {
        return { type: 'lookaround', text: '(?!', description: 'Negative lookahead — asserts the following pattern does not match ahead.', length: 3 };
    }

    const four = pattern.slice(start, start + 4);

    if (four === '(?<=') {
        return { type: 'lookaround', text: '(?<=', description: 'Positive lookbehind — asserts the preceding pattern matches behind, without consuming characters.', length: 4 };
    }

    if (four === '(?<!') {
        return { type: 'lookaround', text: '(?<!', description: 'Negative lookbehind — asserts the preceding pattern does not match behind.', length: 4 };
    }

    if (pattern[start + 2] === '<') {
        const end = pattern.indexOf('>', start + 3);

        if (end !== -1) {
            const name = pattern.slice(start + 3, end);
            const text = pattern.slice(start, end + 1);

            return { type: 'group-start', text, description: `Named capturing group "${name}" — accessible as groups.${name} and via \\k<${name}>.`, length: text.length };
        }
    }

    return { type: 'unsupported', text: '(?', description: 'Unsupported or rare group syntax — verify this construct manually.', length: 2 };
}

function readQuantifier(pattern, start) {
    const ch = pattern[start];

    if (ch === '+' || ch === '*') {
        const lazy = pattern[start + 1] === '?' ? '?' : '';
        const text = ch + lazy;
        const greedy = ch === '+' ? 'one or more times' : 'zero or more times';

        return {
            text,
            description: `Matches {target} ${greedy}${lazy ? ' (lazy — prefers the shortest match)' : ' (greedy — prefers the longest match)'}.`,
            length: text.length,
        };
    }

    if (ch === '?') {
        const previous = null; // binding handled by caller
        const lazy = pattern[start + 1] === '?' ? '?' : '';
        const text = '?' + lazy;

        return {
            text,
            description: `Makes {target} optional — zero or one time${lazy ? ' (lazy — prefers the shortest match)' : ' (greedy)'}.`,
            length: text.length,
            previous,
        };
    }

    if (ch === '{') {
        const end = pattern.indexOf('}', start);

        if (end === -1) {
            return null; // literal '{'
        }

        const body = pattern.slice(start + 1, end);

        if (!/^\d+(?:,\d*)?$/.test(body)) {
            return null; // literal braces
        }

        const lazy = pattern[end + 1] === '?' ? '?' : '';
        const text = pattern.slice(start, end + 1) + lazy;

        let times;

        if (body.includes(',')) {
            const [, min, max] = /^(\d+),(\d*)$/.exec(body) ?? [null, body.replace(',', ''), ''];
            times = max === '' ? `at least ${min} times` : `between ${min} and ${max} times`;
        } else {
            times = `exactly ${body} times`;
        }

        return {
            text,
            description: `Matches {target} ${times}${lazy ? ' (lazy — prefers the shortest match)' : ' (greedy — prefers the longest match)'}.`,
            length: text.length,
        };
    }

    return null;
}
