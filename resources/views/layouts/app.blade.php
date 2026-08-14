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
    <!-- Google tag (gtag.js) -->
    @if (config('app.env') !== 'local')
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-4HK625WV4X"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-4HK625WV4X');
        </script>
    @endif

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Favicon / أمان ستريم أيقونة -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <meta name="theme-color" content="#0f172a">

    <title>{{ $metaTitle ?? config('app.name', 'أمان ستريم | بث أسعار ومراجعات الأجهزة في مصر') }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'أمان ستريم — بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر مع حاسبة التقسيط والأمان في الشراء.' }}">
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
        <link rel="preconnect" href="{{ $r2Url }}" crossorigin>
        <link rel="dns-prefetch" href="{{ $r2Url }}">
    @endif

    <!-- OpenGraph Tagging -->
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta property="og:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta property="og:image" content="{{ ! empty($ogImage) ? $ogImage : url('/img/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta property="og:url" content="{{ url()->current() }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta name="twitter:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta name="twitter:image" content="{{ ! empty($ogImage) ? $ogImage : url('/img/og-image.png') }}">

    <link rel="canonical" href="{{ url()->current() }}">

    @php
        $organizationSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'أمان ستريم | AmanStream Egypt',
            'alternateName' => ['AmanStream', 'أمان ستريم مصر'],
            'url' => 'https://amanstream.me',
            'logo' => 'https://amanstream.me/favicon.svg',
            'description' => 'منصة ودليل مصري مستقل لمراجعة أسعار الأجهزة المنزلية، التكييفات، ومقارنات التقسيط البنكي على أمازون مصر.',
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

    @stack('schema')
</head>
<body class="min-h-screen bg-white text-ink antialiased selection:bg-primary-600 selection:text-white">

    <!-- Rich AmanStream Header -->
    <x-layouts.header :categories="$headerCategories ?? collect()" />

    <!-- Main Content Slot -->
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    <!-- AmanStream Footer -->
    <x-layouts.footer />

    <!-- WebMCP (AgentCat / navigator.modelContext): registers a search tool so
         browser agents can query the site without a token. Feature-detected so
         browsers without modelContext are untouched. -->
    <script>
    (function () {
        if (! ("modelContext" in navigator)) return;

        try {
            navigator.modelContext.registerTool({
                name: "amanstream_search",
                description: "Search AmanStream (amanstream.me), an independent Egyptian guide to appliance prices, reviews and bank-installment comparisons on Amazon Egypt. Returns Markdown search results.",
                inputSchema: {
                    type: "object",
                    properties: {
                        q: {
                            type: "string",
                            description: "Search terms, e.g. 'تكييف 1.5 حصان' or 'washing machine price Egypt'."
                        },
                        deals: {
                            type: "boolean",
                            description: "Only show items with a live discount."
                        }
                    },
                    required: ["q"]
                },
                outputSchema: {
                    type: "string",
                    contentType: "text/markdown"
                },
                annotations: {
                    readOnlyHint: true,
                    confirmMessage: "Search AmanStream for products?"
                },
                handler: async function (params) {
                    const q = encodeURIComponent(String(params.q || ""));
                    const deals = params.deals ? "&deals=1" : "";
                    const url = "/?q=" + q + deals + "&_fmt=md";
                    const response = await fetch(url, {
                        headers: { "Accept": "text/markdown" }
                    });
                    const text = await response.text();
                    return { content: [{ type: "text", text: text }] };
                }
            });
        } catch (e) {
            // Non-fatal: the page still works without WebMCP.
        }
    })();
    </script>

</body>
</html>
