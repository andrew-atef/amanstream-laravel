@php
    // Search, category-filter, deals and paginated views are near-duplicates
    // of the canonical homepage — keep crawlers from splitting index equity.
    $isFilteredView = $searchQuery !== '' || $selectedCategory !== null || $dealsOnly || request()->has('page');
    $robotsMeta = $isFilteredView ? 'noindex, follow' : 'index, follow';

    $siteName = config('app.name', 'أمان برايس');

    // ديناميكية العنوان والوصف حسب حالة العرض للاستفادة القصوى من الكلمات المفتاحية
    if (! empty($searchQuery)) {
        $pageTitle = 'نتائج البحث عن «'.$searchQuery.'» | '.$siteName;
        $pageDescription = 'نتائج البحث عن «'.$searchQuery.'» — مقارنات وأسعار محدثة يومياً من أمازون مصر مع حاسبة تقسيط البنوك.';
    } elseif ($selectedCategory !== null) {
        $pageTitle = $selectedCategory->name.' في مصر — أسعار ومقارنات | '.$siteName;
        $pageDescription = 'أسعار ومقارنات '.$selectedCategory->name.' في مصر محدثة يومياً من أمازون مصر، مع مراجعات شفافة وحاسبة تقسيط البنوك.';
    } elseif ($dealsOnly) {
        $pageTitle = 'أقوى عروض وتخفيضات اليوم على أمازون مصر | '.$siteName;
        $pageDescription = 'أقوى عروض وتخفيضات الأجهزة المنزلية والإلكترونيات اليوم في مصر على أمازون مصر، محدثة يومياً بأقل الأسعار.';
    } else {
        $pageTitle = $siteName.' | أسعار ومقارنات ومراجعات الأجهزة المنزلية في مصر';
        $pageDescription = 'أمان برايس — دليل مصري مستقل لمقارنة أسعار ومراجعات الأجهزة المنزلية والإلكترونيات على أمازون مصر، مع تتبع يومي للأسعار وحاسبة تقسيط البنوك.';
    }
@endphp

<x-layouts.app
    :robots="$robotsMeta"
    :meta-title="$pageTitle"
    :meta-description="$pageDescription"
    :og-title="$pageTitle"
    :og-description="$pageDescription"
    :og-url="url('/')"
