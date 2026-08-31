@php
    $metrics = $chartData ?? [];
    $clicks = $metrics['clicks'] ?? [];
    $impressions = $metrics['impressions'] ?? [];
    $labels = $metrics['labels'] ?? [];

    $maxImpressions = max(array_merge($impressions, [1]));
    $maxClicks = max(array_merge($clicks, [1]));
    $width = 800;
    $height = 120;
    $padding = 10;
@endphp

@if(count($clicks) > 1)
<div class="w-full rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <div class="mb-3 flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
        <span class="flex items-center gap-1">
            <span class="inline-block h-2 w-2 rounded-full bg-primary-500"></span>
            النقرات
        </span>
        <span class="flex items-center gap-1">
            <span class="inline-block h-2 w-2 rounded-full bg-info-500"></span>
            الظهور
        </span>
    </div>
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full" preserveAspectRatio="none">
        {{-- Impressions area --}}
        @php
            $points = [];
            $count = count($impressions);
            for ($i = 0; $i < $count; $i++) {
                $x = $padding + ($i / max($count - 1, 1)) * ($width - 2 * $padding);
                $y = $height - $padding - ($impressions[$i] / $maxImpressions) * ($height - 2 * $padding);
                $points[] = "{$x},{$y}";
            }
        @endphp
        <polygon
            points="{{ $padding }},{{ $height - $padding }} {{ implode(' ', $points) }} {{ $width - $padding }},{{ $height - $padding }}"
            fill="rgb(59 130 246 / 0.1)"
            stroke="none"
        />
        <polyline
            points="{{ implode(' ', $points) }}"
            fill="none"
            stroke="rgb(59 130 246)"
            stroke-width="2"
            stroke-linejoin="round"
        />

        {{-- Clicks line --}}
        @php
            $points = [];
            $count = count($clicks);
            for ($i = 0; $i < $count; $i++) {
                $x = $padding + ($i / max($count - 1, 1)) * ($width - 2 * $padding);
                $y = $height - $padding - ($clicks[$i] / $maxImpressions) * ($height - 2 * $padding);
                $points[] = "{$x},{$y}";
            }
        @endphp
        <polyline
            points="{{ implode(' ', $points) }}"
            fill="none"
            stroke="rgb(16 185 129)"
            stroke-width="2.5"
            stroke-linejoin="round"
        />
    </svg>
    <div class="mt-2 flex justify-between text-[10px] text-gray-400 dark:text-gray-500">
        @if(count($labels) > 0)
            <span>{{ $labels[0] }}</span>
            @if(count($labels) > 1)
                <span>{{ $labels[count($labels) - 1] }}</span>
            @endif
        @endif
    </div>
</div>
@else
<div class="flex h-[140px] items-center justify-center rounded-xl border border-dashed border-gray-300 text-sm text-gray-400 dark:border-gray-700 dark:text-gray-500">
    لا توجد بيانات كافية لعرض المخطط — قم بمزامنة بيانات GSC أولاً.
</div>
@endif
