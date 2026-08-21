@props(['items'])

@if (! empty($items))
    <nav 
        x-data="{ open: true }" 
        class="my-8 overflow-hidden rounded-2xl border border-slate-200/90 bg-gradient-to-b from-slate-50/90 to-slate-100/50 p-4 sm:p-5 shadow-sm transition-all" 
        dir="rtl" 
        aria-label="فهرس محتويات المقال"
    >
        <!-- Header with Toggle Button -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-primary-600/10 text-primary-600 font-bold text-sm">
                    📑
                </div>
                <div>
                    <span class="text-sm font-black text-ink">محتويات الدليل السريع</span>
                    <span class="block text-[11px] font-semibold text-slate-400">انتقال مباشر للقسم المطلوب</span>
                </div>
            </div>
            
            <button 
                type="button" 
                @click="open = !open" 
                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-primary-600"
            >
                <span x-text="open ? 'إخفاء الفهرس' : 'عرض الفهرس'"></span>
                <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="{ 'rotate-180': !open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>

        <!-- Numbered Grid Items -->
        <div x-show="open" x-collapse class="mt-4 pt-3 border-t border-slate-200/70">
            <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($items as $index => $item)
                    <li>
                        <a 
                            href="#{{ $item['id'] }}" 
                            class="group flex items-center justify-between gap-2 rounded-xl border border-transparent bg-white/70 px-3 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:border-primary-200 hover:bg-white hover:text-primary-700 hover:shadow"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[10px] font-black text-slate-600 group-hover:bg-primary-50 group-hover:text-primary-700">
                                    {{ sprintf('%02d', $index + 1) }}
                                </span>
                                <span class="truncate">{{ $item['title'] }}</span>
                            </div>
                            <span class="text-[11px] font-bold text-slate-300 transition group-hover:translate-x-[-2px] group-hover:text-primary-600">➔</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </nav>
@endif
