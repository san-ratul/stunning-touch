<?php

namespace App\Providers;

use App\Services\Smtp\SmtpConnectionFactoryInterface;
use App\Services\Smtp\SocketSmtpConnectionFactory;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmtpConnectionFactoryInterface::class, SocketSmtpConnectionFactory::class);
    }

    public function boot(): void
    {
        // Authenticated users hitting guest pages (/admin/login) go to the
        // admin dashboard instead of the public homepage.
        RedirectIfAuthenticated::redirectUsing(fn () => route('admin.dashboard'));

        // Force HTTPS for generated URLs when behind a TLS-terminating proxy.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registerSmtpRateLimiters();
    }

    /**
     * Abuse prevention for the public SMTP tester endpoints.
     *
     * Connection testing is cheap-ish but still an outbound network probe:
     * 10/minute and 50/day per IP. Sending a real email is tightly capped:
     * 3/hour and 10/day per IP, one recipient per request (enforced in the
     * controller validation).
     */
    protected function registerSmtpRateLimiters(): void
    {
        RateLimiter::for('smtp-test', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perDay(50)->by($request->ip()),
        ]);

        RateLimiter::for('smtp-send', fn (Request $request) => [
            Limit::perHour(3)->by($request->ip()),
            Limit::perDay(10)->by($request->ip()),
        ]);
    }
}
