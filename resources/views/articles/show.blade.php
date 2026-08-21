@php
    // OpenGraph/Twitter/Discover: use the article's primary image (custom
    // featured cover → primary product → first compared product). The favicon
    // brand fallback is never used as an OG/Twitter image — it degrades to
    // null so the layout still emits the branded 1200x630 og-image.png.
    $primaryImage = $article->primary_image_url;
    if (str_ends_with($primaryImage, 'favicon.svg')) {
        $primaryImage = null;
    }
@endphp

<x-layouts.app
    :meta-title="isset($article) ? \App\Services\SEOHelper::cleanTitle($article->meta_title ?: $article->title) : null"
    :meta-description="$article->meta_description"
    :og-title="isset($article) ? \App\Services\SEOHelper::cleanTitle($article->title) : null"
    :og-description="$article->meta_description"
    :og-image="$primaryImage"
    og-type="article"
>
    @push('head_meta')
        @if ($product && (float) $product->price > 0)
            <meta property="product:price:amount" content="{{ number_format((float) $product->price, 2, '.', '') }}">
            <meta property="product:price:currency" content="EGP">
            <meta property="product:availability" content="{{ $product->in_stock ? 'in stock' : 'out of stock' }}">
            <meta property="product:condition" content="new">
            <meta property="product:retailer" content="Amazon Egypt">
            <meta property="product:brand" content="{{ $product->brand ?: 'أمازون مصر' }}">
            <meta property="product:retailer_item_id" content="{{ $product->asin }}">
        @endif
    @endpush

    @push('schema')
        @php
            $seoHelper = \App\Services\SEOHelper::class;
            $pageUrl = $seoHelper::canonical('articles/'.$article->slug);
            $siteUrl = $seoHelper::url();
            $cleanContent = $seoHelper::renderDynamicYear(\App\Services\ShortcodeParser::stripShortcodes($article->content));
            $schemaDescription = $article->meta_description ?: Str::limit(strip_tags($cleanContent), 300);
            $shortDescription = $article->meta_description ?: Str::limit(strip_tags($cleanContent), 160);
            $cleanArticleTitle = $seoHelper::cleanTitle($article->title);

            $isListicle = $article->articleProducts->whereNotNull('product_id')->count() >= 2;

            // 1. Build Single Product Node (For Dedicated Review Tier 3)
            $buildSingleProductNode = function (App\Models\Product $p) use ($schemaDescription, $siteUrl, $seoHelper): array {
                $title = $seoHelper::cleanTitle((string) $p->title);
                $rating = (float) $p->rating;
                $reviewCount = (int) $p->review_count;
                $cleanAffiliateUrl = $seoHelper::cleanAffiliateUrl((string) $p->affiliate_url, (string) $p->asin);

                $node = [
                    '@type' => 'Product',
                    'name' => $title,
                    'image' => $p->image_url ?: $siteUrl.'/favicon.svg',
                    'description' => $schemaDescription,
                    'brand' => ['@type' => 'Brand', 'name' => $p->brand ?: $title],
                    'sku' => $p->asin,
                    'mpn' => $p->asin,
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => $cleanAffiliateUrl,
                        'priceCurrency' => 'EGP',
                        'price' => number_format((float) $p->price, 2, '.', ''),
                        'priceValidUntil' => now()->addDays(7)->format('Y-m-d'),
                        'availability' => $p->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        'itemCondition' => 'https://schema.org/NewCondition',
                        'seller' => [
                            '@type' => 'Organization',
                            'name' => 'أمازون مصر',
                        ],
                    ],
                ];

                if ($rating > 0 && $reviewCount > 0) {
                    $node['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => number_format($rating, 1, '.', ''),
                        'reviewCount' => $reviewCount,
                        'bestRating' => 5,
                        'worstRating' => 1,
                    ];
                }

                return $node;
            };

            // 2. Build Clean Listicle Item Node (Zero Merchant Conflict)
            $buildListicleItemNode = function (App\Models\Product $p) use ($schemaDescription, $siteUrl, $seoHelper): array {
                $title = $seoHelper::cleanTitle((string) $p->title);
                $rating = (float) $p->rating;
                $reviewCount = (int) $p->review_count;
                $cleanAffiliateUrl = $seoHelper::cleanAffiliateUrl((string) $p->affiliate_url, (string) $p->asin);

                $node = [
                    '@type' => 'Product',
                    'name' => $title,
                    'image' => $p->image_url ?: $siteUrl.'/favicon.svg',
                    'description' => Str::limit($schemaDescription, 200),
                    'brand' => ['@type' => 'Brand', 'name' => $p->brand ?: $title],
                    'sku' => $p->asin,
                    'mpn' => $p->asin,
                    'offers' => [
                        '@type' => 'Offer',
                        'url' => $cleanAffiliateUrl,
                        'priceCurrency' => 'EGP',
                        'price' => number_format((float) $p->price, 2, '.', ''),
                        'priceValidUntil' => now()->addDays(7)->format('Y-m-d'),
                        'availability' => $p->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        'seller' => [
                            '@type' => 'Organization',
                            'name' => 'أمازون مصر',
                        ],
                    ],
                ];

                if ($rating > 0 && $reviewCount > 0) {
                    $node['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => number_format($rating, 1, '.', ''),
                        'reviewCount' => $reviewCount,
                        'bestRating' => 5,
                        'worstRating' => 1,
                    ];
                }

                return $node;
            };

            // Standalone Product Schema (ONLY when single product review)
            $productSchema = (! $isListicle && $product) ? [
                '@context' => 'https://schema.org',
                ...$buildSingleProductNode($product),
            ] : null;

            // ItemList Schema for Listicles & Comparisons
            $listicleItems = $article->articleProducts
                ->sortBy('sort_order')
                ->filter(fn ($row) => $row->product !== null)
                ->values();

            $itemListSchema = $isListicle ? [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => $cleanArticleTitle,
                'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
                'numberOfItems' => $listicleItems->count(),
                'itemListElement' => $listicleItems->map(function ($row, $index) use ($buildListicleItemNode, $pageUrl): array {
                    $p = $row->product;
                    $title = \App\Services\SEOHelper::cleanTitle((string) $p->title);
                    return [
                        '@type' => 'ListItem',
                        'position' => $index + 1,
                        'name' => $title,
                        'url' => $pageUrl . '#product-' . ($index + 1),
                        'image' => $p->image_url ?: \App\Services\SEOHelper::url('favicon.svg'),
                        'item' => $buildListicleItemNode($p),
                    ];
                })->values()->all(),
            ] : null;

            // Article Schema (Universal)
            $articleImageUrl = $primaryImage ?: $siteUrl.'/favicon.svg';
            $brandName = config('app.name', 'أمان برايس');

            $articleSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $pageUrl],
                'headline' => $cleanArticleTitle,
                'description' => $shortDescription,
                'image' => [$articleImageUrl],
                'datePublished' => $article->created_at?->toIso8601String(),
                'dateModified' => $article->updated_at?->toIso8601String(),
                'inLanguage' => 'ar-EG',
                'author' => [
                    '@type' => 'Organization',
                    'name' => $brandName,
                    'url' => $siteUrl,
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $brandName,
                    'url' => $siteUrl,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => $siteUrl.'/favicon.svg',
                    ],
                ],
            ];

            if ($isListicle && $listicleItems->isNotEmpty()) {
                $articleSchema['about'] = $listicleItems->map(fn ($row) => $buildListicleItemNode($row->product))->values()->all();
            }

            $categoryName = $article->category?->name ?? 'مقالات';
            $categoryUrl = $article->category ? route('categories.show', $article->category->slug) : $siteUrl;

            $breadcrumbSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => $siteUrl],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $categoryName, 'item' => $categoryUrl],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $cleanArticleTitle, 'item' => $pageUrl],
                ],
            ];

            $faqQuestions = $article->getFaqSchemaData();
            $faqPageSchema = ! empty($faqQuestions) ? [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $faqQuestions,
            ] : null;
        @endphp

        @if ($productSchema)
            <script type="application/ld+json">{!! json_encode(array_filter($productSchema), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @endif

        <script type="application/ld+json">{!! json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @if ($itemListSchema)
            <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @endif
        @if (! empty($faqPageSchema))
            <script type="application/ld+json">{!! json_encode($faqPageSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @endif
    @endpush

    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
        <nav class="mb-6 text-sm text-slate-500" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1">
                <li><a href="/" class="hover:text-primary-600">الرئيسية</a></li>
                @if ($article->category)
                    <li><span aria-hidden="true">/</span> <a href="{{ route('categories.show', $article->category->slug) }}" class="hover:text-primary-600">{{ $article->category->name }}</a></li>
                @endif
                <li aria-current="page"><span aria-hidden="true">/</span> <span class="text-ink">{{ \App\Services\SEOHelper::cleanTitle($article->title) }}</span></li>
            </ol>
        </nav>

        <header class="mb-8 border-b border-slate-100 pb-6">
            @if (filled($article->getRawOriginal('featured_image_url')))
                <div class="mb-6 overflow-hidden rounded-2xl border border-slate-100 bg-slate-50">
                    <img src="{{ $article->primary_image_url }}" alt="{{ \App\Services\SEOHelper::cleanTitle($article->title) }}" width="1200" height="675" fetchpriority="high" decoding="async" class="aspect-[16/9] w-full object-cover">
                </div>
            @endif

            <h1 class="text-3xl font-black leading-snug text-ink sm:text-4xl">{{ \App\Services\SEOHelper::cleanTitle($article->title) }}</h1>

            <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs font-medium sm:text-sm">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-50 border border-primary-200 px-3 py-1 text-primary-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    آخر تحديث للسعر والمواصفات: {{ $article->updated_at?->format('Y/m/d') ?: now()->format('Y/m/d') }}
                </span>
                <span class="inline-flex items-center gap-1.5 text-slate-500">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                    مراجعة: فريق {{ config('app.name', 'أمان برايس') }}
                </span>
            </div>

            @if ($product)
                <div class="mt-6 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-1 items-center gap-4">
                        @if ($product->image_url)
                            <img
                                src="{{ $product->image_url }}"
                                alt="{{ \App\Services\SEOHelper::cleanTitle($product->title) }}"
                                width="80"
                                height="80"
                                fetchpriority="high"
                                decoding="async"
                                class="h-20 w-20 shrink-0 rounded-xl border border-slate-100 bg-white object-contain p-1"
                            >
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-ink">{{ \App\Services\SEOHelper::cleanTitle($product->title) }}</div>
                            <div class="mt-1 text-sm text-slate-500">
                                {{ $product->brand }} · ASIN: {{ $product->asin }}
                            </div>
                            @php
                                $showPrice = (float) $product->price;
                                $showOriginal = (float) ($product->original_price ?? 0);
                                $hasDiscount = $showOriginal > $showPrice && $showOriginal > 0;
                            @endphp
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-2xl font-black text-primary-700">
                                @if ($hasDiscount)
                                    <span class="text-base font-semibold text-slate-500 line-through">{{ number_format($showOriginal, 2) }} ج.م</span>
                                @endif
                                <span>{{ number_format($showPrice, 2) }} ج.م</span>
                                @if ($hasDiscount)
                                    <span class="rounded-md bg-primary-50 px-2 py-0.5 text-xs font-bold text-primary-700">
                                        خصم {{ round((($showOriginal - $showPrice) / $showOriginal) * 100) }}%
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if ($product->in_stock)
                        <a
                            href="{{ $product->affiliate_url }}"
                            target="_blank"
                            rel="nofollow sponsored noopener"
                            class="inline-flex h-11 w-full shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 px-6 text-sm font-bold text-white shadow-lg shadow-primary-600/30 transition-all hover:bg-primary-700 hover:shadow-xl active:scale-95 sm:w-auto"
                        >
                            <span class="flex items-center justify-center rounded-md bg-white px-2 py-1">
                                <img src="/icons/amazon.png" alt="Amazon" width="80" height="24" loading="lazy" class="h-4 w-auto object-contain">
                            </span>
                            اشترِ الآن
                        </a>
                    @else
                        <span class="inline-flex h-11 w-full shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-slate-100 border border-slate-200 px-6 text-sm font-bold text-slate-500 cursor-not-allowed sm:w-auto">
                            غير متوفر حالياً في أمازون مصر
                        </span>
                    @endif
                </div>
            @endif
        </header>

        @if ($product)
            <div class="buy-bar-sentinel" aria-hidden="true"></div>
        @endif

        <div class="article-content mx-auto max-w-none space-y-6 text-lg leading-8 text-ink/80">
            {!! $parsedContent !!}
        </div>
    </article>

    <!-- SLIDER 1: منتجات ومراجعات من نفس القسم -->
    <x-sliders.deals-slider
        :articles="$relatedArticles"
        title="منتجات ومراجعات من نفس القسم ({{ $article->category?->name ?: 'التصنيف' }})"
        accent="blue"
        :more-href="$article->category ? route('categories.show', $article->category->slug) : route('home', ['deals' => 1])"
    />

    <!-- SLIDER 2: أقوى عروض وتخفيضات اليوم في مصر -->
    <x-sliders.deals-slider
        :articles="$topDeals"
        title="أقوى عروض وتخفيضات اليوم على أمازون مصر"
        accent="red"
        :more-href="route('home', ['deals' => 1])"
    />

    @if ($product)
        <div
            id="sticky-buy-bar"
            class="fixed inset-x-0 bottom-0 z-40 hidden border-t border-slate-200 bg-white/95 px-4 pb-4 pt-3 shadow-[0_-4px_20px_rgba(0,0,0,0.08)] backdrop-blur lg:hidden"
        >
            <div class="mx-auto flex max-w-5xl items-center gap-3">
                <div class="flex min-w-0 flex-1 items-center gap-2.5">
                    @if ($product->image_url)
                        <img
                            src="{{ $product->image_url }}"
                            alt="{{ \App\Services\SEOHelper::cleanTitle($product->title) }}"
                            width="40"
                            height="40"
                            loading="lazy"
                            class="h-10 w-10 shrink-0 rounded-lg border border-slate-100 bg-white object-contain p-0.5"
                        >
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-bold text-ink">{{ \App\Services\SEOHelper::cleanTitle($product->title) }}</div>
                        <div class="truncate">
                            @if ($hasDiscount)
                                <span class="text-xs font-semibold text-slate-500 line-through">{{ number_format($showOriginal, 2) }} ج.م</span>
                                <span class="mx-1"></span>
                            @endif
                            <span class="text-xl font-black text-primary-700">{{ number_format($showPrice, 2) }} ج.م</span>
                        </div>
                    </div>
                </div>
                @if ($product->in_stock)
                    <a
                        href="{{ $product->affiliate_url }}"
                        target="_blank"
                        rel="nofollow sponsored noopener"
                        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 px-6 text-sm font-bold text-white shadow-lg shadow-primary-600/30 transition-all hover:bg-primary-700 hover:shadow-xl active:scale-95"
                    >
                        <span class="flex items-center justify-center rounded-md bg-white px-2 py-1">
                            <img src="/icons/amazon.png" alt="Amazon" width="80" height="24" loading="lazy" class="h-4 w-auto object-contain">
                        </span>
                        <span>اشترِ الآن</span>
                    </a>
                @else
                    <span class="inline-flex h-11 shrink-0 items-center justify-center whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 px-6 text-sm font-bold text-slate-500 cursor-not-allowed">
                        غير متوفر حالياً
                    </span>
                @endif
            </div>
        </div>

        <script>
            (function () {
                var bar = document.getElementById('sticky-buy-bar');
                var sentinel = document.querySelector('.buy-bar-sentinel');
                if (! bar || ! sentinel || typeof IntersectionObserver === 'undefined') return;

                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        bar.classList.toggle('flex', ! entry.isIntersecting);
                        bar.classList.toggle('hidden', entry.isIntersecting);
                    });
                });

                observer.observe(sentinel);
            })();
        </script>
    @endif
</x-layouts.app>