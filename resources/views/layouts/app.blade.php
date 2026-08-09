@props([
    'metaTitle' => config('app.name'),
    'metaDescription' => null,
    'ogTitle' => null,
    'ogDescription' => null,
    'ogImage' => null,
    'ogType' => 'website',
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
    <meta name="robots" content="index, follow">

    <!-- Google Fonts: Readex Pro (Optimized with Preload and Display Swap) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=swap">
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

    <!-- OpenGraph Tagging -->
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta property="og:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta property="og:image" content="{{ ! empty($ogImage) ? $ogImage : url('/favicon.svg') }}">
    <meta property="og:url" content="{{ url()->current() }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta name="twitter:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    <meta name="twitter:image" content="{{ ! empty($ogImage) ? $ogImage : url('/favicon.svg') }}">

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
<body class="min-h-screen bg-[#f8fafc] text-slate-900 antialiased selection:bg-sky-500 selection:text-white">

    <!-- Rich AmanStream Header -->
    <x-layouts.header :categories="$headerCategories ?? collect()" />

    <!-- Main Content Slot -->
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    <!-- AmanStream Footer -->
    <x-layouts.footer />

</body>
</html>
