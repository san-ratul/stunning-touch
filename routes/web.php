<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Public;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website routes
|--------------------------------------------------------------------------
*/

Route::get('/', [Public\HomeController::class, 'index'])->name('home');
Route::get('/tools', [Public\ToolsController::class, 'index'])->name('tools.index');
Route::get('/tools/search', [Public\ToolsController::class, 'search'])->name('tools.search');
Route::get('/about', [Public\PageController::class, 'about'])->name('about');
Route::get('/contact', [Public\PageController::class, 'contact'])->name('contact');
Route::post('/contact', [Public\PageController::class, 'submitContact'])->middleware('throttle:5,1')->name('contact.submit');
Route::get('/privacy-policy', [Public\PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [Public\PageController::class, 'terms'])->name('terms');
Route::get('/disclaimer', [Public\PageController::class, 'disclaimer'])->name('disclaimer');

// Blog
Route::get('/blog', [Public\BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/category/{slug}', [Public\BlogController::class, 'category'])->name('blog.category');
Route::get('/blog/{slug}', [Public\BlogController::class, 'show'])->name('blog.show');

// Technical SEO
Route::get('/sitemap.xml', [Public\SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [Public\SitemapController::class, 'robots'])->name('robots');

// Tool usage beacon (records real processing events only)
Route::post('/api/tool-usage', [Public\ToolUsageController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('tool-usage.store');

// Approximate visitor locale hint (once per session, privacy-safe)
Route::post('/api/visitor-locale', [Public\VisitorLocaleController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('visitor-locale.store');

// SMTP Connection & Email Tester (server-side tool; rate-limited, CSRF-protected)
Route::post('/api/smtp/test-connection', [Public\SmtpTesterController::class, 'testConnection'])
    ->middleware('throttle:smtp-test')
    ->name('smtp.test-connection');
Route::post('/api/smtp/send-test-email', [Public\SmtpTesterController::class, 'sendTestEmail'])
    ->middleware('throttle:smtp-send')
    ->name('smtp.send-test-email');

// Advertisement banner click redirect (real click tracking for internal banners)
Route::get('/ad-click/{ad}', [Public\AdClickController::class, 'redirect'])
    ->middleware('throttle:30,1')
    ->name('ad.click');

// Protected web-cron endpoint for shared hosting (secret in settings)
Route::get('/run-scheduler/{secret}', function (string $secret) {
    abort_unless(hash_equals((string) config('services.scheduler.secret'), $secret), 404);

    $exitCode = Artisan::call('schedule:run');

    return response('Scheduler executed ('.$exitCode.')', 200);
})->middleware('throttle:2,1')->name('scheduler.run');

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
*/

// Admin authentication (login must exist as route('login') for the auth middleware)
Route::get('admin/login', [Admin\AuthController::class, 'showLogin'])->middleware('guest')->name('login');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
    });

    Route::middleware(['auth', EnsureAdmin::class])->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout'])->name('logout');

        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

        Route::resource('tools', Admin\ToolController::class)->except('show');
        Route::post('tools/{tool}/toggle-active', [Admin\ToolController::class, 'toggleActive'])->name('tools.toggle-active');
        Route::post('tools/{tool}/archive', [Admin\ToolController::class, 'archive'])->name('tools.archive');

        Route::resource('categories', Admin\CategoryController::class)->except('show');

        Route::get('seo', [Admin\SeoController::class, 'index'])->name('seo.index');
        Route::get('seo/{type}/{id}/edit', [Admin\SeoController::class, 'edit'])->name('seo.edit');
        Route::put('seo/{type}/{id}', [Admin\SeoController::class, 'update'])->name('seo.update');

        Route::get('analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('tool-statistics', [Admin\ToolStatisticsController::class, 'index'])->name('tool-statistics.index');

        Route::get('advertisements', [Admin\AdvertisementController::class, 'index'])->name('advertisements.index');
        Route::get('advertisements/create', [Admin\AdvertisementController::class, 'create'])->name('advertisements.create');
        Route::post('advertisements', [Admin\AdvertisementController::class, 'store'])->name('advertisements.store');
        Route::get('advertisements/{advertisement}/edit', [Admin\AdvertisementController::class, 'edit'])->name('advertisements.edit');
        Route::put('advertisements/{advertisement}', [Admin\AdvertisementController::class, 'update'])->name('advertisements.update');
        Route::delete('advertisements/{advertisement}', [Admin\AdvertisementController::class, 'destroy'])->name('advertisements.destroy');
        Route::post('advertisements/{advertisement}/toggle', [Admin\AdvertisementController::class, 'toggle'])->name('advertisements.toggle');
        Route::get('advertisements/{advertisement}/preview', [Admin\AdvertisementController::class, 'preview'])->name('advertisements.preview');

        Route::resource('blog/posts', Admin\BlogPostController::class)->names('blog.posts')->except('show');
        Route::get('blog/generate', Admin\ManualBlogGenerationController::class.'@create')->name('blog.generate.create');
        Route::post('blog/generate', Admin\ManualBlogGenerationController::class.'@store')->name('blog.generate.store');
        Route::post('blog/posts/{post}/publish', [Admin\BlogPostController::class, 'publish'])->name('blog-posts.publish');
        Route::post('blog/posts/{post}/regenerate-image', [Admin\ManualBlogGenerationController::class, 'regenerateImage'])->name('blog-posts.regenerate-image');
        Route::post('blog/posts/{post}/unpublish', [Admin\BlogPostController::class, 'unpublish'])->name('blog-posts.unpublish');
        Route::post('blog/posts/{post}/archive', [Admin\BlogPostController::class, 'archive'])->name('blog-posts.archive');
        Route::resource('blog/categories', Admin\BlogCategoryController::class)->names('blog.categories')->except('show');
        Route::resource('blog/tags', Admin\BlogTagController::class)->names('blog.tags')->except('show');

        Route::get('ai-settings', [Admin\AiSettingsController::class, 'edit'])->name('ai-settings.edit');
        Route::put('ai-settings', [Admin\AiSettingsController::class, 'update'])->name('ai-settings.update');
        Route::post('ai-settings/test', [Admin\AiSettingsController::class, 'testConnection'])->name('ai-settings.test');
        Route::post('ai-settings/model-check', [Admin\AiSettingsController::class, 'checkModel'])->name('ai-settings.model-check');
        Route::post('ai-settings/models', [Admin\AiSettingsController::class, 'discoverModels'])->name('ai-settings.models');
        Route::post('ai-settings/generate', [Admin\AiSettingsController::class, 'generateNow'])->name('ai-settings.generate');

        Route::get('automation', [Admin\AutomationController::class, 'edit'])->name('automation.edit');
        Route::put('automation', [Admin\AutomationController::class, 'update'])->name('automation.update');

        Route::get('research-settings', [Admin\ResearchSettingsController::class, 'edit'])->name('research.edit');
        Route::put('research-settings', [Admin\ResearchSettingsController::class, 'update'])->name('research.update');

        Route::get('monitoring', [Admin\MonitoringController::class, 'index'])->name('monitoring.index');
        Route::post('monitoring/refresh-health', [Admin\MonitoringController::class, 'refreshHealth'])->name('monitoring.refresh');

        Route::put('image-settings', [Admin\ImageSettingsController::class, 'update'])->name('image-settings.update');
        Route::post('image-settings/test', [Admin\ImageSettingsController::class, 'testConnection'])->name('image-settings.test');
        Route::post('image-settings/test-generate', [Admin\ImageSettingsController::class, 'testGeneration'])->name('image-settings.test-generate');

        Route::get('media', [Admin\MediaController::class, 'index'])->name('media.index');
        Route::post('media', [Admin\MediaController::class, 'store'])->name('media.store');
        Route::delete('media/{media}', [Admin\MediaController::class, 'destroy'])->name('media.destroy');

        Route::get('pages', [Admin\PageController::class, 'index'])->name('pages.index');
        Route::get('pages/{page}/edit', [Admin\PageController::class, 'edit'])->name('pages.edit');
        Route::put('pages/{page}', [Admin\PageController::class, 'update'])->name('pages.update');

        Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');

        Route::get('account/password', [Admin\AccountController::class, 'edit'])->name('account.password');
        Route::put('account/password', [Admin\AccountController::class, 'update'])->name('account.password.update');

        Route::get('users', [Admin\AdminUserController::class, 'index'])->name('users.index');
        Route::get('users/create', [Admin\AdminUserController::class, 'create'])->name('users.create');
        Route::post('users', [Admin\AdminUserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [Admin\AdminUserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [Admin\AdminUserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [Admin\AdminUserController::class, 'destroy'])->name('users.destroy');

        Route::get('audit-logs', [Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('contact-messages', [Admin\ContactMessageController::class, 'index'])->name('contact-messages.index');
        Route::delete('contact-messages/{message}', [Admin\ContactMessageController::class, 'destroy'])->name('contact-messages.destroy');
        Route::post('contact-messages/{message}/toggle-read', [Admin\ContactMessageController::class, 'toggleRead'])->name('contact-messages.toggle-read');

        // Artisan Command Runner — dedicated permission, default-deny,
        // enforced again inside the controller (defense in depth).
        Route::middleware('can:artisan-runner.access')->group(function () {
            Route::get('artisan-runner', [Admin\ArtisanRunnerController::class, 'index'])->name('artisan-runner.index');
            Route::post('artisan-runner/run', [Admin\ArtisanRunnerController::class, 'run'])
                ->middleware('throttle:30,1')
                ->name('artisan-runner.run');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Dynamic slug resolver — categories and tools live at the root.
| Keep LAST so explicit routes always win.
|--------------------------------------------------------------------------
*/

Route::get('/{slug}', [Public\SlugResolverController::class, '__invoke'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('slug.resolve');
