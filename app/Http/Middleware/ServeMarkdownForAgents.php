<?php

namespace App\Http\Middleware;

use App\Models\Article;
use App\Services\SEOHelper;
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
                ->with(['product', 'category', 'products', 'articleProducts.product'])
                ->where('slug', $matches[1])
                ->where('is_published', true)
                ->first();

            return $article ? $this->renderArticle($article) : null;
        }

        if (preg_match('#^blog/([^/]+)$#', $path, $matches)) {
            $article = Article::query()
                ->with(['category'])
                ->where('slug', $matches[1])
                ->where('type', 'blog')
                ->where('is_published', true)
                ->first();

            return $article ? $this->renderBlogArticle($article) : null;
        }

        if ($path === 'blog') {
            return $this->renderBlogIndex();
        }

        return null;
    }

    private function isInternalPath(Request $request): bool
    {
        $path = $request->path();

        return $path === 'up'
            || $path === 'mcp'
            || str_starts_with($path, 'mcp/')
            || $path === 'go'
            || str_starts_with($path, 'go/')
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
        // Blog posts requested via the review URL (or any /articles slug) are
        // served with the editorial renderer so product entities never leak
        // into an agent's view of a non-commercial post.
        if ($article->isBlog()) {
            return $this->renderBlogArticle($article);
        }

        $siteUrl = SEOHelper::url();
        $cleanTitle = SEOHelper::cleanTitle($article->title);
        $product = $article->product;
        $description = trim((string) $article->meta_description);

        $frontmatter = [
            'title: '.$cleanTitle,
        ];

        if ($description !== '') {
            $frontmatter[] = 'description: '.$description;
        }

        if ($article->isComparison()) {
            $frontmatter[] = 'article_type: comparison';
            $frontmatter[] = 'compared_products:';
            foreach ($article->articleProducts->sortBy('sort_order') as $row) {
                $variant = $row->product;
                if (! $variant) {
                    continue;
                }
                $variantOffer = SEOHelper::goUrl((string) $variant->asin);
                $frontmatter[] = '  - title: "'.str_replace('"', '\"', SEOHelper::cleanTitle((string) $variant->title)).'"';
                $frontmatter[] = '    asin: "'.$variant->asin.'"';
                $frontmatter[] = '    offer_url: "'.$variantOffer.'"';
                if ((float) $variant->price > 0) {
                    $frontmatter[] = '    price_egp: "'.number_format((float) $variant->price, 2, '.', '').'"';
                }
            }
        } elseif ($product) {
            if (filled($product->asin)) {
                $frontmatter[] = 'asin: '.$product->asin;
            }
            if (filled($product->brand)) {
                $frontmatter[] = 'brand: '.$product->brand;
            }
            if ((float) $product->price > 0) {
                $frontmatter[] = 'price_egp: '.number_format((float) $product->price, 2, '.', '');
            }
            if ((float) $product->original_price > (float) $product->price) {
                $frontmatter[] = 'original_price_egp: '.number_format((float) $product->original_price, 2, '.', '');
            }
            if ((float) $product->rating > 0) {
                $frontmatter[] = 'rating: '.number_format((float) $product->rating, 1);
            }
            if ((int) $product->review_count > 0) {
                $frontmatter[] = 'review_count: '.(int) $product->review_count;
            }
            if (filled($product->image_url)) {
                $frontmatter[] = 'image: '.$product->image_url;
            }

            // Explicit commercial entity metadata so AI agents (Perplexity,
            // AutoGPT, ChatGPT/Search) can resolve the buyable offer straight
            // from the frontmatter without treating the CTA as boilerplate.
            // offer_url is ALWAYS the first-party /go/ redirect — the raw
            // Amazon URL is never exposed in Markdown, schema.org or agents.
            $goUrl = SEOHelper::goUrl((string) $product->asin);
            if (filled($goUrl)) {
                $frontmatter[] = 'offer_url: '.$goUrl;
                $frontmatter[] = 'merchant: أمازون مصر';
                $frontmatter[] = 'currency: EGP';
                $frontmatter[] = 'availability: '.($product->in_stock ? 'in_stock' : 'out_of_stock');
            }

            // Brand-level warranty facts (e.g. merchant/agency hotline) can be
            // attached ONLY when real data is stored — never synthesized, so the
            // E-E-A-T fact-check stays truthful for every brand.
            if (filled($product->brand) && filled($product->warranty_provider)) {
                $frontmatter[] = 'warranty_provider: '.$product->warranty_provider;
            }
        }

        $lastUpdated = $product?->last_synced_at ?? $article->updated_at;
        if ($lastUpdated !== null) {
            $frontmatter[] = 'last_updated: '.$lastUpdated->toIso8601String();
        }

        $frontmatter[] = 'url: '.SEOHelper::canonical('articles/'.$article->slug);

        $parser = app(ShortcodeParser::class);
        $parsedMarkdownContent = $parser->parseForMarkdown($article);

        // For comparisons, prepend a clear table so AI agents can resolve every SKU.
        $multiVariantComparisonTable = null;
        if ($article->isComparison()) {
            $rows = $article->articleProducts->sortBy('sort_order')->map(function ($row) {
                $v = $row->product;
                if (! $v) {
                    return null;
                }
                $goUrl = SEOHelper::goUrl((string) $v->asin);
                $availability = $v->in_stock ? 'متوفر' : 'غير متوفر';
                return '| '.str_replace('|', '\\|', SEOHelper::cleanTitle((string) $v->title)).' ('.$v->asin.') | '.number_format((float) $v->price, 2, '.', '').' ج.م | '.$availability.' | [شراء]('.$goUrl.') |';
            })->filter()->implode(PHP_EOL);
            if ($rows !== '') {
                $multiVariantComparisonTable = '| المنتج | السعر الحالي | التوفر | رابط الشراء |'.PHP_EOL.'| :--- | :--- | :--- | :--- |'.PHP_EOL.$rows;
            }
        }

        // Verified-entity hook paragraph
        $introParagraph = null;
        if ($article->isComparison()) {
            $firstProduct = $article->articleProducts->sortBy('sort_order')->first()?->product;
            $firstUrl = $firstProduct ? SEOHelper::goUrl((string) $firstProduct->asin) : null;
            if (filled($firstUrl)) {
                $introParagraph = 'تتضمن هذه المقارنة '.$article->articleProducts->whereNotNull('product_id')->count().' منتجات — يمكنك مراجعة المواصفات واختيار الأنسب عبر [صفحات العروض المعتمدة على أمازون مصر]('.$firstUrl.') مع خيارات التقسيط 0% فائدة.';
            }
        } else {
            $goUrl = SEOHelper::goUrl((string) ($product?->asin ?? ''));
            if ($product && filled($goUrl)) {
                $introParagraph = 'يمكنك الاطلاع على المواصفات والطلب مباشرة عبر [صفحة العرض والضمان المعتمد على أمازون مصر]('.$goUrl.') مع تفعيل خيارات التقسيط البنكي 0% فائدة.';
            }
        }

        return implode(PHP_EOL, [
            '---',
            ...$frontmatter,
            '---',
            '',
            '# '.$cleanTitle,
            '',
            ...($introParagraph !== null ? [$introParagraph, ''] : []),
            ...($multiVariantComparisonTable !== null ? [$multiVariantComparisonTable, ''] : []),
            $parsedMarkdownContent,
            '',
        ]).PHP_EOL;
    }

    /**
     * Editorial Markdown variant for blog posts/guides: title + description +
     * category + freshness frontmatter and the parsed body. Product commerce
     * keys (asin, offer_url, merchant, currency, availability) are deliberately
     * omitted because blog posts carry no buyable entity.
     */
    private function renderBlogArticle(Article $article): string
    {
        $cleanTitle = SEOHelper::cleanTitle($article->title);
        $description = trim((string) $article->meta_description);

        $frontmatter = [
            'title: '.$cleanTitle,
        ];

        if ($description !== '') {
            $frontmatter[] = 'description: '.$description;
        }

        $frontmatter[] = 'type: blog';
        $frontmatter[] = 'category: '.($article->category?->name ?? 'المدونة');
        $frontmatter[] = 'last_updated: '.$article->updated_at->toIso8601String();
        $frontmatter[] = 'url: '.SEOHelper::canonical('blog/'.$article->slug);

        $parser = app(ShortcodeParser::class);
        $parsedMarkdownContent = $parser->parseForMarkdown($article);

        return implode(PHP_EOL, [
            '---',
            ...$frontmatter,
            '---',
            '',
            '# '.$cleanTitle,
            '',
            $parsedMarkdownContent,
            '',
        ]).PHP_EOL;
    }

    /**
     * Markdown variant for the /blog hub: plain index of recent editorial
     * posts so crawlers can enumerate the blog feed without HTML.
     */
    private function renderBlogIndex(): string
    {
        $siteUrl = SEOHelper::url();

        $posts = Article::query()
            ->with('category')
            ->where('type', 'blog')
            ->where('is_published', true)
            ->latest()
            ->limit(50)
            ->get();

        $lines = [
            '---',
            'title: المدونة والمقالات الإرشادية — أمان برايس',
            'description: دلائل ونصائح عملية قبل الشراء من الأمان برايس — محتوى إرشادي غير مربوط بمنتج وحيد.',
            'type: blog-index',
            '---',
            '',
            '# المدونة والمقالات الإرشادية',
            '',
            'دلائل ونصائح عملية قبل الشراء من الأمان برايس — محتوى إرشادي غير مربوط بمنتج وحيد.',
            '',
        ];

        $index = 0;
        foreach ($posts as $post) {
            $index++;
            $lines[] = ($index < 10 ? '0'.$index : (string) $index).'. ['.$post->title.']('.$siteUrl.'/blog/'.$post->slug.')';
        }

        $lines[] = '';
        $lines[] = '## أقسام';
        $lines[] = '- [الرئيسية والمراجعات]('.$siteUrl.'/)';
        $lines[] = '- [llms.txt]('.$siteUrl.'/llms.txt)';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function renderHome(): string
    {
        $siteName = config('app.name', 'أمان برايس');
        $siteUrl = SEOHelper::url();

        $articles = Article::query()
            ->with(['product', 'category'])
            ->where('is_published', true)
            ->latest()
            ->limit(50)
            ->get();

        $lines = [
            '---',
            'title: '.$siteName.' — دليل مراجعات وأسعار الأجهزة في مصر',
            'description: بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر مع حاسبة التقسيط.',
            '---',
            '',
            '# '.$siteName,
            '',
            'بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر، مع حاسبة التقسيط والمقارنات والأسعار المحدثة يوميًا.',
            '',
            '## المقالات والمراجعات',
            '',
        ];

        foreach ($articles as $article) {
            $isBlog = $article->isBlog();
            $lines[] = '- ['.$article->title.']('.$siteUrl.($isBlog ? '/blog/' : '/articles/').$article->slug.')';
        }

        $lines[] = '';
        $lines[] = '## المدونة والمقالات الإرشادية';
        $lines[] = '- ['.$siteName.' المدونة]('.$siteUrl.'/blog)';
        $lines[] = '';
        $lines[] = '## موارد إضافية';
        $lines[] = '- [خريطة الموقع XML]('.$siteUrl.'/sitemap.xml)';
        $lines[] = '- [llms.txt]('.$siteUrl.'/llms.txt)';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
