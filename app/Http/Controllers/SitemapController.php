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
     * Build a category URL using the existing homepage category filter, so
     * each category resolves to its own filtered listing instead of the homepage.
     */
    protected function categoryUrl(string $slug): string
    {
        if (\Route::has('categories.show')) {
            return route('categories.show', $slug);
        }

        return route('home', ['category' => $slug]);
    }
}
