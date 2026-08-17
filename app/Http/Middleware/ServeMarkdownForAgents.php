<?php

namespace App\Http\Middleware;

use App\Models\Article;
use App\Services\ShortcodeParser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Negotiation + Protocol Discovery for AI agents:
 *  - serves clean Markdown when the client requests `Accept: text/markdown`
 *    instead of the heavy HTML page, and
 *  - attaches RFC 9727 Link headers so crawlers discover the API catalog,
 *    sitemap and llms.txt up-front.
 */
class ServeMarkdownForAgents
{
    public function handle(Request $request, Closure $next): Response
    {
        // Public GET pages only — never admin, API, Livewire, assets or health.
        if (! $request->isMethod('GET') || $this->isInternalPath($request)) {
            return $next($request);
        }

        // Machine readers that explicitly ask for Markdown get a clean text version.
        // The `_fmt=md` marker (as a query param or a request header set by a
        // zone Transform Rule) also opts in, so the variant still resolves even
        // if the edge normalizes the Accept header.
        $wantsMarkdown = str_contains(strtolower($request->header('Accept', '')), 'text/markdown')
            || $request->query('_fmt') === 'md'
            || strtolower((string) $request->header('_fmt')) === 'md';

        if ($wantsMarkdown) {
            $markdown = $this->resolveMarkdown($request);

            if ($markdown !== null) {
                return $this->withDiscoveryHeaders(response($markdown, 200, [
                    'Content-Type' => 'text/markdown; charset=utf-8',
                    'Vary' => 'Accept',
                ]));
            }
        }

        return $this->withDiscoveryHeaders($next($request));
    }

    /**
     * Global middleware runs before the router resolves the route, so match the
     * URL path directly instead of querying the (still-null) route name.
     */
    private function resolveMarkdown(Request $request): ?string
    {
        $path = $request->path();

        if ($path === '/') {
            return $this->renderHome();
        }

        if (preg_match('#^articles/([^/]+)$#', $path, $matches)) {
            $article = Article::query()
                ->with(['product', 'category', 'articleProducts.product'])
                ->where('slug', $matches[1])
                ->where('is_published', true)
                ->first();

            return $article ? $this->renderArticle($article) : null;
        }

        return null;
    }

    private function isInternalPath(Request $request): bool
    {
        $path = $request->path();

        return $path === 'up'
            || $path === 'mcp'
            || str_starts_with($path, 'mcp/')
            || str_starts_with($path, 'admin')
            || str_starts_with($path, 'api/')
            || str_starts_with($path, 'livewire/')
            || str_starts_with($path, 'filament/')
            || str_starts_with($path, 'build/')
            || str_starts_with($path, 'storage/')
            || str_starts_with($path, '_ignition/');
    }

    private function withDiscoveryHeaders(Response $response): Response
    {
        $base = url('/');

        // RFC 8288 Link headers matching the Cloudflare/agent-readiness
        // reference format: api-catalog + MCP server card + sitemap + llms.txt.
        $response->headers->set('Link', sprintf(
            '<%s/.well-known/api-catalog>; rel="api-catalog"; type="application/linkset+json", <%s/.well-known/mcp/server-card.json>; rel="describedby"; type="application/json", <%s/sitemap.xml>; rel="sitemap"; type="application/xml", <%s/llms.txt>; rel="describedby"; type="text/markdown"',
            $base,
            $base,
            $base,
            $base
        ));

        // Split the cache by Accept on EVERY public page (HTML included) so a
        // Cloudflare edge cache can never serve the HTML variant to a crawler
        // that explicitly asked for text/markdown.
        $vary = $response->headers->get('Vary');

        if (! str_contains((string) $vary, 'Accept')) {
            $response->headers->set('Vary', $vary ? $vary.', Accept' : 'Accept');
        }

        return $response;
    }

    private function renderArticle(Article $article): string
    {
        $siteUrl = \App\Services\SEOHelper::url();
        $cleanTitle = \App\Services\SEOHelper::cleanTitle($article->title);
        $product = $article->product;
        $description = trim((string) $article->meta_description);

        $frontmatter = [
            'title: ' . $cleanTitle,
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
            if ((float) $product->rating > 0) {
                $frontmatter[] = 'rating: ' . number_format((float) $product->rating, 1);
            }
            if ((int) $product->review_count > 0) {
                $frontmatter[] = 'review_count: ' . (int) $product->review_count;
            }
            if (filled($product->image_url)) {
                $frontmatter[] = 'image: ' . $product->image_url;
            }

            // Explicit commercial entity metadata so AI agents (Perplexity,
            // AutoGPT, ChatGPT/Search) can resolve the buyable offer straight
            // from the frontmatter without treating the CTA as boilerplate.
            if (filled($product->affiliate_url)) {
                $frontmatter[] = 'offer_url: ' . $product->affiliate_url;
                $frontmatter[] = 'merchant: أمازون مصر';
                $frontmatter[] = 'currency: EGP';
                $frontmatter[] = 'availability: ' . ($product->in_stock ? 'in_stock' : 'out_of_stock');
            }

            // Brand-level warranty facts (e.g. merchant/agency hotline) can be
            // attached ONLY when real data is stored — never synthesized, so the
            // E-E-A-T fact-check stays truthful for every brand.
            if (filled($product->brand) && filled($product->warranty_provider)) {
                $frontmatter[] = 'warranty_provider: ' . $product->warranty_provider;
            }
        }

        $lastUpdated = $product?->last_synced_at ?? $article->updated_at;
        if ($lastUpdated !== null) {
            $frontmatter[] = 'last_updated: ' . $lastUpdated->toIso8601String();
        }

        $frontmatter[] = 'url: ' . \App\Services\SEOHelper::canonical('articles/' . $article->slug);

        $parser = app(ShortcodeParser::class);
        $parsedMarkdownContent = $parser->parseForMarkdown($article);

        // Verified-entity hook paragraph: embeds the affiliate URL inside a
        // factual warranty/merchant phrase (before the shortcode body) instead
        // of leaving a lone isolated CTA at the bottom that LLM summarizers
        // classify as boilerplate and drop.
        $introParagraph = null;
        if ($product && filled($product->affiliate_url)) {
            $introParagraph = 'يمكنك الاطلاع على المواصفات والطلب مباشرة عبر [صفحة العرض والضمان المعتمد على أمازون مصر](' . $product->affiliate_url . ') مع تفعيل خيارات التقسيط البنكي 0% فائدة.';
        }

        return implode(PHP_EOL, [
            '---',
            ...$frontmatter,
            '---',
            '',
            '# ' . $cleanTitle,
            '',
            ...($introParagraph !== null ? [$introParagraph, ''] : []),
            $parsedMarkdownContent,
            '',
        ]) . PHP_EOL;
    }

    private function renderHome(): string
    {
        $siteName = config('app.name', 'أمان برايس');
        $siteUrl = \App\Services\SEOHelper::url();

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