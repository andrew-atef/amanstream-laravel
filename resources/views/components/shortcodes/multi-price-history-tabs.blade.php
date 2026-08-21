@props(['article'])

@php
    $variants = $article->products;
@endphp

@if ($variants->isNotEmpty())
<div x-data="{ activeModel: 0 }" class="my-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <div class="flex gap-1 border-b border-slate-200 bg-slate-50 p-2">
        @foreach ($variants as $index => $variant)
            <button
                type="button"
                @click="activeModel = {{ $index }}"
                :class="activeModel === {{ $index }} ? 'bg-primary-600 text-white shadow' : 'bg-white text-ink/70 hover:bg-slate-100'"
                class="flex-1 rounded-lg px-3 py-2 text-xs font-bold transition"
            >
                {{ \App\Services\SEOHelper::cleanTitle(Str::limit((string) $variant->title, 22)) }}
                <span class="block text-[10px] font-semibold opacity-80">{{ $variant->asin ?: number_format((float) $variant->price, 0).' ج.م' }}</span>
            </button>
        @endforeach
    </div>
    @foreach ($variants as $index => $variant)
        <div x-show="activeModel === {{ $index }}" x-cloak class="p-0">
            @include('components.shortcodes.price-history', ['product' => $variant])
        </div>
    @endforeach
</div>
@endif
