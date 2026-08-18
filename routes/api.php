<?php

use App\Http\Controllers\Api\CatalogSyncApiController;
use App\Http\Controllers\Api\DeepScrapeApiController;
use App\Http\Middleware\VerifyCatalogSyncToken;
use Illuminate\Support\Facades\Route;

Route::middleware([VerifyCatalogSyncToken::class])
    ->prefix('v1/catalog')
    ->group(function () {
        Route::get('/pending-sync', [CatalogSyncApiController::class, 'pendingSync']);
        Route::post('/sync-results', [CatalogSyncApiController::class, 'syncResults']);
    });

Route::middleware([VerifyCatalogSyncToken::class])
    ->prefix('v1/deep-scrape')
    ->group(function () {
        Route::get('/pending', [DeepScrapeApiController::class, 'pending']);
        Route::post('/submit', [DeepScrapeApiController::class, 'submit']);
    });
