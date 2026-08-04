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
            $content = str_replace('[price]', $this->priceBadge($product), $content);
            $content = str_replace('[rating]', $this->ratingWidget($product), $content);
            $content = str_replace('[installment]', $this->installmentBox($product), $content);
            $content = str_replace('[buy_button]', $this->buyButton($product), $content);
            $content = str_replace('[summary_box]', $this->summaryBox($product), $content);
            $content = str_replace('[interactive_installment]', $this->interactiveInstallment($product), $content);
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
            ['[price]', '[rating]', '[installment]', '[buy_button]', '[summary_box]', '[interactive_installment]'],
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
}
