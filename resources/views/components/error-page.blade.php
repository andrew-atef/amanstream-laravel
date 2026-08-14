@props([
    'status' => '404',
    'title',
    'message',
    'icon' => 'warning',
    'noindex' => true,
])

<x-layouts.app
    :meta-title="'خطأ ' . $status . ' | ' . config('app.name')"
    :meta-description="$message"
    :robots="$noindex ? 'noindex, nofollow' : 'index, follow'"
>
    <div class="mx-auto max-w-3xl">
        <div class="my-4 rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-14">
            @if ($icon === 'sad')
                <div class="mx-auto mb-6 grid h-24 w-24 place-items-center rounded-full bg-primary-50 text-primary-600">
                    <svg class="h-12 w-12" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.182 16.318A4.486 4.486 0 0012 14.25c-1.262 0-2.415.316-3.436.885 1.125 1.078 2.468 1.615 3.941 1.615a7.5 7.5 0 004.677-1.565zM18.364 5.636l-7.564 7.564m7.564 0L10.8 5.636M9 9v.01M11 11.5v.01M15 15v.01M14 10.5v.01M17.25 21h-7.5a.75.75 0 01-.75-.75v-5.25a.75.75 0 01.75-.75h7.5a.75.75 0 01.75.75v5.25a.75.75 0 01-.75.75z" />
                    </svg>
                </div>
            @else
                <div class="mx-auto mb-6 grid h-24 w-24 place-items-center rounded-full bg-primary-50 text-primary-600">
                    <svg class="h-12 w-12" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
            @endif

            <p class="text-7xl font-black leading-none tracking-tight text-primary-600">{{ $status }}</p>
            <h1 class="mt-4 text-2xl font-black leading-snug text-ink sm:text-3xl">{{ $title }}</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-ink/70 sm:text-base">{{ $message }}</p>

            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('home') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-sm font-bold text-white shadow-md shadow-primary-600/20 transition hover:bg-primary-700 sm:w-auto">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                    </svg>
                    العودة للرئيسية
                </a>
                <a href="{{ route('sitemap') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-primary-200 bg-primary-50 px-6 py-3 text-sm font-bold text-primary-700 transition hover:bg-primary-100 sm:w-auto">
                    استعرض كل المراجعات
                </a>
                <a href="mailto:contact@amanstream.me" class="inline-flex w-full items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-bold text-ink/70 transition hover:text-primary-700 sm:w-auto">
                    تواصل معنا
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>