# Tools Catalog

All 12 tools process data **entirely in the browser**. Nothing is uploaded to any server. Each tool records a usage event only after real processing (success or failure).

| # | Tool | URL | component_key | Libraries |
|---|---|---|---|---|
| 1 | Image Compressor | `/image-compressor` | `image-compressor` | Canvas API |
| 2 | Image Resizer | `/image-resizer` | `image-resizer` | Canvas API |
| 3 | JPG to PNG | `/jpg-to-png` | `jpg-to-png` | Canvas API |
| 4 | PNG to WebP | `/png-to-webp` | `png-to-webp` | Canvas API |
| 5 | Merge PDF | `/merge-pdf` | `merge-pdf` | pdf-lib (lazy chunk) |
| 6 | Split PDF | `/split-pdf` | `split-pdf` | pdf-lib (lazy chunk) |
| 7 | Word Counter | `/word-counter` | `word-counter` | Vanilla JS |
| 8 | Case Converter | `/case-converter` | `case-converter` | Vanilla JS |
| 9 | JSON Formatter | `/json-formatter` | `json-formatter` | Vanilla JS |
| 10 | Base64 Encoder/Decoder | `/base64-encoder-decoder` | `base64-encoder-decoder` | TextEncoder/Decoder |
| 11 | QR Code Generator | `/qr-code-generator` | `qr-code-generator` | qrcode npm |
| 12 | UUID Generator | `/uuid-generator` | `uuid-generator` | `crypto.randomUUID` |

## Feature details

1. **Image Compressor** — drag-drop or picker (JPG/PNG/WebP, ≤15 MB), quality slider (10–95%), output format JPEG/WebP/PNG, original vs new size, savings %, preview, download.
2. **Image Resizer** — exact width/height, aspect-ratio lock, percentage scaling (1–400%), presets (HD, Full HD, square 1080, story 1080×1920), format choice, input validation (1–12000 px). Integrated interactive crop editor (`crop-core.js` + `crop-editor.js`): draggable/resizable crop box with 8 handles (pointer + keyboard + touch), Keep Aspect Ratio toggle, nine position presets with custom-position detection, reset, live crop preview; the crop region is exported at the requested output size (upscaled when the request exceeds the image bounds).
3. **JPG to PNG** — real canvas conversion, white background under transparency, preview + size comparison, download.
4. **PNG to WebP** — quality slider with live re-encode, resulting size + savings, graceful error if the browser cannot export WebP.
5. **Merge PDF** — multiple PDFs (≤40 MB each), list with reorder (up/down), remove, merged page count, single download, `ignoreEncryption` handling, producer stamp.
6. **Split PDF** — reads page count first, page-range mode (From–To) or custom pages (`1, 3, 5-8`), full input validation against page count, extracted-pages download.
7. **Word Counter** — words, characters, characters without spaces, sentences, paragraphs, lines, longest word, reading time (200 wpm); live updates, copy/clear.
8. **Case Converter** — UPPERCASE, lowercase, Title Case, Sentence case, Capitalized Words, iNVERSE cASE; copy result.
9. **JSON Formatter** — Format (2-space pretty print), Minify, Validate with precise error messages, key/node count + max depth stats, copy, clear.
10. **Base64** — UTF-8 safe encode/decode (emojis, Bangla, CJK), URL-safe output toggle (RFC 4648 §5), clear validation errors.
11. **QR Code Generator** — text/URL input, auto-regeneration (debounced), size 128–1024, quiet-zone margin, error correction L/M/Q/H, foreground/background colors, PNG + SVG download.
12. **UUID Generator** — bulk 1–100 UUID v4 via `crypto.randomUUID` (RFC 4122 fallback), uppercase / no-dashes options, copy individual, copy all.

## Adding a new functional tool

1. Create `resources/js/tools/my-tool.js` exporting `init(el)` (see `helpers.js` for shared utilities).
2. Register the key in `app/Support/ToolRegistry.php` (`view`, `js`, `accept`).
3. Create the Blade view `resources/views/tools/my-tool.blade.php` extending `layouts.tool`; mount element: `<div data-tool="my-tool">…</div>`.
4. Add the tool row in Admin → Tools with `component_key = my-tool` and give it a category.

Only registry keys render a functional UI — arbitrary values from the database cannot execute code.
