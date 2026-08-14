@props([
    'categories' => [],
])

@php
    $navLinks = [
        [
            'label' => 'الرئيسية',
            'href' => '/',
            'active' => request()->is('/') && ! request('q') && ! request('category'),
        ],
        ...collect($categories)->map(fn ($category) => [
            'label' => $category->name,
            'href' => route('home', ['category' => $category->slug]),
            'active' => request('category') == $category->slug,
        ]),
        [
            'label' => 'عن أمان برايس',
            'href' => '/about',
            'active' => request()->is('about'),
        ],
    ];
@endphp

<header class="sticky top-0 z-50 border-b border-primary-600/30 bg-[#0f172a] text-white shadow-md">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3.5 sm:px-6">

        <!-- Logo AmanPrice -->
        <a href="/" class="group shrink-0" aria-label="أمان برايس الرئيسية">
            <img src="/logo.svg" alt="أمان برايس | AmanPrice" width="429" height="120" class="h-10 w-auto transition duration-200 sm:h-11 group-hover:opacity-90">
        </a>

        <!-- Dynamic Category Navigation Links (Desktop) -->
        <nav class="hidden items-center gap-5 text-xs font-semibold text-mist md:flex">
            @foreach ($navLinks as $link)
                <a href="{{ $link['href'] }}" class="transition hover:text-white {{ $link['active'] ? 'font-extrabold text-white' : '' }}">
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <!-- Mobile: Hamburger Toggle -->
        <button
            type="button"
            class="grid h-10 w-10 place-items-center rounded-lg border border-mist/40 text-mist transition hover:border-white hover:text-white md:hidden"
            aria-label="فتح قائمة التنقل"
            aria-expanded="false"
            aria-controls="mobile-nav"
            data-mobile-menu-toggle
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" data-mobile-menu-icon="open"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            <svg class="hidden h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" data-mobile-menu-icon="close"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

    </div>

    <!-- Mobile Dropdown Menu -->
    <nav id="mobile-nav" class="hidden border-t border-white/10 bg-[#0f172a] px-4 pb-4 pt-2 md:hidden">
        <ul class="space-y-1 text-sm font-semibold text-mist">
            @foreach ($navLinks as $link)
                <li>
                    <a href="{{ $link['href'] }}" class="block rounded-lg px-3 py-2.5 transition hover:bg-primary-600/20 hover:text-white {{ $link['active'] ? 'bg-primary-600/20 font-extrabold text-white' : '' }}">
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</header>