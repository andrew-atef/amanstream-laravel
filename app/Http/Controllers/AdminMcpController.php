<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleProduct;
use App\Models\Product;
use App\Services\SEOHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Private Admin MCP Editorial Server — JSON-RPC 2.0 endpoint at POST /mcp/admin.
 *
 * Gated by a shared secret header `x-admin-mcp-key` matching
 * config('services.mcp.admin_key'). Exposes deep product intelligence,
 * article draft creation, and publishing tools for AI editorial agents
 * (OpenCode, Claude Desktop, Cursor).
 *
 * Supported methods: initialize, ping, tools/list, tools/call.
 */
class AdminMcpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    private const SERVER_NAME = 'amanprice-admin';

    private const SERVER_VERSION = '1.0.0';

    public function handle(Request $request): Response
    {
        if ($request->isMethod('options')) {
            return $this->emptyResponse();
        }

        $adminKey = config('services.mcp.admin_key');

        if ($adminKey === null || $adminKey === '') {
            return $this->rpcError(null, -32603, 'Admin MCP is not configured.');
        }

        $providedKey = $request->header('x-admin-mcp-key', '');

        if (! hash_equals($adminKey, $providedKey)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: invalid or missing x-admin-mcp-key header.',
                ],
            ], 401, $this->jsonHeaders());
        }

        $decoded = json_decode($request->getContent(), true);

        if ($decoded === null && trim((string) $request->getContent()) !== 'null') {
            return $this->rpcError(null, -32700, 'Parse error');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->rpcError(null, -32600, 'Invalid Request');
        }

        $method = $decoded['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->rpcError(null, -32600, 'Invalid Request');
        }

        $id = array_key_exists('id', $decoded) ? $decoded['id'] : null;

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping' => new \stdClass,
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($decoded['params'] ?? []),
                default => throw new \RuntimeException("Method not found: {$method}"),
            };
        } catch (\InvalidArgumentException $exception) {
            return $this->rpcError($id, -32602, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            return $this->rpcError($id, -32601, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->rpcError($id, -32603, 'Internal error');
        }

        if (! array_key_exists('id', $decoded)) {
            return $this->emptyResponse();
        }

        return $this->rpcResult($id, $result);
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    private function toolsList(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'get_product_intelligence',
                    'description' => 'Retrieve the full editorial intelligence profile for a single product: core data, Amazon raw notes, Facebook community insights, YouTube video transcripts, catalog/manual text, and deep technical specs.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => [
                                'type' => 'integer',
                                'description' => 'Numeric product ID.',
                            ],
                            'asin' => [
                                'type' => 'string',
                                'description' => 'Amazon ASIN identifier (e.g. B0D77 BX4R6).',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'list_products',
                    'description' => 'List products with optional category filter, limit, and "without articles" flag to find editorial gaps.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'category_id' => [
                                'type' => 'integer',
                                'description' => 'Filter by category ID.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Max results (default 25, max 100).',
                            ],
                            'without_articles' => [
                                'type' => 'boolean',
                                'description' => 'If true, return only products that have zero published articles.',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'create_article_draft',
                    'description' => 'Create a new article as an unpublished draft (is_published: false). Supports review, blog, and comparison/listicle types with full shortcode content.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['review', 'blog'],
                                'description' => 'Article type.',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Article title (may include [year] token).',
                            ],
                            'slug' => [
                                'type' => 'string',
                                'description' => 'URL slug (auto-generated from title if omitted).',
                            ],
                            'category_id' => [
                                'type' => 'integer',
                                'description' => 'Category ID.',
                            ],
                            'product_id' => [
                                'type' => 'integer',
                                'description' => 'Primary product ID for Tier 3 single reviews.',
                            ],
                            'comparison_products' => [
                                'type' => 'array',
                                'description' => 'Array of comparison entries for Tier 1 & 2 listicles.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'product_id' => ['type' => 'integer'],
                                        'sort_order' => ['type' => 'integer'],
                                        'badge_label' => ['type' => 'string'],
                                        'quick_verdict' => ['type' => 'string'],
                                        'specs_markdown' => ['type' => 'string'],
                                    ],
                                    'required' => ['product_id', 'sort_order'],
                                ],
                            ],
                            'comparison_markdown' => [
                                'type' => 'string',
                                'description' => 'Custom Markdown comparison table for [comparison_table] shortcode.',
                            ],
                            'meta_title' => [
                                'type' => 'string',
                            ],
                            'meta_description' => [
                                'type' => 'string',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Full Markdown content with shortcodes ([price], [rating], [installment], [price_history], [comparison_table], [product_cards], [toc], ### س: FAQs).',
                            ],
                        ],
                        'required' => ['type', 'title', 'content'],
                    ],
                ],
                [
                    'name' => 'update_article_draft',
                    'description' => 'Update an existing draft article content and metadata.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'article_id' => [
                                'type' => 'integer',
                                'description' => 'Article ID to update.',
                            ],
                            'title' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                            'comparison_markdown' => ['type' => 'string'],
                            'meta_title' => ['type' => 'string'],
                            'meta_description' => ['type' => 'string'],
                        ],
                        'required' => ['article_id'],
                    ],
                ],
                [
                    'name' => 'publish_article',
                    'description' => 'Publish a draft article by flipping is_published to true.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'article_id' => [
                                'type' => 'integer',
                                'description' => 'Article ID to publish.',
                            ],
                        ],
                        'required' => ['article_id'],
                    ],
                ],
            ],
        ];
    }

    private function toolsCall(mixed $params): array
    {
        if (! is_array($params)) {
            throw new \InvalidArgumentException('Invalid params: expected an object.');
        }

        $name = $params['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Invalid params: "name" is required.');
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return match ($name) {
            'get_product_intelligence' => $this->callGetProductIntelligence($arguments),
            'list_products' => $this->callListProducts($arguments),
            'create_article_draft' => $this->callCreateArticleDraft($arguments),
            'update_article_draft' => $this->callUpdateArticleDraft($arguments),
            'publish_article' => $this->callPublishArticle($arguments),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // Tool: get_product_intelligence
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $arguments
     */
    private function callGetProductIntelligence(array $arguments): array
    {
        $productId = $arguments['product_id'] ?? null;
        $asin = $arguments['asin'] ?? null;

        if ($productId === null && $asin === null) {
            throw new \InvalidArgumentException('Provide either "product_id" or "asin".');
        }

        $query = Product::query()->with('category');

        if ($productId !== null) {
            $query->where('id', (int) $productId);
        } else {
            $query->where('asin', trim(strtoupper((string) $asin)));
        }

        $product = $query->first();

        if ($product === null) {
            throw new \InvalidArgumentException('Product not found.');
        }

        $data = [
            'id' => $product->id,
            'asin' => $product->asin,
            'title' => SEOHelper::cleanTitle((string) $product->title),
            'brand' => $product->brand,
            'category' => $product->category?->name,
            'category_id' => $product->category_id,
            'price' => (float) $product->price,
            'original_price' => (float) $product->original_price,
            'in_stock' => (bool) $product->in_stock,
            'rating' => (float) $product->rating,
            'review_count' => (int) $product->review_count,
            'affiliate_url' => $product->clean_affiliate_url,
            'raw_amazon_data' => $product->raw_amazon_data,
            'facebook_insights' => $product->facebook_insights,
            'video_transcripts' => $product->video_transcripts,
            'catalog_manual' => $product->catalog_manual,
            'deep_specs_json' => $product->deep_specs_json,
        ];

        // Attach existing articles
        $articles = Article::query()
            ->where('product_id', $product->id)
            ->select('id', 'title', 'slug', 'type', 'is_published')
            ->get();

        $data['articles'] = $articles->isEmpty() ? [] : $articles->toArray();

        // Attach comparison appearances
        $comparisons = ArticleProduct::query()
            ->where('product_id', $product->id)
            ->with('article:id,title,slug,is_published')
            ->get();

        $data['comparison_appearances'] = $comparisons->isEmpty() ? [] : $comparisons->map(fn (ArticleProduct $ap): array => [
            'article_id' => $ap->article_id,
            'article_title' => $ap->article?->title,
            'article_slug' => $ap->article?->slug,
            'article_published' => $ap->article?->is_published,
            'sort_order' => $ap->sort_order,
            'badge_label' => $ap->badge_label,
            'quick_verdict' => $ap->quick_verdict,
        ])->toArray();

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Tool: list_products
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $arguments
     */
    private function callListProducts(array $arguments): array
    {
        $categoryId = $arguments['category_id'] ?? null;
        $limit = min(max((int) ($arguments['limit'] ?? 25), 1), 100);
        $withoutArticles = filter_var($arguments['without_articles'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = Product::query()
            ->select('id', 'asin', 'title', 'brand', 'price', 'rating', 'review_count', 'category_id', 'is_active')
            ->with('category:id,name');

        if ($categoryId !== null) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($withoutArticles) {
            $query->whereDoesntHave('articles', fn ($q) => $q->where('is_published', true));
        }

        $products = $query->orderByDesc('rating')->limit($limit)->get();

        $articleCounts = Article::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('is_published', true)
            ->selectRaw('product_id, count(*) as cnt')
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id');

        $items = $products->map(fn (Product $p): array => [
            'id' => $p->id,
            'asin' => $p->asin,
            'title' => SEOHelper::cleanTitle((string) $p->title),
            'brand' => $p->brand,
            'price' => (float) $p->price,
            'rating' => (float) $p->rating,
            'review_count' => (int) $p->review_count,
            'category' => $p->category?->name,
            'is_active' => (bool) $p->is_active,
            'published_article_count' => (int) $articleCounts->get($p->id, 0),
        ])->toArray();

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode(['count' => count($items), 'products' => $items], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Tool: create_article_draft
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $arguments
     */
    private function callCreateArticleDraft(array $arguments): array
    {
        $type = $arguments['type'] ?? 'review';
        $title = trim((string) ($arguments['title'] ?? ''));

        if ($title === '') {
            throw new \InvalidArgumentException('"title" is required.');
        }

        $content = trim((string) ($arguments['content'] ?? ''));

        if ($content === '') {
            throw new \InvalidArgumentException('"content" is required.');
        }

        $slug = trim((string) ($arguments['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug(SEOHelper::renderDynamicYear($title));
        }

        $categoryId = $arguments['category_id'] ?? null;
        $categoryId = $categoryId !== null ? (int) $categoryId : null;

        $article = DB::transaction(function () use ($type, $title, $slug, $content, $categoryId, $arguments) {
            $article = Article::create([
                'type' => $type,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'category_id' => $categoryId,
                'product_id' => ($arguments['product_id'] ?? null) !== null ? (int) $arguments['product_id'] : null,
                'comparison_markdown' => $arguments['comparison_markdown'] ?? null,
                'meta_title' => $arguments['meta_title'] ?? null,
                'meta_description' => $arguments['meta_description'] ?? null,
                'is_published' => false,
            ]);

            // Attach comparison products (Tier 1 & 2 listicles)
            $comparisonProducts = $arguments['comparison_products'] ?? [];

            if (is_array($comparisonProducts) && $comparisonProducts !== []) {
                foreach ($comparisonProducts as $entry) {
                    $productId = $entry['product_id'] ?? null;
                    if ($productId === null) {
                        continue;
                    }

                    ArticleProduct::create([
                        'article_id' => $article->id,
                        'product_id' => (int) $productId,
                        'sort_order' => (int) ($entry['sort_order'] ?? 0),
                        'badge_label' => $entry['badge_label'] ?? null,
                        'quick_verdict' => $entry['quick_verdict'] ?? null,
                        'specs_markdown' => $entry['specs_markdown'] ?? null,
                    ]);
                }
            }

            return $article;
        });

        $previewUrl = route('articles.show', $article->slug).'?preview='.config('services.mcp.admin_key');

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'success' => true,
                        'article_id' => $article->id,
                        'slug' => $article->slug,
                        'preview_url' => $previewUrl,
                        'status' => 'draft',
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Tool: update_article_draft
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $arguments
     */
    private function callUpdateArticleDraft(array $arguments): array
    {
        $articleId = $arguments['article_id'] ?? null;

        if ($articleId === null) {
            throw new \InvalidArgumentException('"article_id" is required.');
        }

        $article = Article::find((int) $articleId);

        if ($article === null) {
            throw new \InvalidArgumentException("Article #{$articleId} not found.");
        }

        $updatable = array_filter([
            'title' => $arguments['title'] ?? null,
            'slug' => $arguments['slug'] ?? null,
            'content' => $arguments['content'] ?? null,
            'comparison_markdown' => $arguments['comparison_markdown'] ?? null,
            'meta_title' => $arguments['meta_title'] ?? null,
            'meta_description' => $arguments['meta_description'] ?? null,
        ], fn ($v) => $v !== null);

        if ($updatable !== []) {
            $article->update($updatable);
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'success' => true,
                        'article_id' => $article->id,
                        'slug' => $article->slug,
                        'is_published' => $article->is_published,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Tool: publish_article
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $arguments
     */
    private function callPublishArticle(array $arguments): array
    {
        $articleId = $arguments['article_id'] ?? null;

        if ($articleId === null) {
            throw new \InvalidArgumentException('"article_id" is required.');
        }

        $article = Article::find((int) $articleId);

        if ($article === null) {
            throw new \InvalidArgumentException("Article #{$articleId} not found.");
        }

        $article->update(['is_published' => true]);

        // Purge cache if the helper exists
        if (method_exists($article, 'flushCache')) {
            $article->flushCache();
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'success' => true,
                        'article_id' => $article->id,
                        'slug' => $article->slug,
                        'is_published' => true,
                        'url' => route('articles.show', $article->slug),
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'isError' => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // JSON-RPC helpers (mirrors McpController)
    // ──────────────────────────────────────────────────────────────────

    private function rpcResult(mixed $id, mixed $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200, $this->jsonHeaders());
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 200, $this->jsonHeaders());
    }

    private function emptyResponse(): Response
    {
        return response('', 204, $this->jsonHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function jsonHeaders(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept, Origin, X-Requested-With, x-admin-mcp-key',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
