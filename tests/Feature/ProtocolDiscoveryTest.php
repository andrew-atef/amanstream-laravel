<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_catalog_is_a_json_linkset(): void
    {
        $response = $this->get('/.well-known/api-catalog');

        $response->assertOk();
        $this->assertStringStartsWith('application/linkset+json', (string) $response->headers->get('Content-Type'));

        $payload = $response->json();
        $this->assertArrayHasKey('linkset', $payload);
        $this->assertCount(2, $payload['linkset']);

        $main = collect($payload['linkset'])->firstWhere('anchor', url('/').'/');
        $this->assertNotNull($main);
        $this->assertArrayHasKey('service-desc', $main);
        $this->assertArrayHasKey('service-doc', $main);
        $this->assertArrayHasKey('status', $main);
        $this->assertArrayHasKey('sitemap', $main);
        $this->assertArrayHasKey('describedby', $main);
        $this->assertArrayHasKey('authoritative-about', $main);

        $api = collect($payload['linkset'])->firstWhere('anchor', url('/').'/api/v1/catalog');
        $this->assertNotNull($api);
        $this->assertArrayHasKey('service-desc', $api);
        $this->assertArrayHasKey('service-doc', $api);

        $this->assertStringContainsString('/openapi.json', $response->getContent());
        $this->assertStringContainsString('/docs', $response->getContent());
        $this->assertStringContainsString('/up', $response->getContent());
        $this->assertStringContainsString('/sitemap.xml', $response->getContent());
        $this->assertStringContainsString('/llms.txt', $response->getContent());
        $this->assertStringContainsString('/auth.md', $response->getContent());
    }

    public function test_openapi_spec_is_served_for_service_desc(): void
    {
        $response = $this->get('/openapi.json');

        $response->assertOk();
        $this->assertStringStartsWith('application/vnd.oai.openapi+json', (string) $response->headers->get('Content-Type'));

        $spec = $response->json();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/api/v1/catalog/pending-sync', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/catalog/sync-results', $spec['paths']);
        $this->assertArrayHasKey('XSyncToken', $spec['components']['securitySchemes']);
    }

    public function test_docs_page_is_served_for_service_doc(): void
    {
        $response = $this->get('/docs');

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# AmanStream API Documentation', $response->getContent());
        $this->assertStringContainsString('/.well-known/api-catalog', $response->getContent());
        $this->assertStringContainsString('/api/v1/catalog/pending-sync', $response->getContent());
    }

    public function test_auth_md_describes_public_and_protected_endpoints(): void
    {
        $response = $this->get('/auth.md');

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('# Auth.md', $response->getContent());
        $this->assertStringContainsString('# Auth.md', $response->getContent());
        $this->assertStringContainsString('no auth required', strtolower($response->getContent()));
        $this->assertStringContainsString('/articles/{slug}', $response->getContent());
        $this->assertStringContainsString('x-sync-token', $response->getContent());
        $this->assertStringContainsString('/admin/*', $response->getContent());
    }

    public function test_homepage_attaches_discovery_link_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $link = $response->headers->get('Link');

        $this->assertIsString($link);
        $this->assertStringContainsString('/.well-known/api-catalog', $link);
        $this->assertStringContainsString('rel="api-catalog"', $link);
        $this->assertStringContainsString('/sitemap.xml', $link);
        $this->assertStringContainsString('rel="sitemap"', $link);
        $this->assertStringContainsString('/llms.txt', $link);
        $this->assertStringContainsString('rel="describedby"', $link);

        // The HTML variant must also declare Vary: Accept so the edge cache
        // never serves it to a client that asked for text/markdown.
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    public function test_article_markdown_response_also_carries_discovery_link_headers(): void
    {
        $category = Category::query()->firstOrCreate(['slug' => 'proto-cat'], ['name' => 'فئة']);

        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'منتج',
            'asin' => 'PROTOASIN1',
            'price' => 1000,
            'affiliate_url' => 'https://www.amazon.eg/dp/PROTOASIN1',
            'in_stock' => true,
            'is_active' => true,
            'platform' => 'amazon',
        ]);

        Article::create([
            'product_id' => $product->id,
            'category_id' => $category->id,
            'title' => 'مقال',
            'slug' => 'proto-article',
            'content' => '## نص [price]',
            'is_published' => true,
        ]);

        $response = $this->get('/articles/proto-article', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));

        $link = $response->headers->get('Link');
        $this->assertIsString($link);
        $this->assertStringContainsString('/.well-known/api-catalog', $link);
        $this->assertStringNotContainsString('[price]', $response->getContent());
    }

    public function test_internal_api_paths_do_not_receive_link_headers(): void
    {
        $response = $this->get('/api/v1/catalog/pending-sync');

        $this->assertNull($response->headers->get('Link'));
    }
}