export function init(el) {
    const input = el.querySelector('[data-input]');
    const statsBox = el.querySelector('[data-stats]');
    const empty = el.querySelector('[data-empty]');

    const counters = {
        words: el.querySelector('[data-count-words]'),
        chars: el.querySelector('[data-count-chars]'),
        charsNoSpaces: el.querySelector('[data-count-chars-nospace]'),
        sentences: el.querySelector('[data-count-sentences]'),
        paragraphs: el.querySelector('[data-count-paragraphs]'),
        readingTime: el.querySelector('[data-count-reading]'),
        lines: el.querySelector('[data-count-lines]'),
        longest: el.querySelector('[data-count-longest]'),
    };

    let t;
    input.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(update, 80);
    });

    el.querySelector('[data-clear]').addEventListener('click', () => {
        input.value = '';
        update();
        input.focus();
    });

    el.querySelector('[data-copy]').addEventListener('click', async (e) => {
        await navigator.clipboard.writeText(input.value);
        flash(e.currentTarget, 'Copied!');
    });

    function update() {
        const text = input.value;
        const trimmed = text.trim();

        const words = trimmed ? trimmed.split(/\s+/).filter(Boolean).length : 0;
        const chars = text.length;
        const charsNoSpaces = text.replace(/\s/g, '').length;
        const sentences = trimmed ? (trimmed.match(/[^.!?…]+[.!?…]+(\s|$)/g) || [trimmed]).filter((s) => s.trim()).length : 0;
        const paragraphs = trimmed ? trimmed.split(/\n{2,}|\r\n{2,}/).filter((p) => p.trim()).length : 0;
        const readingMinutes = Math.max(words > 0 ? 1 : 0, Math.ceil(words / 200));
        const lines = text ? text.split(/\n/).length : 0;
        const longest = trimmed ? Math.max(0, ...trimmed.split(/\s+/).map((w) => w.length)) : 0;

        animate(counters.words, words.toLocaleString());
        animate(counters.chars, chars.toLocaleString());
        animate(counters.charsNoSpaces, charsNoSpaces.toLocaleString());
        animate(counters.sentences, sentences.toLocaleString());
        animate(counters.paragraphs, paragraphs.toLocaleString());
        animate(counters.readingTime, readingMinutes === 0 ? '0 min' : `${readingMinutes} min`);
        animate(counters.lines, lines.toLocaleString());
        animate(counters.longest, longest.toLocaleString());

        statsBox.hidden = trimmed === '';
        empty.hidden = trimmed !== '';
    }

    function animate(node, value) {
        if (!node) return;
        if (node.textContent !== value) {
            node.textContent = value;
            node.classList.remove('stat-pop');
            void node.offsetWidth;
            node.classList.add('stat-pop');
        }
    }

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => { btn.textContent = original; }, 1200);
    }
}
