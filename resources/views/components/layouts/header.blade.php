@props([
    'categories' => [],
])

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
            @foreach ($categories as $category)
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