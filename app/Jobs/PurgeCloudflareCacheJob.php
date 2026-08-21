<?php

namespace App\Jobs;

use App\Services\CloudflareCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PurgeCloudflareCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param  array<string>  $urls  Full absolute URLs to purge from Cloudflare Edge.
     */
    public function __construct(public readonly array $urls) {}

    /**
     * Purge the given URLs from Cloudflare and enqueue instant-indexing
     * notifications for any article URLs found among them.
     */
    public function handle(CloudflareCacheService $cache): void
    {
        $cache->purgeUrls($this->urls);

        $this->notifySearchEngines();
    }

    /**
     * Queue one SendInstantIndexingNotification per article URL so Google
     * Indexing + IndexNow submissions run in the background without blocking
     * this worker thread or hammering the Indexing API rate limits.
     */
    protected function notifySearchEngines(): void
    {
        $indexNowEnabled = filter_var(config('services.indexnow.enabled', true), FILTER_VALIDATE_BOOLEAN);
        $googleEnabled = filter_var(config('services.google_indexing.enabled', true), FILTER_VALIDATE_BOOLEAN);

        if (! $indexNowEnabled && ! $googleEnabled) {
            return;
        }

        $articleBase = url('/articles/');

        foreach ($this->urls as $url) {
            if (! str_starts_with($url, $articleBase)) {
                continue;
            }

            SendInstantIndexingNotification::dispatch($url);
        }
    }
}
