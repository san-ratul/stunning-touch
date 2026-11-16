# Security

## AI endpoint exposure

- Ollama must never be exposed unauthenticated. Bind it to `127.0.0.1` and,
  when the Laravel app runs remotely, front it with an authenticating HTTPS
  reverse proxy (Caddy/Nginx with Basic auth, or a bearer token proxy).
- The Laravel client supports `auth_mode`: `none` (private network),
  `bearer`, and `basic`. Credentials are stored encrypted in the database and
  are never rendered back to the browser or logged.
- Oracle Cloud: use firewall/security-list rules to allow the proxy port only
  from the app's IP; keep 11434 closed.

## Credentials handling

- API keys (`ai_settings.api_key`, `basic_auth_password`, `research_settings.api_key`,
  `image_settings.api_key`) use Laravel's `encrypted` cast (APP_KEY).
- All admin views show only "stored — leave blank to keep" placeholders.
- `AiSetting`, `ResearchSetting`, `ImageSetting` hide credential attributes
  from serialization, so they never reach JSON responses.
- `.env` secrets (`SCHEDULER_SECRET`, `APP_KEY`) stay out of source control.

## Application hardening (existing)

- Admin routes behind `auth` + `EnsureAdmin`; CSRF on all POST/PUT/DELETE.
- Scheduler web endpoint is secret-protected and throttled.
- Security headers middleware enabled; error pages do not leak internals.
- AI research data is only fetched from configured sources; no arbitrary URL
  fetching of user input.

## Publishing safety

- Auto-publish is off by default; a failed mandatory validation, missing
  research, or duplicate verdict always keeps the article as a draft.
- Every AI article is `source=ai` with generation provenance and research
  URLs + timestamps retained.
