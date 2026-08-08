<?php

namespace App\Services;

use App\Models\Article;
use App\Models\InstallmentPlan;
use App\Models\Product;
use Illuminate\Support\HtmlString;
use League\CommonMark\CommonMarkConverter;

class ShortcodeParser
{
    /**
     * Parse all supported shortcodes inside an article's content
     * and replace them with SEO-optimized, Tailwind-styled HTML components.
     */
    public function parse(Article $article): HtmlString
    {
        $content = $this->markdownToHtml($article->content);
        $product = $article->product;

        if ($product !== null) {
            $replacements = [
                '[price]' => fn (): string => $this->priceBadge($product),
                '[rating]' => fn (): string => $this->ratingWidget($product),
                '[installment]' => fn (): string => $this->installmentBox($product),
                '[buy_button]' => fn (): string => $this->buyButton($product),
                '[summary_box]' => fn (): string => $this->summaryBox($product),
                '[interactive_installment]' => fn (): string => $this->interactiveInstallment($product),
                '[price_history]' => fn (): string => $this->priceHistory($product),
            ];

            // Lazily render only the shortcodes actually used in the article,
            // so unused builders (e.g. DB-backed installment tables) never run.
            foreach ($replacements as $token => $builder) {
                if (str_contains($content, $token)) {
                    $content = str_replace($token, $builder(), $content);
                }
            }
        }

        return new HtmlString($content);
    }

