<?php

namespace App\Jobs;

use App\Services\InstantIndexingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInstantIndexingNotification implements ShouldQueue
{
    use Queueable;

    /**
     * The absolute public URL of the affected article.
     */
    public function __construct(public readonly string $url) {}

    /**
     * Dispatch Google Indexing + IndexNow submissions for the URL.
     */
    public function handle(InstantIndexingService $indexing): void
    {
        $indexing->notifyGoogle($this->url);
        $indexing->notifyIndexNow($this->url);
    }
}