>
    @push('schema')
        @php
            $siteUrl = url('/');
            $brandName = config('app.name', 'أمان برايس');

            // Schema التعريف بالموقع ومربع البحث الفوري داخل جوجل
            $websiteSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $brandName,
                'alternateName' => 'AmanPrice Egypt',
                'url' => $siteUrl,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => "{$siteUrl}/?q={search_term_string}",
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ];

            // ItemList للمنتجات المعروضة حالياً — يساعد جوجل في فهم محتوى الصفحة
            $productItems = collect();
            foreach ($articles as $article) {
                $p = $article->product;
                if (! $p) {
                    continue;
                }
                $productItems->push([
                    '@type' => 'ListItem',
                    'position' => $productItems->count() + 1,
                    'url' => route('articles.show', $article->slug),
                    'name' => $p->title,
                    'image' => $p->image_url ?: url('/favicon.svg'),
                    'item' => [
                        '@type' => 'Product',
                        'name' => $p->title,
                        'image' => $p->image_url ?: url('/favicon.svg'),
                        'brand' => ['@type' => 'Brand', 'name' => $p->brand ?: $p->title],
                        'sku' => $p->asin,
                        'offers' => [
                            '@type' => 'Offer',
                            'url' => $p->affiliate_url,
                            'priceCurrency' => 'EGP',
                            'price' => number_format((float) $p->price, 2, '.', ''),
                            'availability' => $p->in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        ],
                    ],
                ]);
            }

            $itemListSchema = $productItems->isNotEmpty() ? [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => $pageTitle,
                'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
                'numberOfItems' => $productItems->count(),
                'itemListElement' => $productItems->values()->all(),
            ] : null;
        @endphp

        <script type="application/ld+json">{!! json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @if ($itemListSchema)
            <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @endif
    @endpush

    <!-- 1. Hero Search Banner -->
    <x-hero-search :search-query="$searchQuery" :deals-only="$dealsOnly" />

    <!-- 2. Top Deals Slider -->
    @if ($topDeals->isNotEmpty() && ! $searchQuery && ! $selectedCategory)
        <x-sliders.deals-slider
            :articles="$topDeals"
            title="أكبر التخفيضات اليوم"
            accent="blue"
            leading
        />
    @endif

    <!-- 3. Main Layout Grid -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

        <!-- Sidebar Filters -->
        <aside class="space-y-6 lg:col-span-1">
            <x-filters.category-list :categories="$categories" :total-articles-count="$totalArticlesCount" />

            <!-- Trust Badge Box -->
            <div class="rounded-2xl border border-primary-100 bg-primary-50 p-4 text-xs text-ink/70">
                <div class="mb-1 flex items-center gap-1.5 font-bold text-primary-800">
                    <svg class="h-4 w-4 shrink-0 text-primary-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    دليل شراء موثوق 100%
                </div>
                تحدث الأسعار ومستويات المخزون تلقائياً عبر الربط المباشر مع أمازون مصر.
            </div>
        </aside>

        <!-- Main Feed Column (Kanbakam Grid) -->
        <main class="space-y-4 lg:col-span-3">

            <!-- Active Filters Notification Bar -->
            @if ($searchQuery || $selectedCategory)
                <div class="flex flex-wrap items-center justify-between rounded-xl border border-primary-100 bg-primary-50 px-4 py-3 text-xs text-primary-900 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold">التصفية النشطة:</span>
                        @if ($selectedCategory)
                            <span class="rounded-lg bg-primary-200/70 px-2.5 py-1 font-extrabold text-primary-900">الفئة: {{ $selectedCategory->name }}</span>
                        @endif
                        @if ($searchQuery)
                            <span class="rounded-lg bg-primary-200/70 px-2.5 py-1 font-extrabold text-primary-900">البحث: "{{ $searchQuery }}"</span>
                        @endif
                        @if ($dealsOnly)
                            <span class="rounded-lg bg-primary-600 px-2.5 py-1 font-extrabold text-white">العروض فقط</span>
                        @endif
                    </div>
                    <a href="{{ route('home') }}" class="shrink-0 font-bold text-primary-700 underline hover:text-primary-900">إلغاء التصفية ✕</a>
                </div>
            @endif

            <!-- Results Counter Header -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200/80 bg-white px-4 py-3 text-xs font-semibold text-ink/70 shadow-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span>تم العثور على <strong>{{ $articles->total() }}</strong> مراجعة محدثة</span>
                    <span class="text-slate-500">|</span>
                    <a href="{{ $dealsOnly ? route('home', array_filter(request()->except(['deals']))) : route('home', array_merge(array_filter(request()->only(['q', 'category'])), ['deals' => 1])) }}"
                       class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 font-bold transition {{ $dealsOnly ? 'bg-primary-600 text-white shadow-sm' : 'border border-primary-200 bg-primary-50 text-primary-600 hover:bg-primary-100' }}">
                        العروض فقط
                        @if ($dealsOnly)
                            <span class="mr-0.5">✕</span>
                        @endif
                    </a>
                </div>
                <span class="text-ink/60">ترتيب حسب: الأحدث</span>
            </div>

            <!-- Kanbakam Style Product Grid -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @forelse ($articles as $article)
                <x-cards.product-card :article="$article" />
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-ink/60">
                    <p class="mb-1 text-base font-bold text-ink">لم نجد أي مراجعات مطابقة لبحثك!</p>
                    <p class="mb-4 text-xs text-slate-500">جرب البحث بكلمات أخرى أو اختر فئة مختلفة من القائمة الجانبية.</p>
                    <a href="{{ route('home') }}" class="inline-block rounded-xl bg-primary-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-primary-700">عرض جميع المراجعات المتاحة</a>
                </div>
            @endforelse
            </div>

            <!-- Pagination -->
            <div class="mt-6">
                {{ $articles->links() }}
            </div>

        </main>

    </div>
</x-layouts.app>