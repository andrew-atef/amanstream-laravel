<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_page_sends_expected_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
        $response->assertHeader('Content-Security-Policy');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        // Livewire 3 bundles Alpine which evaluates directives via new Function();
        // without 'unsafe-eval' it throws EvalError and every live component dies.
        $this->assertMatchesRegularExpression("/script-src [^;]*'unsafe-eval'/", $csp);
        // Auth views load Inter from fonts.bunny.net and Cloudflare injects its
        // Web Analytics beacon on every page; blocking either breaks the login
        // page with Console CSP violations.
        $this->assertStringContainsString('https://fonts.bunny.net', $csp);
        $this->assertStringContainsString('https://static.cloudflareinsights.com', $csp);
        // Filament admin+login render avatar images from ui-avatars.com; blocking
        // them breaks both the auth and admin UI.
        $this->assertStringContainsString('https://ui-avatars.com', $csp);
    }

    #[Test]
    public function about_page_sends_the_same_security_headers(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
    }
}