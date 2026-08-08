<?php

namespace App\Console\Commands;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use Illuminate\Console\Command;

class PurgeCloudflareCache extends Command
{
    protected $signature = 'cloudflare:purge
                            {--article= : Purge a specific article by slug}
                            {--all : Purge all published article URLs + homepage + sitemap}';

    protected $description = 'Manually dispatch a Cloudflare edge cache purge for one or all URLs.';

    public function handle(): int
    {
        $urls = [
            url('/'),
            url('/sitemap.xml'),
        ];

        if ($this->option('article')) {
            $slug = $this->option('article');
            $article = Article::where('slug', $slug)->first();

            if (! $article) {
                $this->error("Article with slug [{$slug}] not found.");

                return self::FAILURE;
            }

            $urls[] = route('articles.show', $article->slug, true);

            $this->info("Purging article: {$article->title}");
        } elseif ($this->option('all')) {
            $count = 0;

            Article::where('is_published', true)->chunk(500, function ($articles) use (&$urls, &$count) {
                foreach ($articles as $article) {
                    $urls[] = route('articles.show', $article->slug, true);
                    $count++;
                }
            });

            $this->info("Purging {$count} article(s) + homepage + sitemap.");
        } else {
            $this->info('Purging homepage + sitemap only.');
        }

        $uniqueUrls = array_values(array_unique($urls));
        $jobs = array_chunk($uniqueUrls, 250);

        foreach ($jobs as $chunk) {
            PurgeCloudflareCacheJob::dispatch($chunk);
        }

        $this->info('Dispatched '.count($jobs).' PurgeCloudflareCacheJob(s) with '.count($uniqueUrls).' URL(s) total.');
        $this->newLine();

        foreach ($uniqueUrls as $url) {
            $this->line("  {$url}");
        }

        return self::SUCCESS;
    }
}
