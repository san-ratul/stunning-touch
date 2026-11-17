<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tool;
use App\Support\SeoResolver;

class HomeController extends Controller
{
    public function index()
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
            'Free browser-based tools for images, PDFs and text. Compress images, merge PDFs, count words, format JSON, generate QR codes and more — private by design.'
        );

        return view('home', [
            'categories' => $categories,
            'featuredTools' => $featuredTools,
            'seo' => $meta,
            'pageType' => 'home',
        ]);
    }
}
