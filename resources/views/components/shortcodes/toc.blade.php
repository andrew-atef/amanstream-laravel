@props(['items'])

@if (! empty($items))
    <nav class="my-8 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/80 p-5 shadow-sm" dir="rtl" aria-label="فهرس محتويات المقال">
        <div class="mb-3 flex items-center justify-between border-b border-slate-200/80 pb-2.5">
            <div class="flex items-center gap-2">
                <span class="grid h-6 w-6 place-items-center rounded-md bg-primary-100 text-xs text-primary-700">📑</span>
                <span class="text-sm font-extrabold text-ink">فهرس المحتويات السريع</span>
            </div>
            <span class="text-[11px] font-semibold text-slate-400">انتقال فوري للقسم</span>
        </div>

        <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 text-xs font-bold text-ink/80">
            @foreach ($items as $item)
                <li>
                    <a href="#{{ $item['id'] }}" class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 transition-colors hover:bg-white hover:text-primary-600 hover:shadow-sm">
                        <span class="text-primary-500">📌</span>
                        <span class="truncate">{{ $item['title'] }}</span>
                        <span class="mr-auto text-[10px] text-slate-400">➔</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
