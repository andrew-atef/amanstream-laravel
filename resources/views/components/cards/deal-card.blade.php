@props([
    'article',
    'accent' => 'blue',
    'eager' => false,
])

@php
    $primaryProduct = $article->product;
    $product = $primaryProduct ?: $article->products->first();
    $isComparison = ! $primaryProduct && $article->products->count() > 1;
    $current = (float) ($product?->price ?? 0);
    $original = (float) ($product?->original_price ?? 0);
    $discount = $original > $current && $original > 0
        ? round((($original - $current) / $original) * 100)
        : 0;

    $url = $product?->affiliate_url ?? '';
    $merchant = 'amazon';
    if (str_contains($url, 'noon.com')) { $merchant = 'noon'; }
    elseif (str_contains($url, 'jumia.com')) { $merchant = 'jumia'; }
@endphp

<article class="group relative flex w-60 shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition duration-200 {{ $accent === 'red' ? 'hover:border-primary-600 hover:shadow-md' : 'hover:border-primary-500 hover:shadow-md' }}">
    <!-- Image & Merchant Badge -->
    <a href="{{ route('articles.show', $article->slug) }}" class="relative flex h-44 w-full items-center justify-center rounded-xl bg-white p-2">
        @if ($product?->image_url)
            @if ($eager)
                <img src="{{ $product->image_url }}" alt="{{ $product->title ?: $article->title }}" width="240" height="176" fetchpriority="high" decoding="async" class="max-h-full max-w-full object-contain transition duration-300 group-hover:scale-105">
            @else
                <img src="{{ $product->image_url }}" alt="{{ $product->title ?: $article->title }}" width="240" height="176" loading="lazy" decoding="async" class="max-h-full max-w-full object-contain transition duration-300 group-hover:scale-105">
            @endif
        @else
            <span class="text-xs font-bold text-slate-500">لا توجد صورة</span>
        @endif

        @if ($isComparison)
            <span class="absolute left-1 top-1 rounded-lg border border-primary-200 bg-white/95 px-2 py-0.5 text-[10px] font-extrabold text-primary-700 shadow-sm backdrop-blur">مقارنة</span>
        @endif

        <x-product.merchant-badge :merchant="$merchant" :brand="$product?->brand" />
    </a>

    <!-- Title -->
    <h3 class="mt-3 line-clamp-3 text-xs font-bold leading-snug text-ink transition {{ $accent === 'red' ? 'group-hover:text-primary-600' : 'group-hover:text-primary-600' }}">
        <a href="{{ route('articles.show', $article->slug) }}">
            {{ $article->title }}
        </a>
    </h3>

    <!-- Price & Trend Badge -->
    <div class="mt-4 flex items-end justify-between border-t border-slate-100 pt-3">
        <div>
            <div class="text-base font-black text-ink">
                {{ number_format($current, 0) }} <span class="text-[10px] font-semibold text-ink/60">ج.م</span>
            </div>
            @if ($discount > 0)
                <div class="text-[11px] font-semibold text-slate-500 line-through">
                    {{ number_format($original, 0) }}
                </div>
            @endif
        </div>

        @if ($discount > 0)
            <div class="flex items-center gap-0.5 rounded-md border border-primary-200 bg-primary-50 px-2 py-1 text-[11px] font-extrabold text-primary-700">
                <svg class="h-3 w-3 text-primary-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0v-8m0 8l-8-8-4 4-6-6"/>
                </svg>
                -{{ $discount }}%
            </div>
        @endif
    </div>
</article>