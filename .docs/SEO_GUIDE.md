# SEO Guide

## Principles
- Every public page is fully **server-rendered**; all metadata exists in the initial HTML.
- Exactly **one** `<title>` and one canonical per page (covered by tests).
- Layered fallbacks: entity SEO row → model fields → sensible defaults with brand suffix (`Page | Stunning Touch`).

## Where metadata is managed
Admin → **SEO Manager** lists every real page grouped by type:

| Type | Storage |
|---|---|
| Tools | `seo_meta` (morph) |
| Tool Categories | `seo_meta` (morph) |
| Blog Posts | dedicated columns on `blog_posts` (also editable in the post editor) |
| Blog Categories | `seo_meta` (morph) |
| Static Pages | `seo_meta` (morph) |

Editable fields per page: Meta title, Meta description (live length counters), Focus keyword, Secondary keywords, Canonical URL, OG title/description/image, Twitter title/description/image, robots index/follow, Schema type, Schema data (JSON).

## Automatic technical SEO
- **Sitemap** `/sitemap.xml`: homepage (1.0), tools (0.9), categories (0.8), blog posts (0.7), blog categories (0.5), static pages (0.4) — with lastmod where meaningful. Draft posts, inactive/archived tools and hidden pages are excluded.
- **robots.txt** `/robots.txt`: allows all, disallows `/admin`, `/api`, `/run-scheduler`, and points to the sitemap.
- **Canonical URLs**: entity canonical override → current URL.
- **Open Graph + Twitter cards** on every page with `og:type` (website/article), `og:site_name`, images when provided.
- **Structured data (JSON-LD)**:
  - `BreadcrumbList` on tools/categories/blog/static pages.
  - `Article` on blog posts (headline, dates, author, publisher, image).
  - Optional per-page schema type/data from the SEO manager (WebApplication on tools by default via seed).
- **Breadcrumbs**: visible nav + schema, on all nested pages.
- **Internal linking**: related tools on tool pages, related tools inside blog posts (keyword-matched), category cross-links in sidebars and footer, header search.
- **Accessibility/conventions**: semantic `h1` per page, heading hierarchy, `alt` attributes everywhere, `aria-label`s, descriptive link text.

## Best practices for admins
1. Keep meta titles ≤ 60 chars and descriptions ≤ 155 chars (the counters warn).
2. Write unique, specific descriptions per tool — never duplicate across pages.
3. Set `robots_index` off only for pages that must not rank (e.g. duplicates).
4. Upload OG images 1200×630 for good social previews.
5. After adding pages, they appear in the sitemap automatically — no manual work needed.
