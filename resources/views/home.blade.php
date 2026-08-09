<x-layouts.app>
    @push('schema')
        @php
            $siteUrl = url('/');
            $brandName = config('app.name', 'أمان ستريم');

            // Schema التعريف بالموقع ومربع البحث الفوري داخل جوجل
            $websiteSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $brandName,
                'alternateName' => 'AmanStream Egypt',
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
    @endpush

    <!-- 1. Hero Search Banner (Wuzzuf / Kanbakam Style Header) -->
    <section class="-mx-4 -mt-8 mb-8 bg-[#001c38] px-4 py-10 text-white shadow-md sm:-mx-6 sm:px-8 lg:-mx-8">
        <div class="mx-auto max-w-4xl text-center">
            <h1 class="text-2xl font-black leading-tight sm:text-4xl">
                ابحث عن مواصفات وأسعار الأجهزة في مصر
            </h1>
            <p class="mt-2 text-xs text-slate-300 sm:text-sm">
                مقارنات دقيقة، أسعار يومية، وحاسبة التقسيط الأمان على أمازون مصر
            </p>

            <!-- Search Bar -->
            <form action="{{ route('home') }}" method="GET" class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center">
                @if (request('category'))
                    <input type="hidden" name="category" value="{{ request('category') }}">
                @endif
                @if ($dealsOnly)
                    <input type="hidden" name="deals" value="1">
                @endif
                <div class="relative flex-1">
                    <input
                        type="text"
                        name="q"
                        value="{{ $searchQuery }}"
                        placeholder="ابحث باسم الجهاز أو الماركة (مثال: تكييف فريش 1.5 حصان)..."
                        class="w-full rounded-xl border-0 px-4 py-3.5 pl-10 text-sm text-slate-900 shadow-inner focus:ring-2 focus:ring-blue-500"
                    >
                    <svg class="absolute left-3 top-3.5 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <button type="submit" class="rounded-xl bg-blue-600 px-8 py-3.5 text-sm font-bold text-white shadow-md shadow-blue-600/20 transition hover:bg-blue-700">
                    بحث سريع
                </button>
            </form>

            <!-- Trending Pills -->
            <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs text-slate-300">
                <span class="font-bold text-slate-400">الأكثر بحثاً:</span>
                <a href="{{ route('home', ['q' => 'تكييف']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">تكييفات</a>
                <a href="{{ route('home', ['q' => 'فريش']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">منتجات فريش</a>
                <a href="{{ route('home', ['q' => 'شاشة']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">شاشات</a>
                <a href="{{ route('home', ['q' => 'غسالة']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">غسالات</a>
            </div>
        </div>
    </section>

    <!-- 2. Top Deals Slider (Kanbakam Style) -->
    @if ($topDeals->isNotEmpty() && ! $searchQuery && ! $selectedCategory)
        <section class="mb-10">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-lg font-black text-slate-900">
                    <span class="text-xl">🔥</span> أكبر التخفيضات اليوم
                </h2>
                <span class="text-xs font-bold text-slate-500">اسحب للمزيد ←</span>
            </div>

            <div class="no-scrollbar -mx-4 flex gap-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6">
                @foreach ($topDeals as $deal)
                    @php
                        $dealProduct = $deal->product;
                        $dealCurrent = (float) ($dealProduct?->price ?? 0);
                        $dealOriginal = (float) ($dealProduct?->original_price ?? 0);
                        $dealDiscount = $dealOriginal > $dealCurrent && $dealOriginal > 0
                            ? round((($dealOriginal - $dealCurrent) / $dealOriginal) * 100)
                            : 0;

                        $dealUrl = $dealProduct?->affiliate_url ?? '';
                        $dealMerchant = 'amazon';
                        if (str_contains($dealUrl, 'noon.com')) { $dealMerchant = 'noon'; }
                        elseif (str_contains($dealUrl, 'jumia.com')) { $dealMerchant = 'jumia'; }
                    @endphp

                    <article class="group relative flex w-60 shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition duration-200 hover:border-blue-500 hover:shadow-md">
                        <!-- Image & Merchant Badge -->
                        <a href="{{ route('articles.show', $deal->slug) }}" class="relative flex h-44 w-full items-center justify-center rounded-xl bg-white p-2">
                            @if ($dealProduct?->image_url)
                                <img src="{{ $dealProduct->image_url }}" alt="{{ $dealProduct->title }}" loading="lazy" class="max-h-full max-w-full object-contain transition duration-300 group-hover:scale-105">
                            @else
                                <span class="text-xs font-bold text-slate-400">لا توجد صورة</span>
                            @endif

                            @include('partials.merchant-badge', ['merchant' => $dealMerchant, 'brand' => $dealProduct?->brand])
                        </a>

                        <!-- Title -->
                        <h3 class="mt-3 line-clamp-3 text-xs font-bold leading-snug text-slate-800 transition group-hover:text-blue-600">
                            <a href="{{ route('articles.show', $deal->slug) }}">
                                {{ $deal->title }}
                            </a>
                        </h3>

                        <!-- Price & Kanbakam Trend Badge -->
                        <div class="mt-4 flex items-end justify-between border-t border-slate-100 pt-3">
                            <div>
                                <div class="text-base font-black text-slate-900">
                                    {{ number_format($dealCurrent, 0) }} <span class="text-[10px] font-semibold text-slate-500">ج.م</span>
                                </div>
                                @if ($dealDiscount > 0)
                                    <div class="text-[11px] font-semibold text-slate-400 line-through">
                                        {{ number_format($dealOriginal, 0) }}
                                    </div>
                                @endif
                            </div>

                            @if ($dealDiscount > 0)
                                <div class="flex items-center gap-0.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-extrabold text-emerald-700">
                                    <svg class="h-3 w-3 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0v-8m0 8l-8-8-4 4-6-6"/>
                                    </svg>
                                    -{{ $dealDiscount }}%
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <!-- 3. Main Layout Grid -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

        <!-- Sidebar Filters -->
        <aside class="space-y-6 lg:col-span-1">
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h2 class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3 text-xs font-bold text-slate-900">
                    <span>الفئات المتاحة</span>
                    <span class="text-[11px] font-semibold text-slate-500">تصفية تلقائية</span>
                </h2>

                <ul class="space-y-1.5 text-xs font-medium">
                    <!-- All Articles Option -->
                    <li>
                        <a href="{{ route('home', array_filter(request()->only('q'))) }}"
                           class="flex items-center justify-between rounded-xl px-3 py-2.5 transition {{ ! request('category') ? 'bg-blue-50 font-extrabold text-blue-700 border border-blue-100' : 'text-slate-700 hover:bg-slate-50' }}">
                            <span>جميع المراجعات</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ $totalArticlesCount }}</span>
                        </a>
                    </li>

                    <!-- Dynamic Categories from Database -->
                    @foreach ($categories as $cat)
                        <li>
                            <a href="{{ route('home', array_merge(['category' => $cat->slug], array_filter(request()->only('q')))) }}"
                               class="flex items-center justify-between rounded-xl px-3 py-2.5 transition {{ request('category') == $cat->slug ? 'bg-blue-50 font-extrabold text-blue-700 border border-blue-100' : 'text-slate-700 hover:bg-slate-50' }}">
                                <span class="truncate">{{ $cat->name }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ $cat->articles_count }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <!-- Trust Badge Box -->
            <div class="rounded-2xl border border-blue-100 bg-blue-50 p-4 text-xs text-slate-600">
                <div class="mb-1 flex items-center gap-1.5 font-bold text-blue-900">
                    <svg class="h-4 w-4 shrink-0 text-blue-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    دليل شراء موثوق 100%
                </div>
                تحدث الأسعار ومستويات المخزون تلقائياً عبر الربط المباشر مع أمازون مصر.
            </div>
        </aside>

        <!-- Main Feed Column (Kanbakam Grid) -->
        <main class="space-y-4 lg:col-span-3">

            <!-- Active Filters Notification Bar -->
            @if ($searchQuery || $selectedCategory)
                <div class="flex flex-wrap items-center justify-between rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-900 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold">التصفية النشطة:</span>
                        @if ($selectedCategory)
                            <span class="rounded-lg bg-blue-200/70 px-2.5 py-1 font-extrabold text-blue-900">الفئة: {{ $selectedCategory->name }}</span>
                        @endif
                        @if ($searchQuery)
                            <span class="rounded-lg bg-blue-200/70 px-2.5 py-1 font-extrabold text-blue-900">البحث: "{{ $searchQuery }}"</span>
                        @endif
                        @if ($dealsOnly)
                            <span class="rounded-lg bg-red-100 px-2.5 py-1 font-extrabold text-red-700">العروض فقط 🔥</span>
                        @endif
                    </div>
                    <a href="{{ route('home') }}" class="shrink-0 font-bold text-blue-700 underline hover:text-blue-900">إلغاء التصفية ✕</a>
                </div>
            @endif

            <!-- Results Counter Header -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200/80 bg-white px-4 py-3 text-xs font-semibold text-slate-600 shadow-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span>تم العثور على <strong>{{ $articles->total() }}</strong> مراجعة محدثة</span>
                    <span class="text-slate-400">|</span>
                    <a href="{{ $dealsOnly ? route('home', array_filter(request()->except(['deals']))) : route('home', array_merge(array_filter(request()->only(['q', 'category'])), ['deals' => 1])) }}"
                       class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 font-bold transition {{ $dealsOnly ? 'bg-red-600 text-white shadow-sm' : 'border border-red-200 bg-red-50 text-red-600 hover:bg-red-100' }}">
                        🔥 العروض فقط
                        @if ($dealsOnly)
                            <span class="mr-0.5">✕</span>
                        @endif
                    </a>
                </div>
                <span class="text-slate-500">ترتيب حسب: الأحدث</span>
            </div>

            <!-- Kanbakam Style Product Grid -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @forelse ($articles as $article)
                @php
                    $primaryProduct = $article->product;
                    $product = $primaryProduct ?: $article->products->first();
                    $isComparison = ! $primaryProduct && $article->products->count() > 1;
                    $current = (float) ($product?->price ?? 0);
                    $original = (float) ($product?->original_price ?? 0);
                    $hasDiscount = $original > $current && $original > 0;
                    $discountPct = $hasDiscount ? round((($original - $current) / $original) * 100) : 0;

                    $url = $product?->affiliate_url ?? '';
                    $merchant = 'amazon';
                    if (str_contains($url, 'noon.com')) { $merchant = 'noon'; }
                    elseif (str_contains($url, 'jumia.com')) { $merchant = 'jumia'; }
                @endphp

                <article class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-sm transition duration-200 hover:border-blue-500 hover:shadow-md">
                    <!-- Image & Merchant Logo -->
                    <a href="{{ route('articles.show', $article->slug) }}" class="relative flex h-40 w-full items-center justify-center rounded-xl bg-white p-2">
                        @if ($product?->image_url)
                            <img src="{{ $product->image_url }}" alt="{{ $product->title ?: $article->title }}" loading="lazy" class="max-h-full max-w-full object-contain transition duration-300 group-hover:scale-105">
                        @else
                            <span class="text-xs font-bold text-slate-400">لا توجد صورة</span>
                        @endif

                        @if ($isComparison)
                            <span class="absolute left-1 top-1 rounded-lg border border-purple-200 bg-white/95 px-2 py-0.5 text-[10px] font-extrabold text-purple-700 shadow-sm backdrop-blur">⚖️ مقارنة</span>
                        @endif

                        @include('partials.merchant-badge', ['merchant' => $merchant, 'brand' => $product?->brand])
                    </a>

                    <!-- Title -->
                    <h2 class="mt-3 line-clamp-2 text-xs font-bold leading-snug text-slate-800 transition group-hover:text-blue-600">
                        <a href="{{ route('articles.show', $article->slug) }}">
                            {{ $article->title }}
                        </a>
                    </h2>

                    <!-- Specs Pills -->
                    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-slate-500">
                        @if ($product)
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-slate-700">⭐ {{ number_format((float) $product->rating, 1) }}</span>
                            <span class="rounded border border-blue-100 bg-blue-50 px-2 py-0.5 font-bold text-blue-700">تقسيط 0%</span>
                        @endif
                    </div>

                    <!-- Kanbakam Price Area & Trend Badge -->
                    <div class="mt-auto flex items-end justify-between border-t border-slate-100 pt-3">
                        <div>
                            <div class="text-lg font-black text-slate-900">
                                {{ number_format($current, 0) }} <span class="text-[10px] font-semibold text-slate-500">ج.م</span>
                            </div>
                            @if ($hasDiscount)
                                <div class="text-[11px] font-semibold text-slate-400 line-through">
                                    {{ number_format($original, 0) }}
                                </div>
                            @endif
                        </div>

                        <!-- Kanbakam Trend / Discount Badge -->
                        @if ($hasDiscount)
                            <div class="flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-extrabold text-emerald-700">
                                <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0v-8m0 8l-8-8-4 4-6-6"/>
                                </svg>
                                -{{ $discountPct }}%
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">
                    <p class="mb-1 text-base font-bold text-slate-700">لم نجد أي مراجعات مطابقة لبحثك!</p>
                    <p class="mb-4 text-xs text-slate-500">جرب البحث بكلمات أخرى أو اختر فئة مختلفة من القائمة الجانبية.</p>
                    <a href="{{ route('home') }}" class="inline-block rounded-xl bg-blue-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-blue-700">عرض جميع المراجعات المتاحة</a>
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