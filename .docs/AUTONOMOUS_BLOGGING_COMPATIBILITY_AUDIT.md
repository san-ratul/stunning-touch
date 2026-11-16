# Autonomous AI Blogging Compatibility Audit — Stunning Touch MVP

**Audit date:** 2026-11-16
**Scope:** Read-only compatibility audit. No code was modified.
**Target system:** 3–4 fully autonomous, high-quality blog posts per day using self-hosted Llama 3.1 8B (Ollama, Oracle Cloud Free Tier), including research, keywords, images, validation, and unattended publishing.

> Every finding below cites the file, class, method, or migration that supports it. Items marked **[verified]** were confirmed by reading the implementation. Items marked **[assumption]** are engineering estimates (e.g. hardware throughput) and are flagged as such.

---

## 1. Executive Summary

The MVP has a **solid foundation** for AI-assisted blogging: a working OpenAI-compatible client with encrypted key storage, a single-shot draft generator with an anti-fabrication contract, a draft-first blog CMS with full SEO, a scheduler, and generation logging. It is production-proven for **1 reviewed post per day**.

It is **not ready for fully autonomous 3–4 posts/day**. Verified blockers:

1. **One post per day is structurally hard-coded** — `GenerateDailyBlog` runs once daily and generates exactly one article per run, with a once-per-day completion guard (`app/Console/Commands/GenerateDailyBlog.php:37-48`).
2. **Automated publishing is dead code** — `automation_settings.auto_publish` exists and is editable in the admin UI, but `BlogGeneratorService::createDraft()` hard-codes draft status: `'status' => $autoPublish ? 'draft' : 'draft'` (`app/Services/Ai/BlogGeneratorService.php:186`). Nothing can ever auto-publish.
3. **Scheduled posts never publish themselves** — no command flips `status='scheduled'` → `published` when `scheduled_at` is due; the public scope `BlogPost::scopePublished()` (`app/Models/BlogPost.php:64`) requires `status='published'`, so scheduled posts remain invisible indefinitely.
4. **No research layer** — topic input is a manual topics list; no web research, no keyword data, no competitor analysis, no source tracking anywhere in the codebase.
5. **HTTP timeout will kill Llama 3.1 8B runs** — `AiProviderService::chat()` uses `->timeout(120)` (`app/Services/Ai/AiProviderService.php:91`); CPU inference on Oracle Free ARM for a 900–1,400-word article typically takes longer than 120 s **[assumption]**.
6. **No retry/backoff, no queue** — one attempt, synchronous, no `app/Jobs` directory, `QUEUE_CONNECTION=database` configured but unused.

The good news: none of the gaps require architectural rework. The provider abstraction, draft pipeline, CMS, and audit tables are the right bones; the missing pieces are additive services plus schema additions.

---

## 2. Existing Architecture Overview [verified]

