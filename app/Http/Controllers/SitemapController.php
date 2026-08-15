<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Render a dynamic XML sitemap covering published articles, category hubs
     * and static pages with per-entry freshness and frequency hints.
     */
    public function index()
    {
        $xml = Cache::remember('sitemap_xml_content', now()->addHours(6), function (): string {
            $urls = [];

            // Static pages.
            $urls[] = [
                'loc' => route('home'),
                'lastmod' => now(),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ];

            // The About/brand page is indexable and earns context for the
            // whole site's entity/brand signals.
            $urls[] = [
                'loc' => route('about'),
                'lastmod' => now(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ];

            // Clean /category/{slug} hubs — the Category Hubs Google should
            // treat as top-level destinations (was missing from the sitemap).
            // Only real categories that already have published articles qualify,
            // so empty shells never waste crawl budget.
            Category::query()
                ->withMax(['articles' => fn ($query) => $query->where('is_published', true)], 'updated_at')
                ->whereHas('articles', fn ($query) => $query->where('is_published', true))
                ->select('slug', 'updated_at')
                ->orderBy('name')
                ->get()
                ->each(function (Category $category) use (&$urls): void {
                    $urls[] = [
                        'loc' => route('categories.show', $category->slug),
                        'lastmod' => $category->articles_max_updated_at
                            ? Carbon::parse($category->articles_max_updated_at)
                            : now(),
                        'changefreq' => 'daily',
                        'priority' => '0.8',
                    ];
                });

            // Published articles only (canonical, no filtered/parameterised URLs).
            Article::query()
                ->with('product')
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->select('id', 'product_id', 'title', 'slug', 'updated_at')
                ->cursor()
                ->each(function (Article $article) use (&$urls): void {
                    $item = [
                        'loc' => route('articles.show', $article->slug),
                        'lastmod' => $article->updated_at,
                        'changefreq' => 'weekly',
                        'priority' => '0.9',
                    ];

                    // Google Images extension: expose the product visual with a
                    // cleaned title so images quality for image search.
                    $imageUrl = $article->product?->image_url;

                    if (filled($imageUrl)) {
                        $item['image'] = [
                            'loc' => $imageUrl,
                            'title' => $article->title,
                        ];
                    }

                    $urls[] = $item;
                });

            return view('sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200)
            ->header('Content-Type', 'application/xml')
            ->header('Cache-Control', 'public, max-age=21600, s-maxage=86400');
    }
}
