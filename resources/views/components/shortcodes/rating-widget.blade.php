@props([
    'product' => null,
])

@php
    $rating = (float) ($product?->rating ?? 0);
    $fullStars = (int) floor($rating);
    $reviewLabel = trans_choice(
        'built on :count review|built on :count reviews',
        (int) ($product?->review_count ?? 0),
        ['count' => number_format((int) ($product?->review_count ?? 0))]
    );
@endphp

<div class="inline-flex items-center gap-2 rounded-xl bg-amber-50 border border-amber-200 px-4 py-2" role="status">
    <div class="flex flex-row-reverse" title="{{ $rating }}">
        @for ($i = 1; $i <= 5; $i++)
            @if ($i <= $fullStars)
                <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>
            @else
                <svg class="w-5 h-5 text-slate-200" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>
            @endif
        @endfor
    </div>
    <span class="text-sm font-medium text-amber-900"><span class="font-bold">{{ number_format($rating, 1) }} / 5</span> ({{ $reviewLabel }})</span>
</div>