<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

class AuditLegacyArticlePatterns extends Command
{
    protected $signature = 'articles:audit-legacy-patterns {--json : Emit machine-readable output}';

    protected $description = 'Find published articles still carrying legacy templated verdicts or stale review counts';

    public function handle(): int
    {
        $rows = [];

        Article::query()
            ->where('is_published', true)
            ->with('product')
            ->orderBy('id')
            ->each(function (Article $article) use (&$rows): void {
                $content = (string) $article->content;
                $flags = [];

                foreach ($this->templateSentences() as $sentence) {
                    if (str_contains($content, $sentence)) {
                        $flags[] = 'templated-verdict:'.$sentence;
                    }
                }

                // Stale numeric review claims inside prose that disagree with the
                // live product.review_count (e.g. the 277 vs 284 batch bug).
                if ($article->product !== null && (int) $article->product->review_count > 0) {
                    $live = (int) $article->product->review_count;

                    if (preg_match_all('/(?:أكثر من|حوالي|بناءً على)\s*[\d.,]+\s*(?:مراجعة|مراجعات)/iu', $content, $matches)) {
                        foreach ($matches[0] as $claim) {
                            preg_match('/[\d.,]+/u', $claim, $num);
                            $claimed = (int) str_replace(['.', ','], '', $num[0] ?? '0');

                            if ($claimed > 0 && $claimed !== $live) {
                                $flags[] = 'stale-review-count:prose='.$claimed.' vs live='.$live;
                            }
                        }
                    }
                }

                if ($flags !== []) {
                    $rows[] = [
                        'id' => $article->id,
                        'slug' => $article->slug,
                        'title' => mb_substr((string) $article->title, 0, 60),
                        'flags' => $flags,
                    ];
                }
            });

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No published articles carry legacy templated verdicts or stale review counts.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($rows).' article(s) needing legacy-pattern review:');
        $this->newLine();

        foreach ($rows as $row) {
            $this->line(sprintf('#%d %s (/articles/%s)', $row['id'], $row['title'], $row['slug']));
            foreach ($row['flags'] as $flag) {
                $this->line('    - '.$flag);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * The banner sentences the legacy generator used for EVERY product verdict,
     * which AI agents were reading as independent assessments.
     *
     * @return array<int, string>
     */
    protected function templateSentences(): array
    {
        return [
            'يوفر موازنة ملموسة بين الثمن والجودة',
            'خيار ممتاز يستحق الشراء اعتماداً على أداء الجهاز المرتفع',
            'خيار جيد ومتوازن ضمن فئته السعرية',
            'اختيار اقتصادي يلبي الاحتياجات الأساسية اليومية',
        ];
    }
}