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
        // [buy_button position="N"] and [summary_box pros=".." cons=".." verdict=".."]
        // resolved BEFORE markdown for the same reason: CommonMark HTML-encodes
        // the inner quotes, which would break the attribute parsing.
        $content = $this->replacePositionalBuyButtons($article->content, $article);
        $content = $this->replaceCustomSummaryBoxes($content, $article);

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
     * [summary_box pros="a|b" cons="x|y" verdict="z"] — custom copy per product.
     * Runs BEFORE markdown (quotes in attr values would be HTML-encoded).
     * Tokens without recognized attributes are left untouched for the adaptive
     * single/multi logic in replaceAdaptiveShortcodes().
     */
    protected function replaceCustomSummaryBoxes(string $content, Article $article): string
    {
        $single = $article->product;
        $compared = $this->comparedProducts($article);

        return preg_replace_callback('/\[summary_box([^\]]*)\]/u', function (array $matches) use ($single, $compared): string {
            $args = trim($matches[1]);

            if ($args === '') {
                return $matches[0];
            }

            $pros = $this->extractAttribute($args, 'pros');
            $cons = $this->extractAttribute($args, 'cons');
            $verdict = $this->extractAttribute($args, 'verdict');

            if ($pros === null && $cons === null && $verdict === null) {
                return $matches[0];
            }

            $prosArray = $pros !== null ? array_values(array_filter(array_map('trim', explode('|', $pros)))) : null;
            $consArray = $cons !== null ? array_values(array_filter(array_map('trim', explode('|', $cons)))) : null;

            if ($single !== null) {
                return $this->summaryBox($single, $prosArray, $consArray, $verdict);
            }

            return $this->summaryBoxes($compared, $prosArray, $consArray, $verdict);
        }, $content) ?? $content;
    }

    /**
     * Pull a single "name=\"value\"" attribute out of a shortcode arg string.
     */
    protected function extractAttribute(string $args, string $name): ?string
    {
        if (preg_match('/(?:^|\s)'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/u', $args, $match)) {
            $value = trim($match[1]);

            return $value !== '' ? $value : null;
        }

        return null;
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
     * @param  array<int, string>|null  $customPros
     * @param  array<int, string>|null  $customCons
     */
    protected function summaryBoxes(Collection $products, ?array $customPros = null, ?array $customCons = null, ?string $customVerdict = null): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ($products as $product) {
            $html .= $this->summaryBoxWithTitle($product, $customPros, $customCons, $customVerdict);
        }

        return '<div class="my-8 space-y-6" dir="rtl">'.$html.'</div>';
    }

    /**
     * [summary_box] + a bold product heading so readers know which device.
     *
     * @param  array<int, string>|null  $customPros
     * @param  array<int, string>|null  $customCons
     */
    protected function summaryBoxWithTitle(Product $product, ?array $customPros = null, ?array $customCons = null, ?string $customVerdict = null): string
    {
        return '<div>'
            .'<h4 class="mb-1 text-base font-black text-slate-900">'.e($product->title).'</h4>'
            .$this->summaryBox($product, $customPros, $customCons, $customVerdict)
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
        $items = $rows
            ->map(fn (ArticleProduct $row): array => [
                'product' => $row->product,
                'specs' => $this->specPairs($row),
            ])
            ->filter(fn (array $item): bool => $item['product'] !== null)
            ->values();

        if ($items->isEmpty()) {
            return '';
        }

        $specLabels = [];
        foreach ($items as $item) {
            foreach ($item['specs'] as $spec) {
                if (isset($spec['label']) && ! in_array($spec['label'], $specLabels, true)) {
                    $specLabels[] = $spec['label'];
                }
            }
        }

        return view('components.shortcodes.comparison-table', [
            'items' => $items->all(),
            'specLabels' => $specLabels,
        ])->render();
    }

    /**
     * [product_cards] — Rank-ordered (#1, #2, #3) rich review cards for each
     * comparison product: badge, live price, rating, quick verdict, and specs.
     *
     * @param  Collection<int, ArticleProduct>  $rows
     */
    public function productCards(Collection $rows): string
    {
        $cards = $rows
            ->map(fn (ArticleProduct $row, int $index): array => [
                'rank' => $index + 1,
                'product' => $row->product,
                'badge' => $row->badge_label,
                'verdict' => $row->quick_verdict,
                'specs' => $this->specPairs($row),
                'specs_html' => filled($row->specs_markdown)
                    ? $this->markdownToHtml((string) $row->specs_markdown)
                    : '',
            ])
            ->filter(fn (array $card): bool => $card['product'] !== null)
            ->values();

        if ($cards->isEmpty()) {
            return '';
        }

        return view('components.shortcodes.product-cards', [
            'cards' => $cards->all(),
        ])->render();
    }

    /**
     * Render the rank chip with an optional admin-curated badge label.
     */
    protected function rankBadge(int $rank, string $label = ''): string
    {
        return view('components.shortcodes.rank-badge', [
            'rank' => $rank,
            'label' => $label,
        ])->render();
    }

    /**
     * Small inline CTA used in product cards / comparison table.
     */
    protected function compactBuyButton(Product $product): string
    {
        return view('components.shortcodes.buy-button', [
            'product' => $product,
            'compact' => true,
        ])->render();
    }

    /**
     * Render a small lazy-loaded product thumbnail.
     */
    protected function thumbImage(Product $product): string
    {
        return view('components.shortcodes.thumb', [
            'product' => $product,
        ])->render();
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

    /**
     * Resolve a comparison product's specs: prefer the free-form Markdown
     * bullets (each "- **Label:** value"), falling back to the legacy JSON
     * repeater stored for older articles.
     *
     * @return array<int, array{label: ?string, value: ?string}>
     */
    protected function specPairs(ArticleProduct $row): array
    {
        $markdown = trim((string) $row->specs_markdown);

        if ($markdown === '') {
            return $this->normalizeSpecs($row->specs_json);
        }

        $pairs = [];

        foreach (preg_split('/\R/u', $markdown) as $line) {
            $line = trim($line);
            $line = preg_replace('/^[-*•]\s+/u', '', $line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/\*\*(.+?)\*\*\s*[:：]?\s*(.*)$/u', $line, $match)) {
                $pairs[] = [
                    'label' => trim($match[1], " \t\n\r\0\x0B:"),
                    'value' => trim($match[2]),
                ];
            } else {
                $pairs[] = ['label' => null, 'value' => $line];
            }
        }

        return $pairs;
    }

    public static function stripShortcodes(string $content): string
    {
        $content = preg_replace('/\[buy_button\s+position\s*=\s*["\']?(\d+)["\']?\]/i', '', $content) ?? $content;
        $content = preg_replace('/\[summary_box[^\]]*\]/u', '', $content) ?? $content;

        return str_replace(
            ['[price]', '[rating]', '[installment]', '[buy_button]', '[summary_box]', '[interactive_installment]', '[price_history]', '[comparison_table]', '[product_cards]'],
            '',
            $content
        );
    }

    protected function priceBadge(Product $product): string
    {
        return view('components.shortcodes.price-badge', [
            'product' => $product,
        ])->render();
    }

    protected function ratingWidget(Product $product): string
    {
        return view('components.shortcodes.rating-widget', [
            'product' => $product,
        ])->render();
    }

    protected function installmentBox(Product $product): string
    {
        return view('components.shortcodes.installment-box', [
            'product' => $product,
        ])->render();
    }

    protected function buyButton(Product $product): string
    {
        return view('components.shortcodes.buy-button', [
            'product' => $product,
            'compact' => false,
        ])->render();
    }

    /**
     * [summary_box] — Pros/Cons/verdict prefer author-supplied copy, fall back
     * to the component's data-driven + product-type-aware defaults.
     *
     * @param  array<int, string>|null  $customPros
     * @param  array<int, string>|null  $customCons
     */
    protected function summaryBox(Product $product, ?array $customPros = null, ?array $customCons = null, ?string $customVerdict = null): string
    {
        return view('components.shortcodes.summary-box', [
            'product' => $product,
            'custom_pros' => $customPros,
            'custom_cons' => $customCons,
            'custom_verdict' => $customVerdict,
        ])->render();
    }

    protected function interactiveInstallment(Product $product): string
    {
        return view('components.shortcodes.interactive-installment', [
            'product' => $product,
            'plans' => $product->getEligibleInstallmentPlans(),
        ])->render();
    }

    /**
     * [price_history] — بسيط وواضح: جدول أسعار بدون تعقيد.
     */
    protected function priceHistory(Product $product): string
    {
        return view('components.shortcodes.price-history', [
            'product' => $product,
        ])->render();
    }
}
