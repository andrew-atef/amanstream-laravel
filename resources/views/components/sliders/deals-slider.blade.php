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
    <section class="{{ $leading ? 'mb-10' : 'mt-10' }}">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-lg font-black text-ink">
                {{ $title }}
            </h2>
            @if ($moreHref)
                <a href="{{ $moreHref }}" class="shrink-0 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary-700 transition hover:bg-primary-100 hover:text-primary-900">
                    المزيد ←
                </a>
            @else
                <span class="text-xs font-bold text-slate-500">اسحب للمزيد ←</span>
            @endif
        </div>

        <div class="no-scrollbar -mx-4 flex gap-4 overflow-x-auto px-4 pb-4 snap-x snap-mandatory sm:-mx-6 sm:px-6">
            @foreach ($articles as $article)
                <x-cards.deal-card :article="$article" :accent="$accent" :clamp-title="! $fullTitles" :eager="$leading && $loop->first" />
            @endforeach
        </div>
    </section>
@endif