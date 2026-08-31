@props([
    'product' => null,
    'plans' => [],
])

@php
    $plans = collect($plans);
    $price = (float) ($product?->price ?? 0);
@endphp

@if ($plans->isEmpty())
    <div class="my-6 rounded-2xl bg-slate-50 border border-slate-200 p-4 text-xs text-ink/70">هذا المنتج بسعر ({{ number_format($price, 2) }} ج.م) متاح للشراء الدفع المباشر/الكاش عبر أمازون مصر.</div>
@else
    <div class="my-8 overflow-hidden rounded-3xl border border-primary-100 bg-white shadow-md">
        <div class="p-5 text-white bg-ink flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="grid place-items-center w-10 h-10 rounded-xl bg-primary-600"><svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg></span>
                <div>
                    <h3 class="font-extrabold text-base !text-white">جدول الأقساط البنكية المتاحة لهذا الجهاز</h3>
                    <p class="text-xs text-slate-300">محسوبة بالنظام المصرفي على سعر اليوم ({{ number_format($price, 2) }} ج.م)</p>
                </div>
            </div>
            <a href="{{ \App\Services\SEOHelper::goUrl((string) ($product->asin ?? '')) }}" target="_blank" rel="nofollow sponsored noopener" class="hidden sm:inline-flex h-9 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 px-4 text-xs font-bold text-white no-underline shadow-md shadow-primary-600/25 transition-all hover:bg-primary-700 hover:shadow-lg hover:no-underline active:scale-95">
                <span class="flex shrink-0 items-center justify-center rounded bg-white px-1.5 py-0.5"><img src="/icons/amazon.svg" alt="Amazon" width="24" height="24" loading="lazy" class="h-4 w-auto object-contain"></span>
                اختر خطة التقسيط في أمازون
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-right text-sm">
                <thead class="bg-slate-50 text-xs font-bold text-ink/70 border-b border-slate-100">
                    <tr>
                        <th class="py-3 px-4">البنك / المزود</th>
                        <th class="py-3 px-4">مدة التقسيط</th>
                        <th class="py-3 px-4">القسط الشهري التقديري</th>
                        <th class="py-3 px-4">نوع العرض</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr class="border-b border-slate-100 hover:bg-primary-50/50 transition">
                            <td class="py-3 px-4 font-bold text-ink text-xs">{{ $plan->bank->name_ar }}</td>
                            <td class="py-3 px-4 text-xs font-semibold text-ink/80">{{ $plan->months }} شهر</td>
                            <td class="py-3 px-4 font-black text-primary-700 text-sm">{{ number_format($plan->calculateMonthlyPayment($price), 2) }} ج.م/شهر</td>
                            <td class="py-3 px-4">
                                @if ($plan->is_zero_interest)
                                    <span class="bg-primary-100 text-primary-800 text-[10px] font-bold px-2 py-0.5 rounded">0% فائدة</span>
                                @else
                                    <span class="text-[10px] text-ink/60">فائدة {{ number_format((float) $plan->interest_rate, 2) }}%</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif