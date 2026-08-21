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
        class="inline-flex h-11 px-6 text-sm items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 font-bold text-white shadow-lg shadow-primary-600/30 transition-all hover:bg-primary-700 hover:shadow-xl active:scale-95"
    >
        <span class="flex h-5 items-center justify-center rounded-md bg-white px-2 py-1">
            <img src="/icons/amazon.png" alt="Amazon" width="80" height="24" loading="lazy" class="h-5 w-auto object-contain">
        </span>
        <span>اشترِ الآن</span>
    </a>
@else
    <span class="inline-flex h-11 px-6 text-sm items-center justify-center whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 font-bold text-slate-500 cursor-not-allowed">
        غير متوفر حالياً
    </span>
@endif
