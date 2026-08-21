@props([
    'product' => null,
    'compact' => false,
])

@php
    if (! $product) return;
    $cleanUrl = \App\Services\SEOHelper::cleanAffiliateUrl((string) $product->affiliate_url, (string) $product->asin);
    $inStock = (bool) $product->in_stock;
@endphp

@if ($inStock && filled($cleanUrl))
    <a
        href="{{ $cleanUrl }}"
        target="_blank"
        rel="nofollow sponsored noopener"
        class="inline-flex {{ $compact ? 'h-8 px-2.5 text-[11px]' : 'h-9 px-4 text-xs' }} items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-primary-600 font-bold text-white shadow-md shadow-primary-600/20 transition-all hover:bg-primary-700 hover:shadow-lg active:scale-95"
    >
        <span class="flex h-4 items-center justify-center rounded bg-white px-1 py-0.5">
            <img src="/icons/amazon.png" alt="Amazon" width="40" height="12" loading="lazy" class="h-2.5 w-auto object-contain">
        </span>
        <span>اشترِ الآن</span>
    </a>
@else
    <span class="inline-flex {{ $compact ? 'h-8 px-2.5 text-[11px]' : 'h-9 px-3 text-xs' }} items-center justify-center whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 font-bold text-slate-500 cursor-not-allowed">
        غير متوفر حالياً
    </span>
@endif
