<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleProduct;
use App\Models\InstallmentPlan;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use League\CommonMark\CommonMarkConverter;

class ShortcodeParser
{
    public function parse(Article $article): HtmlString
    {
        // [buy_button position="N"] resolved BEFORE markdown: the CommonMark
        // converter HTML-encodes the inner quotes, which would break the regex.
        $content = $this->replacePositionalBuyButtons($article->content, $article);

        $content = $this->markdownToHtml($content);

        $content = $this->replaceListicleShortcodes($content, $article);
        $content = $this->replaceAdaptiveShortcodes($content, $article);

        return new HtmlString($content);
    }

    /**
     * [buy_button position="N"] — render the buy button for the Nth comparison
     * product only (1-based). Works for both single and multi articles.
     */
    protected function replacePositionalBuyButtons(string $content, Article $article): string
    {
        return preg_replace_callback(
            '/\[buy_button\s+position\s*=\s*["\']?(\d+)["\']?\]/i',
            function (array $matches) use ($article): string {
                $position = (int) $matches[1];
                $product = $this->productByPosition($article, $position);

                return $product !== null ? $this->buyButton($product) : '';
            },
            $content
        ) ?? $content;
    }

    /**
     * [comparison_table] & [product_cards] — only meaningful for listicles.
     */
    protected function replaceListicleShortcodes(string $content, Article $article): string
    {
        $rows = $this->listicleProducts($article);

        if ($rows->isEmpty()) {
            return $content;
        }

        if (str_contains($content, '[comparison_table]')) {
            $content = str_replace('[comparison_table]', $this->comparisonTable($rows), $content);
        }

        if (str_contains($content, '[product_cards]')) {
            $content = str_replace('[product_cards]', $this->productCards($rows), $content);
        }

        return $content;
    }

    /**
     * Single-product tokens that become adaptive for comparison articles:
     * when no single product exists but compared products do, they render for
     * ALL compared products. Otherwise fall back to the single product.
     */
    protected function replaceAdaptiveShortcodes(string $content, Article $article): string
    {
        $single = $article->product;
        $compared = $this->comparedProducts($article);

        // [summary_box] adapts: single -> product story; multi -> per-product stories
        if (str_contains($content, '[summary_box]')) {
            $html = $single !== null
                ? $this->summaryBox($single)
                : $this->summaryBoxes($compared);
            $content = str_replace('[summary_box]', $html, $content);
        }

        // [interactive_installment] — multi => stacked interactive plans.
        // NOTE: must run BEFORE plain [installment] since its token contains it.
        if (str_contains($content, '[interactive_installment]')) {
            $html = $single !== null
                ? $this->interactiveInstallment($single)
                : $this->interactiveInstallments($compared);
            $content = str_replace('[interactive_installment]', $html, $content);
        }

        // [installment] — multi => stacked boxes, one per compared product.
        if (str_contains($content, '[installment]')) {
            $html = $single !== null
                ? $this->installmentBox($single)
                : $this->installmentBoxes($compared);
            $content = str_replace('[installment]', $html, $content);
        }

        // [price_history] — multi => stacked Kanbakam-style bars.
        if (str_contains($content, '[price_history]')) {
            $html = $single !== null
                ? $this->priceHistory($single)
                : $this->priceHistories($compared);
            $content = str_replace('[price_history]', $html, $content);
        }

        // [buy_button] (no position) — multi => one CTA row per compared product.
        if (str_contains($content, '[buy_button]')) {
            $html = $single !== null
                ? $this->buyButton($single)
                : $this->multiBuyButtons($compared);
            $content = str_replace('[buy_button]', $html, $content);
        }

        // [price] — multi => per-product price badge.
        if (str_contains($content, '[price]')) {
            $html = $single !== null
                ? $this->priceBadge($single)
                : $this->priceBadges($compared);
            $content = str_replace('[price]', $html, $content);
        }

        // [rating] — multi => per-product rating widget.
        if (str_contains($content, '[rating]')) {
            $html = $single !== null
                ? $this->ratingWidget($single)
                : $this->ratingWidgets($compared);
            $content = str_replace('[rating]', $html, $content);
        }

        return $content;
    }

