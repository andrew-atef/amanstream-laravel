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
        $providedString = is_array($provided) ? ($provided[0] ?? '') : (string) $provided;
        $expected = (string) config('services.catalog_sync.token');

        if (blank($providedString) || blank($expected) || ! hash_equals($expected, $providedString)) {
            return response()->json([
                'message' => 'Unauthorized. Missing or invalid x-sync-token header.',
            ], 401)
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
                ->header('CDN-Cache-Control', 'no-store')
                ->header('Pragma', 'no-cache');
        }

        return $next($request);
    }
}
