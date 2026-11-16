# AI Provider Setup — Stunning Touch

The application talks to any OpenAI-compatible chat API **or** a native Ollama
endpoint. All provider settings are stored in the `ai_settings` table via
**Admin → AI Provider** and are editable without redeploying.

## Settings reference

| Setting | Column | Default | Notes |
|---|---|---|---|
| Provider label | `provider` | `openai-compatible` | Free-form label, shown in logs |
| Endpoint | `endpoint` | — | `https://host/v1` (service appends `/chat/completions`) **or** `http://host:11434/api/chat` (Ollama native) |
| Authentication | `auth_mode` | `bearer` | `none`, `bearer`, or `basic` |
| API key | `api_key` | — | Bearer mode only; stored encrypted, never rendered back |
| Basic auth user/password | `basic_auth_*` | — | For authenticating reverse proxies; password encrypted |
| Model | `model` | — | e.g. `qwen3.5:4b` |
| Context length | `context_length` | `16384` | Sent as `options.num_ctx` to Ollama native endpoints |
| Request timeout | `timeout_seconds` | `900` | Long CPU generations need minutes, not seconds |
| Connect timeout | `connect_timeout_seconds` | `15` | |
| Max retries | `max_retries` | `2` | Retries 429 + 5xx responses and connection failures |
| Retry backoff | `retry_backoff_ms` | `2000` | Exponential: base × 2^(attempt−1) |
| Keep-alive | `keep_alive` | `10m` | How long Ollama keeps the model in RAM |
| Max output tokens | `max_tokens` | — | Mapped to `max_tokens` (OpenAI) or `options.num_predict` (Ollama) |

## Keyless Ollama

Choose **Authentication = None** when Ollama is reached through an
authenticating reverse proxy or a private network path. The key field is then
optional and no `Authorization` header is sent.

## Google Gemini (OpenAI-compatible)

Verified against https://ai.google.dev/gemini-api/docs/openai:

| Setting | Value |
|---|---|
| Endpoint | `https://generativelanguage.googleapis.com/v1beta/openai/` |
| Model | e.g. `gemini-3.8-flash` (see Google's models doc for current IDs) |
| Authentication | `bearer` |
| API key | Gemini API key from Google AI Studio (stored encrypted) |

The client builds `POST https://generativelanguage.googleapis.com/v1beta/openai/chat/completions`
with `Authorization: Bearer <key>` and an OpenAI-format body; model listing
uses `GET /v1beta/openai/models`. The admin page shows a persistent
configuration-state banner (ready / disabled / incomplete) computed by the
same service code that performs requests, so the UI and backend can never
disagree. A bearer provider cannot be saved without a key.

## Token usage normalization

`AiProviderService::chatWithUsage()` returns `usage` in one normalized shape
regardless of provider:

- OpenAI-compatible: `usage.prompt_tokens` / `usage.completion_tokens`
- Ollama native: `prompt_eval_count` / `eval_count`

Both map to `prompt_tokens`, `completion_tokens`, `total_tokens`, and are
persisted per generation in `ai_generations`.

## Concurrency

`AiProviderService::withGenerationLock()` enforces a single simultaneous LLM
request via a cache lock (`ai:generation`). On 12 GB Oracle ARM instances keep
this at 1 — see `.docs/QWEN_MODEL_CONFIGURATION.md`.

## Verifying

Admin → AI Provider provides **Test Connection** (short 60 s chat ping) and
**Check Model Availability** (lists `/api/tags` or `/models` and matches the
configured model).
