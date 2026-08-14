<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-hop 301 redirect that unifies the host on the canonical www. variant.
 *
 * The enforced target host is derived from APP_URL and normalized to the www.
 * subdomain, so it stays correct whether APP_URL is set to the www or the bare
 * (apex) variant — both must funnel into https://www.<domain> in production.
 *
 * Only engages when the app is configured with an https:// APP_URL — so
 * localhost/testing pass straight through. Health checks are skipped to avoid
 * breaking uptime monitors on bare or alternate hosts.
 */
class CanonicalHostRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getPathInfo() === '/up') {
            return $next($request);
        }

        $appUrl = (string) config('app.url');

        if (! str_starts_with($appUrl, 'https://')) {
            return $next($request);
        }

        $configuredHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        if ($configuredHost === '') {
            return $next($request);
        }

        // Normalize: always enforce the www. subdomain as the canonical host so
        // bare-host visitors (amanprice.tech) 301 to the www variant even when
        // APP_URL already carries the www prefix.
        $canonicalHost = str_starts_with($configuredHost, 'www.')
            ? $configuredHost
            : 'www.'.$configuredHost;

        $requestHost = strtolower((string) $request->getHost());

        if ($requestHost === $canonicalHost && $request->secure()) {
            return $next($request);
        }

        return redirect()->away('https://'.$canonicalHost.$request->getRequestUri(), 301);
    }
}