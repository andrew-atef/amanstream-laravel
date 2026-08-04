<?php

namespace App\Jobs;

use App\Services\CloudflareCacheService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeArticleFromCache implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url) {}

    public function handle(CloudflareCacheService $service): void
    {
        $service->purgeUrl($this->url);
    }
}
