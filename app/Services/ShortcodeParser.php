<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleProduct;
use App\Models\InstallmentPlan;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

class ShortcodeParser
{
    public function parse(Article $article): HtmlString
    {
        // Evergreen year token: [year], %%year%% and {year} in body prose render
        // as the current year before markdown so the number is NEVER HTML-encoded
        // as a link reference or wrapped in <em> by CommonMark.
        $content = str_replace(['[year]', '%%year%%', '{year}'], date('Y'), (string) $article->content);

        // [buy_button position="N"] and [summary_box pros=".." cons=".." verdict=".."]
        // resolved BEFORE markdown for the same reason: CommonMark HTML-encodes
        // the inner quotes, which would break the attribute parsing.
        $content = $this->replacePositionalBuyButtons($content, $article);
        $content = $this->replaceCustomSummaryBoxes($content, $article);

        $content = $this->markdownToHtml($content);

        $content = $this->replaceListicleShortcodes($content, $article);
        $content = $this->replaceAdaptiveShortcodes($content, $article);

        return new HtmlString($content);
    }

    /**
     * Render article content as clean, fully-populated Markdown for AI agents
     * / LLMs (the `Accept: text/markdown` variant). Every shortcode is
     * translated into dynamic text — live price, rating, installment, CTA link,
     * pros/cons verdict, comparison table or rank-ordered cards — instead of
     * being deleted, so agents never see empty bold labels like
     * "**السعر الحالي على أمازون مصر:** " or lose comparison data.
     *
     * Prices use the exact same formatting as the YAML frontmatter
     * (dot decimal, two digits, no thousands separator) so the two always
     * match 100% when an LLM cross-checks them.
     */
    public function parseForMarkdown(Article $article): string
    {
        $content = (string) $article->content;

        // Evergreen year token: rendered to the live year so agents reading the
        // Markdown variant see the same "2026" a browser does.
        $content = str_replace(['[year]', '%%year%%', '{year}'], date('Y'), $content);

        // [buy_button position="N"] — positional CTA for the Nth product.
        $content = preg_replace_callback(
            '/\[buy_button\s+position\s*=\s*["\']?(\d+)["\']?\]/i',
            function (array $matches) use ($article): string {
                $position = (int) $matches[1];
                $product = $this->productByPosition($article, $position);

                return $product !== null
                    ? $this->buyButtonMarkdown($product)
                    : '';
            },
            $content
        ) ?? $content;

        $single = $article->product;
        $compared = $this->comparedProducts($article);

        // [summary_box position="N" pros=".." cons=".." verdict=".."] — custom
        // Markdown box for a SINGLE compared product (N = 1-based pivot position),
        // so each product gets its own features/cons/verdict. Runs while the
        // token still exists so the adaptive [summary_box] below never sees it.
        $content = preg_replace_callback(
            '/\[summary_box\s+position\s*=\s*["\']?(\d+)["\']?([^\]]*)\]/iu',
            function (array $matches) use ($article): string {
                $position = (int) $matches[1];
                $product = $this->productByPosition($article, $position);

                if ($product === null) {
                    return '';
                }

                $args = trim($matches[2]);
                $pros = $this->extractAttribute($args, 'pros');
                $cons = $this->extractAttribute($args, 'cons');
                $verdict = $this->extractAttribute($args, 'verdict');

                return $this->summaryBoxMarkdown(
                    $product,
                    $pros !== null ? $this->splitList($pros) : null,
                    $cons !== null ? $this->splitList($cons) : null,
                    $verdict
                );
            },
            $content
        ) ?? $content;

        // [summary_box pros=".." cons=".." verdict=".."] — custom copy per
        // product, translated to Markdown. Runs while the token still exists.
        $content = preg_replace_callback(
            '/\[summary_box([^\]]*)\]/u',
            function (array $matches) use ($single, $compared): string {
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

                $prosArray = $pros !== null ? $this->splitList($pros) : null;
                $consArray = $cons !== null ? $this->splitList($cons) : null;

                return $single !== null
                    ? $this->summaryBoxMarkdown($single, $prosArray, $consArray, $verdict)
                    : $this->summaryBoxesMarkdown($compared, $prosArray, $consArray, $verdict);
            },
            $content
        ) ?? $content;

        // Plain [summary_box] — adaptive: single => its own story, multi => one
        // per compared product.
        if (str_contains($content, '[summary_box]')) {
            $markdown = $single !== null
                ? $this->summaryBoxMarkdown($single)
                : $this->summaryBoxesMarkdown($compared);
            $content = str_replace('[summary_box]', $markdown, $content);
        }

        if (str_contains($content, '[price]')) {
            $markdown = $single !== null
                ? $this->priceMarkdown($single)
                : $this->pricesMarkdown($compared);
            $content = str_replace('[price]', $markdown, $content);
        }

        if (str_contains($content, '[rating]')) {
            $markdown = $single !== null
                ? $this->ratingMarkdown($single)
                : $this->ratingsMarkdown($compared);
            $content = str_replace('[rating]', $markdown, $content);
        }

        // [installment] & [interactive_installment] BOTH translate to the same
        // Markdown sentence (the interactive widget is an HTML-only concern),
        // and an editor paste can double the plain token too. Render the line
        // exactly ONCE from the first occurrence and drop any duplicate
        // tokens, so agents never read the same installment line twice.
        if (str_contains($content, '[installment]') || str_contains($content, '[interactive_installment]')) {
            $markdown = $single !== null
                ? $this->installmentMarkdown($single)
                : $this->installmentsMarkdown($compared);

            $rendered = false;
            $content = preg_replace_callback(
                '/\[(?:interactive_)?installment\]/',
                function () use (&$rendered, $markdown): string {
                    if (! $rendered) {
                        $rendered = true;

                        return $markdown;
                    }

                    return '';
                },
                $content
            ) ?? $content;
        }

        if (str_contains($content, '[price_history]')) {
            $markdown = $single !== null
                ? $this->priceHistoryMarkdown($single)
                : $this->priceHistoriesMarkdown($compared);
            $content = str_replace('[price_history]', $markdown, $content);
        }

        if (str_contains($content, '[buy_button]')) {
            $markdown = $single !== null
                ? $this->buyButtonMarkdown($single)
                : $this->buyButtonsMarkdown($compared);
            $content = str_replace('[buy_button]', $markdown, $content);
        }

        if (str_contains($content, '[comparison_table]')) {
            $content = str_replace('[comparison_table]', $this->comparisonTableMarkdown($article), $content);
        }

        if (str_contains($content, '[product_cards]')) {
            $content = str_replace('[product_cards]', $this->productCardsMarkdown($article), $content);
        }

        return trim($content);
    }

