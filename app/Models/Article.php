<?php

namespace App\Models;

use App\Services\ArticleMediaService;
use App\Services\SEOHelper;
use App\Services\ShortcodeParser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Article extends Model
{
    protected $fillable = [
        'product_id',
        'category_id',
        'type',
        'title',
        'slug',
        'content',
        'comparison_markdown',
        'featured_image_url',
        'meta_title',
        'meta_description',
        'is_published',
        'gsc_clicks_30d',
        'gsc_impressions_30d',
        'gsc_ctr_30d',
        'gsc_position_30d',
        'gsc_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'gsc_clicks_30d' => 'integer',
            'gsc_impressions_30d' => 'integer',
            'gsc_ctr_30d' => 'float',
            'gsc_position_30d' => 'float',
            'gsc_synced_at' => 'datetime',
        ];
    }

    /**
     * Editorial cover image used by OG/Schema on content types with no product
     * card: the custom featured cover first, then the product image, then the
     * first in-article R2 image, then the brand fallback. Never the favicon —
     * a tiny icon fails Facebook cards and the Google Discover image pool.
     */
    public function getFeaturedImageUrlAttribute(): string
    {
        if (filled($this->getAttributeFromArray('featured_image_url'))) {
            return (string) $this->getAttributeFromArray('featured_image_url');
        }

        if (filled((string) ($this->product?->image_url))) {
            return (string) $this->product->image_url;
        }

        $contentImages = ArticleMediaService::extractR2Images($this->content);

        if ($contentImages !== []) {
            return $contentImages[0];
        }

        return SEOHelper::url('img/og-image.png');
    }

    /**
     * Primary cover image for article cards, the hero banner and OpenGraph
     * tagging, resolved through a smart fallback chain: a custom
     * `featured_image_url` wins, then the primary product image, then the
     * first compared/round-up product image, and finally the site favicon as
     * the absolute last resort.
     */
    public function getPrimaryImageUrlAttribute(): string
    {
        if (filled($this->getAttributeFromArray('featured_image_url'))) {
            return (string) $this->getAttributeFromArray('featured_image_url');
        }

        if ($this->product && filled($this->product->image_url)) {
            return $this->product->image_url;
        }

        $firstCompared = $this->products->first();
        if ($firstCompared && filled($firstCompared->image_url)) {
            return $firstCompared->image_url;
        }

        return SEOHelper::url('favicon.svg');
    }

    /**
     * Dual-article architecture helper: is this a product review/comparison?
     */
    public function isReview(): bool
    {
        return $this->type === 'review';
    }

    /**
     * Dual-article architecture helper: is this a general blog post/guide?
     */
    public function isBlog(): bool
    {
        return $this->type === 'blog';
    }

    public function scopeReviews(Builder $query): Builder
    {
        return $query->where('type', 'review');
    }

    public function scopeBlog(Builder $query): Builder
    {
        return $query->where('type', 'blog');
    }

    /**
     * Multi-product comparison rounds: a review with no primary product but at
     * least two compared devices on the article_product pivot.
     */
    public function scopeComparisons(Builder $query): Builder
    {
        return $query->reviews()
            ->whereNull('product_id')
            ->has('articleProducts', '>=', 2);
    }

    /**
     * Estimated editorial read time in minutes, derived from the whitespace-
     * separated word count of the content body (shortcodes stripped). Arabic
     * reading speed ~180-220 wpm; never returns 0 for a published post.
     */
    public function readMinutes(): int
    {
        $content = ShortcodeParser::stripShortcodes((string) $this->content);
        $words = Str::of(strip_tags($content))->squish()->split('/\s+/')->filter()->count();

        return max(1, (int) ceil($words / 200));
    }

    /**
     * Title is normalized and year-token-rendered on every read so scraped
     * pollution never leaks into the H1, OG tags, meta title or JSON-LD on
     * legacy rows, while `[year]` keeps evergreen headlines evergreen. The raw
     * column always stores the clean, un-rendered value — only reads carry the
     * current year.
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : SEOHelper::renderDynamicYear(SEOHelper::cleanTitle($value)),
            set: fn (?string $value): ?string => $value === null ? null : SEOHelper::cleanTitle($value),
        );
    }

    /**
     * meta_title supports the evergreen `[year]` token on read; null columns
     * stay null so Filament fill/`?:` semantics are untouched.
     */
    protected function metaTitle(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : SEOHelper::renderDynamicYear($value),
        );
    }

    /**
     * meta_description supports the evergreen `[year]` token on read; null
     * columns stay null so Filament fill/`?:` semantics are untouched.
     */
    protected function metaDescription(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : SEOHelper::renderDynamicYear($value),
        );
    }

    /**
     * Auto-extract FAQ Question/Answer pairs from the article content for the
     * FAQPage JSON-LD schema. Content is a Markdown/HTML mix, so both `<h3>`
     * + `<p>` blocks and `##`/`###` headings + following paragraphs are parsed.
     * Shortcodes are stripped first so widget placeholders never leak into the
     * answer text. Only headings that read as real questions are kept, which
     * keeps the emitted schema clean for Google AI Overviews.
     *
     * @return array<int, array{@type: string, name: string, acceptedAnswer: array{@type: string, text: string}}>
     */
    public function getFaqSchemaData(): array
    {
        $content = ShortcodeParser::stripShortcodes((string) $this->content);

        $faqs = [];

        // HTML form: <h3>Question</h3> immediately followed by a <p>Answer</p>.
        if (preg_match_all('/<h3[^>]*>(.*?)<\/h3>\s*<p[^>]*>(.*?)<\/p>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $question = SEOHelper::renderDynamicYear(trim(strip_tags($match[1])));
                $answer = SEOHelper::renderDynamicYear(trim(strip_tags($match[2])));

                if ($this->isPlausibleFaqPair($question, $answer)) {
                    $faqs[] = $this->faqEntry($question, $answer);
                }
            }
        }

        // Markdown form: "### Question" heading followed by paragraph line(s)
        // until the next heading — used by compact/DB-seeded articles.
        if ($faqs === []) {
            $currentQuestion = null;
            $answerParts = [];

            $flushCurrent = function () use (&$faqs, &$currentQuestion, &$answerParts): void {
                if ($currentQuestion === null) {
                    return;
                }

                $answer = trim(implode(' ', $answerParts));

                if ($this->isPlausibleFaqPair($currentQuestion, $answer)) {
                    $faqs[] = $this->faqEntry($currentQuestion, $answer);
                }

                $currentQuestion = null;
                $answerParts = [];
            };

            foreach (preg_split('/\R/u', $content) ?? [] as $line) {
                $line = trim($line);

                if (preg_match('/^#{2,3}\s+(.+)$/u', $line, $headingMatch)) {
                    $flushCurrent();
                    $currentQuestion = SEOHelper::renderDynamicYear(trim($headingMatch[1]));

                    continue;
                }

                if ($currentQuestion !== null && $line !== '') {
                    $answerParts[] = SEOHelper::renderDynamicYear($line);
                }
            }

            $flushCurrent();
        }

        // Google recommends capping FAQPage at ~10 questions per page.
        return array_slice($faqs, 0, 10);
    }

    /**
     * Build a single FAQPage Question/Answer node with sane length caps.
     *
     * Answers are capped by characters, but never cut in the middle of a word:
     * a mid-word truncation looks like a typo to Google's answer extraction and
     * corrupts the AI Overview snippet. Except that the truncation stops at the
     * last space before the cap, so "والمكاتب" never becomes "والم".
     *
     * @return array{@type: string, name: string, acceptedAnswer: array{@type: string, text: string}}
     */
    protected function faqEntry(string $question, string $answer): array
    {
        return [
            '@type' => 'Question',
            'name' => mb_strimwidth($question, 0, 150),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $this->truncateAtWordBoundary($answer, 500),
            ],
        ];
    }

    /**
     * Character-cap a string at the last word boundary before the limit.
     *
     * Unlike mb_strimwidth/Str::limit this never splits a word, so schema text
     * keeps whole words only. When the whole string fits, it is returned
     * untouched. A tiebreaker space is not strictly required in Arabic, but
     * keeping ASCII/space readers in mind, a lone word longer than the cap is
     * returned whole rather than chopped.
     */
    protected function truncateAtWordBoundary(string $text, int $limit): string
    {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit, 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');

        return $lastSpace !== false
            ? mb_substr($cut, 0, $lastSpace, 'UTF-8')
            : $cut;
    }

    /**
     * Only keep pairs where the heading genuinely reads as a question, so
     * section headers like "المميزات الرئيسية" never become junk FAQ entries.
     */
    protected function isPlausibleFaqPair(string $question, string $answer): bool
    {
        $question = trim($question);
        $answer = trim($answer);

        if ($question === '' || $answer === '') {
            return false;
        }

        return str_contains($question, '؟')
            || str_contains($question, '?')
            || str_starts_with($question, 'س:')
            || str_starts_with($question, 'هل ')
            || str_starts_with($question, 'ما ')
            || str_starts_with($question, 'كيف')
            || str_starts_with($question, 'لماذا')
            || str_starts_with($question, 'إزاي');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Pivot rows managed from the Filament listicle section.
     *
     * @return HasMany<ArticleProduct, $this>
     */
    public function articleProducts(): HasMany
    {
        return $this->hasMany(ArticleProduct::class, 'article_id');
    }

    /**
     * The comparison/round-up products, ordered by their pivot sort_order.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'article_product')
            ->withPivot(['sort_order', 'badge_label', 'quick_verdict', 'specs_json', 'specs_markdown'])
            ->orderBy('article_product.sort_order');
    }

    /**
     * Daily Google Search Console performance records for this article.
     *
     * @return HasMany<ArticleSearchAnalytic, $this>
     */
    public function searchAnalytics(): HasMany
    {
        return $this->hasMany(ArticleSearchAnalytic::class, 'article_id');
    }

    /**
     * Eager-load GSC aggregates for a given date range as query aliases.
     * Used by the Filament table to make Clicks/Impressions/Position sortable at DB level.
     */
    public function scopeWithGscAggregates(Builder $query, \Carbon\CarbonInterface $startDate, \Carbon\CarbonInterface $endDate): Builder
    {
        $start = $startDate->format('Y-m-d');
        $end = $endDate->format('Y-m-d');

        return $query
            ->withSum(['searchAnalytics as gsc_clicks_sum' => fn (Builder $q) => $q->whereBetween('date', [$start, $end])], 'clicks')
            ->withSum(['searchAnalytics as gsc_impressions_sum' => fn (Builder $q) => $q->whereBetween('date', [$start, $end])], 'impressions')
            ->withAvg(['searchAnalytics as gsc_position_avg' => fn (Builder $q) => $q->whereBetween('date', [$start, $end])], 'position');
    }

    /**
     * Aggregate GSC metrics for any arbitrary date range.
     */
    public function getGscMetricsForPeriod(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $data = $this->searchAnalytics()
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->selectRaw('SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, AVG(position) as avg_pos')
            ->first();

        $clicks = (int) ($data->total_clicks ?? 0);
        $impressions = (int) ($data->total_impressions ?? 0);
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.00;
        $position = round((float) ($data->avg_pos ?? 0.0), 1);

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $position,
        ];
    }

    /**
     * Get daily chart data for the given period, suitable for Filament LineChart.
     *
     * @return array{labels: array<int, string>, clicks: array<int, int>, impressions: array<int, int>}
     */
    public function getGscChartData(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $rows = $this->searchAnalytics()
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->orderBy('date')
            ->get(['date', 'clicks', 'impressions']);

        $labels = [];
        $clicks = [];
        $impressions = [];

        foreach ($rows as $row) {
            $labels[] = $row->date->format('M d');
            $clicks[] = (int) $row->clicks;
            $impressions[] = (int) $row->impressions;
        }

        return [
            'labels' => $labels,
            'clicks' => $clicks,
            'impressions' => $impressions,
        ];
    }

    /**
     * Enforce dynamic pricing exclusively (GEO / AI-RAG correctness).
     *
     * Hardcoded price figures typed into prose (e.g. "بتكلفة اقتصادية تلامس
     * 1,475 جنيهاً مصرياً") go stale the moment the [price] shortcode price or
     * YAML frontmatter updates, and Google AI Overviews / Perplexity flag that
     * mismatch as inaccurate data. Any Egyptian-currency figure found in body
     * text is rewritten to the live [price] token so the rendered number always
     * tracks the database — a reverse-desync guard next to the generation
     * prompt rule ("no manual price numbers in paragraphs").
     *
     * Only numbers that are explicitly followed by an EGP currency marker are
     * touched, so real specs like "1.5 حصان" or "284 مراجعة" are never harmed.
     */
    public static function normalizeHardcodedPrices(string $content): string
    {
        $currency = 'ج\.?\s?م\.?|جنيهاً\s*مصرياً|جنيهات\s*مصرية|جنيهات\s*مصرياً|جنيه\s*مصرياً|جنيه\s*مصري|جنيهات|جنيهاً|جنيه|EGP|LE|£';
        $pattern = '/(?<![\d])\d[\d.,]*\s*(?:'.$currency.')/iu';

        // Track byte offset and previous match to detect accessory price ranges
        // like "700 إلى 850 جنيه" where the second number should also be skipped.
        $offset = 0;
        $prevCharPos = null;
        $prevWasAccessory = false;

        return preg_replace_callback(
            $pattern,
            function (array $m) use (&$offset, &$prevCharPos, &$prevWasAccessory, $content): string {
                $match = $m[0];
                $pos = strpos($content, $match, $offset);
                if ($pos === false) {
                    $pos = $offset;
                }
                $charPos = mb_strlen(substr($content, 0, $pos), 'UTF-8');
                $offset = $pos + strlen($match);

                $before25 = mb_substr($content, max(0, $charPos - 25), min(25, $charPos), 'UTF-8');
                $before8 = mb_substr($content, max(0, $charPos - 8), min(8, $charPos), 'UTF-8');

                // حامل/كابولي/مصاريف/تركيب within 25 chars; تكلفة only when directly before number
                $hasAccessoryContext = (bool) preg_match('/حامل|كابولي|مصاريف|تركيب/iu', $before25)
                    || (bool) preg_match('/تكلفة\s*$/iu', $before8);

                // Range continuation: "700 إلى 850 جنيه" — second number's immediate before is " إلى "
                // but it belongs to the same accessory phrase as the first number.
                if (! $hasAccessoryContext && $prevWasAccessory && $prevCharPos !== null) {
                    $distance = $charPos - $prevCharPos;
                    if ($distance > 0 && $distance < 30) {
                        $between = mb_substr($content, $prevCharPos, $distance, 'UTF-8');
                        if (mb_strpos($between, 'إلى') !== false) {
                            $hasAccessoryContext = true;
                        }
                    }
                }

                $prevCharPos = $charPos;
                $prevWasAccessory = $hasAccessoryContext;

                if ($hasAccessoryContext) {
                    return $match;
                }

                return '[price]';
            },
            $content
        ) ?? $content;
    }

    public function isComparison(): bool
    {
        return $this->articleProducts->whereNotNull('product_id')->count() >= 2;
    }

    public function isMultiVariant(): bool
    {
        if ($this->articleProducts->whereNotNull('product_id')->count() < 2) {
            return false;
        }

        // Legacy variant-family helper (now subsumed by isComparison for the
        // certified 3-tier architecture). Kept for backward-compatible view
        // logic but schema no longer emits ProductGroup.
        if ($this->products->isEmpty()) {
            return false;
        }

        $brands = $this->products
            ->pluck('brand')
            ->filter()
            ->map(fn ($b) => mb_strtolower(trim((string) $b)))
            ->unique();

        return $brands->count() === 1;
    }

    public function getLowestVariantPrice(): float
    {
        $prices = $this->products->where('in_stock', true)->pluck('price')->filter(fn ($p) => (float) $p > 0);
        if ($prices->isEmpty()) {
            $prices = $this->products->pluck('price')->filter(fn ($p) => (float) $p > 0);
        }

        return $prices->isNotEmpty() ? (float) $prices->min() : (float) ($this->product?->price ?? 0);
    }

    public function getHighestVariantPrice(): float
    {
        $prices = $this->products->pluck('price')->filter(fn ($p) => (float) $p > 0);

        return $prices->isNotEmpty() ? (float) $prices->max() : (float) ($this->product?->price ?? 0);
    }

    public function getMaxVariantDiscount(): int
    {
        $maxDiscount = 0;
        foreach ($this->products as $p) {
            $price = (float) $p->price;
            $original = (float) ($p->original_price ?? 0);
            if ($original > $price && $original > 0) {
                $discount = (int) round((($original - $price) / $original) * 100);
                if ($discount > $maxDiscount) {
                    $maxDiscount = $discount;
                }
            }
        }

        return $maxDiscount;
    }

    protected static function booted(): void
    {
        static::saving(function (Article $article) {
            // Clean the RAW attribute (bypassing the read-time year accessor),
            // so `[year]`-style evergreen tokens are never baked into the
            // database with a literal year.
            $rawTitle = $article->getAttributes()['title'] ?? null;
            if ($rawTitle !== null) {
                $article->title = SEOHelper::cleanTitle((string) $rawTitle);
            }

            if ($article->content !== null && $article->isDirty('content')) {
                // Normalize only single-product review articles. Blog posts and
                // comparison articles (no primary product_id) keep their
                // hardcoded prices intact so illustrative numbers stay as-is.
                // type === null means the DB default 'review' will apply.
                $isReview = ($article->type ?? 'review') === 'review';
                if ($isReview && $article->product_id !== null) {
                    $article->content = static::normalizeHardcodedPrices((string) $article->content);
                }
            }
        });
    }
}
