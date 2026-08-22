<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\SEOHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * First-party cloaked affiliate redirect engine.
 *
 * Serves /go/{ASIN} → 302 → Amazon Egypt product page with the affiliate
 * tag, while keeping the outbound link hidden from HTML source, Markdown
 * generators and schema.org — all consumers point to the /go/ short URL.
 *
 * Every redirect carries X-Robots-Tag: noindex, nofollow so crawlers
 * never index the intermediate hop.
 */
class RedirectController extends Controller
{
    public function go(Request $request, string $asin): RedirectResponse
    {
        $asin = strtoupper(trim($asin));

        $tag = config('services.amazon.tag', 'khatfadeals2-21');

        // If the product exists in the database and has a stored affiliate URL,
        // resolve the canonical clean link (which may carry platform-specific
        // query params for Noon, etc.). Otherwise fall back to the standard
        // Amazon Egypt dp/ link built from the ASIN + tag.
        $product = Product::query()
            ->where('asin', $asin)
            ->first();

        if ($product !== null) {
            $targetUrl = SEOHelper::cleanAffiliateUrl(
                (string) $product->affiliate_url,
                (string) $product->asin
            );
        } else {
            $targetUrl = 'https://www.amazon.eg/dp/'.$asin.'?tag='.$tag
                .'&linkCode=ll2&ref_=as_li_ss_tl';
        }

        // Fallback: if cleanAffiliateUrl returned empty (product with no URL
        // and no ASIN match), build the standard Amazon link.
        if ($targetUrl === '') {
            $targetUrl = 'https://www.amazon.eg/dp/'.$asin.'?tag='.$tag
                .'&linkCode=ll2&ref_=as_li_ss_tl';
        }

        return redirect()->away($targetUrl, 302, [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
        ]);
    }
}
