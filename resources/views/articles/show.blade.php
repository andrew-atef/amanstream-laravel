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

            // 1. Build Standalone Product Node (For Dedicated Review Tier 3 ONLY)
            $buildSingleProductNode = function (App\Models\Product $p) use ($schemaDescription, $siteUrl, $seoHelper, $pageUrl): array {
                $title = $seoHelper::cleanTitle((string) $p->title);
                $rating = (float) $p->rating;
                $reviewCount = (int) $p->review_count;
                $price = (float) $p->price;
                $originalPrice = (float) ($p->original_price ?? 0);
                $hasDiscount = $originalPrice > $price && $originalPrice > 0;
                $goUrl = $seoHelper::goUrl((string) $p->asin);

                $offerData = [
                    '@type' => 'Offer',
                    'url' => $goUrl,
                    'priceCurrency' => 'EGP',
                    'price' => number_format($price, 2, '.', ''),
                    'availability' => $p->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => [
                        '@type' => 'Organization',
                        'name' => 'أمازون مصر',
                    ],
                    'validFrom' => now()->subDays(1)->toIso8601String(),
                    'priceValidUntil' => now()->addDays(7)->format('Y-m-d'),
                    'shippingDetails' => [
                        '@type' => 'OfferShippingDetails',
                        'shippingDestination' => [
                            '@type' => 'DefinedRegion',
                            'addressCountry' => 'EG',
                        ],
                        'deliveryTime' => [
                            '@type' => 'ShippingDeliveryTime',
                            'handlingTime' => [
                                '@type' => 'QuantitativeValue',
                                'minValue' => 0,
                                'maxValue' => 1,
                                'unitCode' => 'DAY',
                            ],
                            'transitTime' => [
                                '@type' => 'QuantitativeValue',
                                'minValue' => 1,
                                'maxValue' => 5,
                                'unitCode' => 'DAY',
                            ],
                        ],
                    ],
                    'hasMerchantReturnPolicy' => [
                        '@type' => 'MerchantReturnPolicy',
                        'applicableCountry' => 'EG',
                        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays' => 14,
                        'returnFees' => 'https://schema.org/FreeReturn',
                    ],
                ];

                if ($hasDiscount) {
                    $offerData['priceSpecification'] = [
                        '@type' => 'UnitPriceSpecification',
                        'priceType' => 'https://schema.org/StrikethroughPrice',
                        'price' => number_format($originalPrice, 2, '.', ''),
                        'priceCurrency' => 'EGP',
                    ];
                }

                $node = [
                    '@type' => 'Product',
                    'name' => $title,
                    'url' => $pageUrl,
                    'image' => $p->image_url ?: $siteUrl.'/favicon.svg',
                    'description' => $schemaDescription,
                    'brand' => ['@type' => 'Brand', 'name' => $p->brand ?: $title],
                    'sku' => $p->asin,
                    'mpn' => $p->asin,
                    'offers' => $offerData,
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

            // 2. Build Nested ItemList Product (Clean and Google Rich Results Compliant)
            $buildListItemProduct = function (App\Models\Product $p, int $index) use ($schemaDescription, $siteUrl, $seoHelper, $pageUrl): array {
                $title = $seoHelper::cleanTitle((string) $p->title);
                $rating = (float) $p->rating;
                $reviewCount = (int) $p->review_count;
                $price = (float) $p->price;
                $originalPrice = (float) ($p->original_price ?? 0);
                $hasDiscount = $originalPrice > $price && $originalPrice > 0;
                $goUrl = $seoHelper::goUrl((string) $p->asin);

                $offerData = [
                    '@type' => 'Offer',
                    'url' => $goUrl,
                    'priceCurrency' => 'EGP',
                    'price' => number_format($price, 2, '.', ''),
                    'availability' => $p->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => [
                        '@type' => 'Organization',
                        'name' => 'أمازون مصر',
                    ],
                    'validFrom' => now()->subDays(1)->toIso8601String(),
                    'priceValidUntil' => now()->addDays(7)->format('Y-m-d'),
                    'shippingDetails' => [
                        '@type' => 'OfferShippingDetails',
                        'shippingDestination' => [
                            '@type' => 'DefinedRegion',
                            'addressCountry' => 'EG',
                        ],
                        'deliveryTime' => [
                            '@type' => 'ShippingDeliveryTime',
                            'handlingTime' => [
                                '@type' => 'QuantitativeValue',
                                'minValue' => 0,
                                'maxValue' => 1,
                                'unitCode' => 'DAY',
                            ],
                            'transitTime' => [
                                '@type' => 'QuantitativeValue',
                                'minValue' => 1,
                                'maxValue' => 5,
                                'unitCode' => 'DAY',
                            ],
                        ],
                    ],
                    'hasMerchantReturnPolicy' => [
                        '@type' => 'MerchantReturnPolicy',
                        'applicableCountry' => 'EG',
                        'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays' => 14,
                        'returnFees' => 'https://schema.org/FreeReturn',
                    ],
                ];

                if ($hasDiscount) {
                    $offerData['priceSpecification'] = [
                        '@type' => 'UnitPriceSpecification',
                        'priceType' => 'https://schema.org/StrikethroughPrice',
                        'price' => number_format($originalPrice, 2, '.', ''),
                        'priceCurrency' => 'EGP',
                    ];
                }

                $productNode = [
                    '@type' => 'Product',
                    'name' => $title,
                    'url' => $pageUrl . '#product-' . $index,
                    'image' => $p->image_url ?: $siteUrl.'/favicon.svg',
                    'description' => Str::limit($schemaDescription, 200),
                    'brand' => ['@type' => 'Brand', 'name' => $p->brand ?: $title],
                    'sku' => $p->asin,
                    'mpn' => $p->asin,
                    'offers' => $offerData,
                ];

                if ($rating > 0 && $reviewCount > 0) {
                    $productNode['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => number_format($rating, 1, '.', ''),
                        'reviewCount' => $reviewCount,
                        'bestRating' => 5,
                        'worstRating' => 1,
                    ];
                }

                return [
                    '@type' => 'ListItem',
                    'position' => $index,
                    'item' => $productNode,
                ];
            };

            // Standalone Product Schema (Tier 3 Single Reviews ONLY)
            $productSchema = (! $isListicle && $product) ? [
                '@context' => 'https://schema.org',
                ...$buildSingleProductNode($product),
            ] : null;

            // ItemList Schema (Tier 1 & 2 Listicles and Comparisons)
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
                'itemListElement' => $listicleItems->map(function ($row, $index) use ($buildListItemProduct): array {
                    return $buildListItemProduct($row->product, $index + 1);
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

            @php
                // Hero pricing vars — used in both hero card and sticky bar
                $showPrice = $product ? (float) $product->price : 0;
                $showOriginal = $product ? (float) ($product->original_price ?? 0) : 0;
                $hasDiscount = $product ? ($showOriginal > $showPrice && $showOriginal > 0) : false;
                // Universal sticky product: single product OR cheapest of comparison
                $stickyProduct = $product;
                $isComparisonSticky = false;
                if (! $stickyProduct && $article->isComparison()) {
                    $stickyProduct = $article->products->where('in_stock', true)->sortBy('price')->first()
                        ?? $article->products->first();
                    $isComparisonSticky = $stickyProduct !== null;
                    if ($isComparisonSticky) {
                        $showPrice = (float) $article->getLowestVariantPrice();
                        $showOriginal = (float) $stickyProduct->original_price;
                        // For comparison, recompute discount vs cheapest original
                        $hasDiscount = false;
                    }
                }
            @endphp

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
                            href="{{ \App\Services\SEOHelper::goUrl((string) $product->asin) }}"
                            target="_blank"
                            rel="nofollow sponsored noopener"
                            class="inline-flex h-12 w-full shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 px-6 text-sm font-bold text-white no-underline shadow-md shadow-primary-600/25 transition-all hover:bg-primary-700 hover:shadow-lg hover:no-underline active:scale-95 sm:h-11 sm:w-auto"
                        >
                            <span class="flex shrink-0 items-center justify-center rounded bg-white px-1.5 py-0.5">
                                <img src="/icons/amazon.svg" alt="Amazon" width="24" height="24" loading="lazy" class="h-4 w-auto object-contain">
                            </span>
                            اشترِ الآن
                        </a>
                    @else
                        <span class="inline-flex h-12 w-full shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 px-5 text-xs font-bold text-slate-500 cursor-not-allowed sm:h-11 sm:w-auto">
                            غير متوفر حالياً في أمازون مصر
                        </span>
                    @endif
                </div>
            @endif
        </header>

        <div class="buy-bar-sentinel h-px w-full" aria-hidden="true"></div>

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

    @php
        // Resolve universal sticky product for both single & comparison
        $stickyProductForBar = $product ?? null;
        $stickyIsComparison = false;
        $stickyPrice = $product ? (float) $product->price : 0;
        $stickyHasDiscount = $product ? ((float) ($product->original_price ?? 0) > $stickyPrice) : false;
        $stickyOriginal = $product ? (float) ($product->original_price ?? 0) : 0;
        if (! $stickyProductForBar && $article->isComparison()) {
            $stickyProductForBar = $article->products->where('in_stock', true)->sortBy('price')->first() ?? $article->products->first();
            if ($stickyProductForBar) {
                $stickyIsComparison = true;
                $stickyPrice = (float) $article->getLowestVariantPrice();
                $stickyOriginal = (float) $stickyProductForBar->original_price;
                $stickyHasDiscount = false;
            }
        }
    @endphp

    @if ($stickyProductForBar)
        <div
            id="sticky-buy-bar"
            class="fixed bottom-0 left-0 right-0 z-50 hidden w-full max-w-[100vw] box-border overflow-hidden border-t border-slate-200 bg-white/95 px-2 py-2.5 pb-[calc(0.6rem+env(safe-area-inset-bottom))] shadow-[0_-4px_20px_rgba(0,0,0,0.08)] backdrop-blur sm:px-4 lg:hidden"
        >
            <div class="mx-auto flex w-full max-w-6xl items-center gap-1.5 overflow-hidden sm:gap-3">
                <div class="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden sm:gap-2.5">
                    @if ($stickyProductForBar->image_url)
                        <img
                            src="{{ $stickyProductForBar->image_url }}"
                            alt="{{ \App\Services\SEOHelper::cleanTitle($stickyProductForBar->title) }}"
                            width="40"
                            height="40"
                            loading="lazy"
                            class="h-8 w-8 shrink-0 rounded-lg border border-slate-100 bg-white object-contain p-0.5 sm:h-10 sm:w-10"
                        >
                    @endif
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <div class="truncate text-[10px] font-bold leading-tight text-ink sm:text-xs">{{ \App\Services\SEOHelper::cleanTitle($stickyProductForBar->title) }}</div>
                        <div class="flex min-w-0 items-center gap-1 overflow-hidden">
                            @if ($stickyIsComparison)
                                <span class="shrink-0 text-[11px] font-bold text-slate-500 sm:text-sm">تبدأ من</span>
                                <span class="truncate text-sm font-black text-primary-700 sm:text-xl">{{ number_format($stickyPrice, 2) }} ج.م</span>
                            @else
                                @if ($stickyHasDiscount)
                                    <span class="hidden shrink-0 text-[11px] font-semibold text-slate-500 line-through sm:inline">{{ number_format($stickyOriginal, 2) }} ج.م</span>
                                @endif
                                <span class="truncate text-sm font-black text-primary-700 sm:text-xl">{{ number_format($stickyPrice, 2) }} ج.م</span>
                            @endif
                        </div>
                    </div>
                </div>
                @if ($stickyProductForBar->in_stock)
                    <a
                        href="{{ \App\Services\SEOHelper::goUrl((string) $stickyProductForBar->asin) }}"
                        target="_blank"
                        rel="nofollow sponsored noopener"
                        class="inline-flex h-10 shrink-0 items-center justify-center gap-1 whitespace-nowrap rounded-xl bg-primary-600 px-3 text-[11px] font-bold text-white no-underline shadow-md shadow-primary-600/25 transition-all hover:bg-primary-700 hover:shadow-lg hover:no-underline active:scale-95 sm:h-12 sm:gap-2 sm:px-6 sm:text-sm"
                    >
                        <span class="flex shrink-0 items-center justify-center rounded bg-white px-1 py-0.5 sm:px-1.5">
                            <img src="/icons/amazon.svg" alt="Amazon" width="20" height="20" loading="lazy" class="h-3 w-auto object-contain sm:h-4">
                        </span>
                        <span>{{ $stickyIsComparison ? 'عرض الموديلات' : 'اشترِ الآن' }}</span>
                    </a>
                @else
                    <span class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 px-3 text-[11px] font-bold text-slate-500 cursor-not-allowed sm:h-12 sm:px-5 sm:text-xs">
                        غير متوفر حالياً
                    </span>
                @endif
            </div>
        </div>

        <div id="sticky-buy-spacer" class="h-20 lg:hidden" aria-hidden="true"></div>

        <script>
            (function () {
                var bar = document.getElementById('sticky-buy-bar');
                var sentinel = document.querySelector('.buy-bar-sentinel');
                if (!bar) return;
                var show = function(){ bar.classList.remove('hidden'); bar.classList.add('flex'); };
                var hide = function(){ bar.classList.add('hidden'); bar.classList.remove('flex'); };
                var onScrollFallback = function(){
                    if (window.scrollY > 350) show(); else hide();
                };
                if (sentinel && 'IntersectionObserver' in window) {
                    var obs = new IntersectionObserver(function(entries){
                        entries.forEach(function(e){ e.isIntersecting ? hide() : show(); });
                    }, {threshold:0, rootMargin:'0px'});
                    obs.observe(sentinel);
                    // Safety net: if JS loads with sentinel already out of view on mobile, force show after 600ms
                    setTimeout(function(){
                        if (sentinel.getBoundingClientRect().bottom < 0) show();
                    }, 600);
                } else {
                    window.addEventListener('scroll', onScrollFallback, {passive:true});
                    onScrollFallback();
                }
                // Additional safety: scroll listener always ensures visibility even if observer fails
                window.addEventListener('scroll', function(){
                    if (!sentinel) { if (window.scrollY > 200) show(); return; }
                    // If observer missing or stuck, use scrollY threshold
                    if (bar.classList.contains('hidden') && window.scrollY > 400) show();
                }, {passive:true});
            })();
        </script>
    @endif
</x-layouts.app>