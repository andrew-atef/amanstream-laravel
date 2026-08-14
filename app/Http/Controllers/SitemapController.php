<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Render a dynamic XML sitemap covering published articles, categories
     * and static pages with per-entry freshness and frequency hints.
     */
    public function index()
    {
        $xml = Cache::remember('sitemap_xml_content', now()->addHours(6), function (): string {
            $urls = [];

            // Static pages.
            $static = [
                ['loc' => route('home'), 'lastmod' => now(), 'changefreq' => 'daily', 'priority' => '1.0'],
                ['loc' => route('about'), 'lastmod' => now(), 'changefreq' => 'monthly', 'priority' => '0.5'],
            ];

            foreach ($static as $entry) {
                $urls[] = $entry;
            }

            // Published articles only (canonical, no filtered/parameterised URLs).
            Article::query()
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->select('slug', 'updated_at')
                ->cursor()
                ->each(function (Article $article) use (&$urls): void {
                    $urls[] = [
                        'loc' => route('articles.show', $article->slug),
                        'lastmod' => $article->updated_at,
                        'changefreq' => 'weekly',
                        'priority' => '0.9',
                    ];
                });

            return view('sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200)
            ->header('Content-Type', 'application/xml')
            ->header('Cache-Control', 'public, max-age=21600, s-maxage=86400');
    }
}
