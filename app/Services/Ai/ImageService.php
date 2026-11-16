<?php

namespace App\Services\Ai;

use App\Models\BlogPost;
use App\Models\ImageSetting;
use App\Models\Media;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Provider-independent featured-image handling.
 *
 * Modes:
 * - none: no featured image.
 * - branded: locally generated branded cover (GD, no external calls).
 * - cloudflare: Cloudflare Workers AI (FLUX.1 [schnell]) with automatic
 *   fallback to the local branded cover on failure or exhausted quota.
 * - external_api: a configurable OpenAI-images-compatible generation API.
 *
 * Guarantees:
 * - One image per article unless regeneration is explicitly forced.
 * - Image failures never invalidate a generated article.
 * - Storage failures degrade to null instead of breaking generation flows.
 * - Credentials are decrypted from settings and never logged or rendered.
 */
class ImageService
{
    public const MODE_NONE = 'none';

    public const MODE_BRANDED = 'branded';

    public const MODE_CLOUDFLARE = 'cloudflare';

    public const MODE_EXTERNAL_API = 'external_api';

    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * Generate and attach a featured image for the post in the given mode.
     * Returns the created Media record, or null when the mode is none, the
     * article already has an image, or generation failed (images never
     * block publishing).
     */
    public function forPost(BlogPost $post, string $mode, bool $force = false): ?Media
    {
        return $this->forPostWithReport($post, $mode, $force)['media'];
    }

    /**
     * Same flow as forPost() but returns a caller-friendly report (used by
     * the manual generation UI to show image status and sanitized warnings).
     *
     * Forced regeneration of an article that already has an image never
     * replaces it with a fallback: if the new generation fails, the
     * previous featured image is kept untouched and the failure is
     * reported honestly.
     *
     * @return array{media: ?Media, provider: ?string, message: ?string}
     */
    public function forPostWithReport(BlogPost $post, string $mode, bool $force = false): array
    {
        $hasImage = filled($post->featured_image);

        if ($hasImage && ! $force) {
            return [
                'media' => null,
                'provider' => null,
                'message' => 'The article already has a featured image; regeneration was not requested.',
            ];
        }

        $attempt = match ($mode) {
            self::MODE_BRANDED => $this->viaBranded($post),
            self::MODE_CLOUDFLARE => $this->viaCloudflare($post, fallbackToBranded: ! $hasImage),
            self::MODE_EXTERNAL_API => $this->viaExternal($post),
            default => ['media' => null, 'provider' => null, 'message' => null],
        };

        if ($attempt['media'] !== null) {
            $post->update([
                'featured_image' => $attempt['media']->file_path,
                'featured_image_alt' => $post->featured_image_alt ?: Str::limit($post->title, 500),
            ]);
        } elseif ($hasImage) {
            $attempt['message'] = trim((string) $attempt['message'].' The previous featured image was kept.');
        }

        return $attempt;
    }

    /**
     * Cloudflare Workers AI with the documented fallback policy:
     * unconfigured / quota exhausted / API failure → local branded cover.
     * A storage or generation failure never removes the article. When
     * $fallbackToBranded is false (forced regeneration of an article that
     * already has an image), failures are reported without generating a
     * replacement so the existing image is preserved.
     */
    protected function viaCloudflare(BlogPost $post, bool $fallbackToBranded = true): array
    {
        $provider = new CloudflareImageProvider;
        $settings = ImageSetting::current();

        if (! $provider->isConfigured($settings)) {
            if (! $fallbackToBranded) {
                return [
                    'media' => null,
                    'provider' => null,
                    'message' => 'Cloudflare image generation is not configured.',
                ];
            }

            $media = $this->viaBranded($post)['media'];

            return [
                'media' => $media,
                'provider' => $media !== null ? 'local' : null,
                'message' => 'Cloudflare image generation is not configured; used the local branded cover instead.',
            ];
        }

        $prompt = $this->buildPrompt($post);
        $outcome = $provider->generate($prompt, $post);

        if ($outcome['ok']) {
            try {
                $media = $this->storeMedia($post, $this->cropToFeatured($outcome['bytes']), 'image/png');

                return ['media' => $media, 'provider' => 'cloudflare', 'message' => null];
            } catch (\Throwable $e) {
                Log::warning('Cloudflare image storage failed: '.Str::limit($e->getMessage(), 160));

                if (! $fallbackToBranded) {
                    return [
                        'media' => null,
                        'provider' => null,
                        'message' => 'The generated image could not be stored.',
                    ];
                }

                $media = $this->viaBranded($post)['media'];

                return [
                    'media' => $media,
                    'provider' => $media !== null ? 'local' : null,
                    'message' => 'The generated image could not be stored; used the local branded cover instead.',
                ];
            }
        }

        // Failed or quota exhausted. During normal generation fall back to
        // the local branded cover; during forced regeneration keep the
        // previous image and report the failure instead.
        if (! $fallbackToBranded) {
            return [
                'media' => null,
                'provider' => null,
                'message' => 'Cloudflare image generation failed: '.$outcome['message'],
            ];
        }

        $media = $this->viaBranded($post)['media'];

        return [
            'media' => $media,
            'provider' => $media !== null ? 'local' : null,
            'message' => 'Cloudflare image generation failed: '.$outcome['message'].($media !== null ? ' Used the local branded cover.' : ' The article is unaffected; no image was attached.'),
        ];
    }