| Layer | Implementation | Reference |
|---|---|---|
| Framework | Laravel `^13.0`, PHP `^8.3` | `composer.json` |
| Database | MySQL (dev: Laragon MySQL 8.4), utf8mb4 | `.env` `DB_*`; `config/database.php` |
| Frontend | Blade SSR + Alpine.js + Tailwind CSS 4 (Vite build) | `resources/views`, `vite.config.js` |
| Queue | `QUEUE_CONNECTION=database` configured, **no jobs exist** (no `app/Jobs` directory) | `.env:38` |
| Cache / sessions | `database` driver (no Redis) | `.env:30,40` |
| Scheduler | Minute-cron → `schedule:run` or protected HTTP endpoint `/run-scheduler/{secret}` (hash_equals + `throttle:2,1`) | `routes/web.php`, `routes/console.php`, `.docs/CRON_SETUP.md` |
| Blog CMS | `BlogPost` (draft/scheduled/published/archived, `source` manual/ai), `BlogCategory`, `BlogTag` (+pivot), `Media` | `database/migrations/2026_01_01_000005_create_blog_media_tables.php`, `app/Http/Controllers/Admin/BlogPostController.php` |
| SEO | Per-post SEO columns + polymorphic `seo_meta` for tools/categories/blog-categories/static pages; sitemap; robots.txt | `2026_01_01_000005_*` lines 33-48, `2026_01_01_000002_*`, `app/Http/Controllers/Public/SitemapController.php` |
| AI provider | `AiProviderService` (OpenAI-compatible `/chat/completions`), settings in `ai_settings` (endpoint, **encrypted** `api_key` cast, model, temperature, max_tokens, is_active) | `app/Services/Ai/AiProviderService.php`, `app/Models/AiSetting.php` (casts `api_key => encrypted`), `2026_01_01_000006_*` lines 12-20 |
| Generation history | `ai_generations` (topic, status pending/completed/failed, error_message, blog_post_id, prompt/completion tokens) | `2026_01_01_000006_*` lines 24-32, `app/Models/AiGeneration.php` |
| Automation config | `automation_settings` (is_enabled, frequency daily/weekly, preferred_time, timezone, categories json, topics json, keywords, auto_publish, last_run_at) | `2026_01_01_000006_*` lines 35-45, `app/Models/AutomationSetting.php` |
| Commands | `blog:generate-daily`, `analytics:aggregate`, (VisitorCleanup helper) | `app/Console/Commands/` |
| Admin UI | AI Provider settings page (with Test Connection + Generate Now), Automation page (incl. auto_publish toggle, safety note), Blog CRUD, Media Library, Audit Logs | `resources/views/admin/ai/*`, `app/Http/Controllers/Admin/{AiSettingsController,AutomationController,BlogPostController,MediaController}.php` |
| Audit trail | `audit_logs` recorded for `ai_settings.updated`, `automation.updated`, `post.created/published/…` | `app/Models/AuditLog.php::record`, `app/Http/Controllers/Admin/*` |
| Tests | 60 passing (262 assertions) incl. `tests/Unit/AiServiceTest.php` (provider guards, endpoint building, failure logging, draft-not-published) and `tests/Feature/AdminManagementTest.php` (draft→publish→schedule workflow, scheduled posts invisible until due) | `tests/` |
| Docs | 13 documents incl. `AI_CONFIGURATION.md`, `CRON_SETUP.md`, `BLOG_MANAGEMENT.md` | `.docs/` |

---

## 3. Compatibility Matrix

