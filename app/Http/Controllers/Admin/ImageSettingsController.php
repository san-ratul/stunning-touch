<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AutomationSetting;
use App\Models\ImageSetting;
use App\Services\Ai\CloudflareImageProvider;
use App\Services\Ai\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImageSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:none,openai-images,cloudflare'],
            'endpoint' => ['nullable', 'url', 'max:500', 'required_if:provider,openai-images'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'account_id' => ['nullable', 'string', 'max:100', 'required_if:provider,cloudflare', 'regex:/^[a-f0-9]{32}$/i'],
            'model' => ['nullable', 'string', 'max:100', 'required_if:provider,cloudflare', 'starts_with:@cf/', 'regex:/^[a-z0-9@\/._\-]+$/i'],
            'steps' => ['nullable', 'integer', 'min:1', 'max:8'],
            'width' => ['nullable', 'integer', 'min:256', 'max:2048'],
            'height' => ['nullable', 'integer', 'min:256', 'max:2048'],
            'daily_limit' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:600'],
            'size' => ['nullable', 'in:1024x1024,1792x1024,1024x1792'],
        ]);

        $data['size'] ??= '1792x1024';
        $data['steps'] ??= 4;
        $data['width'] ??= 1024;
        $data['height'] ??= 1024;
        $data['daily_limit'] ??= 4;
        $data['max_retries'] ??= 2;
        $data['timeout_seconds'] ??= 120;

        if ($data['provider'] === 'cloudflare') {
            // The URL is always constructed server-side from the saved
            // Account ID and a validated model ID — arbitrary endpoints are
            // never accepted from browser input.

            // Never silently overwrite a stored token with an empty value,
            // and never save a Cloudflare provider without any token.
            if (blank($data['api_key'] ?? null) && blank(ImageSetting::current()->api_key)) {
                throw ValidationException::withMessages([
                    'api_key' => 'A Cloudflare API token is required. Enter the token, or switch the provider back to "None" / local covers.',
                ]);
            }
        }

        // Only overwrite the stored key when a new one was submitted.
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $settings = ImageSetting::current();
        $settings->update($data);

        // Keep the featured-image mode in sync with the saved provider:
        // selecting Cloudflare (or an OpenAI-images endpoint) here IS the
        // featured-image selection — without this sync the credentials are
        // saved while generation keeps using the local branded cover.
        $modeForProvider = [
            'cloudflare' => 'cloudflare',
            'openai-images' => 'external_api',
        ];

        $syncedMode = $modeForProvider[$data['provider']] ?? null;
        if ($syncedMode !== null && AutomationSetting::current()->featured_image_mode !== $syncedMode) {
            AutomationSetting::current()->update(['featured_image_mode' => $syncedMode]);
        }

        AuditLog::record('image_settings.updated', null, 'Updated featured image settings');

        $message = $syncedMode !== null
            ? 'Featured image settings saved. The featured image mode was switched to "'.$syncedMode.'" to match the selected provider. The API token is stored encrypted.'
            : 'Featured image settings saved. The API token is stored encrypted.';

        return redirect()->route('admin.automation.edit')->with('success', $message);
    }

    public function testConnection(CloudflareImageProvider $provider): RedirectResponse
    {
        $result = $provider->testConnection();

        return redirect()->route('admin.automation.edit')->with(
            $result['ok'] ? 'success' : 'error',
            $result['message']
        );
    }

    public function testGeneration(CloudflareImageProvider $provider, ImageService $images): RedirectResponse
    {
        $outcome = $provider->generate('Minimal clean abstract illustration for testing the image pipeline, flat vector style, no text.');

        if (! $outcome['ok']) {
            return redirect()->route('admin.automation.edit')->with('error', 'Test image generation failed: '.$outcome['message']);
        }

        try {
            $media = $images->storeTestImage($images->cropToFeatured($outcome['bytes']), 'image/png', 'Cloudflare image generation test');
        } catch (\Throwable $e) {
            return redirect()->route('admin.automation.edit')->with('error', 'Test image generated but storing it failed: '.substr($e->getMessage(), 0, 160));
        }

        AuditLog::record('image_settings.test_generated', $media, 'Generated a Cloudflare test image: '.$media->file_name);

        return redirect()->route('admin.automation.edit')->with(
            'success',
            'Test image generated and stored in the Media Library: '.$media->file_name
        );
    }
}
