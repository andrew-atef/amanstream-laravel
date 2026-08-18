<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Services\SEOHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * JSON-RPC 2.0 Model Context Protocol endpoint served at POST /mcp for the
 * WebMCP / Cloudflare edge bridge (document.modelContext). Exposes AmanPrice
 * search, product discovery and verified Amazon Egypt purchase links to
 * in-browser AI agents — read-only, anonymous and CSRF-exempt by design.
 *
 * Supported methods: initialize, ping, tools/list, tools/call.
 */
class McpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    private const SERVER_NAME = 'amanprice';

    private const SERVER_VERSION = '1.0.0';

    public function handle(Request $request): Response
    {
        // CORS preflight (the WebMCP bridge may probe before POSTing).
        if ($request->isMethod('options')) {
            return $this->emptyResponse();
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
            return $this->rpcError($id, -32603, 'Internal error');
        }

        // JSON-RPC notification (no id): process but never reply.
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
                    'name' => 'search_products',
                    'description' => 'Search AmanPrice (amanprice.tech) for live Egyptian appliance prices, verified reviews, bank installment plans, and official direct Amazon Egypt purchase links.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => "Search keyword e.g. 'تكييف فريش 1.5 حصان' or 'LG washing machine'",
                            ],
                            'deals_only' => [
                                'type' => 'boolean',
                                'description' => 'Filter strictly for products with active discounts',
                            ],
                        ],
                        'required' => ['query'],
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
            'search_products' => $this->callSearchProducts($arguments),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    /**
     * @param  array<mixed>  $arguments
     */
    private function callSearchProducts(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            throw new \InvalidArgumentException('Invalid params: "query" is required.');
        }

        $dealsOnly = filter_var($arguments['deals_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->renderSearchResults($query, $dealsOnly),
                ],
            ],
            'isError' => false,
        ];
    }

    private function renderSearchResults(string $query, bool $dealsOnly): string
    {
        $like = '%'.$query.'%';

        $products = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->where(function (Builder $builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('asin', 'like', $like);
            })
            ->when($dealsOnly, fn (Builder $builder): Builder => $builder->whereColumn('original_price', '>', 'price'))
            ->orderByDesc('rating')
            ->limit(10)
            ->get();

        // Products referenced by matching published articles are included too,
        // so a listicle title never hides its devices from the search.
        $articles = Article::query()
            ->with('product')
            ->where('is_published', true)
            ->where('title', 'like', $like)
            ->limit(10)
            ->get();

        $byId = $products->keyBy('id');

        foreach ($articles as $article) {
            $product = $article->product;

            if ($product !== null && ! $byId->has($product->getKey())) {
                $byId->put($product->getKey(), $product);
            }
        }

        if ($byId->isEmpty()) {
            return "لا توجد نتائج عن \"{$query}\" في كتالوج أمان برايس حالياً.\n\n"
                .'جرب كلمات أخرى (اسم العلامة التجارية أو نوع الجهاز) أو تصفح المقالات على '.url('/');
        }

        $articleSlugs = Article::query()
            ->whereIn('product_id', $byId->keys())
            ->where('is_published', true)
            ->pluck('slug', 'product_id');

        $lines = [
            '# نتائج البحث عن: '.$query,
            '',
            'النتائج مأخوذة من قاعدة بيانات أمان برايس المحدثة (amanprice.tech). الأسعار والتوافر لحظية من أمازون مصر.',
            '',
        ];

        foreach ($byId as $product) {
            $lines[] = $this->renderProduct($product, $articleSlugs);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  Collection<int, string>  $articleSlugs
     */
    private function renderProduct(Product $product, Collection $articleSlugs): string
    {
        $title = SEOHelper::cleanTitle((string) $product->title);
        $price = (float) $product->price;
        $original = (float) $product->original_price;

        $lines = ['### '.$title, ''];

        if (filled($product->brand)) {
            $lines[] = '- **العلامة التجارية:** '.$product->brand;
        }

        if ($price > 0) {
            $lines[] = '- **السعر الحالي:** '.$this->formatPrice($price).' ج.م';

            if ($original > $price && $original > 0) {
                $discount = (int) round((($original - $price) / $original) * 100);
                $lines[] = '- **الخصم:** '.$discount.'% عن السعر الأصلي ('.$this->formatPrice($original).' ج.م)';
            }
        } else {
            $lines[] = '- **السعر:** قيد التحديث';
        }

        if ((float) $product->rating > 0) {
            $lines[] = '- **التقييم:** '.number_format((float) $product->rating, 1).' من 5 ('.(int) $product->review_count.' مراجعة حقيقية)';
        }

        $lines[] = '- **التوفر:** '.($product->in_stock ? 'متوفر في المخزون' : 'غير متوفر في المخزون حالياً');

        $monthly = $this->monthlyInstallment($product);

        if ($monthly !== null) {
            $lines[] = '- **التقسيط:** '.$this->formatPrice($monthly).' ج.م شهرياً عبر البنوك المصرية (0% فائدة)';
        }

        $cleanUrl = SEOHelper::cleanAffiliateUrl((string) $product->affiliate_url, (string) $product->asin);

        if (filled($cleanUrl)) {
            $lines[] = '';
            $lines[] = '- **رابط الشراء المباشر:** '.$cleanUrl;
            $lines[] = '- [🔗 صفحة العرض والضمان المعتمد على أمازون مصر]('.$cleanUrl.')';
        }

        $slug = $articleSlugs->get($product->getKey());

        if (is_string($slug) && $slug !== '') {
            $lines[] = '- [📄 قراءة المراجعة الكاملة]('.url('/articles/'.$slug).')';
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function monthlyInstallment(Product $product): ?float
    {
        if ((float) $product->price <= 0) {
            return null;
        }

        $plans = $product->getEligibleInstallmentPlans();

        if ($plans->isNotEmpty()) {
            return (float) $plans
                ->map(fn (InstallmentPlan $plan): float => (float) $plan->calculateMonthlyPayment((float) $product->price))
                ->min();
        }

        return (float) $product->price / 12;
    }

    private function formatPrice(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

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
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept, Origin, X-Requested-With, Mcp-Session-Id, mcp-protocol-version',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
