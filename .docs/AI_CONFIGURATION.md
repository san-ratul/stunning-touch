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
| Topics | One per line; treated as a shuffled pool — every subject generates at most once (completed runs and AI article titles are never re-picked), and the generator avoids repeating the 20 most recent AI titles |
| Keyword preferences | Fed into the prompt |
| Target categories | Given to the prompt; first one is the fallback category |
| Auto-publish | OFF by default — see Safety |

The schedule runs via Laravel Scheduler (`routes/console.php`): `blog:auto-pipeline` every five minutes with `withoutOverlapping` + `onOneServer` locking. Each tick runs at most one bounded article attempt for the earliest due publishing slot (from `publish_slots` / `posts_per_day` / timezone); the preferred time and frequency remain advisory for the legacy `blog:generate-daily` command. Requires the hosting cron (see CRON_SETUP.md). With auto-publish ON, articles publish only when every mandatory quality check passes — failures stay drafts and the reason is visible under Recent Pipeline Activity and Admin → Monitoring.

## Required credentials
Only one: the API key from your chosen provider, entered in the admin panel (or it can live in `AI_*` env variables if you prefer). No keys are hardcoded; without a key, all AI features degrade gracefully and the rest of the platform is unaffected.
