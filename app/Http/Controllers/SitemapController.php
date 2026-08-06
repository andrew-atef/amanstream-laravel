<?php

namespace App\Http\Controllers;

use App\Models\Article;

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

        // Published articles only (canonical, no filtered/parameterised URLs).
        Article::query()
            ->where('is_published', true)
            ->whereNotNull('slug')
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
}
