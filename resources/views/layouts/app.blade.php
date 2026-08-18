@props([
    'metaTitle' => config('app.name'),
    'metaDescription' => null,
    'ogTitle' => null,
    'ogDescription' => null,
    'ogImage' => null,
    'ogType' => 'website',
    'robots' => 'index, follow',
])
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <!-- Google tag (gtag.js) — async loader + config deferred off the main
         thread (requestIdleCallback) to keep TBT/interaction-low on small hosts. -->
    @if (config('app.env') !== 'local')
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-4HK625WV4X"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        (function () {
            var init = function () {
                gtag('js', new Date());
                gtag('config', 'G-4HK625WV4X', { send_page_view: true });
            };
            if ('requestIdleCallback' in window) {
                requestIdleCallback(init, { timeout: 2000 });
            } else {
                window.addEventListener('load', function () { setTimeout(init, 0); });
            }
        })();
        </script>
    @endif

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Favicon / أمان برايس أيقونة -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <meta name="theme-color" content="#0f172a">

    @php
        $seoHelper = \App\Services\SEOHelper::class;
        $canonicalPath = request()->path() === '/' ? '' : request()->path();
        $canonicalUrl = $seoHelper::canonical($canonicalPath);
        $siteName = config('app.name', 'أمان برايس');
    @endphp

    <title>{{ $metaTitle ?? $siteName }}</title>
    <meta name="description" content="{{ $metaDescription ?? $siteName.' — بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر مع حاسبة التقسيط والأمان في الشراء.' }}">
    <meta name="robots" content="{{ $robots }}">

    <!-- Google Fonts: Readex Pro (preloaded; font-display=optional kills swap-CLS) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=optional">
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=optional" rel="stylesheet" media="print" onload="this.media='all'">

    @php
        $r2Url = (string) config('filesystems.disks.r2.url');
        $r2Host = parse_url($r2Url, PHP_URL_HOST);
    @endphp
    @if ($r2Host)
        <link rel="preconnect" href="{{ $r2Url }}">
        <link rel="dns-prefetch" href="{{ $r2Url }}">
    @endif

    <!-- OpenGraph Tagging -->
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta property="og:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta property="og:image" content="{{ ! empty($ogImage) ? $ogImage : url('/img/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta name="twitter:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta name="twitter:image" content="{{ ! empty($ogImage) ? $ogImage : url('/img/og-image.png') }}">

    <link rel="canonical" href="{{ $canonicalUrl }}">

    @php
        $organizationSchema = [
            '@context' => 'https://schema.org',
            '@id' => $seoHelper::url('#organization'),
            '@type' => 'Organization',
            'name' => 'أمان برايس | AmanPrice Egypt',
            'alternateName' => ['AmanPrice', 'أمان برايس مصر'],
            'slogan' => 'أكبر موقع لمقارنة أسعار الأجهزة والتقسيط في مصر',
            'url' => $seoHelper::url(),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $seoHelper::url('logo.svg'),
            ],
            'description' => 'دليل مصري مستقل لمراجعة أسعار الأجهزة المنزلية، التكييفات، ومقارنات التقسيط البنكي على أمازون مصر.',
            'address' => [
                '@type' => 'PostalAddress',
                'addressCountry' => 'EG',
            ],
            'areaServed' => 'EG',
            'knowsAbout' => [
                'تكييفات ومبردات في مصر',
                'أسعار الأجهزة المنزلية',
                'تقسيط أمازون مصر',
                'مقارنات الأسعار',
            ],
        ];
    @endphp

    <script type="application/ld+json">{!! json_encode($organizationSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Readex Pro', system-ui, -apple-system, sans-serif;
        }
    </style>

    @stack('head_meta')
    @stack('schema')
</head>
<body class="min-h-screen bg-white text-ink antialiased selection:bg-primary-600 selection:text-white">

    <!-- Rich AmanPrice Header -->
    <x-layouts.header :categories="$headerCategories ?? collect()" />

    <!-- Main Content Slot -->
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    <!-- AmanPrice Footer -->
    <x-layouts.footer />

    <!-- WebMCP (AgentCat / navigator.modelContext): registers a search tool with
         a Primary Purchase Action output (direct Amazon Egypt URL) so browser
         agents can discover and buy without a token. Deferred to idle time and
         feature-detected so regular visitors and PageSpeed scores are untouched. -->
    <script>
    (function () {
        var register = function () {
            if (typeof window !== 'undefined' && window.navigator && 'modelContext' in navigator && typeof navigator.modelContext.registerTool === 'function') {
                try {
                    navigator.modelContext.registerTool({
                        name: "amanprice_search",
                        description: "Search AmanPrice (amanprice.tech) for Egyptian appliance prices, reviews, bank installment comparisons, and official verified Amazon Egypt purchase links.",
                        inputSchema: {
                            type: "object",
                            properties: {
                                q: {
                                    type: "string",
                                    description: "Search query e.g. 'تكييف ميديا 1.5 حصان' or 'washing machine'."
                                },
                                deals: {
                                    type: "boolean",
                                    description: "Filter only discounted items."
                                }
                            },
                            required: ["q"]
                        },
                        outputSchema: {
                            type: "object",
                            properties: {
                                markdown_content: { type: "string" },
                                direct_purchase_url: { type: "string", description: "Direct official Amazon Egypt purchase link" },
                                product_title: { type: "string" }
                            }
                        }
                    });
                } catch (e) {
                    console.debug("WebMCP registration skipped", e);
                }
            }
        };

        // Runs off the main thread after first idle (mirrors the gtag loader
        // above) so the tool never blocks paint or interaction.
        if ('requestIdleCallback' in window) {
            requestIdleCallback(register, { timeout: 2000 });
        } else {
            window.addEventListener('load', function () { setTimeout(register, 0); });
        }
    })();
    </script>

</body>
</html>
