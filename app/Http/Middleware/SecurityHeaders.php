<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://static.cloudflareinsights.com; ".
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net; ".
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net; ".
            "img-src 'self' data: blob: https://media.amanprice.tech https://ui-avatars.com; ".
            "connect-src 'self' https://www.google-analytics.com https://cloudflareinsights.com; ".
            "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'"
        );

        return $response;
    }
}