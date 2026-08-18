<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

class ArticlesApplyYearTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:apply-year-tokens
                            {--dry-run : Preview the changes without writing anything}
                            {--year=2026 : The four-digit year literal to rewrite as an evergreen [year] token}
                            {--content : Also rewrite the year literal inside the article body}
                            {--ids=* : Only rewrite these article id(s); defaults to every article}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replace literal year figures (e.g. 2026) in article titles, SEO titles and SEO descriptions with the evergreen [year] token so headlines always track the current year.';

    public function handle(): int
    {
        $year = (string) $this->option('year');

        if (! preg_match('/^\d{4}$/', $year)) {
            $this->error("Invalid --year \"{$year}\": expected a four-digit year such as 2026.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rewriteContent = (bool) $this->option('content');

        $ids = array_map('intval', $this->option('ids'));

        $patterns = [
            $this->yearPattern($year),
            $this->yearPattern($this->toArabicIndic($year)),
        ];

        $query = Article::query();

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No articles matched.');

            return self::SUCCESS;
        }

        $summary = [
            'title' => 0,
            'meta_title' => 0,
            'meta_description' => 0,
            'content' => 0,
        ];

        $this->line(($dryRun ? '[DRY-RUN] ' : '')."Scanning {$total} article(s) for the literal \"{$year}\" → [year]…");

        $query->orderBy('id')->chunkById(200, function ($articles) use ($patterns, $rewriteContent, $dryRun, &$summary) {
            foreach ($articles as $article) {
                $raw = $article->getAttributes();

                $changes = [];

                foreach (['title', 'meta_title', 'meta_description'] as $field) {
                    $value = $raw[$field] ?? null;

                    if (! is_string($value)) {
                        continue;
                    }

                    $rewritten = $this->rewrite($value, $patterns);

                    if ($rewritten !== $value) {
                        $changes[$field] = $rewritten;
                    }
                }

                if ($rewriteContent && is_string($raw['content'] ?? null)) {
                    $rewrittenContent = $this->rewrite($raw['content'], $patterns);

                    if ($rewrittenContent !== $raw['content']) {
                        $changes['content'] = $rewrittenContent;
                    }
                }

                if ($changes === []) {
                    continue;
                }

                foreach (array_keys($changes) as $field) {
                    $summary[$field]++;
                }

                $this->line("[{$article->id}] {$raw['title']}");

                foreach ($changes as $field => $value) {
                    $this->line("    {$field}: …{$this->cap($value)}");

                    if (! $dryRun) {
                        $article->{$field} = $value;
                    }
                }

                if (! $dryRun) {
                    $article->save();
                }
            }
        });

        $this->newLine();

        $message = sprintf(
            '%s: %d title(s), %d meta title(s), %d meta description(s)%s updated.',
            $dryRun ? 'Would update' : 'Done',
            $summary['title'],
            $summary['meta_title'],
            $summary['meta_description'],
            $rewriteContent ? sprintf(', %d content body', $summary['content']) : '',
        );

        $this->info($message);

        return self::SUCCESS;
    }

    /**
     * Build a regex that matches the given year only when it is not glued to
     * other digits, so "2026" inside "20260" or "12026" is never touched.
     */
    protected function yearPattern(string $year): string
    {
        return '/(?<![\d٠-٩])'.preg_quote($year, '/').'(?![\d٠-٩])/u';
    }

    protected function rewrite(string $value, array $patterns): string
    {
        $result = $value;

        foreach ($patterns as $pattern) {
            $result = preg_replace($pattern, '[year]', $result) ?? $result;
        }

        return $result;
    }

    protected function toArabicIndic(string $year): string
    {
        return strtr($year, [
            '0' => '٠',
            '1' => '١',
            '2' => '٢',
            '3' => '٣',
            '4' => '٤',
            '5' => '٥',
            '6' => '٦',
            '7' => '٧',
            '8' => '٨',
            '9' => '٩',
        ]);
    }

    protected function cap(string $value, int $length = 60): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length).'…';
    }
}