| Capability | Assessment | Evidence |
|---|---|---|
| Ollama integration | **Partial** | OpenAI-compatible client exists (`AiProviderService::chat`, appends `/chat/completions` at lines 73-77 — matches Ollama's `/v1/chat/completions`). No Ollama-native `/api/generate` mode. `isConfigured()` **requires a non-empty `api_key`** (line 30) although Ollama ignores auth — a dummy key works but is undocumented. No timeout tuning, no keep-alive handling. |
| Llama 3.1 8B | **Partial** | Any model name passes through (`ai_settings.model`, `2026_01_01_000006_*:16`). `max_tokens` default 6000 set in `BlogGeneratorService:64`. No known incompatibilities — but `->timeout(120)` (`AiProviderService:91`) is too short for CPU inference of 900–1,400-word articles **[assumption]**. |
| External web research | **Missing** | No research code exists. `BlogGeneratorService` goes straight from topic → single prompt (`generateForTopic:59-64`). No HTTP fetch of external sources anywhere outside the admin AI Test button. |
| Keyword research | **Missing** | `automation_settings.keywords` is a free-text string fed into the prompt (`buildPrompt:103,112`). No keyword API, no volume/intent data. |
| Competitor analysis | **Missing** | No competitor fetch/analysis code in `app/Services/Ai/` or `app/Console/Commands/`. |
| Automated topic discovery | **Missing** | `pickTopic()` (`BlogGeneratorService:85-99`) selects from the manually curated `topics` json, skipping exact matches among the last 5 AI posts. No trends, feeds, or search-derived topics. |
| Long-form article generation | **Compatible** | Prompt requests 900–1,400 words (`buildPrompt:118`), `max_tokens: 6000` (`generateForTopic:64`), HTML structure contract, JSON parsing with fence stripping (`parseJson:143-158`). Single-shot only — no outline→sections multi-pass. |
| Featured image generation | **Missing** | No image generation. `createDraft` explicitly does not create images (`BlogGeneratorService:193` comment); only `suggested_image_alt` JSON field exists. Featured images come from the admin media library (`resources/views/admin/blog/posts/form.blade.php` picker). |
| SEO metadata generation | **Compatible** | Generator maps title/slug/excerpt/focus_keyword/secondary_keywords/seo_title/meta_description into post columns (`createDraft:175-191`); robots flags default true; `BlogPost::readingTimeFor` computed. OG/Twitter fields left empty for admin (acceptable). |
| Internal link generation | **Partial** | Prompt instructs 1–4 absolute tool links (`buildPrompt:121`); `related_tools` JSON enables the “Try the tools mentioned” box via keyword matching (`app/Models/BlogPost.php::findToolsForKeywords`, `resources/views/blog/show.blade.php`). No automatic post↔post internal linking, no link validation. |
| Article quality validation | **Missing** | Only JSON-shape validation (`parseJson:153-155`). No word-count enforcement, readability checks, banned-claim scanning, or link verification. Anti-fabrication exists only as a system-prompt instruction (`generateForTopic:62`). |
| Automated publishing | **Missing (dead code)** | `auto_publish` is stored and editable (`AutomationController::update`, `2026_01_01_000006_*:43`) but `createDraft:186` always writes `'draft'` — the ternary `$autoPublish ? 'draft' : 'draft'` is a hard-coded draft. Additionally, scheduled posts are never auto-flipped to published (no code in `app/Console/Commands/*`). |
| Daily 3–4 post scheduling | **Missing** | Scheduler runs the generator once daily at `preferred_time`-ish fixed `09:05` (`routes/console.php:17-21`); the command generates exactly one article and dedupes against any completed generation that day (`GenerateDailyBlog:37-48`). No posts-per-day concept, no spacing, no multi-slot runs. |
| Duplicate topic detection | **Partial** | Exact, case-insensitive title match against the last 5 AI posts only (`pickTopic:94-96`) + slug collision suffixing (`createDraft:164-166`). No near-duplicate/similarity detection, no keyword-overlap checks. |
| Research source tracking | **Missing** | No table/columns for source URLs or fetch timestamps. `ai_generations` stores topic/status/error/tokens only (`2026_01_01_000006_*:24-32`). |
| Failure recovery and retry | **Partial** | Failures are captured (`ai_generations.status=failed`, `error_message`) and logged (`generateForTopic:77-82`), and the command exits FAILURE. But there is **no retry, no backoff, no partial-progress resume, no rate-limit handling** — a single transient HTTP failure loses the whole run for that day (unless `--force` is re-run manually). |

---

## 4. Already Implemented Features (verified)

1. **Provider abstraction** — `AiProviderService` is the only AI touchpoint; provider is swappable by editing `ai_settings` rows via Admin → AI Provider (`AiSettingsController::update` keeps the stored key when a blank one is submitted). Key encrypted at rest via `'api_key' => 'encrypted'` cast (`app/Models/AiSetting.php`).
2. **Connection test + manual one-off generation** — `AiSettingsController::testConnection` / `generateNow` → `BlogGeneratorService::generateFromAutomationSettings(manual: true)`.
3. **Draft-first pipeline with full CMS** — AI drafts land as `status=draft`, `source=ai`, tagged `AI` in the admin list (`resources/views/admin/blog/posts/index.blade.php` badge), then flow through the existing publish/unpublish/archive actions.
4. **Generation audit trail** — every run creates an `ai_generations` row (pending → completed/failed, error text, linked post); admin list shows the last 20 with links to drafts (`resources/views/admin/ai/settings.blade.php`).
5. **Honesty guardrails (prompt-level)** — system prompt forbids fabricated statistics/quotes/search volumes (`BlogGeneratorService:62,119`); featured images are never invented (line 193).
6. **Daily-run dedupe + locking** — `withoutOverlapping()` + `onOneServer()` mutexes (`routes/console.php:20-21`) and the completed-today guard (`GenerateDailyBlog:38-48`).
7. **Shared-hosting cron path** — minute cron, `/run-scheduler/{secret}` fallback with `hash_equals` + throttle (`routes/web.php`), documented in `.docs/CRON_SETUP.md`.
8. **Blog CMS completeness** — statuses, scheduling fields, categories, tags (auto-created by the generator), authors, featured image + alt, reading time, view counts, per-post SEO columns.
9. **Media library** — validated uploads (jpg/jpeg/png/webp/gif/svg/pdf/txt/csv ≤ 4 MB, MIME-checked — `MediaController::ALLOWED_MIMES`), library picker embedded in the post editor.
10. **Token usage logging (unused)** — `ai_generations.prompt_tokens/completion_tokens` columns exist (`2026_01_01_000006_*:29-30`) but are never populated by `AiProviderService::chat()` (the usage field of the API response is ignored).
11. **Test coverage of the AI pipeline** — `tests/Unit/AiServiceTest.php` (7 tests): unconfigured provider guard, endpoint building, API error propagation, JSON generation → draft (never published), failure logging, topic guard.
12. **Site-level SEO for AI posts** — published AI posts automatically enter `sitemap.xml` (`SitemapController` includes published posts), Article JSON-LD, canonical, breadcrumbs.

---

## 5. Partially Implemented Features

1. **Ollama compatibility** — works via the OpenAI-compatible endpoint, but: (a) requires a dummy `api_key` due to `isConfigured()` (`AiProviderService:30`); (b) no streaming (irrelevant for cron but hurts long-request supervision); (c) 120 s timeout too aggressive for local LLMs; (d) no `keep_alive` / model warm-up handling, so a cold Ollama instance adds 30–60 s model-load time per call **[assumption]**.
2. **Duplicate detection** — exact-match only across 5 recent AI titles; no similarity metrics, no keyword-clash checks, no per-category quotas.
3. **Internal linking** — tool links only, AI-suggested, unvalidated (links may 404 if a tool slug changes; no href verification).
4. **Failure handling** — good logging, zero recovery: no retries, no exponential backoff, no rate-limit awareness, no partial-result checkpointing.
5. **Scheduling model** — daily|weekly only; a fixed `dailyAt('09:05')` is hard-coded in `routes/console.php:18` and ignores `automation_settings.preferred_time` (the setting exists but only drives documentation/UX; the scheduler time is static).
6. **Token accounting** — columns exist, never written; cost/usage dashboards impossible without them.
7. **Prompt/context quality** — single-shot prompt includes tools + categories, but not: site’s best-performing posts, existing tag inventory, recent trend context, or per-category topic history.

---

## 6. Missing Components

| # | Component | Why it is required for the target | Where it would live (suggested, not implemented) |
|---|---|---|---|
| 1 | **ResearchService** — fetches real, citable sources (RSS feeds, Wikipedia REST API, Hacker News API, GitHub trending — all free, no keys) | Requirement 1–4, 8: real research, no fabrication | `app/Services/Ai/ResearchService.php` |
| 2 | **Research source storage** — `blog_research_sources` table (post/generation id, url, title, snippet, source_type, fetched_at) | “Store source URLs and research timestamps for every article” | migration + `app/Models/BlogResearchSource.php` |
| 3 | **KeywordService** — keyword candidates from Google Trends (unofficial), autocomplete suggest endpoints, or site’s own search logs; intent classification | Requirement 2 | `app/Services/Ai/KeywordService.php` |
| 4 | **TopicDiscoveryService** — combines trends + keyword data + site gaps (tool coverage) → scored topic proposals | Requirement 1, 4 | `app/Services/Ai/TopicDiscoveryService.php` |
| 5 | **DuplicateDetectorService** — normalized-title similarity (e.g. `similar_text`/shingle Jaccard) + slug/keyword clash + per-day topic history | “Detect duplicate and near-duplicate topics” | `app/Services/Ai/DuplicateDetectorService.php` |
| 6 | **QualityValidatorService** — min/max word count, heading structure, banned-claim lexicon scan (“studies show”, invented percentages), internal-link presence + HTTP check, readability (Flesch), broken-HTML lint | Requirement 8, “validate articles before automatic publishing” | `app/Services/Ai/QualityValidatorService.php` (+ `quality_reports` table) |
| 7 | **ImageService** — generates simple branded SVG/PNG cover images locally (tool + title composition) or pulls properly licensed assets (Openverse/Wikimedia API with attribution) | Requirement 6, licensing rule | `app/Services/Ai/ImageService.php` |
| 8 | **Auto-publish path** — honor `auto_publish` only when the article passes QualityValidator; plus a `blog:publish-scheduled` command that flips due `scheduled` posts | Requirements 9; fixes two dead-code bugs | extend `BlogGeneratorService::createDraft`, new command + schedule entry |
| 9 | **Multi-post orchestrator** — `posts_per_day` setting, spaced runs (e.g. 3 slots), one post per cron invocation with state cursor, overall daily topic budget | 3–4 posts/day on minute-cron shared hosting | `app/Console/Commands/BlogAutoPipeline.php` + `automation_settings.posts_per_day` |
| 10 | **Resilient AI client** — configurable timeout (600–900 s), retries with backoff (2–3 attempts), 429/5xx handling, response-usage logging into `ai_generations` | “Handle long-running AI requests, timeouts, rate limits” | extend `AiProviderService` |
| 11 | **Provider profile: Ollama-native + auth handling** — support `auth: none` / basic-auth reverse proxy; keep-alive option | “Llama 3.1 8B configurable as OpenAI-compatible or Ollama-native” | extend `AiProviderService` + `ai_settings` (auth_mode, timeout, retries columns) |
| 12 | **Generation run bookkeeping** — duration_ms, model snapshot, attempt count, research summary link | “Track generated articles, research sources, failures, publishing history” | `ai_generations` migration additions |

---

## 7. Technical Limitations (verified constraints of the current design)

1. **Synchronous generation in cron** — no queue workers exist by design (shared-hosting rule). A 3–4× daily multi-minute AI call must be split into one-post-per-cron-tick steps or accept long-running cron invocations (`GenerateDailyBlog` currently blocks the scheduler mutex for the full generation).
2. **`AiProviderService::chat()` hard-codes `timeout(120)` / `connectTimeout(15)`** (`AiProviderService:90-93`) — no injection point for per-call overrides.
3. **`buildPrompt` is a single monolithic template** (`BlogGeneratorService:101-141`) — no multi-stage outline → section → review passes, which matters for 8B-model coherence over long articles.
4. **JSON-only response contract** — small models frequently wrap JSON in prose despite instructions; `parseJson` strips code fences but has no repair/retry path (`BlogGeneratorService:143-158`).
5. **`pickTopic` statefulness** — relies on manual topics; after exhaustion it silently reuses the first topic forever (line 98: falls back to `$topics->first()`), producing duplicates over time.
6. **No storage abstraction for remote files** — media is `Storage::disk('public')` local only (`MediaController::store`); fine for shared hosting, relevant only if image generation runs remotely.
7. **Scheduler time is static** — `dailyAt('09:05')` ignores `preferred_time`; changing it requires code edit or a re-registered schedule (relevant for spreading 3–4 posts/day).
8. **`QUEUE_CONNECTION=database` is configured but unused** — introducing one queued job would require a worker or cron-based `queue:work --stop-when-empty` step; the current design deliberately avoids that (keep it that way for shared hosting).

---

## 8. Database Changes Required

All additive; no breaking changes to existing tables.

| Table | Change | Purpose |
|---|---|---|
| `automation_settings` | + `posts_per_day` int (default 1), + `publish_slots` json (e.g. ["06:00","12:00","18:00"]), + `min_word_count` int, + `research_enabled` bool, + `auto_publish` **honor** (column exists) | 3–4/day scheduling + safety |
| `ai_settings` | + `timeout_seconds` int (default 600), + `max_retries` int (default 2), + `auth_mode` enum(none,basic,bearer), + `keep_alive` bool | Ollama profiles |
| `ai_generations` | + `blog_post_id` (exists), + `duration_ms`, + `attempt`, + `model_snapshot` string, + `provider_snapshot` string, + `research_summary` json | run bookkeeping |
| NEW `blog_research_sources` | id, `ai_generation_id` FK nullable, `blog_post_id` FK nullable, `url` string(768), `title`, `snippet` text null, `source_type` (rss/wikipedia/api/manual), `fetched_at`, unique(url + generation_id) | source tracking |
| NEW `quality_reports` | id, `blog_post_id` FK, `score` decimal, `checks` json (per-check pass/fail/details), `passed` bool | publish gate |
| `blog_posts` | + `ai_model` string null, + `generation_id` FK null (reverse link), optional `duplicate_of` id null | provenance |

Indexes: `(blog_posts.status, scheduled_at)` already indexed via `status` index + `scheduled_at` column; add index on `blog_research_sources.blog_post_id`.

---

## 9. Required Services and Classes

1. `App\Services\Ai\AiProviderService` — **extend** (do not replace): inject timeout/retries/auth-mode from settings; log usage tokens; expose `stream()` optional; keep `chat()` signature for backward compatibility with `AiSettingsController::testConnection`.
2. `App\Services\Ai\ResearchService` — `research(string $topic): ResearchResult` returning candidate sources (url, title, extract, fetchedAt) from whitelisted free APIs (Wikipedia REST `https://**/api/rest_v1/…`, Hacker News Algolia API, RSS feeds per category). All sources stored verbatim — never synthesized.
3. `App\Services\Ai\TopicDiscoveryService` — merges RSS/trends + keyword suggestions + `automation_settings.topics`; returns scored topic queue persisted in a `topics_queue` json column or table.
4. `App\Services\Ai\KeywordService` — derives focus/secondary keywords from research extracts + site tool inventory; explicitly labels volume data as unavailable unless a real provider is configured.
5. `App\Services\Ai\DuplicateDetectorService` — normalized similarity vs the last N=100 posts (title shingles + slug/keyword overlap), threshold configurable.
6. `App\Services\Ai\QualityValidatorService` — runs pre-publish checks; returns `QualityReport`; **auto-publish only on `passed=true`**.
7. `App\Services\Ai\ImageService` — deterministic branded cover generator (GD/Imagick is already available on typical shared hosting; SVG covers need the `svg` mime already whitelisted in `MediaController`).
8. `App\Console\Commands\BlogAutoPipeline` — one **step** per invocation (research → draft → validate → publish), driven by `posts_per_day` and `publish_slots`, using cache locks; replaces single-shot `GenerateDailyBlog` (keep the old command as a thin wrapper for compatibility).
9. `App\Console\Commands\BlogPublishScheduled` — flips due scheduled posts; also the fix for the existing scheduled-visibility gap.
10. `App\Services\Ai\BlogGeneratorService` — **extend**: accept a `ResearchResult` to ground the prompt (“use only these sources; do not add facts beyond them”), emit `research_sources` rows, run validator, and honor auto-publish on pass.

---

## 10. Required Admin Settings

Existing admin pages already cover provider CRUD, automation toggles, and media (`resources/views/admin/ai/*`, `resources/views/admin/media/*`). Additions needed on the **Automation** page:

| Setting | Suggested default | Notes |
|---|---|---|
| Posts per day | 1 (cap 4) | Drives orchestrator |
| Publish slots (times) | 06:00 / 12:00 / 18:00 / 21:00 | Spacing + `preferred_time` honored in scheduler registration |
| Auto-publish | **off** (exists) | Must actually gate on validator pass — currently dead code |
| Min word count / max word count | 900 / 2,000 | Validator |
| Research enabled + source whitelist | on / curated list | ResearchService |
| AI timeout / retries / auth mode | 600 s / 2 / bearer | Ollama profiles |
| Duplicate threshold | 85 % | DuplicateDetector |
| Featured image mode | none / generated-cover / licensed-fetch | ImageService |
| Per-category daily quotas | derived | avoids topical monoculture |

All persisted in `automation_settings` / `ai_settings` (existing single-row pattern, `*::current()` helpers), audited via `AuditLog::record` like existing updates.

---

## 11. Recommended Autonomous Workflow (design that fits the current architecture)

```
Minute-cron (schedule:run) every minute
 └─ BlogAutoPipeline (withoutOverlapping, onOneServer)
     ├─ Step 1 — due slot? (posts_per_day × publish_slots vs state cursor) → no → exit
     ├─ Step 2 — TopicDiscovery: pick next topic (skip duplicates via DuplicateDetector)
     ├─ Step 3 — ResearchService: fetch 3–6 whitelisted sources, store rows + timestamps
     ├─ Step 4 — AiProviderService.chat(): grounded prompt (sources embedded, "do not invent")
     │            long timeout + retry/backoff; usage logged to ai_generations
     ├─ Step 5 — createDraft() (source=ai) + research rows linked
     ├─ Step 6 — QualityValidator: structure/length/claims/links
     ├─ Step 7 — auto_publish && passed → publish (status=published, published_at=now)
     │            else → stays draft for review (email/admin badge)
     └─ Step 8 — audit_log entry; advance cursor
```

Safety invariants to implement (consistent with the existing prompt-level rules in `BlogGeneratorService:62`):
- Never publish a draft whose quality report failed.
- Never publish without research rows when `research_enabled`.
- Cap AI posts per category per week; keep `source=ai` marker forever.
- Every article stores: model snapshot, generation id, research URLs + fetch timestamps.

---

## 12. Oracle Free Tier Compatibility

| Aspect | Assessment |
|---|---|
| Instance | Always Free **A1.Flex (ARM Ampere)** — up to 4 OCPU / 24 GB RAM across 1–4 VMs is the only viable shape; the x86 **VM.Standard.E2.1.Micro (1 GB RAM)** cannot load Llama 3.1 8B even at Q4 (~4.9 GB) **[verified against model size; instance specs are public Oracle pricing]** |
| Model footprint | Llama 3.1 8B Q4_K_M GGUF ≈ 4.7–5.1 GB; Ollama runtime overhead → fits comfortably in 8–24 GB RAM |
| Throughput | CPU-only A1 inference ≈ 2–8 tokens/s per article run **[assumption]** → 1,800–2,600 completion tokens ≈ **5–15 min per article**; 3–4 articles/day ≈ 15–60 min total GPU-free compute — well within budget |
| Storage | Model + OS ≈ 10–15 GB; Always Free block storage 200 GB total — fine |
| Network | Ollama must be reachable by the shared-hosting app: bind Ollama to `0.0.0.0` behind an authenticating reverse proxy (Caddy/nginx basic auth or Cloudflare Access) — **Ollama’s OpenAI endpoint has no built-in auth**, and the MVP always sends `Authorization: Bearer …` (`AiProviderService:90`), which pairs cleanly with basic-auth proxies |
| Timeout | The 120 s hard timeout (`AiProviderService:91`) must be raised to ≥ 900 s for this provider profile; keep `max_tokens: 6000` (`BlogGeneratorService:64`) — 8B context 128k is sufficient for research-grounded 1,400-word articles |
| Capacity risk | Oracle A1 “out of capacity” is a well-known issue in busy regions; mitigations: pick home region carefully, keep instance stopped→restarted habit, or fall back to a paid shape temporarily — **[assumption/community-reported]** |
| Verdict | **Feasible** with the A1 Flex ARM shape; requires the provider-profile settings (timeout/auth) from §10 |

---

## 13. Implementation Roadmap

**Phase 1 — Correctness fixes (prerequisite, ~1 day)**
1. Fix `createDraft` dead auto-publish ternary (`BlogGeneratorService:186`) — gate on `auto_publish` + quality pass.
2. Add `blog:publish-scheduled` command + schedule entry to flip due `scheduled` posts (fixes invisible-scheduled-posts gap).
3. Honor `preferred_time` when registering the schedule (cache the schedule or use a per-slot `dailyAt` registration).
4. Log API usage tokens into `ai_generations` from `chat()`.

**Phase 2 — Provider hardening (~1–2 days)**
5. `ai_settings` additions: timeout_seconds, max_retries, auth_mode, keep_alive; refactor `chat()` to use them; retry with exponential backoff on 429/5xx/timeouts.
6. Ollama profile documentation + seed example; verify `isConfigured()` accepts `auth_mode=none` (relax the `api_key` requirement accordingly).
7. Admin UI additions on the AI Provider page; extend `AiServiceTest`.

**Phase 3 — Research layer (~3–4 days)**
8. `blog_research_sources` migration + model.
9. `ResearchService` (Wikipedia REST + HN Algolia + category RSS whitelists; each fetch stored with URL + timestamp).
10. Ground `buildPrompt` in research; forbid facts beyond sources; extend tests with `Http::fake`.

**Phase 4 — Topic + duplicate intelligence (~2–3 days)**
11. `TopicDiscoveryService` + `topics_queue` storage; `KeywordService` (clear labeling: no fabricated volumes).
12. `DuplicateDetectorService`; wire into topic pick AND pre-publish check.

**Phase 5 — Quality gate + images (~2–3 days)**
13. `QualityValidatorService` + `quality_reports`; publish only on pass.
14. `ImageService` (branded cover generation into the existing media pipeline; alt text from `suggested_image_alt`).

**Phase 6 — Autonomous orchestration (~2–3 days)**
15. `automation_settings` additions (posts_per_day, slots, word bounds); `BlogAutoPipeline` stepwise command replacing the daily single-shot; admin UI for new settings; end-to-end test simulating 4 runs/day with `Http::fake`.

**Phase 7 — Ops (ongoing)**
16. Dashboard widget: generation success rate, drafts pending review, sources per article; retention policy for `ai_generations`.

---

## 14. Estimated Resource Requirements and Bottlenecks

**Oracle A1 (Llama 3.1 8B Q4 via Ollama) [assumption-based estimates]**
- RAM: 6–10 GB steady during inference (model + KV cache for ~8–10k context) — fits 24 GB free shape with headroom.
- Per-article wall time: 5–15 min (CPU); 3–4/day ≈ 20–60 min — trivial for the daily budget, but each call exceeds any shared-hosting PHP `max_execution_time` (typically 30–300 s) → **keep generation on the Oracle box** (Ollama holds the long request; PHP just waits) or split generation into outline/section calls.
- Bottleneck #1: CPU token throughput → response length discipline (cap 1,200–1,400 words) and `keep_alive` to avoid reload costs.

**Shared hosting (Laravel app)**
- Bottleneck #2: cron execution window — one AI round-trip per cron tick is already ≥ 5 min; long-running PHP requests may be killed by host watchdogs. Mitigation: the pipeline must checkpoint per step (research persisted, then generation) so a killed run resumes — this is why the stepwise `BlogAutoPipeline` design is recommended over one long synchronous command.
- Bottleneck #3: MySQL row growth from `page_views`/`tool_usage` — already mitigated by `analytics:aggregate --cleanup`.

**Quality bottleneck (the biggest product risk)**
- Llama 3.1 8B long-form output quality is mediocre vs frontier models; without the research grounding + validator, 4×/day output risks thin, repetitive content and search-engine demotion. The quality gate + human spot-checks are mandatory, not optional.

---

## 15. Risks and Recommendations

| Risk | Severity | Mitigation |
|---|---|---|
| Dead auto-publish + never-publishing scheduled posts (verified bugs) | **High** | Phase 1 fixes; add feature tests for both paths |
| 120 s timeout kills every real Llama run | **High** | Configurable timeout/retries (Phase 2) |
| 3–4×/day autonomous publishing of 8B-model content can trigger search-engine spam policies | **High (product)** | Keep `auto_publish` default off initially; quality gate; human review cadence; enforce originality via research grounding; keep `source=ai` provenance |
| Fabricated facts from 8B model despite prompt rules | High | Research-grounded prompts (only supplied sources), validator banned-claim scan, source display on article (“Sources” footer) |
| Oracle A1 capacity unavailability | Medium | Region choice, fallback provider profile in `ai_settings` (abstraction already supports hot-swapping) |
| Ollama exposed without auth | Medium | Reverse-proxy basic auth (matches existing Bearer header), HTTPS only, firewall to app host |
| Unvalidated AI links (404s) | Medium | Validator HTTP-checks internal links (external links `rel="nofollow"` already handled in views) |
| Topic exhaustion → repetition (verified `pickTopic` fallback) | Medium | TopicDiscovery queue + duplicate detector |
| `parseJson` fragility with 8B models | Medium | JSON repair pass + one regeneration attempt with stricter instruction |
| Cron mutex held for minutes during generation (`withoutOverlapping`) | Low | Stepwise pipeline checkpoints; keep each step < host PHP time limit |

---

## 16. Verification Appendix (what was inspected)

- `composer.json` (Laravel ^13.0, PHP ^8.3)
- `app/Services/Ai/AiProviderService.php` (full)
- `app/Services/Ai/BlogGeneratorService.php` (full)
- `app/Console/Commands/GenerateDailyBlog.php` (full), `AggregateAnalytics.php`, `VisitorCleanup.php`
- `routes/console.php` (full), `routes/web.php` (scheduler endpoint)
- `app/Models/{AiSetting, AiGeneration, AutomationSetting, BlogPost}.php`
- `database/migrations/2026_01_01_000005_create_blog_media_tables.php`, `2026_01_01_000006_create_ai_settings_pages_tables.php`
- `app/Http/Controllers/Admin/{AiSettingsController, AutomationController, BlogPostController, MediaController}.php`
- `.env` (queue/cache/session drivers), absence of `app/Jobs`
- `tests/Unit/AiServiceTest.php`, `tests/Feature/AdminManagementTest.php`
- `.docs/` inventory (13 documents)
