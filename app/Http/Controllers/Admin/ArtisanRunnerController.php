<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ArtisanCommandException;
use App\Support\ArtisanRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin Artisan Command Runner.
 *
 * Authorization is enforced twice on every request:
 * 1. the `can:artisan-runner.access` route middleware (Gate), and
 * 2. an explicit gate re-check inside every controller method.
 *
 * There is no public path to the executor: unauthorized requests are
 * rejected before any parsing, process creation or output handling.
 */
class ArtisanRunnerController extends Controller
{
    public function __construct(private readonly ArtisanRunner $runner) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess();

        return view('admin.artisan-runner.index', [
            'catalog' => ArtisanRunner::catalog(),
            'environmentProblem' => $this->runner->environmentProblem(),
            'history' => AuditLog::query()
                ->where('action', 'like', 'artisan_runner.%')
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:'.ArtisanRunner::MAX_INPUT_LENGTH],
        ]);

        try {
            $result = $this->runner->run($validated['command']);
        } catch (ArtisanCommandException $exception) {
            $result = [
                'ok' => false,
                'status' => 'rejected',
                'message' => $exception->getMessage(),
                'command' => $validated['command'],
                'exit_code' => null,
                'duration_ms' => 0,
                'stdout' => '',
                'stderr' => '',
                'output_truncated' => false,
            ];

            $this->audit($validated['command'], $result);

            return response()
                ->json(['ok' => false, 'result' => $result])
                ->header('Cache-Control', 'no-store, private');
        }

        $this->audit($result['command'], $result);

        return response()
            ->json(['ok' => true, 'result' => $result])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Defense-in-depth authorization — runs after the route middleware.
     * Fails closed: anything unverified is denied.
     */
    protected function authorizeAccess(): void
    {
        $user = auth()->user();

        abort_unless($user && $user->is_active, 401);
        abort_unless($user->can('artisan-runner.access'), 403);
    }

    protected function audit(string $command, array $result): void
    {
        if (($result['status'] ?? '') === 'rejected') {
            // Rejected attempts are logged without their raw input so that
            // no unintended content is persisted.
            AuditLog::record(
                'artisan_runner.rejected',
                null,
                'Rejected Artisan command attempt',
                ['reason' => $result['message']],
            );

            return;
        }

        AuditLog::record(
            'artisan_runner.'.($result['status'] ?? 'executed'),
            null,
            'Artisan command: '.$command,
            [
                'command' => $command,
                'exit_code' => $result['exit_code'],
                'duration_ms' => $result['duration_ms'],
            ],
        );
    }
}
