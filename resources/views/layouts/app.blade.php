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
    <header class="sticky top-0 z-50 border-b border-slate-800 bg-[#0f172a] text-white shadow-md">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3.5 sm:px-6">

            <!-- Logo AmanStream -->
            <a href="/" class="group shrink-0" aria-label="أمان ستريم الرئيسية">
                <img src="/logo.svg" alt="أمان ستريم | AmanStream" width="429" height="120" class="h-10 w-auto transition duration-200 sm:h-11 group-hover:opacity-90">
            </a>

            <!-- Dynamic Category Navigation Links -->
            <nav class="hidden items-center gap-5 text-xs font-semibold text-slate-300 md:flex">
                <a href="/" class="transition hover:text-sky-400 {{ request()->is('/') && ! request('q') && ! request('category') ? 'font-extrabold text-sky-400' : '' }}">
                    الرئيسية
                </a>
                @foreach ($headerCategories ?? collect() as $category)
                    <a href="{{ route('home', ['category' => $category->slug]) }}" class="transition hover:text-sky-400 {{ request('category') == $category->slug ? 'font-extrabold text-sky-400' : '' }}">
                        {{ $category->name }}
                    </a>
                @endforeach
                <a href="/about" class="transition hover:text-sky-400 {{ request()->is('about') ? 'font-extrabold text-sky-400' : '' }}">
                    عن أمان ستريم
                </a>
            </nav>

            <!-- Quick Action Mobile Button -->
            <div class="flex items-center gap-3">
                <a href="/about" class="text-xs font-bold text-sky-400 transition hover:text-white md:hidden">
                    عن الموقع
                </a>
            </div>

        </div>
    </header>

    <!-- Main Content Slot -->
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    <!-- AmanStream Footer -->
    <footer class="mt-20 border-t border-slate-200 bg-white py-10 text-center text-xs text-slate-600">
        <div class="mx-auto max-w-6xl px-4">

            <!-- Footer Links -->
            <div class="mb-4 flex flex-wrap items-center justify-center gap-4 text-xs font-bold text-slate-600">
                <a href="/" class="transition hover:text-sky-600">الرئيسية</a>
                <span aria-hidden="true">•</span>
                <a href="/about" class="transition hover:text-sky-600">عن أمان ستريم</a>
                <span aria-hidden="true">•</span>
                <a href="/sitemap.xml" class="transition hover:text-sky-600">خريطة الموقع (Sitemap)</a>
                <span aria-hidden="true">•</span>
                <a href="/llms.txt" target="_blank" rel="noopener" class="rounded border border-slate-200 bg-slate-100 px-2 py-0.5 font-mono text-[11px] transition hover:text-sky-600">llms.txt (AI Specs)</a>
            </div>

            <div class="mb-4 flex items-center justify-center">
                <img src="/logo_dark.svg" alt="AmanStream Egypt" width="429" height="120" class="h-14 w-auto sm:h-16" loading="lazy">
            </div>

            <p>© {{ date('Y') }} {{ config('app.name') }} (amanstream.me) — جميع الحقوق محفوظة.</p>
            <p class="mx-auto mt-2 max-w-md text-[11px] text-slate-500">
                موقع أمان ستريم يقدم مراجعات ومقارنات أسعار محايدة. قد نتحصل على عمولة تسويقية عند الشراء من خلال روابط أمازون دون أي زيادة في السعر عليك.
            </p>
        </div>
    </footer>

</body>
</html>
