@props([
    'searchQuery' => null,
    'dealsOnly' => false,
    'selectedCategory' => null,
])

<section class="-mx-4 -mt-8 mb-8 bg-[#0f172a] px-4 py-10 text-white shadow-md sm:-mx-6 sm:px-8 lg:-mx-8">
    <div class="mx-auto max-w-4xl text-center">
        <h1 class="text-2xl font-black leading-tight sm:text-4xl">
            {{ $selectedCategory ? $selectedCategory->name : 'ابحث عن مواصفات وأسعار الأجهزة في مصر' }}
        </h1>
        <p class="mt-2 text-xs text-mist sm:text-sm">
            {{ $selectedCategory
                ? trim((string) $selectedCategory->description) ?: 'مراجعات ومقارنات محدثة يومياً من أمازون مصر'
                : 'مقارنات دقيقة، أسعار يومية، وحاسبة التقسيط الأمان على أمازون مصر' }}
        </p>

        <!-- Search Bar -->
        <form
            action="{{ route('home') }}"
            method="GET"
            class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center"
            toolname="amanprice_search"
            tooldescription="Search AmanPrice (amanprice.tech) for Egyptian appliance prices, reviews and bank-installment comparisons on Amazon Egypt."
        >
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
                    class="w-full rounded-xl border-0 px-4 py-3.5 pl-10 text-sm text-ink shadow-inner focus:ring-2 focus:ring-primary-600"
                >
                <svg class="absolute left-3 top-3.5 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <button type="submit" class="rounded-xl bg-primary-600 px-8 py-3.5 text-sm font-bold text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-700">
                بحث سريع
            </button>
        </form>

        <!-- Trending Pills -->
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs text-mist">
            <span class="font-bold text-mist/60">الأكثر بحثاً:</span>
            <a href="{{ route('home', ['q' => 'تكييف']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">تكييفات</a>
            <a href="{{ route('home', ['q' => 'فريش']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">منتجات فريش</a>
            <a href="{{ route('home', ['q' => 'شاشة']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">شاشات</a>
            <a href="{{ route('home', ['q' => 'غسالة']) }}" class="rounded-lg bg-white/10 px-2.5 py-1 transition hover:bg-white/20">غسالات</a>
        </div>
    </div>
</section>