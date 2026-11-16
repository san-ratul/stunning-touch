<?php

namespace App\Http\Middleware;

use App\Support\Analytics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackVisitor
{
    public function handle(Request $request, Closure $next, string $pageType = 'auto'): Response
    {
        $response = $next($request);

        try {
            if ($response->getStatusCode() === 200) {
                Analytics::recordPageView($request, $pageType);
            }
        } catch (\Throwable) {
            // Analytics must never break page rendering.
        }

        return $response;
    }
}
