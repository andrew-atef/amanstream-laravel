<?php

namespace App\Observers;

use App\Jobs\PurgeArticleFromCache;
use App\Jobs\SendInstantIndexingNotification;
use App\Models\Article;
use Illuminate\Support\Facades\Queue;

class ArticleObserver
{
    /**
     * Automatically request instant indexing and purge the Cloudflare cache
     * whenever a published article is saved.
     */
    public function saved(Article $article): void
    {
        if (! $article->is_published) {
            return;
        }

        $this->notifyIndexingAndPurge($article);
    }

    /**
     * Queue the instant-indexing notification and Cloudflare cache purge for a
     * published article. Reused by ArticleObserver::saved() and by the catalog sync
     * engine when a product price change (>3%) requires refreshing its articles.
     */
    public function notifyIndexingAndPurge(Article $article): void
    {
        $articleUrl = route('articles.show', $article->slug);

        Queue::connection(config('queue.default'))->push(
            new SendInstantIndexingNotification($articleUrl)
        );

        Queue::connection(config('queue.default'))->push(
            new PurgeArticleFromCache($articleUrl)
        );
    }
}
