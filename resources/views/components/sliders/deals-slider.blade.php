@props([
    'articles' => [],
    'title' => null,
    'accent' => 'red',
    'leading' => false,
    'moreHref' => null,
    'fullTitles' => false,
])

@php $articles = collect($articles); @endphp

@if ($articles->isNotEmpty())
    <section class="{{ $leading ? 'mb-10' : 'mt-10' }} max-w-full overflow-hidden">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="min-w-0 text-base font-black leading-tight text-ink sm:text-lg">
                {{ $title }}
            </h2>
            @if ($moreHref)
                <a href="{{ $moreHref }}" aria-label="تصفح جميع {{ $title }}" class="self-start shrink-0 whitespace-nowrap rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-[11px] font-bold text-primary-700 transition hover:bg-primary-100 hover:text-primary-900 sm:self-auto sm:text-xs">
                    <span class="sm:hidden">عرض الكل ←</span><span class="hidden sm:inline">تصفح كل {{ $title }} ←</span>
                </a>
            @else
                <span class="self-start text-xs font-bold text-slate-500 sm:self-auto">اسحب للمزيد ←</span>
            @endif
        </div>

        <div class="no-scrollbar -mx-4 flex gap-4 overflow-x-auto overflow-y-hidden px-4 pb-4 snap-x snap-mandatory sm:-mx-6 sm:px-6">
            @foreach ($articles as $article)
                <x-cards.deal-card :article="$article" :accent="$accent" :clamp-title="! $fullTitles" :eager="$leading && $loop->first" />
            @endforeach
        </div>
    </section>
@endif