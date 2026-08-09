@props([
    'categories' => [],
    'totalArticlesCount' => 0,
])

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