    protected function viaBranded(BlogPost $post): array
    {
        try {
            $media = $this->generateBrandedCover($post);
        } catch (\Throwable $e) {
            Log::warning('Branded cover failed: '.Str::limit($e->getMessage(), 160));
            $media = null;
        }

        return [
            'media' => $media,
            'provider' => $media !== null ? 'local' : null,
            'message' => $media === null ? 'The local branded cover could not be generated.' : null,
        ];
    }

    protected function viaExternal(BlogPost $post): array
    {
        try {
            $media = $this->generateExternalCover($post);
        } catch (\Throwable $e) {
            Log::warning('External featured image failed: '.Str::limit($e->getMessage(), 160));
            $media = null;
        }

        return [
            'media' => $media,
            'provider' => $media !== null ? 'external_api' : null,
            'message' => $media === null ? 'The external image API returned no usable image.' : null,
        ];
    }

    /**
     * Article-specific, professional editorial prompt.
     *
     * The visual concept is derived deterministically from the article
     * title, focus keyword and content signals (merge/split/convert/
     * compress/...), so every topic gets its own composition instead of a
     * repeated generic laptop-and-icons scene. Shared composition rules
     * keep the focal subject centered and fully framed with generous
     * margins, which also keeps the final center-crop to 1200×630 safe.
     *
     * The article theme is paraphrased WITHOUT format acronyms (PDF, JPG,
     * PNG, ...): a real generation showed FLUX.1 Schnell paints partial
     * letterforms ("PF", "IG") when text-like tokens appear in the prompt.
     * Text is discouraged positively ("placeholder bars") because listing
     * negative words can itself induce text in diffusion models.
     */
    public function buildPrompt(BlogPost $post): string
    {
        $theme = $this->articleTheme($post);
        $concept = $this->visualConcept($post);

        $prompt = 'Professional editorial illustration for a technology blog article about: '.Str::limit($theme, 120).'.';
        $prompt .= ' Visual concept: '.$concept.'.';
        $prompt .= ' Composition: one dominant central focal subject, complete and fully framed with generous margins, balanced negative space, intentional clean layout, soft consistent lighting with subtle realistic shadows, harmonious modern color palette.';
        $prompt .= ' Style: modern flat vector illustration, crisp shapes, polished professional finish, high quality.';
        $prompt .= ' Cards and documents show only soft gray placeholder bars instead of any writing — no typography, no lettering, no logos, no watermarks.';

        return $prompt;
    }

    /**
     * Deterministic title paraphrase with text-prone acronyms removed, so
     * the prompt stays article-specific without inviting rendered text.
     */
    protected function articleTheme(BlogPost $post): string
    {
        $theme = trim((string) $post->title);

        $theme = preg_replace('/\b(pdf|jpe?g|png|webp|svg|gif|docx?|xlsx|pptx|url|uuid|qr|seo|api|http|html|css)\b/i', '', $theme) ?? $theme;
        $theme = trim((string) preg_replace('/\s+/', ' ', $theme) ?? $theme);
        $theme = trim($theme, " \t-:,.");

        if ($theme === '') {
            $theme = trim((string) $post->focus_keyword);
        }

        return $theme !== '' ? $theme : 'digital documents and online tools';
    }

