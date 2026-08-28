@props(['article'])

@php
    $variants = $article->products;
    // Prefer pivot ordering if available, otherwise keep products order
    if ($article->relationLoaded('articleProducts') && $article->articleProducts->isNotEmpty()) {
        $ordered = $article->articleProducts->sortBy('sort_order')->pluck('product')->filter()->values();
        if ($ordered->isNotEmpty()) {
            $variants = $ordered;
        }
    }
@endphp

@if ($variants->isNotEmpty())
<div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2" dir="rtl">
    @foreach ($variants as $index => $variant)
        @php
            $variantPrice = (float) $variant->price;
            $variantOriginal = (float) ($variant->original_price ?? 0);
            $variantHasDiscount = $variantOriginal > $variantPrice && $variantOriginal > 0;
            $variantDiscount = $variantHasDiscount ? (int) round((($variantOriginal - $variantPrice) / $variantOriginal) * 100) : 0;
            $variantPlans = $variant->getEligibleInstallmentPlans();
            $variantMonthly = $variantPlans->isNotEmpty()
                ? (float) $variantPlans->map(fn ($plan) => $plan->calculateMonthlyPayment($variantPrice))->min()
                : ($variantPrice > 0 ? $variantPrice / 12 : 0);
            // Try to find pivot badge for this variant
            $pivotRow = $article->articleProducts->firstWhere('product_id', $variant->id);
            $badgeLabel = $pivotRow?->badge_label;
            if (blank($badgeLabel)) {
                $badgeLabel = $index === 0 ? 'الخيار الأوفر' : 'الإصدار المتقدم';
            }
        @endphp
        <div class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:border-primary-200 hover:shadow-md">
            @if ($variant->image_url)
                <div class="flex h-36 items-center justify-center bg-white p-3">
                    <img src="{{ $variant->image_url }}" alt="{{ \App\Services\SEOHelper::cleanTitle((string) $variant->title) }}" width="200" height="120" loading="lazy" class="h-full w-auto object-contain">
                </div>
            @endif
            <div class="flex flex-1 flex-col p-4">
                <div class="mb-1 text-xs font-bold text-slate-500">موديل {{ $variant->asin ?: '—' }}</div>
                <h3 class="line-clamp-2 text-sm font-black leading-snug text-ink">{{ \App\Services\SEOHelper::cleanTitle((string) $variant->title) }}</h3>
                @if (filled($badgeLabel))
                    <span class="mt-2 inline-flex w-fit rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-700 border border-primary-100">{{ $badgeLabel }}</span>
                @endif
                <div class="mt-3 flex flex-wrap items-baseline gap-2">
                    @if ($variantHasDiscount)
                        <span class="text-sm font-semibold text-slate-500 line-through">{{ number_format($variantOriginal, 2) }} ج.م</span>
                    @endif
                    <span class="text-xl font-black text-primary-700">{{ number_format($variantPrice, 2) }} <span class="text-xs font-bold text-slate-500">ج.م</span></span>
                    @if ($variantHasDiscount)
                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700">خصم {{ $variantDiscount }}%</span>
                    @endif
                </div>
                @if ($variantMonthly > 0)
                    <div class="mt-2 inline-flex items-center gap-1 rounded-lg bg-slate-50 border border-slate-100 px-2.5 py-1 text-xs font-semibold text-ink/70">
                        <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
                        قسط يبدأ من {{ number_format($variantMonthly, 2) }} ج.م/شهر
                    </div>
                @endif
                <div class="mt-4">
                    @if ($variant->in_stock)
                        <a href="{{ \App\Services\SEOHelper::goUrl((string) $variant->asin) }}" target="_blank" rel="nofollow sponsored noopener" class="inline-flex w-full h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-primary-600 px-6 text-sm font-bold text-white no-underline shadow-md shadow-primary-600/25 transition-all hover:bg-primary-700 hover:shadow-lg hover:no-underline active:scale-95">
                            <span class="flex shrink-0 items-center justify-center rounded bg-white px-1.5 py-0.5"><img src="/icons/amazon.svg" alt="Amazon" width="24" height="24" loading="lazy" class="h-4 w-auto object-contain"></span>
                            <span>اشترِ الآن من أمازون مصر</span>
                        </a>
                    @else
                        <span class="inline-flex w-full h-11 items-center justify-center whitespace-nowrap rounded-xl border border-slate-200 bg-slate-100 px-5 text-xs font-bold text-slate-500 cursor-not-allowed">غير متوفر حالياً</span>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif
