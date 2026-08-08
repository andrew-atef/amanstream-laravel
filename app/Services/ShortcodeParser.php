<?php

namespace App\Services;

use App\Models\Article;
use App\Models\InstallmentPlan;
use App\Models\Product;
use Illuminate\Support\HtmlString;
use League\CommonMark\CommonMarkConverter;

class ShortcodeParser
{
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

            foreach ($replacements as $token => $builder) {
                if (str_contains($content, $token)) {
                    $content = str_replace($token, $builder(), $content);
                }
            }
        }

        return new HtmlString($content);
    }

    protected function markdownToHtml(string $content): string
    {
        return (new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]))->convert($content)->getContent();
    }

    public static function stripShortcodes(string $content): string
    {
        return str_replace(
            ['[price]', '[rating]', '[installment]', '[buy_button]', '[summary_box]', '[interactive_installment]', '[price_history]'],
            '',
            $content
        );
    }

    protected function priceBadge(Product $product): string
    {
        $formatted = number_format((float) $product->price, 2);

        return sprintf(
            '<span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 px-4 py-2 text-emerald-700 font-bold text-lg rtl:flex-row-reverse" role="status"><svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 6h18v13H3V6Zm9 2v4h5v3l4-1v2l-7 2H7V8h5Zm0 4V8h7l-1-2H4v3h8Z"/></svg>%s ج.م</span>',
            $formatted
        );
    }

    protected function ratingWidget(Product $product): string
    {
        $rating = (float) $product->rating;
        $fullStars = (int) floor($rating);

        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            $stars .= $i <= $fullStars
                ? '<svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>'
                : '<svg class="w-5 h-5 text-slate-200" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.9 6.3 6.9.9-5 4.8 1.2 6.9-6-3.2-6 3.2 1.2-6.9-5-4.8 6.9-.9L12 2z"/></svg>';
        }

        $reviewLabel = trans_choice(
            'built on :count review|built on :count reviews',
            $product->review_count,
            ['count' => number_format($product->review_count)]
        );

        return sprintf(
            '<div class="inline-flex items-center gap-2 rounded-xl bg-amber-50 border border-amber-200 px-4 py-2" role="status"><div class="flex flex-row-reverse" title="%s">%s</div><span class="text-sm font-medium text-amber-900"><span class="font-bold">%s / 5</span> (%s)</span></div>',
            e($product->rating),
            $stars,
            number_format($rating, 1),
            $reviewLabel
        );
    }

    protected function installmentBox(Product $product): string
    {
        $monthly = (float) $product->price / 12;

        return sprintf(
            <<<'HTML'
<div class="my-6 rounded-2xl border border-sky-200 bg-gradient-to-br from-sky-50 to-emerald-50 p-6">
    <div class="flex items-center gap-3 mb-4">
        <span class="grid place-items-center w-10 h-10 rounded-full bg-sky-600 text-white shadow-sm">
            💳
        </span>
        <div>
            <h3 class="font-bold text-sky-900 text-lg">قسّط سعر الجهاز على 12 شهر</h3>
            <p class="text-sky-700 text-sm">دفعة شهرية تقريبية بدون فوائد عبر البنوك المصرية</p>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-4">
        <div class="rounded-xl bg-white shadow-sm border border-sky-100 px-6 py-4 text-center">
            <div class="text-xs text-sky-600 font-medium">الشهرية التقريبية</div>
            <div class="text-2xl font-black text-emerald-600">%s ج.م</div>
            <div class="text-xs text-slate-500">على 12 شهر</div>
        </div>
        <div class="text-sm text-sky-800 leading-relaxed max-w-xl">
            <strong>تقسيط 0%% فائدة</strong> متاح عبر البنك الأهلي المصري (NBE)،
            البنك التجاري الدولي (CIB)، أو <strong>فاليو valU</strong> — مع خيارات مرنة تناسب الميزانية.
        </div>
    </div>
</div>
HTML,
            number_format($monthly, 2)
        );
    }

    protected function buyButton(Product $product): string
    {
        if ($product->in_stock === false) {
            return sprintf(
                '<span class="inline-flex items-center gap-2 rounded-xl bg-slate-100 border border-slate-200 text-slate-500 px-6 py-3 font-bold">⚠️ غير متوفر حالياً بأمازون</span>',
            );
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="nofollow sponsored noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg px-8 py-4 shadow-lg shadow-blue-500/30 transition-colors">اشترِ الآن من أمازون مصر 👈</a>',
            e($product->affiliate_url)
        );
    }

    /**
     * [summary_box] — Coherent, non-contradictory Pros & Cons.
     */
    protected function summaryBox(Product $product): string
    {
        $pros = $this->pros($product);
        $cons = $this->cons($product);

        $proList = implode('', array_map(
            fn (string $item): string => sprintf(
                '<li class="flex items-start gap-2"><svg class="w-5 h-5 mt-0.5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2l-3.5-3.5L4 14.2 9 19l11-11-1.5-1.5L9 16.2z"/></svg><span>%s</span></li>',
                $item
            ),
            $pros
        ));

        $conList = implode('', array_map(
            fn (string $item): string => sprintf(
                '<li class="flex items-start gap-2"><svg class="w-5 h-5 mt-0.5 shrink-0 text-rose-500" fill="currentColor" viewBox="0 0 24 24"><path d="M6.2 5L5 6.2 10.8 12 5 17.8 6.2 19 12 13.2 17.8 19 19 17.8 13.2 12 19 6.2 17.8 5 12 10.8 6.2 5z"/></svg><span>%s</span></li>',
                $item
            ),
            $cons
        ));

        return sprintf(
            <<<'HTML'
<div class="my-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 bg-slate-50 px-6 py-3.5 flex items-center gap-2">
        <span class="text-blue-600 font-bold">📋</span>
        <h3 class="font-bold text-slate-800 text-base">ملخص سريع: %s</h3>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x rtl:divide-x-reverse divide-slate-200">
        <div class="p-5">
            <h4 class="font-bold text-emerald-700 mb-3 flex items-center gap-2">المميزات الرئيسية</h4>
            <ul class="space-y-2.5 text-sm text-slate-700">%s</ul>
        </div>
        <div class="p-5">
            <h4 class="font-bold text-rose-700 mb-3 flex items-center gap-2">ملاحظات قبل الشراء</h4>
            <ul class="space-y-2.5 text-sm text-slate-700">%s</ul>
        </div>
    </div>
    <div class="border-t border-slate-100 bg-blue-50/50 px-6 py-4 text-sm text-slate-700">
        <strong>الخلاصة والتقييم:</strong> %s
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
     * Non-contradictory, data-driven Pros.
     */
    protected function pros(Product $product): array
    {
        $pros = [];

        if (filled($product->brand)) {
            $pros[] = 'علامة تجارية موثوقة داخل السوق المصري ('.e($product->brand).').';
        }

        $price = (float) $product->price;
        $original = (float) ($product->original_price ?? 0);

        if ($original > $price && $original > 0) {
            $discountPct = round((($original - $price) / $original) * 100);
            $pros[] = "يتوفر عليه خصم حالياً بقيمة {$discountPct}% عن السعر الأصلي.";
        }

        if ((float) $product->rating >= 4.0) {
            $pros[] = 'تقييم مرتفع ('.number_format((float) $product->rating, 1).'/5) وإشادات إيجابية من المشتريين.';
        }

        if ($product->supports_installment) {
            $pros[] = 'خيارات تقسيط مريحة بدون فوائد على 12 شهر مع البنوك المصرية.';
        }

        $pros[] = 'متاح مع خدمة الشحن المباشر والسريع عبر أمازون مصر.';

        return $pros;
    }

    /**
     * Non-contradictory, data-driven Cons.
     */
    protected function cons(Product $product): array
    {
        $cons = [];
        $price = (float) $product->price;

        if ($price >= 15000) {
            $cons[] = 'سعر الجهاز ينتمي للفئة المتوسطة/العالية ويستلزم ميزانية مخصصة أو تقسيط.';
        }

        if ((float) $product->rating < 4.0) {
            $cons[] = 'تقييم المستخدمين متوسط ('.number_format((float) $product->rating, 1).'/5) مما يستدعي مراجعة تفاصيل الاستخدام.';
        }

        $cons[] = 'يتطلب التركيب عبر فنيين معتمدين لضمان سريان الضمان المحلي.';
        $cons[] = 'تتفاوت الأسعار وتتغير العروض دورياً حسب توفر المخزون.';

        return $cons;
    }

    protected function verdict(Product $product): string
    {
        $rating = (float) $product->rating;

        if ($rating >= 4.3) {
            $base = 'خيار ممتاز يستحق الشراء اعتماداً على أداء الجهاز المرتفع';
        } elseif ($rating >= 3.8) {
            $base = 'خيار جيد ومتوازن ضمن فئته السعرية';
        } else {
            $base = 'اختيار اقتصادي يلبي الاحتياجات الأساسية اليومية';
        }

        return sprintf(
            '%s (%s بتقييم %s/5). يوفر موازنة ملموسة بين الثمن والجودة.',
            $base,
            e($product->title),
            number_format($rating, 1)
        );
    }

    protected function interactiveInstallment(Product $product): string
    {
        $plans = $product->getEligibleInstallmentPlans();
        $price = (float) $product->price;

        if ($plans->isEmpty()) {
            return sprintf(
                '<div class="my-6 rounded-2xl bg-slate-50 border border-slate-200 p-4 text-xs text-slate-600">💡 هذا المنتج بسعر (%s ج.م) متاح للشراء الدفع المباشر/الكاش عبر أمازون مصر.</div>',
                number_format($price, 2)
            );
        }

        $rows = '';
        foreach ($plans as $plan) {
            $monthly = $plan->calculateMonthlyPayment($price);
            $zeroBadge = $plan->is_zero_interest
                ? '<span class="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-0.5 rounded">0% فائدة</span>'
                : '<span class="text-[10px] text-slate-500">فائدة '.number_format((float) $plan->interest_rate, 2).'%</span>';

            $rows .= sprintf(
                '<tr class="border-b border-slate-100 hover:bg-sky-50/50 transition">
                    <td class="py-3 px-4 font-bold text-slate-900 text-xs">%s</td>
                    <td class="py-3 px-4 text-xs font-semibold text-slate-700">%d شهر</td>
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
</div>
HTML,
            number_format($price, 2),
            e($product->affiliate_url),
            $rows
        );
    }

    /**
     * [price_history] — Sleek, accurate Kanbakam price barometer.
     */
    protected function priceHistory(Product $product): string
    {
        $status = $product->getPriceStatus();
        $color = $status['color'];

        $current = (float) $product->price;
        $lowest = $product->getLowestRecordedPrice();
        $highest = $product->getHighestRecordedPrice();

        $points = $product->getPriceHistoryPoints();

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

        $badgeClass = match ($color) {
            'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'rose' => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-sky-50 text-sky-700 border-sky-200',
        };

        $chartHtml = $this->priceHistoryChart($points, $current, $lowest, $highest, $color);

        return sprintf(
            <<<'HTML'
<div class="my-10 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-900 px-6 py-4">
        <div class="flex items-center gap-3">
            <span class="grid place-items-center h-10 w-10 rounded-xl bg-emerald-600 text-white font-bold">📉</span>
            <div>
                <h3 class="font-extrabold text-base text-white">مؤشر أمان ستريم لتاريخ السعر (كان بكام)</h3>
                <p class="text-xs text-slate-300">تحليل حركة السعر ومقارنته بأعلى وأقل سعر مسجل</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-xs font-bold %1$s" role="status">
            %2$s
        </span>
    </div>

    <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-3">
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
            <div class="flex items-center gap-1.5 text-xs font-bold text-emerald-700">🟢 أقل سعر سُجِّل</div>
            <div class="mt-2 text-2xl font-black text-emerald-700">%3$s ج.م</div>
            <div class="mt-1 text-[11px] text-emerald-600">تاريخ التسجيل: %4$s</div>
        </div>

        <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
            <div class="flex items-center gap-1.5 text-xs font-bold text-blue-700">🔵 السعر الحالي اليوم</div>
            <div class="mt-2 text-2xl font-black text-blue-700">%5$s ج.م</div>
            <div class="mt-1 text-[11px] text-blue-600">محدث مباشرة من أمازون مصر</div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
            <div class="flex items-center gap-1.5 text-xs font-bold text-slate-700">🔴 أعلى سعر سُجِّل</div>
            <div class="mt-2 text-2xl font-black text-slate-700">%6$s ج.م</div>
            <div class="mt-1 text-[11px] text-slate-500">تاريخ التسجيل: %7$s</div>
        </div>
    </div>

    <div class="px-6 pb-6">%8$s</div>

    <div class="flex flex-col gap-4 border-t border-slate-100 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between">
        <p class="max-w-xl text-xs leading-relaxed text-slate-600">💡 يتابع أمان ستريم أسعار هذا الجهاز يومياً لمساعدتك في معرفة الوقت الأنسب للشراء بأقل سعر.</p>
        <a href="%9$s" target="_blank" rel="nofollow sponsored noopener" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-3 text-xs font-bold text-white shadow-lg shadow-blue-500/30 transition-colors hover:bg-blue-700">
            اشترِ الآن بأفضل سعر من أمازون مصر 👈
        </a>
    </div>
</div>
HTML,
            $badgeClass,
            $status['label'],
            number_format($lowest, 2),
            $lowestDate,
            number_format($current, 2),
            number_format($highest, 2),
            $highestDate,
            $chartHtml,
            e($product->affiliate_url)
        );
    }

    /**
     * Render SVG chart or sleek position slider if historical points are scarce.
     */
    protected function priceHistoryChart(array $points, float $current, float $lowest, float $highest, string $accent): string
    {
        // If we have 2 or more points, render continuous SVG sparkline
        if (count($points) >= 2) {
            return $this->priceSvgSparkline($points, $accent);
        }

        // Sleek Range Bar for initial products
        $range = max(1, $highest - $lowest);
        $percent = min(100, max(0, round((($current - $lowest) / $range) * 100)));

        return sprintf(
            <<<'HTML'
<div class="rounded-2xl border border-slate-100 bg-slate-50 p-5">
    <div class="flex justify-between text-xs font-bold text-slate-600 mb-2">
        <span>أدنى سعر (%s ج.م)</span>
        <span>موقع السعر الحالي</span>
        <span>أعلى سعر (%s ج.م)</span>
    </div>
    <div class="relative h-3 w-full rounded-full bg-slate-200">
        <div class="absolute top-0 bottom-0 left-0 rounded-full bg-emerald-500" style="width: %d%%"></div>
        <div class="absolute top-1/2 -translate-y-1/2 h-5 w-5 rounded-full border-2 border-white bg-blue-600 shadow-md" style="right: calc(%d%% - 10px)"></div>
    </div>
</div>
HTML,
            number_format($lowest, 0),
            number_format($highest, 0),
            $percent,
            100 - $percent
        );
    }

    protected function priceSvgSparkline(array $points, string $accent): string
    {
        $width = 500;
        $height = 100;
        $padTop = 15;
        $padRight = 20;
        $padBottom = 25;
        $padLeft = 50;

        $plotW = $width - $padLeft - $padRight;
        $plotH = $height - $padTop - $padBottom;

        $prices = array_column($points, 'price');
        $minRaw = min($prices);
        $maxRaw = max($prices);
        $range = max(1, $maxRaw - $minRaw);

        $count = count($points);
        $coords = [];

        foreach ($points as $i => $point) {
            $x = $padLeft + ($i / max(1, $count - 1)) * $plotW;
            $ratio = ($point['price'] - $minRaw) / $range;
            $y = $padTop + (1 - $ratio) * $plotH;
            $coords[] = ['x' => round($x, 2), 'y' => round($y, 2)];
        }

        $path = "M {$coords[0]['x']} {$coords[0]['y']}";
        for ($i = 1; $i < $count; $i++) {
            $path .= " L {$coords[$i]['x']} {$coords[$i]['y']}";
        }

        $stroke = match ($accent) {
            'rose' => '#e11d48',
            'sky' => '#0284c7',
            default => '#059669',
        };

        return sprintf(
            '<svg class="w-full h-24" viewBox="0 0 %d %d" role="img"><path d="%s" fill="none" stroke="%s" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" /></svg>',
            $width,
            $height,
            $path,
            $stroke
        );
    }
}
