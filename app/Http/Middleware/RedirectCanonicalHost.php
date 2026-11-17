<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical-host redirect: any request whose host is the "www." variant of
 * the canonical domain is permanently redirected to the canonical URL in a
 * single hop, preserving path and query string.
 *
 * Deliberately HOST-ONLY: the HTTP->HTTPS upgrade is enforced in the
 * web-server layer (.htaccess), where the real TLS state of the client
 * connection is observable. This middleware can therefore never loop under
 * any reverse-proxy configuration, and non-canonical third-party hostnames
 * (staging domains, localhost, direct IP access) are left untouched so
 * local development and other deployments keep working unchanged.
 */
class RedirectCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($canonicalHost) && $canonicalHost !== '') {
            $host = strtolower((string) $request->getHost());

            if ($host === 'www.'.strtolower($canonicalHost)) {
                return redirect()->away(rtrim((string) config('app.url'), '/').$request->getRequestUri(), 301);
            }
        }

        return $next($request);
    }
}
