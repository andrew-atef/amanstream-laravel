@props([
    'items' => [],
    'specLabels' => [],
])

<div class="my-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <table class="w-full min-w-[720px] border-collapse text-sm">
        <thead class="bg-ink text-white">
            <tr>
                <th class="py-3 px-4 text-right">المنتج</th>
                @foreach ($items as $index => $item)
                    <th class="py-3 px-4 text-center font-bold">{{ $index + 1 }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <tr>
                <th class="py-3 px-4 text-right font-bold text-ink/70">الصورة</th>
                @foreach ($items as $item)
                    <td class="py-3 px-4 text-center"><x-shortcodes.thumb :product="$item['product']" /></td>
                @endforeach
            </tr>
            <tr>
                <th class="py-3 px-4 text-right font-bold text-ink/70">التقييم</th>
                @foreach ($items as $item)
                    <td class="py-3 px-4 text-center text-sm font-semibold text-primary-700">
                        <svg class="inline h-4 w-4 text-primary-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg> {{ number_format((float) $item['product']->rating, 1) }} / 5
                    </td>
                @endforeach
            </tr>
            @foreach ($specLabels as $label)
                <tr>
                    <th class="py-3 px-4 text-right font-bold text-ink/70">{{ $label }}</th>
                    @foreach ($items as $item)
                        @php
                            $matched = '—';
                            foreach ($item['specs'] as $spec) {
                                if (($spec['label'] ?? '') === $label) {
                                    $matched = $spec['value'] ?? '—';
                                    break;
                                }
                            }
                        @endphp
                        <td class="py-3 px-4 text-center text-sm text-ink/80">{{ $matched }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="bg-slate-50">
                <th class="py-3 px-4 text-right font-bold text-ink/70">السعر اليوم</th>
                @foreach ($items as $item)
                    <td class="py-3 px-4 text-center">
                        <span class="text-lg font-black text-primary-700">{{ number_format((float) $item['product']->price, 0) }}</span> <span class="text-xs font-bold text-ink/60">ج.م</span>
                    </td>
                @endforeach
            </tr>
            <tr>
                <th class="py-3 px-4 text-right font-bold text-ink/70">الشراء</th>
                @foreach ($items as $item)
                    <td class="py-3 px-4 text-center"><x-shortcodes.buy-button :product="$item['product']" compact /></td>
                @endforeach
            </tr>
        </tbody>
    </table>
</div>