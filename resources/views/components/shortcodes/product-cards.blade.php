@props([
    'cards' => [],
])

@php
    $plainCards = collect($cards)->filter(fn ($card) => $card['product'] !== null)->values();
@endphp

@if ($plainCards->isNotEmpty())
    <div class="my-8 space-y-6" dir="rtl">
        @foreach ($plainCards as $card)
            @php
                $product = $card['product'];
                $rank = (int) $card['rank'];
                $badgeLabel = (string) ($card['badge'] ?? '');
                $hasDiscount = (float) $product->original_price > (float) $product->price && (float) $product->original_price > 0;
                $discountPct = $hasDiscount ? round(((float) $product->original_price - (float) $product->price) / (float) $product->original_price * 100) : 0;
                $specs = $card['specs'] ?? [];
            @endphp

            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:gap-6">
                    <x-shortcodes.thumb :product="$product" />
                    <div class="min-w-0 flex-1">
                        <x-shortcodes.rank-badge :rank="$rank" :label="$badgeLabel" />
                        <h3 class="text-lg font-black text-ink">{{ $rank }}. {{ $product->title }}</h3>
                        <div class="mt-1 text-xs font-medium text-slate-500">{{ $product->brand ?: 'معلوم' }} · ASIN: {{ $product->asin ?: 'N/A' }}</div>
                        @if (filled($card['verdict'] ?? null))
                            <p class="mt-2 text-sm leading-relaxed text-ink/70">{{ $card['verdict'] }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($hasDiscount)
                                <span class="rounded-md bg-primary-50 px-2 py-0.5 text-xs font-bold text-primary-700">خصم {{ $discountPct }}%</span>
                            @endif
                            <span class="inline-flex items-center gap-1 rounded-lg border border-primary-200 bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-900">
                                <svg class="h-3.5 w-3.5 text-primary-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg> {{ number_format((float) $product->rating, 1) }} من 5 <span class="text-slate-500">({{ (int) $product->review_count }} مراجعة)</span>
                            </span>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($hasDiscount)
                                <span class="text-sm font-semibold text-slate-500 line-through">{{ number_format((float) $product->original_price, 0) }} ج.م</span>
                            @endif
                            <span class="text-3xl font-black text-primary-700">{{ number_format((float) $product->price, 0) }} ج.م</span>
                        </div>
                        @php
                            $reviewArticle = \App\Models\Article::where('type', 'review')->where('product_id', $product->id)->where('is_published', true)->first();
                        @endphp
                        <div class="mt-4 flex flex-wrap items-center gap-3 pt-3 border-t border-slate-100">
                            <a
                                href="{{ $product->clean_affiliate_url }}"
                                target="_blank"
                                rel="nofollow sponsored noopener"
                                class="inline-flex h-10 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-primary-600 px-5 text-xs font-bold text-white shadow-md shadow-primary-600/20 transition-all hover:bg-primary-700 active:scale-95"
                            >
                                <span class="flex h-4 items-center justify-center rounded bg-white px-1">
                                    <img src="/icons/amazon.svg" alt="Amazon" width="12" height="12" loading="lazy" class="h-2.5 w-2.5 object-contain">
                                </span>
                                <span>اشترِ الآن</span>
                            </a>
                            @if ($reviewArticle)
                                <a
                                    href="{{ route('articles.show', $reviewArticle->slug) }}"
                                    class="inline-flex h-10 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 shadow-sm transition-all hover:border-primary-500 hover:text-primary-600 hover:shadow"
                                >
                                    <span>📄 قراءة المراجعة الشاملة وتجارب الفنيين</span>
                                    <span>👈</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
                @if (filled($card['specs_html'] ?? null))
                    <div class="border-t border-slate-100 bg-slate-50 px-6 py-4">
                        <div class="specs-md">
                            {!! $card['specs_html'] !!}
                        </div>
                    </div>
                @elseif (count(array_filter($specs, fn ($spec) => filled($spec['label'] ?? null))) > 0)
                    <div class="border-t border-slate-100 bg-slate-50 px-6 py-4">
                        <div class="grid gap-x-6 sm:grid-cols-2">
                            @foreach ($specs as $spec)
                                @if (blank($spec['label'] ?? null))
                                    @continue
                                @endif
                                <div class="flex justify-between gap-3 border-b border-slate-100 py-2 text-xs">
                                    <span class="font-medium text-slate-500">{{ $spec['label'] }}</span>
                                    <span class="text-left font-bold text-ink">{{ $spec['value'] ?? '—' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </article>
        @endforeach
    </div>
@endif