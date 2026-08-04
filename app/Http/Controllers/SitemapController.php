<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;

class SitemapController extends Controller
{
    /**
     * Render a dynamic XML sitemap covering published articles, categories
     * and static pages with per-entry freshness and frequency hints.
     */
    public function index()
    {
        $urls = [];

        // Static pages.
        $static = [
            ['loc' => route('home'), 'lastmod' => now(), 'changefreq' => 'daily', 'priority' => '1.0'],
        ];

        foreach ($static as $entry) {
            $urls[] = $entry;
        }

        // Categories.
        Category::query()->select('slug', 'updated_at')->get()->each(function (Category $category) use (&$urls): void {
            $urls[] = [
                'loc' => $this->categoryUrl($category->slug),
                'lastmod' => $category->updated_at,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ];
        });

        // Published articles.
        Article::query()
            ->where('is_published', true)
            ->select('slug', 'updated_at')
            ->get()
            ->each(function (Article $article) use (&$urls): void {
                $urls[] = [
                    'loc' => route('articles.show', $article->slug),
                    'lastmod' => $article->updated_at,
                    'changefreq' => 'weekly',
                    'priority' => '0.9',
                ];
            });

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Build a category URL. Eagerly-linked to a real filtered listing page
     * once a category route exists; for now it points at a named route.
     */
    protected function categoryUrl(string $slug): string
    {
        $route = \Route::has('categories.show') ? route('categories.show', $slug) : url('/');

        return $route;
    }
}
