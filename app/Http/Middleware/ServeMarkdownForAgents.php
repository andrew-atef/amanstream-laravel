<?php

namespace App\Http\Middleware;

use App\Models\Article;
use App\Services\ShortcodeParser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Negotiation: serves clean Markdown to AI agents / crawlers that
 * request `Accept: text/markdown`, while browsers keep receiving the normal
 * HTML pages. The check happens BEFORE the controller renders the (heavy)
 * HTML view, so there is no wasted rendering work for machine readers.
 */
class ServeMarkdownForAgents
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only GET requests coming from an agent that explicitly wants Markdown.
        $accept = strtolower($request->header('Accept', ''));

        if (! str_contains($accept, 'text/markdown') || ! $request->isMethod('GET')) {
            return $next($request);
        }

        // Never touch the admin panel, API, Livewire, compiled assets or health checks.
        if (
            $request->path() === 'up'
            || $request->is('admin*')
            || $request->is('api*')
            || $request->is('livewire*')
            || $request->is('filament*')
            || $request->is('build/*')
            || $request->is('storage/*')
            || $request->is('_ignition/*')
        ) {
            return $next($request);
        }

        // Global middleware runs before the router resolves the route, so match
        // the URL path directly instead of querying the (still-null) route name.
        $markdown = null;
        $path = $request->path();

        if ($path === '/') {
            $markdown = $this->renderHome();
        } elseif (preg_match('#^articles/([^/]+)$#', $path, $matches)) {
            $article = Article::query()
                ->with('product')
                ->where('slug', $matches[1])
                ->where('is_published', true)
                ->first();

            if ($article) {
                $markdown = $this->renderArticle($article);
            }
        }

        if ($markdown === null) {
            return $next($request);
        }

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Vary' => 'Accept',
        ]);
    }

    private function renderArticle(Article $article): string
    {
        $siteUrl = url('/');
        $product = $article->product;
        $description = trim((string) $article->meta_description);

        $frontmatter = [
            'title: ' . $article->title,
        ];

        if ($description !== '') {
            $frontmatter[] = 'description: ' . $description;
        }

        if ($product) {
            if (filled($product->asin)) {
                $frontmatter[] = 'asin: ' . $product->asin;
            }
            if (filled($product->brand)) {
                $frontmatter[] = 'brand: ' . $product->brand;
            }
            if ((float) $product->price > 0) {
                $frontmatter[] = 'price_egp: ' . number_format((float) $product->price, 2, '.', '');
            }
            if ((float) $product->original_price > (float) $product->price) {
                $frontmatter[] = 'original_price_egp: ' . number_format((float) $product->original_price, 2, '.', '');
            }
            if (filled($product->image_url)) {
                $frontmatter[] = 'image: ' . $product->image_url;
            }
        }

        $frontmatter[] = 'url: ' . $siteUrl . '/articles/' . $article->slug;

        return implode(PHP_EOL, [
            '---',
            ...$frontmatter,
            '---',
            '',
            '# ' . $article->title,
            '',
            ShortcodeParser::stripShortcodes($article->content),
            '',
        ]) . PHP_EOL;
    }

    private function renderHome(): string
    {
        $siteName = config('app.name', 'أمان ستريم');
        $siteUrl = url('/');

        $articles = Article::query()
            ->with(['product', 'category'])
            ->where('is_published', true)
            ->latest()
            ->limit(50)
            ->get();

        $lines = [
            '---',
            'title: ' . $siteName . ' — دليل مراجعات وأسعار الأجهزة في مصر',
            'description: بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر مع حاسبة التقسيط.',
            '---',
            '',
            '# ' . $siteName,
            '',
            'بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر، مع حاسبة التقسيط والمقارنات والأسعار المحدثة يوميًا.',
            '',
            '## المقالات والمراجعات',
            '',
        ];

        foreach ($articles as $article) {
            $lines[] = '- [' . $article->title . '](' . $siteUrl . '/articles/' . $article->slug . ')';
        }

        $lines[] = '';
        $lines[] = '## موارد إضافية';
        $lines[] = '- [خريطة الموقع XML](' . $siteUrl . '/sitemap.xml)';
        $lines[] = '- [llms.txt](' . $siteUrl . '/llms.txt)';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}