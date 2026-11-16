# Research Provider Configuration

Research settings live in **Admin → Research Settings** (`research_settings`).
No credentials are hardcoded or rendered back to the browser.

## Sources

| Source | Requires config | Notes |
|---|---|---|
| Wikipedia REST API | No | `https://en.wikipedia.org/w/rest.php/v1/search/page` — URLs are built from API-returned page keys |
| Hacker News (Algolia) | No | `https://hn.algolia.com/api/v1/search` — stories without external URLs fall back to their HN item URL |
| RSS feeds | Feed URLs | Admin-curated; up to 10 feeds; RSS 2.0 `<item>` and Atom `<entry>` supported |
| Web search | Provider + API key | Optional; see below |

## Web search providers

| Provider | Key | Endpoint |
|---|---|---|
| `none` | — | No web search; Wikipedia/HN/RSS only |
| `tavily` | `TAVILY_API_KEY` (stored in DB, encrypted) | `POST https://api.tavily.com/search` |
| `serpapi` | `SERPAPI_KEY` (stored in DB, encrypted) | `GET https://serpapi.com/search.json?engine=google` |

Adding a new provider = one new method in `ResearchService::fromWebSearch()`.

## Trusted domains

When the whitelist is set (one domain per line or comma-separated), results
from Wikipedia/HN/web search whose host is not on the list (or a subdomain of
a listed domain) are discarded. RSS feeds are admin-curated and bypass the
filter. Empty list = accept all.

## Behavior guarantees

- Every stored URL/title comes from a real provider response — the system
  never fabricates URLs, titles, statistics, quotations, or search volumes.
- Fetch timestamps and source type are stored per source
  (`blog_research_sources.fetched_at`, `source_type`).
- Source failures are recorded on the research run (`status` becomes
  `partial`; all-failures → `failed`) and the article generation continues
  with whatever sources succeeded.
- When research is disabled or fails entirely, generation proceeds without
  research context but **auto-publish stays blocked** by the research
  requirement check (see Quality Validation).

## Testing integrations

All integrations are covered by `tests/Unit/ResearchServiceTest.php` using
`Http::fake()`. No external calls are made during tests.
