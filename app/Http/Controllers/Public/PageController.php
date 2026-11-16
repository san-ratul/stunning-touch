<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\StaticPage;
use App\Support\SeoResolver;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function about()
    {
        $page = StaticPage::query()->where('slug', 'about')->where('is_active', true)->first();

        return view('pages.static', [
            'page' => $page,
            'seo' => SeoResolver::normalize(SeoResolver::forModel($page), 'About '.SeoResolver::siteName(), null),
            'pageType' => 'static_page',
            'breadcrumbs' => [['label' => 'About']],
        ]);
    }

    public function contact()
    {
        return view('pages.contact', [
            'seo' => SeoResolver::normalize(null, 'Contact Us', 'Get in touch with the '.SeoResolver::siteName().' team. Questions, feedback and partnership inquiries are welcome.'),
            'pageType' => 'static_page',
            'breadcrumbs' => [['label' => 'Contact']],
        ]);
    }

    public function submitContact(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'website' => ['prohibited'], // honeypot
        ]);

        ContactMessage::create(collect($validated)->except('website')->all());

        return back()->with('success', 'Thank you for your message. We will get back to you soon.');
    }

    public function privacy()
    {
        return $this->renderLegal('privacy-policy', 'Privacy Policy');
    }

    public function terms()
    {
        return $this->renderLegal('terms', 'Terms of Service');
    }

    public function disclaimer()
    {
        return $this->renderLegal('disclaimer', 'Disclaimer');
    }

    protected function renderLegal(string $slug, string $fallbackTitle)
    {
        $page = StaticPage::query()->where('slug', $slug)->where('is_active', true)->first();

        abort_if(! $page, 404);

        return view('pages.static', [
            'page' => $page,
            'seo' => SeoResolver::normalize(SeoResolver::forModel($page), $fallbackTitle, null),
            'pageType' => 'static_page',
            'breadcrumbs' => [['label' => $fallbackTitle]],
        ]);
    }
}
