# Deployment Manifest — Image Resizer Crop Update (2026-11-17)

- **Commits:** `c346e1b` — feat: add interactive cropping to image resizer ·
  `3f0db25` — fix: fit crop editor and controls on one screen with side
  panel layout
- **Package:** `deployment/image-resizer-crop-update.zip` (402.42 KB, 41 entries)
- **SHA-256:** `d3bb5397d02369103d97a4d911dee9ca403d0747ff8f323cb8c0d26adf626fef`
- **Builder:** `deploy/build-image-resizer-crop-package.ps1` (rebuildable)
- **Migration:** none — the database is not touched.
- **Dependencies:** none added. Cropper.js was evaluated and rejected in
  favor of a purpose-built vanilla ES module (`crop-core.js` pure
  geometry + `crop-editor.js` DOM layer) to match the project's
  dependency-free tool architecture and keep the bundle tiny
  (crop-editor chunk: 3.69 kB / 1.59 kB gzip).
- **Cron:** none required. **Redis/queues/SSH:** not required.
- **Package contents:** root-relative paths, no wrapper directory;
  includes complete consistent `public/build/`, `MANIFEST.md`,
  `DEPLOYMENT_GUIDE.md`, `.docs/TOOLS_CATALOG.md` and the two new test
  files. Excludes `.env`, `.git`, `vendor/`, `node_modules/`.
- **After upload:** run `optimize:clear` through the Admin → Artisan
  Runner; no other server steps.
- **Verification:** full test suite 522 passed (1890 assertions), JS
  tests 88 passed (63 existing + 25 new crop-core tests), Laravel Pint
  passed, Vite build succeeded, ZIP round-trip hash-verified
  (independent extraction + per-file SHA-256 comparison).
- **Layout:** on desktop (≥1024px) the crop editor sits on the left with
  all controls in a 300px side panel (inputs, presets, format, ratio
  toggle, position grid, small live preview, reset, apply) so everything
  fits on one screen; the editor image is capped at min(400px, 52vh).
  On mobile the panel stacks below the editor as before.

## Behavior notes for the live site

- The width/height inputs remain the source of truth and drive the crop
  box; dragging/resizing the box writes its dimensions back (1:1
  export). Requests larger than the image upscale the selected region.
- Keep Aspect Ratio captures the current crop ratio when enabled.
- Nine position presets reposition without resizing; manual moves show
  a custom state (no preset highlighted). "Reset crop" restores the
  full-image selection and original dimensions.
- Processing stays 100% local in the browser; upload validation
  (15 MB, JPG/PNG/WebP), output formats/quality, rate limits, analytics
  beacon and the `image-resizer` component key are unchanged.
- No other tool or shared component was modified (shared `image-tool.js`
  only gained an additive `cropTo()` export).
