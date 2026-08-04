<?php

namespace App\Jobs;

use App\Services\CloudflareCacheService;
use App\Services\InstantIndexingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
     * Purge the given URLs from Cloudflare and notify search engines for
     * any article URLs found among them.
     */
    public function handle(CloudflareCacheService $cache, InstantIndexingService $indexing): void
    {
        $cache->purgeUrls($this->urls);

        $this->notifySearchEngines($indexing);
    }

    /**
     * Submit article URLs to Google Indexing API and IndexNow so search
     * engines pick up the fresh content as soon as the cache is warm.
     */
    protected function notifySearchEngines(InstantIndexingService $indexing): void
    {
        $articleBase = url('/articles/');

        foreach ($this->urls as $url) {
            if (! str_starts_with($url, $articleBase)) {
                continue;
            }

            Log::info('PurgeCloudflareCacheJob: notifying search engines.', ['url' => $url]);

            $indexing->notifyGoogle($url);
            $indexing->notifyIndexNow($url);
        }
    }
}
