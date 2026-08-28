@props([
    'product' => null,
    'compact' => false,
])

@php
    if (! $product) return;
    $goUrl = \App\Services\SEOHelper::goUrl((string) $product->asin);
    $inStock = (bool) $product->in_stock;
@endphp

@if ($inStock && filled($goUrl))
    <a
        href="{{ $goUrl }}"
        target="_blank"
        rel="nofollow sponsored noopener"
        class="inline-flex {{ $compact ? 'h-9 px-4 text-xs' : 'h-11 px-6 text-sm' }} items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 font-bold text-white no-underline shadow-md shadow-primary-600/25 transition-all hover:bg-primary-700 hover:shadow-lg hover:no-underline active:scale-95"
    >
        <span class="flex shrink-0 items-center justify-center rounded bg-white px-1.5 py-0.5">
            <img src="/icons/amazon.svg" alt="Amazon" width="24" height="24" loading="lazy" class="h-4 w-auto object-contain">
        </span>
        <span>اشترِ الآن</span>
    </a>
@else
    <span class="inline-flex {{ $compact ? 'h-9 px-4' : 'h-11 px-5' }} items-center justify-center whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 text-xs font-bold text-slate-500 cursor-not-allowed">
        غير متوفر حالياً
    </span>
@endif