    /**
     * Comparison pivot rows sorted by their admin-defined ordering, eager linked
     * to products (also serves ItemList schema & Blade's listicle queries).
     *
     * @return Collection<int, ArticleProduct>
     */
    protected function listicleProducts(Article $article): Collection
    {
        return $article->articleProducts
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * Plain Product models from the comparison rows, in pivot order.
     *
     * @return Collection<int, Product>
     */
    protected function comparedProducts(Article $article): Collection
    {
        return $this->listicleProducts($article)
            ->map(fn (ArticleProduct $row) => $row->product)
            ->filter()
            ->values();
    }

    /**
     * Resolve either the Nth comparison product or the single linked product.
     */
    protected function productByPosition(Article $article, int $position): ?Product
    {
        if ($position >= 1) {
            $product = $this->comparedProducts($article)->get($position - 1);

            if ($product !== null) {
                return $product;
            }
        }

        return $article->product;
    }

    /**
     * Stacked [summary_box] panels, one per compared product.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function summaryBoxes(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= $this->summaryBoxWithTitle($product);
        }

        return '<div class="my-8 space-y-6" dir="rtl">'.$html.'</div>';
    }

    /**
     * [summary_box] + a bold product heading so readers know which device.
     */
    protected function summaryBoxWithTitle(Product $product): string
    {
        return '<div>'
            .'<h4 class="mb-1 text-base font-black text-slate-900">'.e($product->title).'</h4>'
            .$this->summaryBox($product)
            .'</div>';
    }

    /**
     * Stacked [installment] boxes, one per compared product.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function installmentBoxes(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= '<div>'
                .'<h4 class="mt-2 mb-1 font-bold text-slate-800">'.e($product->title).'</h4>'
                .$this->installmentBox($product)
                .'</div>';
        }

        return '<div class="my-8 space-y-4" dir="rtl">'.$html.'</div>';
    }

    /**
     * Stacked [interactive_installment] plans, one per compared product.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function interactiveInstallments(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= '<div>'
                .'<h4 class="mb-1 font-bold text-slate-800">'.e($product->title).'</h4>'
                .$this->interactiveInstallment($product)
                .'</div>';
        }

        return '<div class="my-8 space-y-8" dir="rtl">'.$html.'</div>';
    }

    /**
     * Stacked [price_history] bars, one per compared product.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function priceHistories(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= '<div>'
                .'<h4 class="mb-2 font-bold text-slate-800">'.e($product->title).'</h4>'
                .$this->priceHistory($product)
                .'</div>';
        }

        return '<div class="my-8 space-y-8" dir="rtl">'.$html.'</div>';
    }

    /**
     * [buy_button] (no position) inside a comparison article: one labeled CTA
     * row per compared product so no device is silently dropped.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function multiBuyButtons(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $buttons = '';

        foreach ($products as $product) {
            $buttons .= sprintf(
                '<div class="my-2 flex flex-col items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center">
                    <span class="text-center text-sm font-bold text-slate-900 sm:text-right">%s</span>
                    <span class="whitespace-nowrap text-sm font-black text-blue-700">%s ج.م</span>
                    %s
                </div>',
                e($product->title),
                number_format((float) $product->price, 0),
                $this->buyButton($product)
            );
        }

        return '<div class="my-6 space-y-4" dir="rtl">'.$buttons.'</div>';
    }

    /**
     * Stacked [price] badges, one per compared product.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function priceBadges(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= '<div>'
                .'<span class="mb-1 block text-xs font-bold text-slate-600">'.e($product->title).'</span>'
                .$this->priceBadge($product)
                .'</div>';
        }

        return '<div class="my-6 space-y-3" dir="rtl">'.$html.'</div>';
    }

    /**
     * Stacked [rating] widgets, one per compared product.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function ratingWidgets(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= '<div>'
                .'<span class="mb-1 block text-xs font-bold text-slate-600">'.e($product->title).'</span>'
                .$this->ratingWidget($product)
                .'</div>';
        }

        return '<div class="my-6 space-y-3" dir="rtl">'.$html.'</div>';
    }

    protected function markdownToHtml(string $content): string
    {
        return (new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]))->convert($content)->getContent();
    }

    /**
     * [comparison_table] — Responsive RTL comparison matrix built from the
     * article's attached products: product name, live price, buy link, and
     * every spec label shared across the selected devices.
     *
     * @param  Collection<int, ArticleProduct>  $rows
     */
    public function comparisonTable(Collection $rows): string
    {
        $products = $rows->map(fn (ArticleProduct $row) => $row->product)->filter();

        if ($products->isEmpty()) {
            return '';
        }

        $specLabels = [];
        foreach ($rows as $row) {
            foreach ($this->normalizeSpecs($row->specs_json) as $spec) {
                if (isset($spec['label']) && ! in_array($spec['label'], $specLabels, true)) {
                    $specLabels[] = $spec['label'];
                }
            }
        }

        $head = '<tr><th class="py-3 px-4 text-right">المنتج</th>';
        foreach ($products as $index => $product) {
            $head .= '<th class="py-3 px-4 text-center font-bold">'.($index + 1).'</th>';
        }
        $head .= '</tr>';

        $bodyRows = '';

        $cells = '<tr><th class="py-3 px-4 text-right font-bold text-slate-500">الصورة</th>';
        foreach ($rows as $row) {
            $product = $row->product;
            $cells .= '<td class="py-3 px-4 text-center">'.$this->thumbImage($product).'</td>';
        }
        $cells .= '</tr>';
        $bodyRows .= $cells;

        $cells = '<tr><th class="py-3 px-4 text-right font-bold text-slate-500">التقييم</th>';
        foreach ($rows as $row) {
            $product = $row->product;
            $cells .= '<td class="py-3 px-4 text-center text-sm font-semibold text-amber-600">⭐ '.number_format((float) $product->rating, 1).' / 5</td>';
        }
        $cells .= '</tr>';
        $bodyRows .= $cells;

        foreach ($specLabels as $label) {
            $cells = '<tr><th class="py-3 px-4 text-right font-bold text-slate-500">'.e($label).'</th>';
            foreach ($rows as $row) {
                $matched = null;
                foreach ($this->normalizeSpecs($row->specs_json) as $spec) {
                    if (($spec['label'] ?? '') === $label) {
                        $matched = $spec['value'] ?? '';
                        break;
                    }
                }
                $cells .= '<td class="py-3 px-4 text-center text-sm text-slate-700">'.e($matched ?? '—').'</td>';
            }
            $cells .= '</tr>';
            $bodyRows .= $cells;
        }

        $prices = '<tr class="bg-slate-50"><th class="py-3 px-4 text-right font-bold text-slate-500">السعر اليوم</th>';
        foreach ($rows as $row) {
            $product = $row->product;
            $prices .= '<td class="py-3 px-4 text-center"><span class="text-lg font-black text-blue-700">'.number_format((float) $product->price, 0).'</span> <span class="text-xs font-bold text-slate-500">ج.م</span></td>';
        }
        $prices .= '</tr>';
        $bodyRows .= $prices;

        $buys = '<tr><th class="py-3 px-4 text-right font-bold text-slate-500">الشراء</th>';
        foreach ($rows as $row) {
            $buys .= '<td class="py-3 px-4 text-center">'.$this->compactBuyButton($row->product).'</td>';
        }
        $buys .= '</tr>';
        $bodyRows .= $buys;

        return sprintf(
            <<<'HTML'
<div class="my-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <table class="w-full min-w-[720px] border-collapse text-sm">
        <thead class="bg-slate-900 text-white">
            %s
        </thead>
        <tbody class="divide-y divide-slate-100">
            %s
        </tbody>
    </table>
</div>
HTML,
            $head,
            $bodyRows
        );
    }

    /**
     * [product_cards] — Rank-ordered (#1, #2, #3) rich review cards for each
     * comparison product: badge, live price, rating, quick verdict, and specs.
     *
     * @param  Collection<int, ArticleProduct>  $rows
     */
    public function productCards(Collection $rows): string
    {
        $cards = '';

        foreach ($rows as $index => $row) {
            $product = $row->product;
            $rank = $index + 1;

            $badge = filled($row->badge_label)
                ? $this->rankBadge($rank, e($row->badge_label))
                : $this->rankBadge($rank);

            $verdict = filled($row->quick_verdict)
                ? '<p class="mt-2 text-sm leading-relaxed text-slate-600">'.e($row->quick_verdict).'</p>'
                : '';

            $specList = '';
            foreach ($this->normalizeSpecs($row->specs_json) as $spec) {
                if (blank($spec['label'])) {
                    continue;
                }
                $specList .= '<div class="flex justify-between gap-3 border-b border-slate-100 py-2 text-xs">'
                    .'<span class="font-medium text-slate-500">'.e($spec['label']).'</span>'
                    .'<span class="text-left font-bold text-slate-800">'.e($spec['value'] ?? '—').'</span></div>';
            }

            $hasDiscount = (float) $product->original_price > (float) $product->price && (float) $product->original_price > 0;
            $priceLine = $hasDiscount
                ? '<span class="text-sm font-semibold text-slate-400 line-through">'.number_format((float) $product->original_price, 0).' ج.م</span> <span class="text-3xl font-black text-blue-700">'.number_format((float) $product->price, 0).' ج.م</span>'
                : '<span class="text-3xl font-black text-blue-700">'.number_format((float) $product->price, 0).' ج.م</span>';

            $cards .= sprintf(
                <<<'HTML'
<article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:gap-6">
        %1$s
        <div class="min-w-0 flex-1">
            %2$s
            <h3 class="text-lg font-black text-slate-900">%3$d. %4$s</h3>
            <div class="mt-1 text-xs font-medium text-slate-500">%5$s · ASIN: %6$s</div>
            %7$s
            <div class="mt-3 flex flex-wrap items-center gap-2">
                %8$s
                <span class="inline-flex items-center gap-1 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800">⭐ %9$s / 5 (%10$d)</span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">%11$s</div>
        </div>
    </div>
    %12$s
</article>
HTML,
                $this->thumbImage($product),
                $badge,
                $rank,
                e($product->title),
                e($product->brand ?: 'معلوم'),
                e($product->asin ?: 'N/A'),
                $verdict,
                $hasDiscount ? '<span class="rounded-md bg-red-50 px-2 py-0.5 text-xs font-bold text-red-600">خصم '.round(((float) $product->original_price - (float) $product->price) / (float) $product->original_price * 100).'%</span>' : '',
                number_format((float) $product->rating, 1),
                (int) $product->review_count,
                $priceLine.$this->compactBuyButton($product),
                $specList === '' ? '' : '<div class="border-t border-slate-100 bg-slate-50 px-6 py-4"><div class="grid gap-x-6 sm:grid-cols-2">'.$specList.'</div></div>'
            );
        }

        return '<div class="my-8 space-y-6" dir="rtl">'.$cards.'</div>';
    }

    /**
     * Render the rank chip with an optional admin-curated badge label.
     */
    protected function rankBadge(int $rank, string $label = ''): string
    {
        $emoji = $this->emojiForRank($rank);

        if ($label === '') {
            $label = "الخيار رقم {$rank}";
        }

        return '<div class="mb-3 inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-bold text-amber-800">'.$emoji.' '.$label.'</div>';
    }

    /**
     * Small inline CTA used in product cards / comparison table.
     */
    protected function compactBuyButton(Product $product): string
    {
        if ($product->in_stock === false) {
            return '<span class="rounded-lg border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-500 cursor-not-allowed">⚠️ غير متوفر</span>';
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="nofollow sponsored noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 transition-colors hover:bg-blue-700">'
            .'<span class="flex items-center justify-center rounded-md bg-white px-1.5 py-1"><img src="/icons/amazon.png" alt="Amazon" width="60" height="20" loading="lazy" class="h-4 w-auto object-contain"></span>'
            .'اشترِ الآن</a>',
            e($product->affiliate_url)
        );
    }

    /**
     * Render a small lazy-loaded product thumbnail.
     */
    protected function thumbImage(Product $product): string
    {
        if (blank($product->image_url)) {
            return '<span class="grid h-20 w-20 shrink-0 place-items-center rounded-xl border border-slate-200 bg-slate-100 text-3xl">📦</span>';
        }

        return sprintf(
            '<img src="%s" alt="%s" width="80" height="80" loading="lazy" class="h-20 w-20 shrink-0 rounded-xl border border-slate-100 bg-white p-1 object-contain">',
            e($product->image_url),
            e($product->title)
        );
    }

    /**
     * Normalize the JSON specs array to a clean list of [label, value] pairs
     * regardless of how Filament shaped the nested repeater keying.
     *
     * @return array<int, array{label: ?string, value: ?string}>
     */
    protected function normalizeSpecs(mixed $specs): array
    {
        $specs = is_array($specs) ? $specs : json_decode((string) $specs, true);

        return collect($specs ?? [])
            ->filter(fn ($spec) => is_array($spec))
            ->map(fn ($spec): array => [
                'label' => $spec['label'] ?? null,
                'value' => $spec['value'] ?? null,
            ])
            ->values()
            ->all();
    }

    protected function emojiForRank(int $rank): string
    {
        return match ($rank) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => '⭐',
        };
    }

