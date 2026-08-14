<?php

namespace App\Console\Commands;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use Illuminate\Console\Command;

class RenameArticleBrand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:rename-brand
                            {--dry-run : Preview the changes without writing anything}
                            {--purge : Dispatch a Cloudflare cache purge for every changed article}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replace the old brand name/domain in every article title, SEO title, SEO description and content.';

    /**
     * Old brand spellings mapped to their replacement.
     */
    private array $replacements = [
        'أمان ستريم' => 'أمان برايس',
        'أمانستريم' => 'أمان برايس',
        'امان ستريم' => 'أمان برايس',
        'امانستريم' => 'أمان برايس',
        'AmanStream' => 'AmanPrice',
        'amanstream.me' => 'amanprice.tech',
        'AMANSTREAM' => 'AMANPRICE',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $purge = (bool) $this->option('purge');

        $changed = 0;
        $purgeUrls = [];

        Article::query()
            ->orderBy('id')
            ->chunkById(200, function ($articles) use ($dryRun, $purge, &$changed, &$purgeUrls) {
                foreach ($articles as $article) {
                    $dirty = false;
                    $diffs = [];

                    foreach (['title', 'meta_title', 'meta_description', 'content'] as $field) {
                        $value = (string) $article->{$field};

                        if ($value === '') {
                            continue;
                        }

                        $newValue = $this->applyReplacements($value);

                        if ($newValue !== $value) {
                            if (! $dryRun) {
                                $article->{$field} = $newValue;
                            }
                            $dirty = true;
                            $diffs[] = "{$field}: ".mb_substr($value, 0, 40).' → '.mb_substr($newValue, 0, 40);
                        }
                    }

                    if (! $dirty) {
                        continue;
                    }

                    $changed++;

                    if (! $dryRun) {
                        $article->save();
                    }

                    if ($purge && $article->slug) {
                        $purgeUrls[] = route('articles.show', $article->slug, true);
                    }

                    $this->line("[{$article->id}] {$article->title}");
                    foreach ($diffs as $diff) {
                        $this->line("    {$diff}");
                    }
                }
            });

        $this->newLine();

        if ($dryRun) {
            $this->info("Dry-run finished: {$changed} article(s) would be updated.");
        } else {
            $this->info("Renamed brand in {$changed} article(s).");
        }

        if ($purge && ! $dryRun && count($purgeUrls) > 0) {
            $uniqueUrls = array_values(array_unique($purgeUrls));

            foreach (array_chunk($uniqueUrls, 250) as $chunk) {
                PurgeCloudflareCacheJob::dispatch($chunk);
            }

            $this->info("Dispatched Cloudflare cache purge for ".count($uniqueUrls)." article URL(s).");
        } elseif ($purge) {
            $this->line('No changed article URLs to purge.');
        }

        return self::SUCCESS;
    }

    private function applyReplacements(string $value): string
    {
        $result = $value;

        foreach ($this->replacements as $old => $new) {
            $result = str_replace($old, $new, $result);
        }

        return $result;
    }
}