<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AuditLog;
use App\Services\Ai\AiProviderService;
use App\Services\Ai\BlogGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiSettingsController extends Controller
{
    public function edit(AiProviderService $ai): View
    {
        return view('admin.ai.settings', [
            'settings' => AiSetting::current() ?? new AiSetting(['provider' => 'openai-compatible']),
            'configurationState' => $ai->configurationState(),
            'generations' => AiGeneration::query()->latest()->limit(20)->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:100'],
            'endpoint' => ['nullable', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'auth_mode' => ['required', 'in:none,bearer,basic'],
            'basic_auth_user' => ['nullable', 'string', 'max:255', 'required_if:auth_mode,basic'],
            'basic_auth_password' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:100', 'max:32000'],
            'context_length' => ['nullable', 'integer', 'min:512', 'max:131072'],
            'timeout_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'connect_timeout_seconds' => ['nullable', 'integer', 'min:2', 'max:120'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'retry_backoff_ms' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'keep_alive' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['auth_mode'] ??= 'bearer';
        $data['timeout_seconds'] ??= 900;
        $data['connect_timeout_seconds'] ??= 15;
        $data['max_retries'] ??= 2;
        $data['retry_backoff_ms'] ??= 2000;
        $data['context_length'] ??= 16384;

        if ($data['auth_mode'] !== 'basic') {
            $data['basic_auth_user'] = null;
            unset($data['basic_auth_password']);
        } elseif (blank($data['basic_auth_password'] ?? null)) {
            // Only overwrite the stored basic-auth password when a new one was submitted.
            unset($data['basic_auth_password']);
        }

        $settings = AiSetting::current();

        // Never silently overwrite a stored key with an empty value, and
        // never allow a bearer provider to be saved without a key (submitted
        // now or already stored) — that combination produces the misleading
        // "not fully configured" warning later.
        if ($data['auth_mode'] === 'bearer'
            && blank($data['api_key'] ?? null)
            && blank(AiSetting::primary()?->api_key)) {
            throw ValidationException::withMessages([
                'api_key' => 'A bearer API key is required. Enter the key in the API key field, or switch Authentication to "None" for keyless providers such as local Ollama.',
            ]);
        }

        if ($settings) {
            // Only overwrite the stored key when a new one was submitted.
            if (blank($data['api_key'] ?? null)) {
                unset($data['api_key']);
            }
            $settings->update($data);
        } else {
            AiSetting::create($data);
        }

        AuditLog::record('ai_settings.updated', null, 'Updated AI provider settings');

        return redirect()->route('admin.ai-settings.edit')->with('success', 'AI settings saved. Credentials are stored encrypted.');
    }

    public function testConnection(AiProviderService $ai): RedirectResponse
    {
        $result = $ai->testConnection();

        return redirect()->route('admin.ai-settings.edit')->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    public function checkModel(AiProviderService $ai): RedirectResponse
    {
        $result = $ai->modelAvailability();

        return redirect()->route('admin.ai-settings.edit')->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    /**
     * JSON endpoint for the settings page: discovers models from the
     * provider's model-list endpoint using the (possibly unsaved) form
     * fields, falling back to the stored encrypted credentials. Submitted
     * credentials are never persisted or echoed; responses contain only
     * sanitized messages and model IDs.
     */
    public function discoverModels(Request $request, AiProviderService $ai): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'auth_mode' => ['nullable', 'in:none,bearer,basic'],
            'basic_auth_user' => ['nullable', 'string', 'max:255'],
            'basic_auth_password' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $ai->discoverModels(
            endpointOverride: $data['endpoint'] ?? null,
            apiKeyOverride: $data['api_key'] ?? null,
            authModeOverride: $data['auth_mode'] ?? null,
            basicUser: $data['basic_auth_user'] ?? null,
            basicPassword: $data['basic_auth_password'] ?? null
        );

        return response()->json($result);
    }

    public function generateNow(BlogGeneratorService $generator): RedirectResponse
    {
        $result = $generator->generateFromAutomationSettings(manual: true);

        return redirect()->route('admin.ai-settings.edit')->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }
}
