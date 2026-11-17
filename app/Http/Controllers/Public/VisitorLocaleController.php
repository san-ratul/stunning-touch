<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Support\Analytics;
use Illuminate\Http\Request;

class VisitorLocaleController extends Controller
{
    /**
     * Records the visitor's approximate country (derived client-side from
     * the browser's IANA timezone, e.g. Asia/Dhaka -> BD) once per session.
     * Used ONLY when no authoritative edge geo header exists. No raw data
     * is stored.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ]);

        $ua = (string) $request->userAgent();

        // Exclude bots and empty user agents, but allow /api/* paths
        // (unlike page-view tracking, this endpoint IS an API route).
        if ($ua === '' || preg_match(Analytics::BOT_PATTERN, $ua)) {
            return response()->noContent();
        }

        $visitor = Visitor::query()->firstWhere('visitor_hash', Analytics::fingerprint($request));

        if ($visitor && blank($visitor->locale_country) && ! empty($validated['country'])) {
            $visitor->updateQuietly(['locale_country' => strtoupper($validated['country'])]);
        }

        return response()->noContent();
    }
}
