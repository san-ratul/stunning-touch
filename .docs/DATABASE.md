# Database

MySQL 8, `utf8mb4_unicode_ci`. All tables created by Laravel migrations (`database/migrations/2026_01_01_*`). Models in `app/Models/`.

## Tables

### users (admin accounts only — no public registration)
`id, name, email (unique), email_verified_at, password, is_active, last_login_at, remember_token, timestamps`

### categories
`id, name, slug (unique), short_description(500), description(text), icon(100), display_order, is_active, timestamps`
- `tools` hasMany (ordered by display_order, name)

### tools
`id, category_id FK→categories, name, slug (unique), short_description(500), description(longtext HTML), icon, component_key(100, indexed — NOT unique by design), is_active, is_featured, is_archived, display_order, how_to_title, how_to_steps(json), faq(json [{q,a}]), timestamps`
- `seo` morphOne → seo_meta; `usages` hasMany → tool_usage

### seo_meta (polymorphic)
`id, seoable_type, seoable_id (unique pair), meta_title, meta_description, focus_keyword, secondary_keywords, canonical_url, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, robots_index, robots_follow, schema_type, schema_data(json), timestamps`
- Used by tools, categories, blog categories, static pages (blog posts use dedicated columns).

### visitors
`id, visitor_hash(64, unique — daily-rotated SHA-256 of IP+UA+salt), first_seen_at, last_seen_at, created_at`

### page_views
`id, visitor_id FK→visitors, page_url(500), page_type(50, indexed), referrer(500), referrer_domain(255, indexed), device_type(20, indexed), browser(50, indexed), country_code(5, indexed), visited_at (indexed)`

### tool_usage (indexed tool_id+created_at, created_at)
`id, tool_id FK→tools, visitor_id FK nullable, event_type(50: run/download/error), success(bool), created_at`

### tool_usage_daily (unique tool_id+date)
`id, tool_id FK, date, total, success, failed, unique_visitors`

### analytics_daily (unique date)
`id, date, visitors, page_views, tool_usage, tool_success, tool_failed`

### ad_placements (legacy — system retired, tables and rows preserved)
`id, key (unique: homepage_top, homepage_content, homepage_bottom, category_top, tool_before_content, tool_after_content, tool_sidebar, tool_bottom, blog_content, blog_sidebar, blog_footer), name, description, is_active, timestamps`

### advertisements (legacy — system retired, tables and rows preserved)
`id, ad_placement_id FK, name, provider(100), ad_type(code|banner), ad_code(longtext), image_path, destination_url, image_alt, width, height, target_pages(json), target_tools(json), device_targeting(all|desktop|mobile|tablet), starts_at, ends_at, priority, is_active, impressions, clicks, timestamps`

### site_settings — includes the global ad settings
`advertisement_enabled ('1'|'0')` and `advertisement_script (raw admin snippet)` keys; see ADVERTISEMENT_MANAGEMENT.md

### blog_categories / blog_tags
`id, name, slug (unique), description, is_active, timestamps` / `id, name, slug (unique), timestamps`

### blog_posts (indexed status+published_at, status, source)
`id, blog_category_id FK nullable, user_id FK nullable, title, slug (unique), excerpt, content (HTML), featured_image, featured_image_alt, focus_keyword, secondary_keywords, seo_title, meta_description, canonical_url, og_title, og_description, og_image, twitter_title, twitter_description, robots_index, robots_follow, reading_time, views, status (draft|scheduled|published|archived), source (manual|ai), scheduled_at, published_at, timestamps`

### blog_post_tag
`blog_post_id FK + blog_tag_id FK (composite PK)`

### media
`id, user_id FK nullable, file_name, file_path, disk, mime_type, size, alt_text, timestamps`

### contact_messages
`id, name, email, subject, message, is_read, timestamps`

### ai_settings (single row)
`id, provider, endpoint, api_key (encrypted cast), model, temperature, max_tokens, is_active, timestamps`

### ai_generations
`id, topic, status (pending|completed|failed), error_message, blog_post_id FK nullable, prompt_tokens, completion_tokens, timestamps`

### automation_settings (single row)
`id, is_enabled, frequency (daily|weekly), preferred_time (H:i), timezone, categories(json), topics(json), keywords, auto_publish, last_run_at, timestamps`

### site_settings (key/value)
`id, key (unique), value (text), timestamps` — cached forever, flushed on write.

### static_pages
`id, title, slug (unique), content (HTML), is_active, timestamps`

### audit_logs (indexed action, entity pair, created_at)
`id, user_id FK nullable, action, entity_type, entity_id, description, properties(json), ip_address(45), user_agent(500), created_at`

### Laravel framework tables
`cache, cache_locks, jobs, job_batches, failed_jobs, sessions, password_reset_tokens` (sessions use the database driver; no Redis required).

## Design decisions
- **component_key indexed, not unique:** multiple public tools may legitimately share one registered functional component.
- **Polymorphic SEO vs post columns:** blog posts keep SEO inline (single-table simplicity); other entities use `seo_meta` via the `HasSeoMeta` trait.
- **No raw IPs anywhere** — visitors are hashed daily; hashed values rotate so history cannot be re-identified.
- **Aggregates** let dashboards serve long ranges cheaply while raw rows are pruned by retention (default 90 days, `analytics:aggregate --cleanup`).
