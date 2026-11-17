/**
 * Regex analysis Web Worker.
 *
 * Runs the full analyze() pipeline off the main thread. The watchdog in
 * regex-tester.js terminates this worker when a pattern exceeds the safe
 * time budget (catastrophic backtracking protection) — a synchronous
 * timeout cannot interrupt a blocked JavaScript thread, but terminating a
 * worker can. The UI transparently re-spawns the worker for the next run.
 */
import { analyze, MAX_PATTERN_LENGTH, MAX_TEXT_LENGTH } from './regex-core.js';

self.onmessage = (event) => {
    const { id, pattern, flags, text } = event.data;

    if (typeof pattern !== 'string' || pattern.length > MAX_PATTERN_LENGTH || typeof text !== 'string' || text.length > MAX_TEXT_LENGTH) {
        self.postMessage({ id, ok: false, error: 'Input exceeds the safe size limit.' });
        return;
    }

    try {
        const result = analyze(pattern, flags, text);
        self.postMessage({ id, ok: true, result });
    } catch {
        self.postMessage({ id, ok: false, error: 'The pattern could not be processed safely.' });
    }
};