    public static function stripShortcodes(string $content): string
    {
        $content = preg_replace('/\[buy_button\s+position\s*=\s*["\']?(\d+)["\']?\]/i', '', $content) ?? $content;

        return str_replace(
            ['[price]', '[rating]', '[installment]', '[buy_button]', '[summary_box]', '[interactive_installment]', '[price_history]', '[comparison_table]', '[product_cards]'],
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
            '<a href="%s" target="_blank" rel="nofollow sponsored noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg px-8 py-4 shadow-lg shadow-blue-500/30 transition-colors">'
            .'<span class="flex items-center justify-center rounded-md bg-white px-2 py-1"><img src="/icons/amazon.png" alt="Amazon" width="80" height="24" loading="lazy" class="h-5 w-auto object-contain"></span>'
            .'اشترِ الآن</a>',
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
     * [price_history] — بسيط وواضح: جدول أسعار بدون تعقيد.
     */
    protected function priceHistory(Product $product): string
    {
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

        $discountVsMax = $highest > $current && $highest > 0
            ? (int) round((1 - $current / $highest) * 100)
            : 0;

        $chartHtml = $this->priceHistoryChart($points, $current, $lowest, $highest);

        return sprintf(
            <<<'HTML'
<div class="my-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900">
        📉 مؤشر أمان ستريم لتاريخ السعر
    </div>

    <table class="w-full text-right text-sm">
        <tbody class="divide-y divide-slate-100">
            <tr>
                <th class="px-4 py-2.5 font-semibold text-slate-500">أقل سعر سُجِّل</th>
                <td class="px-4 py-2.5 text-left font-bold text-slate-900">%1$s <span class="text-xs font-normal text-slate-400">ج.م</span></td>
                <td class="px-4 py-2.5 whitespace-nowrap text-left text-xs text-slate-400">%2$s</td>
            </tr>
            <tr class="bg-sky-50/70">
                <th class="px-4 py-2.5 font-bold text-slate-900">السعر الحالي اليوم</th>
                <td class="px-4 py-2.5 text-left text-lg font-black text-sky-700">%3$s <span class="text-xs font-normal text-slate-400">ج.م</span></td>
                <td class="px-4 py-2.5 whitespace-nowrap text-left text-xs font-bold text-emerald-600">%4$s</td>
            </tr>
            <tr>
                <th class="px-4 py-2.5 font-semibold text-slate-500">أعلى سعر سُجِّل</th>
                <td class="px-4 py-2.5 text-left font-bold text-slate-900">%5$s <span class="text-xs font-normal text-slate-400">ج.م</span></td>
                <td class="px-4 py-2.5 whitespace-nowrap text-left text-xs text-slate-400">%6$s</td>
            </tr>
        </tbody>
    </table>

    <div class="border-t border-slate-200 px-4 py-3">%7$s</div>

    <div class="flex flex-col items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row">
        <p class="text-xs text-slate-500">💡 أمان ستريم يتابع سعر هذا الجهاز يومياً ليساعدك في الشراء بأقل سعر.</p>
        <a href="%8$s" target="_blank" rel="nofollow sponsored noopener" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-sky-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-sky-500">
            <span class="flex items-center justify-center rounded bg-white px-1 py-0.5"><img src="/icons/amazon.png" alt="Amazon" width="48" height="16" loading="lazy" class="h-3 w-auto object-contain"></span>
            اشترِ الآن
        </a>
    </div>
</div>
HTML,
            number_format($lowest, 2),
            $lowestDate,
            number_format($current, 2),
            $discountVsMax > 0 ? "خصم {$discountVsMax}%" : '—',
            number_format($highest, 2),
            $highestDate,
            $chartHtml,
            e($product->affiliate_url)
        );
    }

    /**
     * Render the interactive Chart.js line chart or a clean range bar.
     */
    protected function priceHistoryChart(array $points, float $current, float $lowest, float $highest): string
    {
        if (count($points) >= 2) {
            return $this->priceChartCanvas($points);
        }

        $range = max(1, $highest - $lowest);
        $percent = min(100, max(0, round((($current - $lowest) / $range) * 100)));

        return sprintf(
            <<<'HTML'
<div class="flex items-center justify-between text-[11px] font-bold text-slate-400">
    <span>أدنى سعر (%s ج.م)</span>
    <span>أعلى سعر (%s ج.م)</span>
</div>
<div class="relative mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-200">
    <div class="absolute top-0 bottom-0 left-0 rounded-full bg-emerald-500" style="width: %d%%"></div>
</div>
HTML,
            number_format($lowest, 0),
            number_format($highest, 0),
            $percent
        );
    }

    /**
     * Interactive Chart.js line chart — tooltips show date + price on hover.
     */
    protected function priceChartCanvas(array $points): string
    {
        $labels = array_map(
            fn (array $point): string => e((string) $point['date']),
            $points
        );

        $prices = array_map(
            fn (array $point): float => (float) $point['price'],
            $points
        );

        return sprintf(
            <<<'HTML'
<div class="relative h-40 w-full sm:h-48">
    <canvas
        data-ph-chart="1"
        data-ph-labels="%s"
        data-ph-prices="%s"
        role="img"
        aria-label="الرسم البياني لحركة السعر"
        class="w-full"
    ></canvas>
</div>
HTML,
            e(json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            e(json_encode($prices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }
}
