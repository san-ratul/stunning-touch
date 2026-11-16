# Qwen3.5 4B Configuration

Model: `qwen3.5:4b` — reference https://ollama.com/library/qwen3.5

## Why these defaults

| Setting | Value | Reason |
|---|---|---|
| Model | `qwen3.5:4b` | Small 4B model fits the 2 OCPU / 12 GB Oracle A1 instance comfortably in Q4 |
| Context | `16384` | Default; 16K context with Q4 quantization stays within RAM budget on 12 GB |
| Concurrency | `1` | One LLM request at a time; enforced by the app's generation lock |
| Keep-alive | `10m` | Keeps the model warm between scheduled slots without pinning RAM forever |
| Timeout | `900 s` | CPU-only inference is slow; never assume advertised speeds |

## Configure

Admin → AI Provider:

1. Endpoint `http://127.0.0.1:11434/api/chat` (native, recommended — enables
   `num_ctx`/`keep_alive` control) or `/v1` (OpenAI-compatible).
2. Model `qwen3.5:4b` (kept fully configurable — the app never hardcodes it).
3. Authentication `none` for local/private access, or `bearer`/`basic` behind
   an authenticating proxy.
4. Context length `16384`.

Changing the model (e.g. to a larger or newer one) is a configuration change
only — no code changes.

## Before raising context or concurrency — measure

Do **not** increase `context_length` or run parallel generations without
measuring on the actual VM:

```bash
ollama run qwen3.5:4b --verbose "Write a 900 word test article."
# watch: %CPU, RAM of the ollama process; eval rate (tokens/s)
```

Rules applied by this project:

- One shared model for all AI services (research analysis, topic discovery,
  SEO, writing, proofreading, linking) — no duplicate model copies in RAM.
- Heavy generation tasks run sequentially through the pipeline.
- If RAM pressure or swap appears at 16K context, lower context or move to a
  smaller quantization instead of adding memory pressure.

## Benchmark expectations (honest)

No inference speed is promised here. Actual tokens/s must be benchmarked on
the deployed VM; the app records real durations per request in
`ai_generations`/pipeline history, which is the authoritative measurement.
