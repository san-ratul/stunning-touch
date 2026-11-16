# Troubleshooting

## AI Provider

| Symptom | Diagnosis |
|---|---|
| "AI provider is not configured" | Admin → AI Provider: endpoint + model set, `is_enabled`, and either a key (bearer) or `auth_mode=none` |
| Connection test hangs | Long CPU generation; check the reverse proxy `proxy_read_timeout` ≥ 900 s and Ollama is running (`systemctl status ollama`) |
| "AI connection failed after N attempt(s)" | Endpoint unreachable/firewall; verify `curl https://ai.example.com/api/tags` from the app host |
| 429/5xx repeatedly | Provider overloaded; retries are automatic with exponential backoff — raise `max_retries` or reduce load |
| Model not found in availability check | `ollama pull qwen3.5:4b` on the AI node, or model name mismatch |
| Empty responses | Context window too small for the prompt — raise `context_length` only after measuring RAM (see QWEN_MODEL_CONFIGURATION.md) |

## Pipeline

| Symptom | Diagnosis |
|---|---|
| "No publishing slot is due…" from command | Check `publish_slots`, timezone, and daily limit in Admin → Automation |
| Runs keep failing with "Research requirement failed" | All sources unreachable (check network from the app host) or research disabled mid-run; configure a web-search provider or verify RSS URLs |
| "No eligible topic found" | All manual topics used/duplicated; add topics, RSS feeds, or let content gaps/research supply candidates |
| Slot never completes | Check `blog_pipeline_runs.error_message` (Admin → AI Monitoring); after 3 attempts/day a slot is skipped until tomorrow |
| Two generations never overlap | Expected: single shared `ai:generation` lock enforces sequential LLM usage |

## Publishing

| Symptom | Diagnosis |
|---|---|
| Draft not auto-published | Open the post → quality report violations on the latest `quality_reports` row (word count, metadata, links, duplicates, claims) |
| Scheduled post didn't publish | `blog:publish-scheduled` runs every minute via cron; verify cron is installed (`.docs/CRON_SETUP.md`) |
| Image missing | GD extension absent (branded) or image API misconfigured (external); images never block publishing |

## Research

| Symptom | Diagnosis |
|---|---|
| Run status "partial" | One or more sources failed; exact errors are on `research_runs.errors` (Research history in monitoring) |
| All sources fail from shared hosting | Some hosts block outbound requests to wikipedia/HN APIs — verify with a plain curl; configure the trusted domains + web provider instead |
| Web search does nothing | Provider set to `none`, or API key missing |

## Data cleanup

Old failed runs clogging monitoring → run `php artisan blog:cleanup-history --days=30`
(published posts and their provenance are never deleted).
