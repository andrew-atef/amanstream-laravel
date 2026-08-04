<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCatalogSyncToken
{
    /**
     * Guard the catalog sync API behind a shared secret header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('x-sync-token');
        $expected = (string) config('services.catalog_sync.token');

        if (blank($provided) || blank($expected) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthorized. Missing or invalid x-sync-token header.',
            ], 401);
        }

        return $next($request);
    }
}
