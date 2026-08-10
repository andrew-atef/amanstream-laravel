@props([
    'product' => null,
])

@php
    $formatted = number_format((float) $product?->price, 2);
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 px-4 py-2 text-emerald-700 font-bold text-lg rtl:flex-row-reverse" role="status">
    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 6h18v13H3V6Zm9 2v4h5v3l4-1v2l-7 2H7V8h5Zm0 4V8h7l-1-2H4v3h8Z"/></svg>{{ $formatted }} ج.م
</span>