    /**
     * Convert Markdown content to HTML. Existing HTML (e.g. RichEditor output
     * or shortcode placeholders) is passed through unchanged, so both Markdown
     * and HTML-authored content render correctly.
     */
    protected function markdownToHtml(string $content): string
    {
        return (new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]))->convert($content)->getContent();
    }

    /**
     * Remove every supported shortcode token from a raw content string,
     * useful for building clean meta descriptions and schema excerpts.
     */
    public static function stripShortcodes(string $content): string
    {
        return str_replace(
            ['[price]', '[rating]', '[installment]', '[buy_button]', '[summary_box]', '[interactive_installment]', '[price_history]'],
            '',
            $content
        );
    }

    /**
     * [price] — live price badge in EGP.
     */
    protected function priceBadge(Product $product): string
    {
        $formatted = number_format((float) $product->price, 2);

        return sprintf(
            '<span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 px-4 py-2 text-emerald-700 font-bold text-lg rtl:flex-row-reverse" role="status"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 6h18v13H3V6Zm9 2v4h5v3l4-1v2l-7 2H7V8h5Zm0 4V8h7l-1-2H4v3h8Z"/></svg>%s ج.م</span>',
            $formatted
        );
    }

    /**
     * [rating] — star rating widget.
     */
    protected function ratingWidget(Product $product): string
    {
        $rating = (float) $product->rating;
        $fullStars = (int) floor($rating);
        $starCount = $fullStars;

        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            $stars .= $i <= $starCount
                ? '<svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>'
                : '<svg class="w-5 h-5 text-gray-300" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>';
        }

        $reviewLabel = trans_choice(
            'built on :count review|built on :count reviews',
            $product->review_count,
            ['count' => number_format($product->review_count)]
        );

        return sprintf(
            '<div class="inline-flex items-center gap-2 rounded-xl bg-yellow-50 border border-yellow-200 px-4 py-2" role="status"><div class="flex flex-row-reverse" title="%s">%s</div><span class="text-sm font-medium text-yellow-900"><span class="font-bold">%s / 5</span> (%s)</span></div>',
            e($product->rating),
            $stars,
            number_format($rating, 1),
            $reviewLabel
        );
    }

    /**
     * [installment] — Egyptian BNPL / bank installment callout box.
     */
    protected function installmentBox(Product $product): string
    {
        $monthly = (float) $product->price / 12;

        return sprintf(
            <<<'HTML'
<div class="my-6 rounded-2xl border border-sky-200 bg-gradient-to-br from-sky-50 to-emerald-50 p-6">
    <div class="flex items-center gap-3 mb-4">
        <span class="grid place-items-center w-10 h-10 rounded-full bg-sky-600 text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 4h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2zm7 12h.01M7 16h.01M17 16h.01" /></svg>
        </span>
        <div>
            <h3 class="font-bold text-sky-900 text-lg">قسّط سعر الجهاز على 12 شهر</h3>
            <p class="text-sky-700 text-sm">دفعة شهرية تقريبية بدون فوائد</p>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-4">
        <div class="rounded-xl bg-white shadow-sm border border-sky-100 px-6 py-4 text-center">
            <div class="text-xs text-sky-600">الشهرية التقريبية</div>
            <div class="text-2xl font-black text-emerald-600">%s ج.م</div>
            <div class="text-xs text-gray-500">على 12 شهر</div>
        </div>
        <div class="text-sm text-sky-800 leading-relaxed">
            <strong>تقسيط 0%% فوائد</strong> متاح عبر البنك الأهلي المصري (NBE)،
            البنك التجاري الدولي (CIB)، أو <strong>فاليو valU</strong> — دون مقدم وبضمان راتب.
            توفر الخيارات الفصلية والأسبوعية للمتسوقين المصريين مرونة كاملة.
        </div>
    </div>
</div>
HTML,
            number_format($monthly, 2)
        );
    }

    /**
     * [buy_button] — high-converting, `nofollow sponsored` CTA to Amazon Egypt.
     */
    protected function buyButton(Product $product): string
    {
        if ($product->in_stock === false) {
            return sprintf(
                '<span class="inline-flex items-center gap-2 rounded-xl bg-gray-100 text-gray-500 px-6 py-3 font-bold">غير متوفر حاليًا</span>',
            );
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="nofollow sponsored noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg px-8 py-4 shadow-lg shadow-blue-500/30 transition-colors"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 6h18v13H3V6zm9 2v4h5l-4 5h-5l4-5H8l4-4z" opacity=".9"/></svg>اشترِ الآن من أمازون مصر</a>',
            e($product->affiliate_url)
        );
    }

    /**
     * [summary_box] — 2-column Pros & Cons / Quick Verdict card.
     */
    protected function summaryBox(Product $product): string
    {
        $pros = $this->pros($product);
        $cons = $this->cons($product);

        $proList = implode('', array_map(
            fn (string $item): string => sprintf(
                '<li class="flex items-start gap-2"><svg class="w-5 h-5 mt-0.5 shrink-0 text-green-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2l-3.5-3.5L4 14.2 9 19l11-11-1.5-1.5L9 16.2z"/></svg><span>%s</span></li>',
                $item
            ),
            $pros
        ));

        $conList = implode('', array_map(
            fn (string $item): string => sprintf(
                '<li class="flex items-start gap-2"><svg class="w-5 h-5 mt-0.5 shrink-0 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M6.2 5L5 6.2 10.8 12 5 17.8 6.2 19 12 13.2 17.8 19 19 17.8 13.2 12 19 6.2 17.8 5 12 10.8 6.2 5z"/></svg><span>%s</span></li>',
                $item
            ),
            $cons
        ));

        return sprintf(
            <<<'HTML'
<div class="my-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-200 bg-gray-50 px-6 py-3 flex items-center gap-2">
        <svg class="w-5 h-5 text-indigo-600" fill="currentColor" viewBox="0 0 24 24"><path d="M4 4h16v2H4V4zm0 4h16v2H4V8zm0 4h13v2H4v-2zm0 4h16v2H4v-2zm0 4h16v2H4v-2z" opacity=".7"/></svg>
        <h3 class="font-bold text-gray-800">ملخص سريع: %s</h3>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x rtl:divide-x-reverse divide-gray-200">
        <div class="p-5">
            <h4 class="font-bold text-green-700 mb-3 flex items-center gap-2"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2l-3.5-3.5L4 14.2 9 19l11-11-1.5-1.5L9 16.2z"/></svg>مميزات</h4>
            <ul class="space-y-2 text-sm text-gray-700">%s</ul>
        </div>
        <div class="p-5">
            <h4 class="font-bold text-red-700 mb-3 flex items-center gap-2"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M6.2 5L5 6.2 10.8 12 5 17.8 6.2 19 12 13.2 17.8 19 19 17.8 13.2 12 19 6.2 17.8 5 12 10.8 6.2 5z"/></svg>ملاحظات</h4>
            <ul class="space-y-2 text-sm text-gray-700">%s</ul>
        </div>
    </div>
    <div class="border-t border-gray-100 bg-indigo-50/50 px-6 py-4 text-sm text-gray-700">
        <strong>الحكم النهائي:</strong> %s
    </div>
</div>
HTML,
            e($product->title),
            $proList,
            $conList,
            $this->verdict($product)
        );
    }

    /**
     * Data-driven pros for the summary box.
     *
     * @return array<int, string>
     */
    protected function pros(Product $product): array
    {
        $pros = [];

        if ($product->brand !== null) {
            $pros[] = 'علامة تجارية موثوقة داخل السوق المصري ('.e($product->brand).') ومعروفة بجودة اللازمة المنزلية.';
        }

        if ((float) $product->rating >= 4.0) {
            $pros[] = 'تقييم مرتفع ('.number_format((float) $product->rating, 1).'/5) وإشادات إيجابية من المستخدمين.';
        } else {
            $pros[] = 'سعر تنافسي مميز مقارنة بالمنتجات المماثلة في الفئة.';
        }

        $pros[] = 'متاح للشراء حاليًا مع شحن سريع عبر أمازون مصر';
        $pros[] = 'خيارات تقسيط بدون فوائد على 12 شهر بطرق دفع محلية';

        return $pros;
    }

    /**
     * Data-driven cons for the summary box.
     *
     * @return array<int, string>
     */
    protected function cons(Product $product): array
    {
        $cons = [];

        if ((float) $product->price > 10000) {
            $cons[] = 'سعر مرتفع نسبيًا قد لا يناسب جميع الميزانيات.';
        } else {
            $cons[] = 'أداء قد لا يلبي احتياجات الاستخدام الاحترافي المكثف لبعض المستخدمين.';
        }

        if ((float) $product->rating < 4.0) {
            $cons[] = 'بعض المراجعات تشير إلى تجارب غير متسقة في الدعم الفني.';
        }

        $cons[] = 'يستلزم التحقق الدقيق من البائع والضمان المحلي قبل الشراء.';
        $cons[] = 'تتفاوت الأسعار بين الفترات وقد تتغير وفقًا للعروض.';

        return $cons;
    }

    /**
     * Quick verdict string built from product data.
     */
    protected function verdict(Product $product): string
    {
        $rating = (float) $product->rating;
        $price = (float) $product->price;

        if ($rating >= 4.3) {
            $base = 'خيار ممتاز يستحق الشراء';
        } elseif ($rating >= 3.8) {
            $base = 'خيار جيد ومتوازن ضمن فئته';
        } else {
            $base = 'اختيار مقبول بقيمة تتوافق مع السعر';
        }

        return sprintf(
            '%s مقارنةً بالميزات والأداء المتوقع (%s بتقييم %s/5). يكفي احتياجك اليومي بموازنة واضحة بين الثمن والجودة.',
            $base,
            e($product->title),
            number_format($rating, 1)
        );
    }

    /**
     * [interactive_installment] — database-driven Egyptian bank installment table.
     */
    protected function interactiveInstallment(Product $product): string
    {
        $plans = $product->getEligibleInstallmentPlans();
        $price = (float) $product->price;

        if ($plans->isEmpty()) {
            $minimum = (int) InstallmentPlan::query()
                ->whereHas('bank', fn ($query) => $query->where('is_active', true))
                ->min('min_order_amount');

            return sprintf(
                '<div class="my-6 rounded-2xl bg-gray-50 border border-gray-200 p-4 text-xs text-gray-600">💡 هذا المنتج بسعر (%s ج.م) متاح للشراء بالدفع المباشر/الكاش عبر أمازون مصر (الحد الأدنى للتقسيط البنكي%s).</div>',
                number_format($price, 2),
                $minimum ? ' هو '.number_format($minimum, 0, '', ',').' ج.م' : ' أعلى من سعر هذا الجهاز'
            );
        }

        $rows = '';
        foreach ($plans as $plan) {
            $monthly = $plan->calculateMonthlyPayment($price);
            $zeroBadge = $plan->is_zero_interest
                ? '<span class="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-0.5 rounded">0% فائدة</span>'
                : '<span class="text-[10px] text-gray-500">فائدة '.number_format((float) $plan->interest_rate, 2).'%</span>';

            $rows .= sprintf(
                '<tr class="border-b border-gray-100 hover:bg-sky-50/50 transition">
                    <td class="py-3 px-4 font-bold text-gray-900 text-xs">%s</td>
                    <td class="py-3 px-4 text-xs font-semibold text-gray-700">%d شهر</td>
                    <td class="py-3 px-4 font-black text-emerald-600 text-sm">%s ج.م/شهر</td>
                    <td class="py-3 px-4">%s</td>
                </tr>',
                e($plan->bank->name_ar),
                $plan->months,
                number_format($monthly, 2),
                $zeroBadge
            );
        }

        return sprintf(
            <<<'HTML'
<div class="my-8 overflow-hidden rounded-3xl border border-sky-100 bg-white shadow-md">
    <div class="p-5 text-white bg-slate-900 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="grid place-items-center w-10 h-10 rounded-xl bg-emerald-600">💳</span>
            <div>
                <h3 class="font-extrabold text-base">جدول الأقساط البنكية المتاحة لهذا الجهاز</h3>
                <p class="text-xs text-slate-300">محسوبة بالنظام المصرفي على سعر اليوم (%1$s ج.م)</p>
            </div>
        </div>
        <a href="%2$s" target="_blank" rel="nofollow sponsored noopener" class="hidden sm:inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-4 py-2.5 rounded-lg transition">
            اختر خطة التقسيط في أمازون 👈
        </a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-xs font-bold text-slate-600 border-b border-slate-100">
                <tr>
                    <th class="py-3 px-4">البنك / المزود</th>
                    <th class="py-3 px-4">مدة التقسيط</th>
                    <th class="py-3 px-4">القسط الشهري التقديري</th>
                    <th class="py-3 px-4">نوع العرض</th>
                </tr>
            </thead>
            <tbody>
                %3$s
            </tbody>
        </table>
    </div>
    <div class="p-4 bg-slate-50 text-[11px] text-slate-500 border-t border-slate-100 flex items-center justify-between">
        <span>⚠️ يتطلب بطاقة ائتمانية (Credit Card) مؤهلة من البنك المختار، وتتغير الشروط والعروض يوميّاً.</span>
        <a href="%2$s" target="_blank" rel="nofollow sponsored noopener" class="font-bold text-blue-600 underline">التوجه لصفحة الدفع بأمازون</a>
    </div>
</div>
HTML,
            number_format($price, 2),
            e($product->affiliate_url),
            $rows
        );
    }

    /**
     * [price_history] — Kanbakam-style price history barometer with a zero-JS
     * responsive SVG line chart. Rendered fully server-side (100/100 CWV).
     */
    protected function priceHistory(Product $product): string
    {
        $status = $product->getPriceStatus();
        $color = $status['color'];

        $current = (float) $product->price;
        $lowest = $product->getLowestRecordedPrice();
        $highest = $product->getHighestRecordedPrice();

        // Zero extra SQL — the last-10 window is already cached on the product row.
        $points = $product->getPriceHistoryPoints();

        $lowestDate = '—';
        $highestDate = '—';
        foreach ($points as $point) {
            if ((float) $point['price'] === $lowest) {
                $lowestDate = $point['date'];
            }

            if ((float) $point['price'] === $highest) {
                $highestDate = $point['date'];
            }
        }

        $discountPercent = $highest > $current && $highest > 0
            ? (int) round((1 - $current / $highest) * 100)
            : 0;

        $discountBadge = $discountPercent > 0
            ? '<span class="mt-1.5 inline-block rounded-full bg-red-50 px-2.5 py-0.5 text-[11px] font-bold text-red-600 border border-red-100">خصم '.$discountPercent.'% عن أعلى سعر</span>'
            : '<span class="mt-1.5 inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-600 border border-emerald-100">بأفضل سعر تاريخي 🔥</span>';

        $badge = sprintf(
            '<span class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-xs font-bold %s" role="status">%s</span>',
            match ($color) {
                'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'rose' => 'bg-rose-50 text-rose-700 border-rose-200',
                default => 'bg-sky-50 text-sky-700 border-sky-200',
            },
            $status['label']
        );

        $card = <<<'HTML'
<div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
    <div class="flex items-center gap-1.5 text-xs font-bold text-emerald-700">🟢 أقل سعر سُجِّل</div>
    <div class="mt-2 text-2xl font-black text-emerald-700">{{LOWEST}} ج.م</div>
    <div class="mt-1 text-[11px] text-emerald-600">سجّل في {{LOWEST_DATE}}</div>
</div>
HTML;
        $card = str_replace(['{{LOWEST}}', '{{LOWEST_DATE}}'], [number_format($lowest, 2), $lowestDate], $card);

        $cardCurrent = <<<'HTML'
<div class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4">
    <div class="flex items-center gap-1.5 text-xs font-bold text-blue-700">🔵 السعر الحالي اليوم</div>
    <div class="mt-2 text-2xl font-black text-blue-700">{{CURRENT}} ج.م</div>
    {{DISCOUNT_BADGE}}
</div>
HTML;
        $cardCurrent = str_replace(['{{CURRENT}}', '{{DISCOUNT_BADGE}}'], [number_format($current, 2), $discountBadge], $cardCurrent);

        $cardHigh = <<<'HTML'
<div class="rounded-2xl border border-rose-100 bg-rose-50/70 p-4">
    <div class="flex items-center gap-1.5 text-xs font-bold text-rose-700">🔴 أعلى سعر سُجِّل</div>
    <div class="mt-2 text-2xl font-black text-rose-700">{{HIGHEST}} ج.م</div>
    <div class="mt-1 text-[11px] text-rose-600">سجّل في {{HIGHEST_DATE}}</div>
</div>
HTML;
        $cardHigh = str_replace(['{{HIGHEST}}', '{{HIGHEST_DATE}}'], [number_format($highest, 2), $highestDate], $cardHigh);

        $chart = $this->priceHistoryChart($points, $color);

        $html = <<<'HTML'
<div class="my-10 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-900 px-5 py-4">
        <div class="flex items-center gap-3">
            <span class="grid place-items-center h-10 w-10 rounded-xl bg-emerald-600">📉</span>
            <div>
                <h3 class="font-extrabold text-base text-white">مؤشر أمان ستريم لتاريخ السعر (كان بكام)</h3>
                <p class="text-xs text-slate-400">تحليل ذكي لحركة السعر خلال الأشهر الماضية</p>
            </div>
        </div>
        {{BADGE}}
    </div>

    <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-3">
        {{CARD_LOW}}
        {{CARD_CURRENT}}
        {{CARD_HIGH}}
    </div>

    <div class="px-5 pb-5">{{CHART}}</div>

    <div class="flex flex-col gap-4 border-t border-slate-100 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between">
        <p class="max-w-xl text-sm leading-relaxed text-slate-600">💡 تتبع الأسعار: يقوم أمان ستريم بفرز وتتبع أسعار هذا المنتج يوميًا لمساعدتك على معرفة هل السعر الحالي فرصة جيدة أم لا.</p>
        <a href="{{URL}}" target="_blank" rel="nofollow sponsored noopener" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/30 transition-colors hover:bg-blue-700">
            اشترِ الآن بأفضل سعر من أمازون مصر 👈
        </a>
    </div>
</div>
HTML;

        return str_replace([
            '{{BADGE}}',
            '{{CARD_LOW}}',
            '{{CARD_CURRENT}}',
            '{{CARD_HIGH}}',
            '{{CHART}}',
            '{{URL}}',
        ], [
            $badge,
            $card,
            $cardCurrent,
            $cardHigh,
            $chart,
            e($product->affiliate_url),
        ], $html);
    }

    /**
     * Render a lightweight, fully server-rendered (zero-JS) responsive SVG line
     * chart mapping the historical price points to a 500x120 viewBox.
     *
     * @param  array<int, array{date: string, price: float}>  $points
     */
    protected function priceHistoryChart(array $points, string $accent = 'emerald'): string
    {
        if (count($points) < 2) {
            return '<div class="flex h-28 items-center justify-center rounded-xl bg-slate-50 text-xs text-slate-400">لا توجد بيانات سعرية كافية بعد — يتابع أمان ستريم هذا المنتج يوميًا.</div>';
        }

        $width = 500;
        $height = 120;
        $padTop = 16;
        $padRight = 18;
        $padBottom = 30;
        $padLeft = 54;

        $plotW = $width - $padLeft - $padRight;
        $plotH = $height - $padTop - $padBottom;
        $baseline = $padTop + $plotH;

        $prices = array_column($points, 'price');
        $minRaw = min($prices);
        $maxRaw = max($prices);
        $spread = $maxRaw - $minRaw;
        $pad = $spread > 0 ? $spread * 0.12 : ($maxRaw > 0 ? $maxRaw * 0.05 : 100);
        $min = max(0, $minRaw - $pad);
        $max = $maxRaw + $pad;

        $count = count($points);
        $coords = [];
        foreach ($points as $i => $point) {
            $x = $padLeft + ($count === 1 ? 0.5 : $i / ($count - 1)) * $plotW;
            $ratio = ($point['price'] - $min) / ($max - $min);
            $y = $padTop + (1 - $ratio) * $plotH;
            $coords[] = ['x' => round($x, 2), 'y' => round($y, 2)];
        }

        $line = $this->smoothLinePath($coords);
        $area = "{$line} L {$coords[$count - 1]['x']} {$baseline} L {$coords[0]['x']} {$baseline} Z";

        [$stroke, $fill] = match ($accent) {
            'rose' => ['#e11d48', '#e11d48'],
            'sky' => ['#0284c7', '#0284c7'],
            default => ['#059669', '#059669'],
        };

        $gradId = 'ph-grad-'.bin2hex(random_bytes(4));

        $axis = '';
        foreach ([0.0, 0.5, 1.0] as $ratio) {
            $y = $padTop + $ratio * $plotH;
            $value = $max - $ratio * ($max - $min);
            $axis .= sprintf(
                '<line x1="%1$d" y1="%2$s" x2="%3$d" y2="%2$s" stroke="#f1f5f9" stroke-width="1" />',
                $padLeft,
                round($y, 2),
                $width - $padRight
            );
            $axis .= sprintf(
                '<text x="%d" y="%s" text-anchor="end" fill="#94a3b8" font-size="10">%s</text>',
                $padLeft - 8,
                round($y + 3.5, 2),
                number_format($value, 0)
            );
        }

        $dates = '';
        foreach (array_values(array_unique([0, (int) floor(($count - 1) / 2), $count - 1])) as $idx) {
            $dates .= sprintf(
                '<text x="%s" y="%d" text-anchor="middle" fill="#94a3b8" font-size="10">%s</text>',
                $coords[$idx]['x'],
                $height - 14,
                e($points[$idx]['date'])
            );
        }

        $minIdx = array_search($minRaw, $prices, true);
        $maxIdx = array_search($maxRaw, $prices, true);

        $nodes = '';
        $nodes .= sprintf(
            '<circle cx="%s" cy="%s" r="8" fill="#10b981" opacity="0.18" /><circle cx="%s" cy="%s" r="5" fill="#10b981" stroke="#ffffff" stroke-width="2.5"><title>أقل سعر: %s في %s</title></circle>',
            $coords[$minIdx]['x'],
            $coords[$minIdx]['y'],
            $coords[$minIdx]['x'],
            $coords[$minIdx]['y'],
            number_format($minRaw, 2),
            e($points[$minIdx]['date'])
        );
        $nodes .= sprintf(
            '<circle cx="%s" cy="%s" r="8" fill="#f43f5e" opacity="0.18" /><circle cx="%s" cy="%s" r="5" fill="#f43f5e" stroke="#ffffff" stroke-width="2.5"><title>أعلى سعر: %s في %s</title></circle>',
            $coords[$maxIdx]['x'],
            $coords[$maxIdx]['y'],
            $coords[$maxIdx]['x'],
            $coords[$maxIdx]['y'],
            number_format($maxRaw, 2),
            e($points[$maxIdx]['date'])
        );

        return sprintf(
            <<<'SVG'
<svg class="w-full h-auto" viewBox="0 0 %1$d %2$d" role="img" aria-label="مخطط تاريخ أسعار المنتج بالجنيه المصري">
    <defs>
        <linearGradient id="%3$s" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="%4$s" stop-opacity="0.28" />
            <stop offset="1" stop-color="%4$s" stop-opacity="0" />
        </linearGradient>
    </defs>
    %5$s
    <path d="%6$s" fill="url(#%3$s)" />
    <path d="%7$s" fill="none" stroke="%4$s" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
    %8$s
    %9$s
</svg>
SVG,
            $width,
            $height,
            $gradId,
            $stroke,
            $axis,
            $area,
            $line,
            $nodes,
            $dates
        );
    }

    /**
     * Map points to a smooth continuous line using a Catmull-Rom spline
     * expressed as cubic beziers — no client-side smoothing required.
     *
     * @param  array<int, array{x: float, y: float}>  $points
     */
    protected function smoothLinePath(array $points): string
    {
        $count = count($points);
        $path = "M {$points[0]['x']} {$points[0]['y']}";

        if ($count === 2) {
            return $path." L {$points[1]['x']} {$points[1]['y']}";
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $points[max($i - 1, 0)];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[min($i + 2, $count - 1)];

            $path .= sprintf(
                ' C %s %s, %s %s, %s %s',
                round($p1['x'] + ($p2['x'] - $p0['x']) / 6, 2),
                round($p1['y'] + ($p2['y'] - $p0['y']) / 6, 2),
                round($p2['x'] - ($p3['x'] - $p1['x']) / 6, 2),
                round($p2['y'] - ($p3['y'] - $p1['y']) / 6, 2),
                $p2['x'],
                $p2['y']
            );
        }

        return $path;
    }
}
