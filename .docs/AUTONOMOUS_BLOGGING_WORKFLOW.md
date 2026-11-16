# Autonomous Blogging Workflow

## Pipeline overview

`BlogAutoPipeline` (`app/Services/Ai/BlogAutoPipeline.php`) orchestrates one
bounded article attempt per invocation:

1. **Slot check** — the earliest due slot from `automation_settings.publish_slots`
   (in the configured timezone) that has no completed run today.
2. **Daily limit** — completed runs today must be below `posts_per_day` (hard cap 4).
3. **Topic discovery** — manual topics, content gaps (tools/categories without
   coverage), RSS items, and external research discoveries, screened through
   `DuplicateDetectorService` (threshold from settings, default 85%).
4. **Research** — Wikipedia + Hacker News + RSS + optional web search
   (`ResearchService`). If no sources are collected, the run fails without
   burning AI tokens.
5. **Keywords** — `KeywordService` derives focus/secondary keywords and search
   intent from real data. Volumes are never fabricated.
6. **Generation** — `BlogGeneratorService` writes the article with research
   extracts in the prompt, persists provenance to `blog_research_sources`,
   validates the result (`QualityValidatorService` → `quality_reports`) and
   auto-publishes **only** when auto-publish is enabled and validation passes.
7. **Featured image** — `ImageService` per `featured_image_mode`; failures never block.
8. **Finalize** — run marked completed with published flag; audit log written.

## Configuration (Admin → Automation)

| Setting | Default | Notes |
|---|---|---|
| posts_per_day | 3 | Max 4 |
| publish_slots | 06:00, 12:00, 18:00, 21:00 | max 4 slots |
| min/max word count | 900 / 2000 | enforced by quality validation |
| auto_publish | **off** | publish only after passing every mandatory check |
| research_enabled | on | missing mandatory research blocks publishing |
| duplicate_threshold | 85% | near-duplicate screening |
| featured_image_mode | branded | none / branded / external_api |
| max_concurrent_generations | 1 | all AI calls already run sequentially |

## Recovery and idempotency

- Every attempt creates a `blog_pipeline_runs` row; steps are checkpointed
  (`completed_steps`). A crashed run leaves a stale `running` row; the next
  scheduler tick starts a fresh attempt for the same slot (attempts + 1).
- A slot is fulfilled only by a `completed` run — failed attempts retry up to
  3 times per slot per day, then the slot is skipped (logged + audited).
- One invocation does one bounded attempt; `schedule:run`-driven cron does the rest.
- The shared cache lock (`ai:generation`) guarantees a single concurrent LLM request.

## Legacy compatibility

`blog:generate-daily` still works (manual or cron) and honors
`preferred_time`; the scheduler now runs `blog:auto-pipeline` every 5 minutes
instead. Manual generation from Admin → AI Provider is unchanged.
