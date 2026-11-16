<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tool;
use App\Support\AdRenderer;
use App\Support\SeoResolver;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::query()
            ->active()
            ->ordered()
            ->with(['activeTools' => fn ($q) => $q->ordered()])
            ->get();

        $featuredTools = Tool::query()->active()->featured()->ordered()->get();

        $meta = SeoResolver::normalize(
            SeoResolver::forModel(null),
            SeoResolver::siteName().' — '.SeoResolver::tagline(),
            'Free online tools for images, PDFs, text, developers and more. Compress images, merge PDFs, convert text, generate QR codes and UUIDs — fast, private, in your browser.'
        );

        return view('home', [
            'categories' => $categories,
            'featuredTools' => $featuredTools,
            'seo' => $meta,
            'pageType' => 'home',
            'ads' => [
                'top' => AdRenderer::render('homepage_top', 'home', null, $request->attributes->get('device', 'all')),
                'content' => AdRenderer::render('homepage_content', 'home', null, $request->attributes->get('device', 'all')),
                'bottom' => AdRenderer::render('homepage_bottom', 'home', null, $request->attributes->get('device', 'all')),
            ],
        ]);
    }
}