    /**
     * Deterministic concept selection: the first matching topic rule wins,
     * so the same article always yields the same concept and different
     * topics yield different compositions.
     */
    protected function visualConcept(BlogPost $post): string
    {
        $haystack = Str::lower($post->title.' '.(string) $post->focus_keyword.' '.Str::limit(trim(strip_tags((string) $post->content)), 3000));

        $rules = [
            '/\b(merges?|merging|combine[sd]?|combining|joins?|joining)\b/' => 'several complete document sheets arranged in a neat row, flowing together into one single organized document',
            '/\b(splits?|splitting|divide[sd]?|dividing|extracts?|extracting)\b/' => 'one complete document dividing cleanly into several neatly ordered document sheets',
            '/\b(converts?|converting|converter|transform[sd]?|transforming|transition)\b/' => 'a complete document transforming into a different complete document, side by side with a clear directional arrow between them',
            '/\b(compress(?:es|ing|ed)?|optimiz(?:es|ing|ed)|reduc(?:es|ing)|shrink(?:s|ing)?)\b/' => 'a bulky stack of image files condensing into one small, neat, compact stack',
            '/\b(resiz(?:es|ing|ed)|rescal(?:es|ing|ed))\b/' => 'a picture card being resized precisely inside a clean square frame with alignment guides',
            '/\bqr\b/' => 'a single QR code card centered on a minimal clean desk surface',
            '/\b(format(?:s|ting|ted)|json|base64|encod(?:es|ing|ed)|decod(?:es|ing|ed))\b/' => 'a single clean data card transforming into a neatly structured, well organized layout',
            '/\b(uuid|random generator)\b/' => 'one small identity card centered above a minimal modern workstation',
            '/\b(uppercase|lowercase|capitaliz(?:es|ing|ed)?)\b/' => 'two neat document cards with different visual weight, arranged as a clean before-and-after transformation',
            '/\bpdf\b/' => 'complete PDF document sheets with folded corners',
            '/\b(images?|photos?|jpe?g|png|webp)\b/' => 'two complete picture cards, one large and one small, neatly aligned on a clean surface',
            '/\b(words?|docx|editable|writing|editor)\b/' => 'two clearly distinct complete documents side by side, one fixed and one editable',
        ];

        foreach ($rules as $pattern => $concept) {
            if (preg_match($pattern, $haystack) === 1) {
                return $concept;
            }
        }

        $focus = trim((string) $post->focus_keyword);

        if ($focus !== '') {
            return 'a clean minimal composition built around the theme of '.Str::limit($focus, 60).', with one clear focal object in a modern workspace';
        }

        return 'a clean modern digital workspace with one clear focal document or tool, centered and fully visible';
    }

    /**
     * Center cover-crop to the featured-image ratio (1200×630) with GD.
     * Never distorts; falls back to the original bytes when GD is missing.
     */
    public function cropToFeatured(?string $bytes): string
    {
        if ($bytes === null || $bytes === '' || ! function_exists('imagecreatefromstring')) {
            return (string) $bytes;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return $bytes;
        }

        $srcWidth = (int) imagesx($source);
        $srcHeight = (int) imagesy($source);
        $targetRatio = self::WIDTH / self::HEIGHT;
        $sourceRatio = $srcWidth / max(1, $srcHeight);

        if ($sourceRatio > $targetRatio) {
            $cropWidth = (int) round($srcHeight * $targetRatio);
            $cropHeight = $srcHeight;
            $offsetX = (int) round(($srcWidth - $cropWidth) / 2);
            $offsetY = 0;
        } else {
            $cropWidth = $srcWidth;
            $cropHeight = (int) round($srcWidth / $targetRatio);
            $offsetX = 0;
            $offsetY = (int) round(($srcHeight - $cropHeight) / 2);
        }

        $target = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($target === false) {
            imagedestroy($source);

            return $bytes;
        }

        imagecopyresampled($target, $source, 0, 0, $offsetX, $offsetY, self::WIDTH, self::HEIGHT, $cropWidth, $cropHeight);
        imagedestroy($source);

        ob_start();
        imagepng($target, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($target);

        return $png !== '' ? $png : $bytes;
    }

    /**
     * Deterministic branded cover: gradient derived from the title hash,
     * site name and wrapped article title. No fonts or network required.
     */
    public function generateBrandedCover(BlogPost $post): ?Media
    {
        if (! function_exists('imagecreatetruecolor')) {
            Log::warning('Featured image skipped: PHP GD extension is not available.');

            return null;
        }

        $baseWidth = 600;
        $baseHeight = 315;

        $image = imagecreatetruecolor($baseWidth, $baseHeight);
        if ($image === false) {
            return null;
        }

        // Deterministic palette from the title so the same article always
        // produces the same cover.
        $hash = md5('stunning-touch:'.$post->title);
        [$r1, $g1, $b1] = $this->hexToRgb(substr($hash, 0, 6));
        [$r2, $g2, $b2] = $this->hexToRgb(substr($hash, 6, 6));

        for ($x = 0; $x < $baseWidth; $x++) {
            $t = $x / max(1, $baseWidth - 1);
            $color = imagecolorallocate(
                $image,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t)
            );
            imageline($image, $x, 0, $x, $baseHeight, $color);
        }

        // Translucent dark panel behind the text.
        $panel = imagecolorallocatealpha($image, 10, 16, 30, 60);
        imagefilledrectangle($image, 24, 70, $baseWidth - 24, $baseHeight - 60, $panel);

        // Accent bar.
        $accent = imagecolorallocate($image, 255, 214, 90);
        imagefilledrectangle($image, 24, 60, $baseWidth - 24, 66, $accent);

        $white = imagecolorallocate($image, 255, 255, 255);
        $softWhite = imagecolorallocate($image, 226, 232, 240);

        $fontName = (string) (SiteSetting::query()->where('key', 'site_name')->value('value') ?: config('app.name', 'Stunning Touch'));

        imagestring($image, 4, 32, 26, strtoupper(Str::limit($fontName, 38)), $accent);

        $font = 5;
        $charWidth = imagefontwidth($font);
        $charHeight = imagefontheight($font);
        $maxChars = (int) floor(($baseWidth - 72) / $charWidth);
        $lines = $this->wrapText($post->title, $maxChars, 4);

        $y = 88;
        foreach ($lines as $line) {
            imagestring($image, $font, 36, $y, $line, $white);
            $y += $charHeight + 6;
        }

        imagestring($image, 3, 36, $baseHeight - 52, Str::limit('Read the full guide at '.$fontName, 70), $softWhite);

        $scaled = imagescale($image, self::WIDTH, self::HEIGHT, IMG_BICUBIC);
        if ($scaled !== false) {
            imagedestroy($image);
            $image = $scaled;
        }

        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $this->storeMedia($post, $bytes, 'image/png');
    }

