<?php

namespace App\Models;

use App\Services\SEOHelper;
use App\Services\ShortcodeParser;
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
        'meta_title',
        'meta_description',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
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
     * Title is normalized on every read so scraped pollution never leaks into
     * the H1, OG tags, meta title or JSON-LD on legacy rows.
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : SEOHelper::cleanTitle($value),
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
                $question = trim(strip_tags($match[1]));
                $answer = trim(strip_tags($match[2]));

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
                    $currentQuestion = trim($headingMatch[1]);

                    continue;
                }

                if ($currentQuestion !== null && $line !== '') {
                    $answerParts[] = $line;
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
        return preg_replace_callback(
            '/(?<![\d])\d[\d.,]*\s*(?:ج\.?\s?م\.?|جنيهاً\s*مصرياً|جنيهات\s*مصرية|جنيهات\s*مصرياً|جنيه\s*مصرياً|جنيه\s*مصري|جنيهات|جنيهاً|جنيه|EGP|LE|£)/iu',
            fn (): string => '[price]',
            $content
        ) ?? $content;
    }

    protected static function booted(): void
    {
        static::saving(function (Article $article) {
            if ($article->title !== null) {
                $article->title = SEOHelper::cleanTitle((string) $article->title);
            }

            if ($article->content !== null && $article->isDirty('content')) {
                $article->content = static::normalizeHardcodedPrices((string) $article->content);
            }
        });
    }
}
