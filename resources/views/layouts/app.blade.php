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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $metaTitle ?? config('app.name', 'أمان ستريم | بث أسعار ومراجعات الأجهزة في مصر') }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'أمان ستريم — بوابتك المباشرة لمراجعة أسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر مع حاسبة التقسيط والأمان في الشراء.' }}">
    <meta name="robots" content="index, follow">

    <!-- Google Fonts: Readex Pro -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta property="og:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">
    @if (! empty($ogImage))
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta property="og:url" content="{{ url()->current() }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? ($metaTitle ?? '') }}">
    <meta name="twitter:description" content="{{ $ogDescription ?? ($metaDescription ?? '') }}">

    <link rel="canonical" href="{{ url()->current() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Readex Pro', system-ui, -apple-system, sans-serif;
        }
    </style>

    @stack('schema')
</head>
<body class="min-h-screen bg-[#f8fafc] text-slate-900 antialiased selection:bg-sky-500 selection:text-white">

    <!-- AmanStream Brand Header -->
    <header class="sticky top-0 z-50 border-b border-slate-800 bg-slate-900 text-white shadow-md">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3.5 sm:px-6">

            <!-- Logo AmanStream -->
            <a href="/" class="group flex items-center gap-2.5">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-sky-600 to-emerald-500 text-white shadow-md shadow-sky-500/20 transition duration-200 group-hover:scale-105">
                    <!-- Stream + Shield Safety Icon -->
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div class="flex flex-col">
                    <span class="flex items-center gap-1 text-lg font-black tracking-tight text-white">
                        AMAN<span class="text-sky-400">STREAM</span>
                    </span>
                    <span class="-mt-1 text-[10px] font-medium text-slate-400">دليلك المباشر للشراء الآمن</span>
                </div>
            </a>

            <!-- Navigation -->
            <nav class="flex items-center gap-6 text-xs font-semibold text-slate-300">
                <a href="/" class="flex items-center gap-1 transition hover:text-sky-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    الرئيسية
                </a>
                <a href="/sitemap.xml" class="hidden transition hover:text-sky-400 sm:inline-block">خريطة الموقع</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    <!-- AmanStream Footer -->
    <footer class="mt-20 border-t border-slate-200 bg-white py-10 text-center text-xs text-slate-500">
        <div class="mx-auto max-w-6xl px-4">
            <div class="mb-3 flex items-center justify-center gap-2">
                <div class="h-2 w-2 animate-pulse rounded-full bg-emerald-500"></div>
                <span class="font-bold text-slate-700">AmanStream Egypt</span>
            </div>
            <p>© {{ date('Y') }} {{ config('app.name') }} (amanstream.me) — جميع الحقوق محفوظة.</p>
            <p class="mx-auto mt-2 max-w-md text-[11px] text-slate-500">
                أمان ستريم يقدم مراجعات ومقارنات أسعار محايدة. قد نتحصل على عمولة تسويقية عند الشراء من خلال روابط أمازون دون أي زيادة في السعر عليك.
            </p>
        </div>
    </footer>

</body>
</html>
