@props([
    'product' => null,
])

@php
    $current = (float) ($product?->price ?? 0);
    $lowest = $product?->getLowestRecordedPrice() ?? 0;
    $highest = $product?->getHighestRecordedPrice() ?? 0;
    $points = $product?->getPriceHistoryPoints() ?? [];

    $lowestDate = 'اليوم';
    $highestDate = 'اليوم';

    foreach ($points as $point) {
        if (abs((float) $point['price'] - $lowest) < 0.01) {
            $lowestDate = $point['date'];
        }
        if (abs((float) $point['price'] - $highest) < 0.01) {
            $highestDate = $point['date'];
        }
    }

    $discountVsMax = $highest > $current && $highest > 0
        ? (int) round((1 - $current / $highest) * 100)
        : 0;
@endphp

<div class="my-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-ink">
        مؤشر أمان ستريم لتاريخ السعر
    </div>

    <table class="w-full text-right text-sm">
        <tbody class="divide-y divide-slate-100">
            <tr>
                <th class="px-4 py-2.5 font-semibold text-ink/60">أقل سعر سُجِّل</th>
                <td class="px-4 py-2.5 text-left font-bold text-ink">{{ number_format($lowest, 2) }} <span class="text-xs font-normal text-mist">ج.م</span></td>
                <td class="px-4 py-2.5 whitespace-nowrap text-left text-xs text-mist">{{ $lowestDate }}</td>
            </tr>
            <tr class="bg-primary-50/70">
                <th class="px-4 py-2.5 font-bold text-ink">السعر الحالي اليوم</th>
                <td class="px-4 py-2.5 text-left text-lg font-black text-primary-700">{{ number_format($current, 2) }} <span class="text-xs font-normal text-mist">ج.م</span></td>
                <td class="px-4 py-2.5 whitespace-nowrap text-left text-xs font-bold text-primary-600">{{ $discountVsMax > 0 ? "خصم {$discountVsMax}%" : '—' }}</td>
            </tr>
            <tr>
                <th class="px-4 py-2.5 font-semibold text-ink/60">أعلى سعر سُجِّل</th>
                <td class="px-4 py-2.5 text-left font-bold text-ink">{{ number_format($highest, 2) }} <span class="text-xs font-normal text-mist">ج.م</span></td>
                <td class="px-4 py-2.5 whitespace-nowrap text-left text-xs text-mist">{{ $highestDate }}</td>
            </tr>
        </tbody>
    </table>

    <div class="border-t border-slate-200 px-4 py-3">
        @if (count($points) >= 2)
            @php
                $labels = array_map(fn (array $point): string => e((string) $point['date']), $points);
                $prices = array_map(fn (array $point): float => (float) $point['price'], $points);
            @endphp
            <div class="relative h-40 w-full sm:h-48">
                <canvas
                    data-ph-chart="1"
                    data-ph-labels="{{ json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                    data-ph-prices="{{ json_encode($prices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                    role="img"
                    aria-label="الرسم البياني لحركة السعر"
                    class="w-full"
                ></canvas>
            </div>
        @else
            @php
                $range = max(1, $highest - $lowest);
                $barPercent = min(100, max(0, round((($current - $lowest) / $range) * 100)));
                // The bar must always be visible: a sliver at least ~4% wide is rendered
                // even when the live price equals the lowest recorded price.
                $barWidth = max(4, $barPercent);
            @endphp
            <div>
                <div class="flex items-center justify-between text-[11px] font-bold text-mist">
                    <span>أدنى سعر ({{ number_format($lowest, 0) }} ج.م)</span>
                    <span>أعلى سعر ({{ number_format($highest, 0) }} ج.م)</span>
                </div>
                <div class="relative mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-200">
                    <div class="absolute top-0 bottom-0 left-0 rounded-full bg-primary-600" style="width: {{ $barWidth }}%"></div>
                    <span class="absolute top-1/2 h-4 w-4 -translate-y-1/2 rounded-full border-2 border-white bg-ink shadow" style="left: {{ $barWidth }}%"></span>
                </div>
            </div>
        @endif
    </div>

    <div class="flex flex-col items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row">
        <p class="text-xs text-ink/60">أمان ستريم يتابع سعر هذا الجهاز يومياً ليساعدك في الشراء بأقل سعر.</p>
        <a href="{{ $product?->affiliate_url }}" target="_blank" rel="nofollow sponsored noopener" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-primary-700">
            <span class="flex items-center justify-center rounded bg-white px-1 py-0.5"><img src="/icons/amazon.png" alt="Amazon" width="48" height="16" loading="lazy" class="h-3 w-auto object-contain"></span>
            اشترِ الآن
        </a>
    </div>
</div>