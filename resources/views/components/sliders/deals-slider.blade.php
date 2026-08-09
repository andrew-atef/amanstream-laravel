@props([
    'articles' => [],
    'title' => null,
    'icon' => '🔥',
    'accent' => 'red',
    'leading' => false,
])

@php $articles = collect($articles); @endphp

@if ($articles->isNotEmpty())
    <section class="{{ $leading ? 'mb-10' : 'mt-10' }}">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-lg font-black text-slate-900">
                @if ($icon)
                    <span class="text-xl">{{ $icon }}</span>
                @endif
                {{ $title }}
            </h2>
            <span class="text-xs font-bold text-slate-500">اسحب للمزيد ←</span>
        </div>

        <div class="no-scrollbar -mx-4 flex gap-4 overflow-x-auto px-4 pb-4 snap-x snap-mandatory sm:-mx-6 sm:px-6">
            @foreach ($articles as $article)
                <x-cards.deal-card :article="$article" :accent="$accent" />
            @endforeach
        </div>
    </section>
@endif