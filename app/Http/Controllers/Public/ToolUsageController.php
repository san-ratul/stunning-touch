<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\Analytics;
use App\Support\ToolRegistry;
use Illuminate\Http\Request;

class ToolUsageController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tool' => ['required', 'string', 'max:100'],
            'success' => ['required', 'in:0,1,true,false'],
            'event' => ['nullable', 'string', 'max:50', 'in:run,download,error'],
        ]);

        abort_unless(ToolRegistry::exists($validated['tool']), 422);

        Analytics::recordToolUsage(
            $validated['tool'],
            filter_var($validated['success'], FILTER_VALIDATE_BOOLEAN),
            $validated['event'] ?? 'run'
        );

        return response()->json(['ok' => true]);
    }
}
