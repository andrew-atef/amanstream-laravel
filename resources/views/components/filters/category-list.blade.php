@props([
    'categories' => [],
    'totalArticlesCount' => 0,
])

<div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
    <h2 class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3 text-xs font-bold text-ink">
        <span>الفئات المتاحة</span>
        <span class="text-[11px] font-semibold text-mist">تصفية تلقائية</span>
    </h2>

    <ul class="space-y-1.5 text-xs font-medium">
        <!-- All Articles Option -->
        <li>
            <a href="{{ route('home', array_filter(request()->only('q'))) }}"
               class="flex items-center justify-between rounded-xl px-3 py-2.5 transition {{ ! request('category') ? 'bg-primary-50 font-extrabold text-primary-700 border border-primary-100' : 'text-ink/70 hover:bg-slate-50' }}">
                <span>جميع المراجعات</span>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-ink/70">{{ $totalArticlesCount }}</span>
            </a>
        </li>

        <!-- Dynamic Categories from Database -->
        @foreach ($categories as $cat)
            <li>
                <a href="{{ route('home', array_merge(['category' => $cat->slug], array_filter(request()->only('q')))) }}"
                   class="flex items-center justify-between rounded-xl px-3 py-2.5 transition {{ request('category') == $cat->slug ? 'bg-primary-50 font-extrabold text-primary-700 border border-primary-100' : 'text-ink/70 hover:bg-slate-50' }}">
                    <span class="truncate">{{ $cat->name }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-ink/70">{{ $cat->articles_count }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>