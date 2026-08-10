@props([
    'product' => null,
])

@if (blank($product?->image_url))
    <span class="grid h-20 w-20 shrink-0 place-items-center rounded-xl border border-slate-200 bg-slate-100 text-mist">
        <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
        </svg>
    </span>
@else
    <img src="{{ $product->image_url }}" alt="{{ $product->title }}" width="80" height="80" loading="lazy" class="h-20 w-20 shrink-0 rounded-xl border border-slate-100 bg-white p-1 object-contain">
@endif