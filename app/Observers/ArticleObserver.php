<?php

namespace App\Observers;

use App\Jobs\PurgeCloudflareCacheJob;
use App\Models\Article;
use App\Services\SEOHelper;
use Illuminate\Support\Facades\Cache;

class ArticleObserver
{
    /**
     * Purge Cloudflare and notify search engines whenever a published
     * article is created or saved. Fires on both inserts and updates,
     * so purge jobs are dispatched exactly once per save cycle.
     */
    public function saved(Article $article): void
    {
        // The sitemap XML is cached 6 hours by SitemapController; publishing,
        // unpublishing or editing an article must drop that snapshot instantly
        // so Googlebot never receives a stale map (Cloudflare purge alone does
        // not touch the Laravel cache layer).
        if ($article->is_published || $article->wasChanged('is_published')) {
            Cache::forget('sitemap_xml_content');
        }

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
        Cache::forget('sitemap_xml_content');

        PurgeCloudflareCacheJob::dispatch($this->collectUrls($article));
    }

    /**
     * Collect all URLs that need cache invalidation for the given article.
     * Built from the canonical www. host so purges always target the same
     * base URL regardless of which host the save was triggered from.
     *
     * @return array<string>
     */
    protected function collectUrls(Article $article): array
    {
        $urls = [];

        if ($article->slug) {
            $urls[] = SEOHelper::url('articles/'.$article->slug);
        }

        // Cloudflare caches "https://www.example.com" and "https://www.example.com/"
        // under separate cache keys, so purge both forms plus the sitemap.
        $urls[] = SEOHelper::url();
        $urls[] = rtrim(SEOHelper::url(), '/');
        $urls[] = SEOHelper::url('sitemap.xml');

        return array_values(array_unique($urls));
    }
}
