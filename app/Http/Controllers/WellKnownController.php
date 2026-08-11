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
                'anchor' => $base.'/',
                'self' => [
                    ['href' => $base.'/.well-known/api-catalog', 'type' => 'application/linkset+json'],
                ],
                'sitemap' => [
                    ['href' => $base.'/sitemap.xml', 'type' => 'application/xml'],
                ],
                'describedby' => [
                    ['href' => $base.'/llms.txt', 'type' => 'text/markdown'],
                ],
                'authoritative-about' => [
                    ['href' => $base.'/auth.md', 'type' => 'text/markdown'],
                ],
                'service-desc' => [
                    ['href' => $base.'/openapi.json', 'type' => 'application/vnd.oai.openapi+json'],
                ],
                'service-doc' => [
                    ['href' => $base.'/docs', 'type' => 'text/markdown'],
                ],
                'status' => [
                    ['href' => $base.'/up', 'type' => 'application/json'],
                ],
            ],
            [
                'anchor' => $base.'/api/v1/catalog',
                'service-desc' => [
                    ['href' => $base.'/openapi.json', 'type' => 'application/vnd.oai.openapi+json'],
                ],
                'service-doc' => [
                    ['href' => $base.'/docs', 'type' => 'text/markdown'],
                ],
            ],
        ];

        return response()->json(['linkset' => $linkset], 200, [
            'Content-Type' => 'application/linkset+json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * OpenAPI 3.0 description of the site's machine-readable surface, referenced
     * from the RFC 9727 catalog via the `service-desc` link relation.
     */
    public function openApi(): JsonResponse
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'AmanStream API',
                'description' => 'Machine-readable catalog and catalog-sync endpoints for AmanStream (amanstream.me), an independent Egyptian guide to appliance prices and bank-installment comparisons on Amazon Egypt.',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => url('/')],
            ],
            'paths' => [
                '/.well-known/api-catalog' => [
                    'get' => [
                        'summary' => 'RFC 9727 API catalog as a linkset document',
                        'produces' => ['application/linkset+json'],
                        'responses' => [
                            '200' => ['description' => 'Linkset document describing the site APIs'],
                        ],
                    ],
                ],
                '/llms.txt' => [
                    'get' => [
                        'summary' => 'Machine-readable specifications for AI agents',
                        'produces' => ['text/markdown'],
                        'responses' => [
                            '200' => ['description' => 'Markdown specification'],
                        ],
                    ],
                ],
                '/auth.md' => [
                    'get' => [
                        'summary' => 'Agent authentication guide',
                        'produces' => ['text/markdown'],
                        'responses' => [
                            '200' => ['description' => 'Markdown authentication guide'],
                        ],
                    ],
                ],
                '/sitemap.xml' => [
                    'get' => [
                        'summary' => 'XML sitemap',
                        'produces' => ['application/xml'],
                        'responses' => [
                            '200' => ['description' => 'XML sitemap'],
                        ],
                    ],
                ],
                '/api/v1/catalog/pending-sync' => [
                    'get' => [
                        'summary' => 'List products awaiting a price sync',
                        'security' => [['XSyncToken' => []]],
                        'parameters' => [
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'maximum' => 100],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'JSON array of pending products'],
                            '401' => ['description' => 'Missing or invalid x-sync-token'],
                        ],
                    ],
                ],
                '/api/v1/catalog/sync-results' => [
                    'post' => [
                        'summary' => 'Submit price sync results',
                        'security' => [['XSyncToken' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['type' => 'object'],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Results accepted'],
                            '401' => ['description' => 'Missing or invalid x-sync-token'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'XSyncToken' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'x-sync-token',
                    ],
                ],
            ],
        ];

        return response()->json($spec, 200, [
            'Content-Type' => 'application/vnd.oai.openapi+json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Human-readable endpoint documentation, referenced from the catalog via
     * the `service-doc` link relation.
     */
    public function docs(): Response
    {
        $markdown = <<<'MARKDOWN'
# AmanStream API Documentation

AmanStream (amanstream.me) is an independent Egyptian guide to appliance prices, reviews, and bank-installment comparisons on Amazon Egypt. Every public resource is readable by AI agents and crawlers without authentication.

## Public endpoints
- `/.well-known/api-catalog` — RFC 9727 catalog of the site's APIs (`application/linkset+json`)
- `/openapi.json` — OpenAPI 3.0 description of this surface (`application/vnd.oai.openapi+json`)
- `/llms.txt` — machine-readable specifications for AI agents (`text/markdown`)
- `/auth.md` — authentication guide for agents (`text/markdown`)
- `/sitemap.xml` — XML sitemap (`application/xml`)
- `/articles/{slug}` — article page; request it with `Accept: text/markdown` for a clean Markdown variant
- `/` — homepage; Markdown variant available the same way
- `/up` — health check (`application/json`)

## Catalog-sync API (protected)
Reserved for the catalog worker and authenticated with the `x-sync-token` request header. **Do not call these without authorization.**
- `GET /api/v1/catalog/pending-sync` — list products awaiting a price sync
- `POST /api/v1/catalog/sync-results` — submit price sync results

## Authentication
No API keys are required for public content. Protected endpoints are described in `/auth.md`.
MARKDOWN;

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function authGuide(): Response
    {
        $markdown = <<<'MARKDOWN'
# Auth.md

> AmanStream (amanstream.me) — an independent Egyptian guide to appliance price
> reviews, comparisons, and bank-installment calculators for Amazon Egypt.
> All public review content is open, free, and readable by AI agents and
> crawlers **without any authentication or API keys**.

## Public endpoints (no auth required)
- Product reviews and articles: `https://amanstream.me/articles/{slug}`
- AI specifications: `https://amanstream.me/llms.txt`
- API catalog: `https://amanstream.me/.well-known/api-catalog`
- XML sitemap: `https://amanstream.me/sitemap.xml`

These return plain HTML or Markdown to any user agent and require no registration, no sign-in, and no credentials.

## Protected endpoints (do not access)
- Internal admin panel (`/admin/*`): interactive UI gated by the Filament login form.
- Catalog sync webhooks (`/api/v1/catalog/pending-sync` and `/api/v1/catalog/sync-results`):
  reserved for the authenticated catalog worker and require a private `x-sync-token`
  header. They are **not** intended for public or agent access.

If you are a read-only crawler or agent, you never need a key — simply crawl the public pages.
MARKDOWN;

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}