    /**
     * Generate a cover via a configurable OpenAI-images-compatible API.
     * Requires provider/endpoint/key settings; failures are logged and
     * never block publishing.
     */
    public function generateExternalCover(BlogPost $post): ?Media
    {
        $settings = ImageSetting::current();

        if (($settings->provider ?? 'none') !== 'openai-images' || blank($settings->endpoint) || blank($settings->api_key)) {
            Log::warning('External featured image skipped: image API is not configured.');

            return null;
        }

        $prompt = 'Clean, professional blog cover illustration for an article titled "'.Str::limit($post->title, 180).'". Flat vector style, no text in the image.';

        try {
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->withToken((string) $settings->api_key)
                ->post(rtrim((string) $settings->endpoint, '/').'/images/generations', [
                    'model' => $settings->model ?: 'dall-e-3',
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => $settings->size ?: '1792x1024',
                    'response_format' => 'b64_json',
                ]);

            if ($response->failed()) {
                throw new \RuntimeException('Image API error '.$response->status().': '.Str::limit($response->body(), 200));
            }

            $b64 = $response->json('data.0.b64_json');

            if (! filled($b64)) {
                throw new \RuntimeException('Image API returned no image data.');
            }

            $bytes = base64_decode((string) $b64, true);

            if ($bytes === false || strlen($bytes) < 64) {
                throw new \RuntimeException('Image API returned invalid image data.');
            }

            return $this->storeMedia($post, $bytes, 'image/png');
        } catch (\Throwable $e) {
            Log::warning('External featured image generation failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Store a test image (no article association) for the admin
     * test-generation action. Returns the Media record.
     */
    public function storeTestImage(string $bytes, string $mimeType, string $altText): Media
    {
        $path = 'media/covers/test-'.substr(md5((string) now()->timestamp), 0, 10).'.png';

        Storage::disk('public')->put($path, $bytes);

        return Media::query()->create([
            'user_id' => null,
            'file_name' => basename($path),
            'file_path' => $path,
            'disk' => 'public',
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'alt_text' => Str::limit($altText, 500),
        ]);
    }

    protected function storeMedia(BlogPost $post, string $bytes, string $mimeType): Media
    {
        $path = 'media/covers/'.Str::slug(Str::limit($post->slug ?: $post->title, 60)).'-'.substr(md5($post->id.(string) now()->timestamp), 0, 8).'.png';

        Storage::disk('public')->put($path, $bytes);

        return Media::query()->create([
            'user_id' => null,
            'file_name' => basename($path),
            'file_path' => $path,
            'disk' => 'public',
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'alt_text' => Str::limit($post->featured_image_alt ?: $post->title, 500),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function wrapText(string $text, int $maxChars, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) > $maxChars) {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = mb_substr($word, 0, $maxChars);

                if (count($lines) === $maxLines) {
                    break;
                }
            } else {
                $current = $candidate;
            }

            if (count($lines) === $maxLines) {
                break;
            }
        }

        if (count($lines) < $maxLines && $current !== '') {
            $lines[] = $current;
        }

        if (count($lines) === $maxLines) {
            $lines[$maxLines - 1] = Str::limit($lines[$maxLines - 1], $maxChars, '…');
        }

        return $lines;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected function hexToRgb(string $hex): array
    {
        $value = hexdec(substr($hex, 0, 6)) ?: 0x35507A;

        return [($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];
    }
}
