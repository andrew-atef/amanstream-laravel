<?php

namespace App\Observers;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;

class ArticleObserver
{
    /**
     * Purge Cloudflare and notify search engines whenever a published
     * article is created or saved. Fires on both inserts and updates,
     * so purge jobs are dispatched exactly once per save cycle.
     */
    public function saved(Article $article): void
    {
        if (! $article->is_published) {
            return;
        }

        PurgeCloudflareCacheJob::dispatch($this->collectUrls($article));
    }

    /**
     * Purge the old article URL when an article is deleted (published or not),
     * so the 404 / homepage is served from a fresh cache.
     */
    public function deleted(Article $article): void
    {
        PurgeCloudflareCacheJob::dispatch($this->collectUrls($article));
    }

    /**
     * Collect all URLs that need cache invalidation for the given article.
     *
     * @return array<string>
     */
    protected function collectUrls(Article $article): array
    {
        $urls = [];

        if ($article->slug) {
            $urls[] = route('articles.show', $article->slug, true);
        }

        // Cloudflare caches "https://amanstream.me" and "https://amanstream.me/"
        // under separate cache keys, so purge both forms plus the sitemap.
        $urls[] = url('/');
        $urls[] = rtrim(url('/'), '/').'/';
        $urls[] = url('/sitemap.xml');

        return array_values(array_unique($urls));
    }
}
