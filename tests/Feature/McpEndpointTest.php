<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Bank;
use App\Models\Category;
use App\Models\InstallmentPlan;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'mcp-cat'], ['name' => 'فئة']);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'title' => 'تكييف فريش 1.5 حصان',
            'asin' => 'MCPASIN01',
            'brand' => 'فريش',
            'price' => 12500,
            'original_price' => 14700,
            'rating' => 4.5,
            'review_count' => 320,
            'affiliate_url' => 'https://www.amazon.eg/dp/MCPASIN01',
            'in_stock' => true,
            'supports_installment' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ], $overrides));
    }

    public function test_initialize_returns_protocol_and_capabilities(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => []],
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertSame(1, $payload['id']);
        $this->assertSame('2025-06-18', $payload['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $payload['result']['capabilities']);
        $this->assertSame('amanprice', $payload['result']['serverInfo']['name']);
    }

    public function test_tools_list_exposes_search_products(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'tools',
            'method' => 'tools/list',
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame('tools', $payload['id']);

        $tool = collect($payload['result']['tools'])->firstWhere('name', 'search_products');
        $this->assertNotNull($tool);
        $this->assertStringContainsString('AmanPrice (amanprice.tech)', $tool['description']);
        $this->assertSame('string', $tool['inputSchema']['properties']['query']['type']);
        $this->assertSame('boolean', $tool['inputSchema']['properties']['deals_only']['type']);
        $this->assertSame(['query'], $tool['inputSchema']['required']);
    }

    public function test_tools_call_search_products_returns_markdown_with_purchase_link(): void
    {
        $product = $this->makeProduct();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'تكييف فريش'],
            ],
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(7, $payload['id']);
        $this->assertArrayHasKey('result', $payload);
        $this->assertArrayHasKey('content', $payload['result']);
        $this->assertSame('text', $payload['result']['content'][0]['type']);
        $this->assertFalse($payload['result']['isError']);

        $text = $payload['result']['content'][0]['text'];
        $this->assertStringContainsString('تكييف فريش 1.5 حصان', $text);
        $this->assertStringContainsString('12500.00 ج.م', $text);
        $this->assertStringContainsString('رابط الشراء المباشر', $text);
        $this->assertStringContainsString($product->affiliate_url, $text);
    }

    public function test_tools_call_reports_discount_and_installment(): void
    {
        $this->makeProduct();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'فريش'],
            ],
        ]);

        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('- **الخصم:** 15% عن السعر الأصلي (14700.00 ج.م)', $text);

        // 12500 / 12 = 1041.67 monthly (no eligible bank plan seeded).
        $this->assertStringContainsString('1041.67 ج.م شهرياً', $text);
    }

    public function test_tools_call_shows_verified_installment_plan_when_available(): void
    {
        $bank = Bank::create(['name_ar' => 'البنك الأهلي', 'name_en' => 'NBE', 'code' => 'nbe', 'is_active' => true]);

        InstallmentPlan::create([
            'bank_id' => $bank->id,
            'months' => 12,
            'interest_rate' => 0,
            'admin_fee_percent' => 1,
            'min_order_amount' => 1000,
            'is_zero_interest' => true,
        ]);

        $this->makeProduct();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'تكييف'],
            ],
        ]);

        // 12500 + 1% admin fee = 12625 / 12 = 1052.08.
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('1052.08 ج.م شهرياً', $text);
        $this->assertStringContainsString('0% فائدة', $text);
    }

    public function test_tools_call_includes_products_from_matching_articles(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'mcp-art-cat'], ['name' => 'فئة']);

        $product = $this->makeProduct(['category_id' => $category->id, 'title' => 'غسالة LG أوتوماتيك', 'asin' => 'MCPASIN02']);

        Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'أفضل غسالات في مصر 2026',
            'slug' => 'best-washers',
            'content' => '## النص [price]',
            'is_published' => true,
        ]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'غسالات'],
            ],
        ]);

        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('غسالة LG أوتوماتيك', $text);
        $this->assertStringContainsString('/articles/best-washers', $text);
    }

    public function test_tools_call_deals_only_filters_to_active_discounts(): void
    {
        $this->makeProduct(['asin' => 'MCPDEAL01', 'original_price' => 15000]);
        $this->makeProduct(['asin' => 'MCPDEAL02', 'title' => 'تكييف ميديا سبليت', 'original_price' => 0]);

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'تكييف', 'deals_only' => true],
            ],
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('- **الخصم:** 17%', $text);
        $this->assertStringNotContainsString('ميديا سبليت', $text);
        $this->assertStringNotContainsString('MCPDEAL02', $text);
    }

    public function test_tools_call_missing_query_is_invalid_params(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => ['name' => 'search_products', 'arguments' => []],
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertSame(12, $payload['id']);
    }

    public function test_tools_call_unknown_tool_returns_error(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'tools/call',
            'params' => ['name' => 'delete_everything', 'arguments' => []],
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(-32602, $payload['error']['code']);
        $this->assertStringContainsString('Unknown tool', $payload['error']['message']);
    }

    public function test_unknown_method_returns_method_not_found(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 14,
            'method' => 'resources/list',
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(-32601, $payload['error']['code']);
    }

    public function test_invalid_json_returns_parse_error(): void
    {
        $response = $this->call('POST', '/mcp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{this is not json');

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(-32700, $payload['error']['code']);
    }

    public function test_batch_array_is_rejected_as_invalid_request(): void
    {
        $response = $this->call('POST', '/mcp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '[{"jsonrpc":"2.0","id":1,"method":"ping"}]');

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(-32600, $payload['error']['code']);
    }

    public function test_notification_gets_no_response_body(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'ping',
        ]);

        $response->assertStatus(204);
        $this->assertSame('', $response->getContent());
    }

    public function test_option_preflight_returns_cors_headers(): void
    {
        $response = $this->call('OPTIONS', '/mcp');

        $response->assertStatus(204);
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_mcp_headers_are_json_and_cors_enabled(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 15,
            'method' => 'ping',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('application/json; charset=utf-8', (string) $response->headers->get('Content-Type'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_mcp_post_is_exempt_from_csrf(): void
    {
        // No CSRF token / session in the request — the web group would normally
        // 419 this. The MCP endpoint must stay reachable for the bridge.
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 16,
            'method' => 'ping',
        ]);

        $response->assertOk();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_get_mcp_is_not_intercepted_by_markdown_middleware(): void
    {
        $this->makeProduct();

        $response = $this->get('/mcp', ['Accept' => 'text/markdown']);

        // No markdown article variant is served for /mcp — the path is exempt.
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_search_returns_empty_result_message_when_no_matches(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 17,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_products',
                'arguments' => ['query' => 'zebra غير موجودة'],
            ],
        ]);

        $response->assertOk();
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('لا توجد نتائج', $text);
        $this->assertFalse($response->json('result.isError'));
    }
}
