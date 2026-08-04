<x-layouts.app>
    <!-- 1. Hero Search Banner (Wuzzuf Style Header) -->
    <section class="-mx-4 -mt-8 mb-8 bg-[#001c38] px-4 py-10 text-white shadow-md sm:-mx-6 sm:px-8 lg:-mx-8">
        <div class="mx-auto max-w-4xl text-center">
            <h1 class="text-2xl font-black leading-tight sm:text-4xl">
                ابحث عن مواصفات وأسعار الأجهزة في مصر
            </h1>
            <p class="mt-2 text-xs text-slate-300 sm:text-sm">
                مقارنات دقيقة، أسعار يومية، وحاسبة التقسيط الأمان على أمازون مصر
            </p>

            <!-- Wuzzuf Search Bar -->
            <form action="{{ route('home') }}" method="GET" class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center">
                @if (request('category'))
                    <input type="hidden" name="category" value="{{ request('category') }}">
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

            <!-- Trending Pills (Quick Searches) -->
            <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs text-slate-300">
                <span class="font-bold text-slate-400">الأكثر بحثاً:</span>
                <a href="{{ route('home', ['q' => 'تكييف']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">تكييفات</a>
                <a href="{{ route('home', ['q' => 'فريش']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">منتجات فريش</a>
                <a href="{{ route('home', ['q' => 'شاشة']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">شاشات</a>
                <a href="{{ route('home', ['q' => 'غسالة']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">غسالات</a>
            </div>
        </div>
    </section>

    <!-- 2. Main Layout (Wuzzuf Grid) -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

        <!-- Sidebar Filters (Dynamic Categories) -->
        <aside class="space-y-6 lg:col-span-1">
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <h3 class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3 text-xs font-bold text-slate-900">
                    <span>الفئات المتاحة</span>
                    <span class="text-[11px] font-semibold text-slate-400">تصفية تلقائية</span>
                </h3>

                <ul class="space-y-1.5 text-xs font-medium">
                    <!-- All Articles Option -->
                    <li>
                        <a href="{{ route('home', request()->only('q')) }}"
                           class="flex items-center justify-between rounded-xl px-3 py-2.5 transition {{ ! request('category') ? 'bg-blue-50 font-extrabold text-blue-700 border border-blue-100' : 'text-slate-700 hover:bg-slate-50' }}">
                            <span>جميع المراجعات</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ $totalArticlesCount }}</span>
                        </a>
                    </li>

                    <!-- Dynamic Categories from Database -->
                    @foreach ($categories as $cat)
                        <li>
                            <a href="{{ route('home', ['category' => $cat->slug] + request()->only('q')) }}"
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

        <!-- Main Feed Column -->
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
                    </div>
                    <a href="{{ route('home') }}" class="shrink-0 font-bold text-blue-700 underline hover:text-blue-900">إلغاء التصفية ✕</a>
                </div>
            @endif

            <!-- Results Counter Header -->
            <div class="flex items-center justify-between rounded-xl border border-slate-200/80 bg-white px-4 py-3 text-xs font-semibold text-slate-600 shadow-sm">
                <span>تم العثور على <strong>{{ $articles->total() }}</strong> مراجعة محدثة</span>
                <span class="text-slate-400">ترتيب حسب: الأحدث</span>
            </div>

            <!-- Wuzzuf-Style Article Cards -->
            @forelse ($articles as $article)
                <article class="group rounded-2xl border border-slate-200/90 bg-white p-5 shadow-sm transition duration-200 hover:border-blue-500 hover:shadow-md">

                    <!-- Top Part: Image, Category, Title -->
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-4">
                            @if ($article->product?->image_url)
                                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl border border-slate-100 bg-white p-1.5 shadow-sm">
                                    <img src="{{ $article->product->image_url }}" alt="{{ $article->product->title }}" loading="lazy" class="max-h-full max-w-full object-contain">
                                </div>
                            @endif

                            <div>
                                <div class="mb-1 flex items-center gap-2 text-xs font-medium text-slate-500">
                                    <span class="font-bold text-blue-600">{{ $article->category?->name ?: 'عام' }}</span>
                                    <span>•</span>
                                    <span>{{ $article->product?->brand ?: 'أمازون مصر' }}</span>
                                </div>
                                <h2 class="text-base font-bold leading-snug text-slate-900 transition group-hover:text-blue-600">
                                    <a href="{{ route('articles.show', $article->slug) }}">
                                        {{ $article->title }}
                                    </a>
                                </h2>
                            </div>
                        </div>

                        <span class="hidden shrink-0 rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600 sm:inline-block">
                            تحديث {{ $article->updated_at?->format('Y') ?: '2026' }}
                        </span>
                    </div>

                    <!-- Middle Part: Spec Pills -->
                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs font-medium">
                        @if ($article->product)
                            <span class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700">
                                ⭐ {{ number_format((float) $article->product->rating, 1) }} / 5
                            </span>
                            <span class="rounded-md border border-blue-100 bg-blue-50 px-2.5 py-1 font-bold text-blue-700">
                                تقسيط 0% فوائد متاح
                            </span>
                        @endif
                        <span class="rounded-md border border-emerald-100 bg-emerald-50 px-2.5 py-1 font-bold text-emerald-700">
                            شحن سريع
                        </span>
                    </div>

                    <!-- Bottom Part: Price and CTA Button -->
                    <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3.5 text-xs">
                        <div>
                            @php
                                $current = (float) $article->product->price;
                                $original = (float) ($article->product->original_price ?? 0);
                            @endphp
                            @if ($article->product)
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-slate-400">السعر اليوم: </span>
                                    @if ($original > $current)
                                        <span class="line-through text-sm font-semibold text-slate-400">{{ number_format($original, 2) }} ج.م</span>
                                    @endif
                                    <span class="text-lg font-black text-slate-900">{{ number_format($current, 2) }}</span>
                                    <span class="font-bold text-slate-500"> ج.م</span>
                                    @if ($original > $current)
                                        <span class="rounded-md bg-red-50 px-2 py-0.5 text-[11px] font-bold text-red-600">
                                            خصم {{ round((($original - $current) / $original) * 100) }}%
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <a href="{{ route('articles.show', $article->slug) }}" class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2.5 font-bold text-white shadow-sm shadow-blue-600/20 transition hover:bg-blue-700">
                            اقرأ المراجعة
                            <svg class="h-3.5 w-3.5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>

                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">
                    <p class="mb-1 text-base font-bold text-slate-700">لم نجد أي مراجعات مطابقة لبحثك!</p>
                    <p class="mb-4 text-xs text-slate-400">جرب البحث بكلمات أخرى أو اختر فئة مختلفة من القائمة الجانبية.</p>
                    <a href="{{ route('home') }}" class="inline-block rounded-xl bg-blue-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-blue-700">عرض جميع المراجعات المتاحة</a>
                </div>
            @endforelse

            <!-- Pagination -->
            <div class="mt-6">
                {{ $articles->links() }}
            </div>

        </main>

    </div>
</x-layouts.app>