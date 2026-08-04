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
            $articles = Article::where('is_published', true)->get();

            foreach ($articles as $article) {
                $urls[] = route('articles.show', $article->slug, true);
            }

            $this->info("Purging {$articles->count()} article(s) + homepage + sitemap.");
        } else {
            $this->info('Purging homepage + sitemap only.');
        }

        $uniqueUrls = array_unique($urls);

        PurgeCloudflareCacheJob::dispatch($uniqueUrls);

        $this->info('Dispatched PurgeCloudflareCacheJob with '.count($uniqueUrls).' URL(s).');
        $this->newLine();

        foreach ($uniqueUrls as $url) {
            $this->line("  {$url}");
        }

        return self::SUCCESS;
    }
}