    /**
     * Split a pipe-separated shortcode attribute ("a|b|c") into a clean array.
     *
     * @return array<int, string>
     */
    protected function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $value))));
    }

    /**
     * Frontmatter-aligned price formatting: "1000.00" — never "1,000.00".
     */
    protected function formatPrice(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    protected function priceMarkdown(Product $product): string
    {
        $cleanUrl = SEOHelper::cleanAffiliateUrl((string) $product->affiliate_url, (string) $product->asin);

        return (float) $product->price > 0
            ? '**'.$this->formatPrice((float) $product->price).' ج.م** (سعر محدث اليوم — [التحقق من السعر والضمان على أمازون مصر]('.$cleanUrl.'))'
            : 'السعر قيد التحديث ⏳';
    }

    protected function ratingMarkdown(Product $product): string
    {
        return (float) $product->rating > 0
            ? '**'.number_format((float) $product->rating, 1).' من 5 نجوم** ('.(int) $product->review_count.' مراجعة حقيقية)'
            : 'تقييم حديث 🌟';
    }

    /**
     * Cheapest eligible monthly payment (real bank plans when available, else a
     * 12-month straight split), always labelled as 0% interest.
     */
    protected function installmentMarkdown(Product $product): string
    {
        if ((float) $product->price <= 0) {
            return '';
        }

        $plans = $product->getEligibleInstallmentPlans();
        $monthly = $plans->isNotEmpty()
            ? $plans->map(fn (InstallmentPlan $plan) => $plan->calculateMonthlyPayment((float) $product->price))->min()
            : (float) $product->price / 12;

        return '**قسط شهري '.$this->formatPrice((float) $monthly).' ج.م** عبر البنوك المصرية (0% فائدة)';
    }

    protected function buyButtonMarkdown(Product $product): string
    {
        $cleanUrl = SEOHelper::cleanAffiliateUrl((string) $product->affiliate_url, (string) $product->asin);

        return filled($cleanUrl)
            ? '[صفحة العرض والضمان المعتمد لـ '.SEOHelper::cleanTitle((string) $product->title).' على أمازون مصر]('.$cleanUrl.')'
            : '';
    }

    /**
     * Compact trailing price window from price_history_json, newest last.
     */
    protected function priceHistoryMarkdown(Product $product): string
    {
        $points = array_slice((array) ($product->price_history_json ?? []), -5);

        if ($points === []) {
            return 'تتبع الأسعار اليومية متاح عبر صفحة المنتج.';
        }

        $lines = [];
        foreach ($points as $point) {
            $lines[] = '- '.($point['d'] ?? '').' : '.$this->formatPrice((float) ($point['p'] ?? 0)).' ج.م';
        }

        return "**آخر تحديثات السعر:**\n".implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function pricesMarkdown(Collection $products): string
    {
        return $products->map(fn (Product $p) => '- **'.$p->title.':** '.$this->priceMarkdown($p))->implode("\n");
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function ratingsMarkdown(Collection $products): string
    {
        return $products->map(fn (Product $p) => '- **'.$p->title.':** '.$this->ratingMarkdown($p))->implode("\n");
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function installmentsMarkdown(Collection $products): string
    {
        return $products
            ->map(fn (Product $p) => '- **'.$p->title.':** '.$this->installmentMarkdown($p))
            ->filter()
            ->implode("\n");
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function buyButtonsMarkdown(Collection $products): string
    {
        return $products
            ->map(fn (Product $p) => '- **'.$p->title.':** '.$this->buyButtonMarkdown($p))
            ->filter()
            ->implode("\n");
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    protected function priceHistoriesMarkdown(Collection $products): string
    {
        return $products
            ->map(fn (Product $p) => "**{$p->title}:**\n".$this->priceHistoryMarkdown($p))
            ->implode("\n\n");
    }

    /**
     * Pros/cons/verdict summary as Markdown. Mirrors the summary-box component:
     * author-supplied copy wins, otherwise portable-aware defaults derived from
     * the live product data (brand, discount, rating, installment).
     *
     * @param  array<int, string>|null  $customPros
     * @param  array<int, string>|null  $customCons
     */
    protected function summaryBoxMarkdown(Product $product, ?array $customPros = null, ?array $customCons = null, ?string $customVerdict = null): string
    {
        $title = (string) $product->title;
        $isPortable = Str::contains(mb_strtolower($title), ['محمول', 'متنقل', 'portable']);

        if ($isPortable) {
            $pros = $customPros ?? [
                'مثالي للشقق الإيجار والسكن المؤقت بدون أي تكسير في الحوائط.',
                'يوفر مصاريف فني التركيب وحوامل الجدار الخارجية (توفير أكثر من 700 ج).',
                'تصميم على 4 عجلات لسهولة التحريك والتنقل بين الغرف.',
                'جاهز للتشغيل المباشر بمجرد التوصيل بالفيشة وإخراج الخرطوم.',
            ];
            $cons = $customCons ?? [
                'يتطلب تثبيت خرطوم طرد الهواء الساخن المرفق في الشباك أو النافذة.',
                'مستوى الصوت أعلى قليلاً من الاسبليت بسبب وجود الكباس داخل الغرفة (54dB).',
                'يتطلب تفريغ وعاء مياه التكثيف (حوالي 2-3 لتر طوال الليل).',
                'مناسب للغرف المغلقة الصغرى حتى 12-14 متر مربع.',
            ];
            $verdict = $customVerdict ?? $this->dataGroundedVerdict($product);
        } else {
            $pros = $customPros;
            if (! is_array($pros) || $pros === []) {
                $pros = [];
                if (filled($product->brand)) {
                    $pros[] = 'علامة تجارية موثوقة داخل السوق المصري ('.$product->brand.').';
                }

                $price = (float) $product->price;
                $original = (float) $product->original_price;

                if ($original > $price && $original > 0) {
                    $pros[] = 'يتوفر عليه خصم حالياً بقيمة '.round((($original - $price) / $original) * 100).'% عن السعر الأصلي.';
                }

                if ((float) $product->rating >= 4.0) {
                    $pros[] = 'تقييم مرتفع ('.number_format((float) $product->rating, 1).' من 5) وإشادات إيجابية من المشتريين.';
                }

                if ($product->supports_installment) {
                    $pros[] = 'خيارات تقسيط مريحة بدون فوائد على 12 شهر مع البنوك المصرية.';
                }

                $pros[] = 'متاح مع خدمة الشحن المباشر والسريع عبر أمازون مصر.';
            }

            $cons = $customCons;
            if (! is_array($cons) || $cons === []) {
                $cons = [];
                if ((float) $product->price >= 15000) {
                    $cons[] = 'سعر الجهاز ينتمي للفئة المتوسطة/العالية ويستلزم ميزانية مخصصة أو تقسيط.';
                }

                if ((float) $product->rating > 0 && (float) $product->rating < 4.0) {
                    $cons[] = 'تقييم المستخدمين متوسط ('.number_format((float) $product->rating, 1).' من 5) مما يستدعي مراجعة تفاصيل الاستخدام.';
                }

                $cons[] = 'يتطلب التركيب عبر فنيين معتمدين لضمان سريان الضمان المحلي.';
                $cons[] = 'تتفاوت الأسعار وتتغير العروض دورياً حسب توفر المخزون.';
            }

            $verdict = $customVerdict ?? $this->dataGroundedVerdict($product);
        }

        $markdown = '### 💡 ملخص التقييم: '.$title."\n\n";
        $markdown .= "**المميزات الرئيسية:**\n";
        foreach ($pros as $item) {
            $markdown .= '- ✅ '.trim((string) $item)."\n";
        }
        $markdown .= "\n**ملاحظات قبل الشراء:**\n";
        foreach ($cons as $item) {
            $markdown .= '- ❌ '.trim((string) $item)."\n";
        }
        $markdown .= "\n**الخلاصة والتقييم:** ".trim((string) $verdict)."\n";

        return trim($markdown);
    }

    /**
     * A factual per-product verdict assembled entirely from live database
     * fields. Unlike the old templated sentence ("يوفر موازنة ملموسة بين
     * الثمن والجودة") this never repeats the same phrasing across different
     * products, and any number quoted (rating, reviews, price, discount) is
     * guaranteed to agree with the YAML frontmatter an LLM cross-checks.
     *
     * Custom author verdicts (quick_verdict / [summary_box verdict=".."])
     * always win — this is only the default fallback.
     */
    protected function dataGroundedVerdict(Product $product): string
    {
        $facts = [];

        $rating = (float) $product->rating;
        $reviews = (int) $product->review_count;

        if ($rating > 0 && $reviews > 0) {
            $facts[] = 'تقييم '.number_format($rating, 1).' من 5 بناءً على '.$reviews.' مراجعة حقيقية على أمازون مصر';
        } elseif ($rating > 0) {
            $facts[] = 'تقييم '.number_format($rating, 1).' من 5 على أمازون مصر';
        }

        $price = (float) $product->price;
        $original = (float) $product->original_price;

        if ($price > 0) {
            if ($original > $price && $original > 0) {
                $discount = round((($original - $price) / $original) * 100);
                $facts[] = 'يُباع بسعر '.$this->formatPrice($price).' ج.م بخصم '.$discount.'% عن السعر الأصلي ('.$this->formatPrice($original).' ج.م)';
            } else {
                $facts[] = 'يُباع حالياً بسعر '.$this->formatPrice($price).' ج.م';
            }
        }

        if ($product->supports_installment) {
            $facts[] = 'متاح بالتقسيط عبر البنوك المصرية';
        }

        if ($product->in_stock) {
            $facts[] = 'متوفر في المخزون';
        } else {
            $facts[] = 'غير متوفر في المخزون حالياً';
        }

        if ($facts === []) {
            return 'تتفاوت المواصفات والأسعار حسب توفر المخزون، ويُنصح بمقارنة العروض قبل الشراء.';
        }

        return implode('، ', $facts).'.';
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int, string>|null  $customPros
     * @param  array<int, string>|null  $customCons
     */
    protected function summaryBoxesMarkdown(Collection $products, ?array $customPros = null, ?array $customCons = null, ?string $customVerdict = null): string
    {
        return $products
            ->map(fn (Product $p) => $this->summaryBoxMarkdown($p, $customPros, $customCons, $customVerdict))
            ->implode("\n\n---\n\n");
    }

    /**
     * [comparison_table] — a Markdown comparison matrix for listicles.
     */
    protected function comparisonTableMarkdown(Article $article): string
    {
        $rows = $this->listicleProducts($article);

        if ($rows->isEmpty()) {
            return '';
        }

        $markdown = "| المنتج | السعر الحالي | رابط الشراء |\n| :--- | :--- | :--- |\n";

        foreach ($rows as $row) {
            $product = $row->product;

            if ($product === null) {
                continue;
            }

            $title = str_replace('|', '\\|', SEOHelper::cleanTitle((string) $product->title));
            $cleanUrl = SEOHelper::cleanAffiliateUrl((string) $product->affiliate_url, (string) $product->asin);

            $markdown .= sprintf(
                "| %s | %s ج.م | [صفحة الشراء والضمان](%s) |\n",
                $title,
                $this->formatPrice((float) $product->price),
                $cleanUrl
            );
        }

        return trim($markdown);
    }

    /**
     * [product_cards] — rank-ordered (#1, #2 ...) product list for listicles.
     */
    protected function productCardsMarkdown(Article $article): string
    {
        $rows = $this->listicleProducts($article);

        if ($rows->isEmpty()) {
            return '';
        }

        $markdown = "### قائمة المنتجات المقارنة:\n\n";

        foreach ($rows as $index => $row) {
            $product = $row->product;

            if ($product === null) {
                continue;
            }

            $rank = $index + 1;
            $markdown .= '#### #'.$rank.' '.SEOHelper::cleanTitle((string) $product->title)."\n";
            $markdown .= '- **السعر:** '.$this->formatPrice((float) $product->price)." ج.م\n";

            if ((float) $product->rating > 0) {
                $markdown .= '- **التقييم:** '.number_format((float) $product->rating, 1).'/5 ('.(int) $product->review_count." مراجعة)\n";
            }

            if (filled($row->badge_label)) {
                $markdown .= '- **الشارة:** '.$row->badge_label."\n";
            }

            if (filled($row->quick_verdict)) {
                $markdown .= '- **الحكم السريع:** '.$row->quick_verdict."\n";
            }

            if (filled($product->affiliate_url)) {
                $cleanUrl = SEOHelper::cleanAffiliateUrl((string) $product->affiliate_url, (string) $product->asin);
                $markdown .= '- [🔗 رابط العرض والضمان المعتمد على أمازون مصر]('.$cleanUrl.")\n";
            }

            $markdown .= "\n";
        }

        return trim($markdown);
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
     * [summary_box position="N" pros="a|b" cons="x|y" verdict="z"] — custom copy
     * for ONE compared product (N = 1-based pivot position), rendered with a
     * product heading so readers know which device.
     * Runs BEFORE markdown (quotes in attr values would be HTML-encoded).
     * Tokens without recognized attributes are left untouched for the adaptive
     * single/multi logic in replaceAdaptiveShortcodes().
     */
    protected function replaceCustomSummaryBoxes(string $content, Article $article): string
    {
        return preg_replace_callback('/\[summary_box([^\]]*)\]/u', function (array $matches) use ($article): string {
            $args = trim($matches[1]);

            if ($args === '') {
                return $matches[0];
            }

            $pros = $this->extractAttribute($args, 'pros');
            $cons = $this->extractAttribute($args, 'cons');
            $verdict = $this->extractAttribute($args, 'verdict');
            $position = $this->extractAttribute($args, 'position');

            if ($position !== null) {
                $product = $this->productByPosition($article, (int) $position);

                if ($product === null) {
                    return '';
                }

                return $this->summaryBoxWithTitle(
                    $product,
                    $pros !== null ? array_values(array_filter(array_map('trim', explode('|', $pros)))) : null,
                    $cons !== null ? array_values(array_filter(array_map('trim', explode('|', $cons)))) : null,
                    $verdict
                );
            }

            if ($pros === null && $cons === null && $verdict === null) {
                return $matches[0];
            }

            $prosArray = $pros !== null ? array_values(array_filter(array_map('trim', explode('|', $pros)))) : null;
            $consArray = $cons !== null ? array_values(array_filter(array_map('trim', explode('|', $cons)))) : null;

            $single = $article->product;
            if ($single !== null) {
                return $this->summaryBox($single, $prosArray, $consArray, $verdict);
            }

            return $this->summaryBoxes($this->comparedProducts($article), $prosArray, $consArray, $verdict);
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
     * Inline-safe stacked [price] badges, one per compared product.
     *
     * Each row is an inline <span> (never a block <div>) so an author who
     * writes "[price]" in the middle of a sentence — e.g. "من 500 إلى [price]"
     * — never breaks the paragraph or shifts layout (CLS). The price badge
     * component itself is already inline-flex; only the wrapper was block.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function priceBadges(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        $badges = $products->map(function (Product $product): string {
            return '<span class="inline-flex flex-wrap items-center gap-1.5">'
                .'<span class="text-sm font-bold text-slate-600">'.e($product->title).':</span>'
                .$this->priceBadge($product)
                .'</span>';
        });

        return '<span class="inline-flex flex-wrap items-center gap-x-3 gap-y-2" dir="rtl">'.$badges->implode('').'</span>';
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
        // Environment is built manually so GFM tables (piped "| a | b |" rows)
        // and strikethrough render properly in article bodies — plain CommonMark
        // does not include the Table extension and would leak raw pipes into the
        // published page.
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);

        return (new MarkdownConverter($environment))->convert($content)->getContent();
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
