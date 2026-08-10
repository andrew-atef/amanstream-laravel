@props([
    'product' => null,
])

@php
    $monthly = (float) ($product?->price ?? 0) / 12;
@endphp

<div class="my-6 rounded-2xl border border-sky-200 bg-gradient-to-br from-sky-50 to-emerald-50 p-6">
    <div class="flex items-center gap-3 mb-4">
        <span class="grid place-items-center w-10 h-10 rounded-full bg-sky-600 text-white shadow-sm">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
        </span>
        <div>
            <h3 class="font-bold text-sky-900 text-lg">قسّط سعر الجهاز على 12 شهر</h3>
            <p class="text-sky-700 text-sm">دفعة شهرية تقريبية بدون فوائد عبر البنوك المصرية</p>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-4">
        <div class="rounded-xl bg-white shadow-sm border border-sky-100 px-6 py-4 text-center">
            <div class="text-xs text-sky-600 font-medium">الشهرية التقريبية</div>
            <div class="text-2xl font-black text-emerald-600">{{ number_format($monthly, 2) }} ج.م</div>
            <div class="text-xs text-slate-500">على 12 شهر</div>
        </div>
        <div class="text-sm text-sky-800 leading-relaxed max-w-xl">
            <strong>تقسيط 0% فائدة</strong> متاح عبر البنك الأهلي المصري (NBE)،
            البنك التجاري الدولي (CIB)، أو <strong>فاليو valU</strong> — مع خيارات مرنة تناسب الميزانية.
        </div>
    </div>
</div>