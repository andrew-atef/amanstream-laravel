<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 9727 (/.well-known/api-catalog) + /auth.md discovery documents that let
 * AI agents/crawlers find the site's machine-readable resources up-front.
 */
class WellKnownController extends Controller
{
    public function catalog(): JsonResponse
    {
        $base = url('/');

        $linkset = [
            [
                'anchor' => $base.'/.well-known/api-catalog',
                'rel' => 'self',
                'type' => 'application/linkset+json',
                'title' => 'AmanStream API Catalog',
            ],
            [
                'anchor' => $base.'/sitemap.xml',
                'rel' => 'sitemap',
                'type' => 'application/xml',
                'title' => 'AmanStream XML Sitemap',
            ],
            [
                'anchor' => $base.'/llms.txt',
                'rel' => 'describedby',
                'type' => 'text/markdown',
                'title' => 'AmanStream Machine Specifications',
            ],
            [
                'anchor' => $base.'/auth.md',
                'rel' => 'authoritative-about',
                'type' => 'text/markdown',
                'title' => 'AmanStream AI Authentication Guide',
            ],
            [
                'anchor' => $base.'/api/v1/catalog/pending-sync',
                'rel' => 'service-desc',
                'type' => 'application/json',
                'title' => 'AmanStream Catalog Sync API (token-protected)',
            ],
        ];

        return response()->json(['linkset' => $linkset], 200, [
            'Content-Type' => 'application/linkset+json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function authGuide(): Response
    {
        $markdown = <<<'MARKDOWN'
# AmanStream AI Agent Authentication Guide

> AmanStream (amanstream.me) is an Egyptian independent guide publishing appliance
> price reviews, comparisons, and bank-installment calculators for Amazon Egypt.
> All public review content is open, free, and readable by AI agents and crawlers
> **without any authentication or API keys**.

## Public Endpoints (No Auth Required)
- Product reviews and articles: `https://amanstream.me/articles/{slug}`
- AI specifications: `https://amanstream.me/llms.txt`
- API catalog: `https://amanstream.me/.well-known/api-catalog`
- XML sitemap: `https://amanstream.me/sitemap.xml`

These return plain HTML or Markdown to any user agent and require no credentials.

## Protected Endpoints (Do Not Access)
- Internal admin panel (`/admin/*`): interactive UI gated by the Filament login form.
- Catalog sync webhooks (`/api/v1/catalog/pending-sync` and `/api/v1/catalog/sync-results`):
  reserved for the authenticated catalog worker and require a private `x-sync-token`
  header. They are **not** intended for public or agent access.

If you are a read-only crawler, you never need a key. Simply crawl the public pages.
MARKDOWN;

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}