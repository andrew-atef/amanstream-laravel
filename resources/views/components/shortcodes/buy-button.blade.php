@props([
    'product' => null,
    'compact' => false,
])

@php
    $inStock = $product?->in_stock !== false;
    $url = $product?->affiliate_url ?? '#';
@endphp

@if (! $inStock)
    @if ($compact)
        <span class="rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-bold leading-none text-ink/60 cursor-not-allowed whitespace-nowrap">غير متوفر</span>
    @else
        <span class="inline-flex items-center gap-2 rounded-xl bg-slate-100 border border-slate-200 text-ink/60 px-6 py-3 font-bold">غير متوفر حالياً بأمازون</span>
    @endif
@else
    @if ($compact)
        <a href="{{ $url }}" target="_blank" rel="nofollow sponsored noopener" class="inline-flex items-center justify-center gap-1 whitespace-nowrap rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-bold leading-none text-white shadow-md shadow-primary-600/20 transition-colors hover:bg-primary-700">
            <span class="flex items-center justify-center rounded bg-white px-0.5 py-0.5"><img src="/icons/amazon.png" alt="Amazon" width="36" height="12" loading="lazy" class="h-3 w-auto object-contain"></span>
            اشترِ الآن
        </a>
    @else
        <a href="{{ $url }}" target="_blank" rel="nofollow sponsored noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-bold text-lg px-8 py-4 shadow-lg shadow-primary-600/30 transition-colors">
            <span class="flex items-center justify-center rounded-md bg-white px-2 py-1"><img src="/icons/amazon.png" alt="Amazon" width="80" height="24" loading="lazy" class="h-5 w-auto object-contain"></span>
            اشترِ الآن
        </a>
    @endif
@endif