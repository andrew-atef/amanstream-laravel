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

            // Editorial blog hub — the index page itself deserves an entry so
            // crawlers discover feed updates even if individual posts rotate.
            $urls[] = [
                'loc' => route('blog.index'),
                'lastmod' => now(),
                'changefreq' => 'daily',
                'priority' => '0.8',
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
                ->select('id', 'product_id', 'type', 'title', 'slug', 'updated_at')
                ->cursor()
                ->each(function (Article $article) use (&$urls): void {
                    $isBlog = $article->isBlog();

                    $item = [
                        'loc' => $isBlog
                            ? route('blog.show', $article->slug)
                            : route('articles.show', $article->slug),
                        'lastmod' => $article->updated_at,
                        'changefreq' => $isBlog ? 'monthly' : 'weekly',
                        'priority' => $isBlog ? '0.7' : '0.9',
                    ];

                    // Google Images extension: only available for review
                    // articles that carry a linked product visual.
                    $imageUrl = $isBlog ? null : $article->product?->image_url;

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
