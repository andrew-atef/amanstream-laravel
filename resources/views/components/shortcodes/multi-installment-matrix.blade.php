@props(['article'])

@php
    $variants = $article->products;
    if ($variants->isEmpty()) {
        $variants = collect([$article->product])->filter();
    }
    // Collect all eligible plans grouped by bank
    $allBanks = collect();
    $variantPlanMap = []; // [variantId => [bankId => monthly]]
    $zeroInterestByBank = [];
    foreach ($variants as $variant) {
        $plans = $variant->getEligibleInstallmentPlans();
        foreach ($plans as $plan) {
            $bankId = $plan->bank->id;
            $allBanks->put($bankId, $plan->bank);
            $variantPlanMap[$variant->id][$bankId] = [
                'monthly' => $plan->calculateMonthlyPayment((float) $variant->price),
                'months' => $plan->months,
                'is_zero' => $plan->is_zero_interest,
            ];
            if ($plan->is_zero_interest) {
                $zeroInterestByBank[$bankId] = true;
            }
        }
    }
    $banks = $allBanks->values();
    // Fallback when no bank plans exist: show 12-month split per variant
    $hasPlans = $banks->isNotEmpty();
@endphp

@if ($hasPlans)
<div class="my-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <div class="bg-slate-900 px-4 py-3 text-sm font-bold text-white">جدول المقارنة — الأقساط الشهرية لكل موديل</div>
    <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-xs font-bold text-ink/70 border-b border-slate-100">
                <tr>
                    <th class="px-4 py-3 whitespace-nowrap">البنك / المزود</th>
                    @foreach ($variants as $variant)
                        <th class="px-4 py-3 whitespace-nowrap text-primary-700">قسط {{ $variant->asin ?: Str::limit($variant->title, 14) }}<br><span class="text-[11px] font-semibold text-slate-500">{{ number_format((float) $variant->price, 0) }} ج.م</span></th>
                    @endforeach
                    <th class="px-4 py-3 whitespace-nowrap">نوع العرض</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($banks as $bank)
                    <tr class="hover:bg-primary-50/40 transition">
                        <td class="px-4 py-3 font-bold text-ink text-xs">{{ $bank->name_ar }}</td>
                        @foreach ($variants as $variant)
                            @php $entry = $variantPlanMap[$variant->id][$bank->id] ?? null; @endphp
                            <td class="px-4 py-3 text-xs font-black text-primary-700">
                                @if ($entry)
                                    {{ number_format($entry['monthly'], 2) }} ج.م<small class="mr-1 font-semibold text-slate-500">/شهر ({{ $entry['months'] }}ش)</small>
                                @else
                                    <span class="font-semibold text-slate-400">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-4 py-3">
                            @if (! empty($zeroInterestByBank[$bank->id]))
                                <span class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800">0% فائدة</span>
                            @else
                                <span class="text-xs text-slate-500">بفائدة</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@else
<div class="my-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs text-ink/70" dir="rtl">
    @foreach ($variants as $variant)
        <div class="flex items-center justify-between py-1">
            <span class="font-bold">{{ Str::limit($variant->title, 30) }} ({{ number_format((float) $variant->price, 0) }} ج.م)</span>
            <span class="font-black text-primary-700">{{ number_format((float) $variant->price / 12, 2) }} ج.م/شهر</span>
        </div>
    @endforeach
    <p class="mt-2 text-[11px] text-slate-500">قسط تقديري على 12 شهر — 0% فائدة حسب البنك.</p>
</div>
@endif
