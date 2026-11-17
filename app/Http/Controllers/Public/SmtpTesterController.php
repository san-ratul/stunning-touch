<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Smtp\SmtpTesterService;
use App\Support\Analytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public endpoints for the SMTP Connection & Email Tester.
 *
 * - Strict validation on every field, single recipient, message size caps.
 * - Named rate limiters (smtp-test, smtp-send) registered in AppServiceProvider.
 * - Results are structured and sanitized; raw exceptions never reach the client.
 * - Credentials are never persisted, logged, or echoed back.
 */
class SmtpTesterController extends Controller
{
    public function __construct(private readonly SmtpTesterService $tester) {}

    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['ssl', 'starttls', 'none'])],
            'verify_auth' => ['sometimes', 'boolean'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
        ]);

        $result = $this->tester->testConnection($validated);

        Analytics::recordToolUsage(
            'smtp-connection-tester',
            $result['status'] === 'ok' || $result['status'] === 'warning',
            $result['status'] === 'ok' ? 'run' : 'error',
        );

        return response()->json(['ok' => true, 'result' => $result]);
    }

    public function sendTestEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['ssl', 'starttls', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'from_email' => ['required', 'email:rfc', 'max:254'],
            'to_email' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $this->tester->sendTestEmail($validated);

        Analytics::recordToolUsage(
            'smtp-connection-tester',
            $result['status'] === 'ok',
            $result['status'] === 'ok' ? 'run' : 'error',
        );

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
