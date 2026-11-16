# Monitoring

Admin → **AI Monitoring** (`/admin/monitoring`, nav: Insights → AI Monitoring).

## Tracked metrics (all computed from real records)

- Total / successful / failed generations
- Prompt + completion token totals (normalized across OpenAI/Ollama formats)
- Average generation duration (pipeline `started_at → finished_at`)
- Drafts awaiting review
- Auto-published vs manually published posts (audit-log user attribution)
- Research sources per article
- Validation failures, pipeline failures, retries used
- Daily target progress (`posts_per_day`)

## Provider health

Cached 5-minute **Check Model Availability** probe (never exposes
credentials). "Re-check" button refreshes on demand. Green = configured model
found on the provider, red = unreachable/unconfigured/missing.

## History views

- Pipeline execution history: slot, attempt count, topic source, status, errors, post link
- Research history: topic, status (completed/partial/failed), source counts
- Recent generations: status, token usage, post link

## Dashboard

The admin dashboard shows an "AI Blogging — Today" widget: published vs
target progress, completed/failed runs today, drafts awaiting review.

## History retention

`blog:cleanup-history` (weekly, or manual) prunes generations, quality
reports, research runs, and pipeline runs older than the retention window
(site setting `history_retention_days`, default 90, override with `--days`).
**Published posts and their full provenance are never pruned.**
