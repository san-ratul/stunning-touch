# Featured Image Generation

Modes are configured in **Admin → Automation** (`automation_settings.featured_image_mode`):

| Mode | Behavior |
|---|---|
| `none` | No featured image; admin can attach one manually from the Media Library |
| `branded` (default) | Locally generated branded cover — deterministic gradient + site name + wrapped title, stored in CMS media storage |
| `cloudflare` | Cloudflare Workers AI (FLUX.1 [schnell]) with automatic fallback to the local branded cover |
| `external_api` | Optional OpenAI-images-compatible API; disabled unless provider/endpoint/key are configured |

## Cloudflare Workers AI

Configure in **Admin → Automation → Image Settings** (`image_settings`):

| Field | Notes |
|---|---|
| Provider | `cloudflare` |
| Account ID | 32 hex chars (Cloudflare dashboard → right sidebar) |
| API token | Token with **Workers AI Edit** permission; stored encrypted, never rendered back |
| Model | `@cf/black-forest-labs/flux-1-schnell` (validated server-side; the request URL is constructed from Account ID + model — no arbitrary endpoints accepted) |
| Inference steps | 1–8 (FLUX.1 [schnell] max 8) |
| Daily limit | Successful generations per UTC day (default 4, free allocation permitting) |
| Retries / timeout | Transient 429/5xx/connection errors retried with exponential backoff |

Actions: **Test Cloudflare Connection** (token verify endpoint) and **Test Image Generation** (one real image, stored in the Media Library).

Behavior:

- The prompt is derived from the article title + focus keyword; no text, watermarks, or logos.
- FLUX.1 [schnell] generates its native size; the app center-crops to 1200×630 locally (GD) without distortion.
- Every attempt is recorded in `image_generation_usage` (success/failure, UTC quota day); only successes consume the daily limit.
- One image per article; regeneration requires the admin **Regenerate featured image** action on the post editor.
- Fallback policy: unconfigured / quota exhausted / API failure → local branded cover; both failing → article preserved, no image.
- Usage/quota shown in **AI Monitoring** (successful today, failures, remaining allowance, last success, recent errors). Cloudflare's actual remaining free allocation is account-dependent and is displayed as an estimate only.

Requires the PHP GD extension for cropping and local fallback. No external image downloads or scraping — licensing responsibility for generated images follows Cloudflare's terms.

## Branded covers

## Branded covers

- Rendered with PHP GD (1200×630, PNG) — no fonts, no network, no paid API.
- Palette is deterministic per title, so regenerating an article's cover always looks the same.
- Stored via the existing media storage (`Storage::disk('public')`, `media/covers/…`) and recorded in the `media` table.
- Text uses GD's built-in fonts (small but readable); admins can replace any cover with a custom upload — generated images never overwrite manually set ones unless the mode regenerates.

Requires the PHP GD extension (`php -m | grep gd`). Without GD the service logs a warning and skips images (publishing is never blocked by image failures).

## External image API

Configure in **Admin → Automation → External Image API** (`image_settings`):

- Provider `openai-images`: `POST {endpoint}/images/generations` with `model`, `prompt`, `size`, `response_format=b64_json`, bearer key.
- The prompt is derived from the article title and explicitly excludes embedded text.
- Failures (HTTP errors, invalid payloads) are logged and skipped — never fatal.
- The API key is stored encrypted and never returned to the browser.

## Licensing policy

The system never scrapes or downloads third-party images. Externally sourced images are only produced through the admin-configured API, whose licensing terms the admin is responsible for. Attribution can be stored in the media `alt_text`.
