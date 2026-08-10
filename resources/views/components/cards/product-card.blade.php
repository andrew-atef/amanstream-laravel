@props([
    'article',
])

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
            <span class="absolute left-1 top-1 rounded-lg border border-purple-200 bg-white/95 px-2 py-0.5 text-[10px] font-extrabold text-purple-700 shadow-sm backdrop-blur">مقارنة</span>
        @endif

        <x-product.merchant-badge :merchant="$merchant" :brand="$product?->brand" />
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
            <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-slate-700">
                <svg class="h-3 w-3 text-amber-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>
                {{ number_format((float) $product->rating, 1) }}
            </span>
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