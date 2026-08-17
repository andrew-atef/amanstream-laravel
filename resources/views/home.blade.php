@php
    $categoryPage ??= false;
    // Clean /category/{slug} pages are indexable Category Hubs. Search, deals,
    // comparisons, legacy `?category=` filtering and pagination stay noindex
    // (near-duplicates of the canonical homepage — don't split index equity).
    $robotsMeta = ($searchQuery !== '' || $dealsOnly || $comparisonsOnly || (! $categoryPage && $selectedCategory !== null) || request()->has('page'))
        ? 'noindex, follow'
        : 'index, follow';

    $brandName = config('app.name', 'أمان برايس');

    // Category hubs get a unique, keyword-targeted <title> + meta description
    // instead of the generic homepage defaults, so each hub competes for its
    // own "category + سعر/مراجعة في مصر" query.
    if ($categoryPage && $selectedCategory) {
        $categoryTitle = \App\Services\SEOHelper::cleanTitle((string) $selectedCategory->name);
        $hubTitle = "سعر {$categoryTitle} وأفضل أنواعه في مصر 2025 | مراجعات وأسعار | {$brandName}";
        $categoryDesc = trim((string) $selectedCategory->description);
        $hubDescription = $categoryDesc !== ''
            ? \Illuminate\Support\Str::limit($categoryDesc, 155)
            : "مراجعات ومقارنات أسعار {$categoryTitle} المحدثة يومياً على أمازون مصر — حاسبة تقسيط ونصائح شراء آمنة من {$brandName}.";
    } elseif ($selectedCategory === null && $searchQuery === '' && ! $dealsOnly) {
        $hubTitle = "{$brandName} | مراجعات وأسعار الأجهزة المنزلية والتكييفات على أمازون مصر";
        $hubDescription = 'بوابتك المباشرة لمراجعة أسعار التكييفات والأجهزة المنزلية على أمازون مصر مع مقارنات يومية وحاسبة التقسيط ونصائح شراء آمنة.';
    }
@endphp

<x-layouts.app
    :robots="$robotsMeta"
    :meta-title="$hubTitle ?? null"
    :meta-description="$hubDescription ?? null"
    :og-title="$hubTitle ?? null"
    :og-description="$hubDescription ?? null"
>
    @push('schema')
        @php
            $siteUrl = \App\Services\SEOHelper::url();
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
        @endphp

        <script type="application/ld+json">{!! json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

        @if ($categoryPage && $selectedCategory)
            @php
                $breadcrumbCategorySchema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => $siteUrl],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $selectedCategory->name, 'item' => route('categories.show', $selectedCategory->slug)],
                    ],
                ];

                // ItemList of the hub's top articles, so the category page can
                // surface as a structured list/collection in the SERP.
                $hubItems = collect($articles->items())
                    ->filter(fn ($article) => filled($article->slug))
                    ->take(10)
                    ->map(function ($article, $index) {
                        return [
                            '@type' => 'ListItem',
                            'position' => $index + 1,
                            'name' => \App\Services\SEOHelper::cleanTitle((string) $article->title),
                            'url' => route('articles.show', $article->slug),
                        ];
                    })
                    ->values()
                    ->all();

                $categoryItemListSchema = count($hubItems) > 0 ? [
                    '@context' => 'https://schema.org',
                    '@type' => 'ItemList',
                    'name' => $selectedCategory->name,
                    'numberOfItems' => count($hubItems),
                    'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
                    'itemListElement' => $hubItems,
                ] : null;
            @endphp
            <script type="application/ld+json">{!! json_encode($breadcrumbCategorySchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
            @if ($categoryItemListSchema)
                <script type="application/ld+json">{!! json_encode($categoryItemListSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
            @endif
        @endif
    @endpush

    <!-- 1. Hero Search Banner -->
    <x-hero-search :search-query="$searchQuery" :deals-only="$dealsOnly" :categories="$categories" :selected-category="$categoryPage ? $selectedCategory : null" />

    <!-- 2. Top Deals Slider -->
    @if ($topDeals->isNotEmpty() && ! $searchQuery && ! $selectedCategory && ! $comparisonsOnly)
        <x-sliders.deals-slider
            :articles="$topDeals"
            title="أكبر التخفيضات اليوم"
            accent="blue"
            leading
            :more-href="route('home', ['deals' => 1])"
        />
    @endif

    <!-- 3. Comparisons Slider -->
    @if ($comparisons->isNotEmpty() && ! $searchQuery && ! $selectedCategory && ! $comparisonsOnly && ! $dealsOnly)
        <x-sliders.deals-slider
            :articles="$comparisons"
            title="مقارنات"
            accent="blue"
            full-titles
            :more-href="route('home', ['comparisons' => 1])"
        />
    @endif

    <!-- 4. Main Layout Grid -->
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
            @if ($searchQuery || $selectedCategory || $comparisonsOnly)
                <div class="flex flex-wrap items-center justify-between rounded-xl border border-primary-100 bg-primary-50 px-4 py-3 text-xs text-primary-900 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold">التصفية النشطة:</span>
                        @if ($selectedCategory)
                            <span class="rounded-lg bg-primary-200/70 px-2.5 py-1 font-extrabold text-primary-900">الفئة: {{ $selectedCategory->name }}</span>
                        @endif
                        @if ($searchQuery)
                            <span class="rounded-lg bg-primary-200/70 px-2.5 py-1 font-extrabold text-primary-900">البحث: "{{ $searchQuery }}"</span>
                        @endif
                        @if ($comparisonsOnly)
                            <span class="rounded-lg bg-primary-600 px-2.5 py-1 font-extrabold text-white">المقارنات</span>
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
                    <span>تم العثور على <strong>{{ $articles->total() }}</strong> {{ $comparisonsOnly ? 'مقارنة محدثة' : 'مراجعة محدثة' }}</span>
                    <span class="text-slate-500">|</span>
                    @php
                        // Deals toggle preserves the current clean path (home or
                        // /category/{slug}) while only flipping the deals flag.
                        $dealsToggleHref = $dealsOnly
                            ? request()->fullUrlWithoutQuery(['deals', 'page', 'comparisons'])
                            : url(request()->path().'?'.http_build_query(array_merge(
                                array_filter(request()->except(['deals', 'page', 'comparisons', 'category'])),
                                ['deals' => 1]
                            )));
                    @endphp
                    @if (! $comparisonsOnly)
                        <a href="{{ $dealsToggleHref }}"
                           class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 font-bold transition {{ $dealsOnly ? 'bg-primary-600 text-white shadow-sm' : 'border border-primary-200 bg-primary-50 text-primary-600 hover:bg-primary-100' }}">
                            العروض فقط
                            @if ($dealsOnly)
                                <span class="mr-0.5">✕</span>
                            @endif
                        </a>
                    @endif
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