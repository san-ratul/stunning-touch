<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Authenticated users hitting guest pages (/admin/login) go to the
        // admin dashboard instead of the public homepage.
        RedirectIfAuthenticated::redirectUsing(fn () => route('admin.dashboard'));

        // Force HTTPS for generated URLs when behind a TLS-terminating proxy.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
