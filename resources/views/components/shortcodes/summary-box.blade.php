@props([
    'product' => null,
    'custom_pros' => null,
    'custom_cons' => null,
    'custom_verdict' => null,
])

@php
    $titleD = (string) ($product?->title ?? '');
    $isPortable = \Illuminate\Support\Str::contains(mb_strtolower($titleD), ['محمول', 'متنقل', 'portable']);

    $pros = $custom_pros;
    if (!is_array($pros) || $pros === []) {
        if ($isPortable) {
            $pros = [
                'مثالي للشقق الإيجار والسكن المؤقت بدون أي تكسير في الحوائط.',
                'يوفر مصاريف فني التركيب وحوامل الجدار الخارجية (توفير أكثر من 700 ج).',
                'تصميم على 4 عجلات لسهولة التحريك والتنقل بين الغرف.',
                'جاهز للتشغيل المباشر بمجرد التوصيل بالفيشة وإخراج الخرطوم.',
            ];
        } else {
            $pros = [];
            if (filled($product?->brand)) {
                $pros[] = 'علامة تجارية موثوقة داخل السوق المصري ('.e($product->brand).').';
            }

            $priceS = (float) ($product?->price ?? 0);
            $originalS = (float) ($product?->original_price ?? 0);

            if ($originalS > $priceS && $originalS > 0) {
                $discountPct = round((($originalS - $priceS) / $originalS) * 100);
                $pros[] = "يتوفر عليه خصم حالياً بقيمة {$discountPct}% عن السعر الأصلي.";
            }

            if ((float) ($product?->rating ?? 0) >= 4.0) {
                $pros[] = 'تقييم مرتفع ('.number_format((float) $product->rating, 1).'/5) وإشادات إيجابية من المشتريين.';
            }

            if ($product?->supports_installment) {
                $pros[] = 'خيارات تقسيط مريحة بدون فوائد على 12 شهر مع البنوك المصرية.';
            }

            $pros[] = 'متاح مع خدمة الشحن المباشر والسريع عبر أمازون مصر.';
        }
    }

    $cons = $custom_cons;
    if (!is_array($cons) || $cons === []) {
        if ($isPortable) {
            $cons = [
                'يتطلب تثبيت خرطوم طرد الهواء الساخن المرفق في الشباك أو النافذة.',
                'مستوى الصوت أعلى قليلاً من الاسبليت بسبب وجود الكباس داخل الغرفة (54dB).',
                'يتطلب تفريغ وعاء مياه التكثيف (حوالي 2-3 لتر طوال الليل).',
                'مناسب للغرف المغلقة الصغرى حتى 12-14 متر مربع.',
            ];
        } else {
            $cons = [];
            if ((float) ($product?->price ?? 0) >= 15000) {
                $cons[] = 'سعر الجهاز ينتمي للفئة المتوسطة/العالية ويستلزم ميزانية مخصصة أو تقسيط.';
            }

            if ((float) ($product?->rating ?? 0) < 4.0) {
                $cons[] = 'تقييم المستخدمين متوسط ('.number_format((float) $product->rating, 1).'/5) مما يستدعي مراجعة تفاصيل الاستخدام.';
            }

            $cons[] = 'يتطلب التركيب عبر فنيين معتمدين لضمان سريان الضمان المحلي.';
            $cons[] = 'تتفاوت الأسعار وتتغير العروض دورياً حسب توفر المخزون.';
        }
    }

    $verdict = $custom_verdict;
    if (!is_string($verdict) || trim($verdict) === '') {
        if ($isPortable) {
            $verdict = 'اختيار مثالي لمن يعيشون في شقق بالإيجار أو سكن مؤقت ويريدون تبريداً فورياً بدون تكاليف تركيب أو تكسير في الحوائط.';
        } else {
            $ratingV = (float) ($product?->rating ?? 0);
            if ($ratingV >= 4.3) {
                $baseS = 'خيار ممتاز يستحق الشراء اعتماداً على أداء الجهاز المرتفع';
            } elseif ($ratingV >= 3.8) {
                $baseS = 'خيار جيد ومتوازن ضمن فئته السعرية';
            } else {
                $baseS = 'اختيار اقتصادي يلبي الاحتياجات الأساسية اليومية';
            }
            $verdict = sprintf(
                '%s (%s بتقييم %s/5). يوفر موازنة ملموسة بين الثمن والجودة.',
                $baseS,
                e($titleD),
                number_format($ratingV, 1)
            );
        }
    }
@endphp

<div class="my-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 bg-slate-50 px-6 py-3.5 flex items-center gap-2">
        <span class="text-primary-600 font-bold">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M4 4h16v2H4V4zm0 4h16v2H4V8zm0 4h16v2H4v-2zm0 4h10v2H4v-2zm13-1l5 5-1.5 1.5-3.5-3.5-1.5 1.5-3-3 1.5-1.5 3 3L17 15z"/></svg>
        </span>
        <h3 class="font-bold text-ink text-base">ملخص سريع: {{ $product?->title }}</h3>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x rtl:divide-x-reverse divide-slate-200">
        <div class="p-5">
            <h4 class="font-bold text-primary-700 mb-3 flex items-center gap-2">المميزات الرئيسية</h4>
            <ul class="space-y-2.5 text-sm text-ink/80">
                @foreach ($pros as $item)
                    <li class="flex items-start gap-2">
                        <svg class="w-5 h-5 mt-0.5 shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2l-3.5-3.5L4 14.2 9 19l11-11-1.5-1.5L9 16.2z"/></svg>
                        <span>{{ $item }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="p-5">
            <h4 class="font-bold text-ink mb-3 flex items-center gap-2">ملاحظات قبل الشراء</h4>
            <ul class="space-y-2.5 text-sm text-ink/80">
                @foreach ($cons as $item)
                    <li class="flex items-start gap-2">
                        <svg class="w-5 h-5 mt-0.5 shrink-0 text-ink/60" fill="currentColor" viewBox="0 0 24 24"><path d="M6.2 5L5 6.2 10.8 12 5 17.8 6.2 19 12 13.2 17.8 19 19 17.8 13.2 12 19 6.2 17.8 5 12 10.8 6.2 5z"/></svg>
                        <span>{{ $item }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    <div class="border-t border-slate-100 bg-primary-50/50 px-6 py-4 text-sm text-ink/80">
        <strong>الخلاصة والتقييم:</strong> {{ $verdict }}
    </div>
</div>