# AI Configuration

The AI system is a **provider-agnostic draft generator**: any OpenAI-compatible chat-completions API works (OpenAI, OpenRouter, Groq, Together, Azure-compatible endpoints, self-hosted LLMs…).

## Provider setup (Admin → AI Provider)
| Field | Notes |
|---|---|
| API endpoint | Base URL ending at `/v1` — the service appends `/chat/completions` automatically |
| Provider label | Free text |
| Model | e.g. `gpt-4o-mini`, `llama-3.1-70b`… |
| API key | Stored **encrypted** in the DB (Laravel `encrypted` cast); never displayed again — leave blank to keep the existing key |
| Temperature / Max tokens | Optional overrides |
| Enabled | Master switch |

Buttons: **Test Connection** (sends a 10-token ping) and **Generate Draft Now** (one-off run).

## What generation produces
A structured JSON article: title, slug, excerpt, HTML content (900–1400 words requested), focus/secondary keywords, SEO title/description, blog category, tags, related tools, suggested image alt, outline. The service:
- validates the JSON (markdown fences stripped),
- dedupes the slug,
- resolves the blog category (by name, fallback to configured categories),
- syncs tags,
- saves the post as a **draft** with `source=ai` and computed reading time.

## Honesty guarantees (implemented, not aspirational)
- The system prompt forbids fabricated statistics, research results, quotes and search volumes; no verified search-data provider is integrated, so nothing claims real search volumes.
- **Featured images are never AI-invented.** `suggested_image_alt` is stored for the admin; a human attaches the actual image from the media library.
- Auto-publish is **off by default**; enabling it is an explicit admin decision (Automation → Safety).
- Failures (HTTP errors, invalid JSON) leave a failed `ai_generations` row with the error — never a half-written post.

## Automation (Admin → Automation)
| Setting | Behavior |
|---|---|
| Enable | Master switch for scheduled generation |
| Frequency | Daily or Weekly (Mondays) |
| Preferred time + timezone | Default 09:05, Asia/Dhaka |
| Topics | One per line; the generator avoids repeating the 5 most recent AI topics |
| Keyword preferences | Fed into the prompt |
| Target categories | Given to the prompt; first one is the fallback category |
| Auto-publish | OFF by default — see Safety |

The schedule runs via Laravel Scheduler (`routes/console.php`): `blog:generate-daily` at the preferred time with `withoutOverlapping` + `onOneServer` locking, and skips if a generation already completed that day. Requires the hosting cron (see CRON_SETUP.md).

## Required credentials
Only one: the API key from your chosen provider, entered in the admin panel (or it can live in `AI_*` env variables if you prefer). No keys are hardcoded; without a key, all AI features degrade gracefully and the rest of the platform is unaffected.
