# Advertisement Management

A **universal** ad system — not tied to any network. Works with Google AdSense, Monetag, Adsterra, PropellerAds, or plain custom HTML/banner ads.

## Concepts

**Placements** (fixed, seeded, cache-cleared individually):

| Key | Location |
|---|---|
| `homepage_top` | Above homepage hero |
| `homepage_content` | Mid-homepage |
| `homepage_bottom` | Below homepage content |
| `category_top` | Above category tool grid |
| `tool_before_content` | Above tool UI |
| `tool_after_content` | Below tool UI |
| `tool_sidebar` | Tool page sidebar (desktop) |
| `tool_bottom` | Below tool content/FAQ |
| `blog_content` | Blog index top |
| `blog_sidebar` | Blog sidebars (desktop) |
| `blog_footer` | Below article content |

**Advertisements** belong to a placement and support:
- Provider label (free text: adsense, monetag, adsterra, custom…)
- Type: **code** (network HTML/JS) or **banner** (uploaded image + destination URL + alt + dimensions)
- Page-type targeting (empty = all pages)
- Tool targeting (specific tools via component key; empty = all tools)
- Device targeting: all / desktop / mobile / tablet
- Scheduling: starts-at / ends-at (checked at render time)
- Priority: highest-priority active match wins
- Enable/disable toggle; deletion
- Counters: impressions (incremented on each public render) and clicks (internal banner redirect via `/ad-click/{ad}`); ad network stats remain in the network's own dashboard — never fabricated locally.

## Safety model
- Ad code is **trusted admin input**: only authenticated, active admins can create/edit it; all forms are CSRF-protected and validated.
- Ad code renders **only on public placements** — never inside admin screens. The admin preview route serves the ad behind `Content-Security-Policy: script-src 'none'` so browser-side preview cannot execute ad scripts in the admin origin.
- Rendering is wrapped so any failure (bad code, DB error) can never break page delivery — the slot simply renders empty.
- Audit log entries record every create/update/delete/toggle with the acting admin.

## Workflow
1. Admin → Advertisements → “+ Add Advertisement”.
2. Choose placement, provider, type; paste network code or upload a banner.
3. (Optional) target pages/tools/devices, set schedule and priority.
4. Save → placement cache flushes within 5 minutes worst case (immediately flushed on save).
5. Use “Preview” for a safe visual check; “Enable/Disable” to control delivery.

## Notes
- Multiple ads on one placement: the highest-priority currently-scheduled active ad renders.
- For AdSense, paste the full `<ins>` + `<script>` snippet into the code field on the desired placements.
- Impressions/clicks counts are local approximations for internal banners only — they do not replace network reporting.
