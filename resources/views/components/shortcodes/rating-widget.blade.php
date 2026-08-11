@props([
    'product' => null,
])

@php
    $rating = (float) ($product?->rating ?? 0);
    $fullStars = (int) floor($rating);
    $reviewCount = (int) ($product?->review_count ?? 0);
    $reviewLabel = match (true) {
        $reviewCount === 0 => 'لا توجد مراجعات بعد',
        $reviewCount === 1 => 'مراجعة واحدة',
        $reviewCount === 2 => 'مراجعتان',
        $reviewCount >= 3 && $reviewCount <= 10 => number_format($reviewCount).' مراجعات',
        default => number_format($reviewCount).' مراجعة',
    };
@endphp

<div class="inline-flex items-center gap-2 rounded-xl bg-primary-50 border border-primary-200 px-4 py-2" role="status">
    <div class="flex flex-row-reverse" title="{{ $rating }}">
        @for ($i = 1; $i <= 5; $i++)
            @if ($i <= $fullStars)
                <svg class="w-5 h-5 text-primary-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>
            @else
                <svg class="w-5 h-5 text-slate-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>
            @endif
        @endfor
    </div>
    <span class="text-sm font-medium text-primary-900"><span class="font-bold">{{ number_format($rating, 1) }} من 5</span> <span class="text-primary-700">({{ $reviewLabel }})</span></span>
</div>