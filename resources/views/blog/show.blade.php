@php
    $seoHelper = \App\Services\SEOHelper::class;
    $siteUrl = $seoHelper::url();
    $pageUrl = $seoHelper::canonical('blog/'.$post->slug);
    $cleanTitle = $seoHelper::cleanTitle($post->title);
    $cleanContent = \App\Services\ShortcodeParser::stripShortcodes($post->content);
    $description = $post->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($cleanContent), 160);
    $categoryName = $post->category?->name ?? 'المدونة';
    $categoryUrl = $post->category ? $seoHelper::canonical('category/'.$post->category->slug) : $siteUrl;
    $imageUrl = $siteUrl.'/favicon.svg';
@endphp

<x-layouts.app
    :meta-title="$cleanTitle"
    :meta-description="$description"
    :og-title="$cleanTitle"
    :og-description="$description"
    :og-image="$imageUrl"
    og-type="article"
>
    @push('schema')
        @php
            $blogPostingSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $pageUrl],
                'headline' => $cleanTitle,
                'description' => $description,
                'image' => [$imageUrl],
                'datePublished' => $post->created_at?->toIso8601String(),
                'dateModified' => $post->updated_at?->toIso8601String(),
                'inLanguage' => 'ar-EG',
                'wordCount' => \Illuminate\Support\Str::of($cleanContent)->stripTags()->squish()->split('/\s+/')->filter()->count(),
                'articleSection' => $categoryName,
                'author' => [
                    '@type' => 'Organization',
                    'name' => config('app.name', 'أمان برايس'),
                    'url' => $siteUrl,
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => config('app.name', 'أمان برايس'),
                    'url' => $siteUrl,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => $siteUrl.'/favicon.svg',
                    ],
                ],
            ];

            $breadcrumbSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => $siteUrl],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'المدونة والمقالات الإرشادية', 'item' => $seoHelper::canonical('blog')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $categoryName, 'item' => $categoryUrl],
                    ['@type' => 'ListItem', 'position' => 4, 'name' => $cleanTitle, 'item' => $pageUrl],
                ],
            ];
        @endphp

        <script type="application/ld+json">{!! json_encode($blogPostingSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
        <nav class="mb-6 text-sm text-slate-500" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1">
                <li><a href="/" class="hover:text-primary-600">الرئيسية</a></li>
                <li><span aria-hidden="true">/</span> <a href="{{ route('blog.index') }}" class="hover:text-primary-600">المدونة</a></li>
                @if ($post->category)
                    <li><span aria-hidden="true">/</span> <a href="{{ route('categories.show', $post->category->slug) }}" class="hover:text-primary-600">{{ $post->category->name }}</a></li>
                @endif
                <li aria-current="page"><span aria-hidden="true">/</span> <span class="text-ink">{{ $cleanTitle }}</span></li>
            </ol>
        </nav>

        <header class="mb-8 border-b border-slate-100 pb-6">
            @if ($post->category)
                <a href="{{ route('categories.show', $post->category->slug) }}"
                   class="inline-block rounded-full bg-primary-600/10 border border-primary-600/20 px-3 py-1 text-xs font-bold text-primary-700 transition hover:bg-primary-600 hover:text-white">
                    {{ $post->category->name }}
                </a>
            @endif

            <h1 class="mt-4 text-3xl font-black leading-snug text-ink sm:text-4xl">{{ $cleanTitle }}</h1>

            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-slate-500 sm:text-sm">
                <span class="inline-flex items-center gap-1.5">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <time datetime="{{ $post->updated_at?->toIso8601String() }}">آخر تحديث: {{ $post->updated_at?->translatedFormat('d F Y') }}</time>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.832.477 5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                    {{ $post->readMinutes() }} دقيقة قراءة
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                    فريق {{ config('app.name', 'أمان برايس') }}
                </span>
            </div>
        </header>

        <div class="article-content mx-auto max-w-none space-y-6 text-lg leading-8 text-ink/80">
            {!! $parsedContent !!}
        </div>
    </article>

    @if ($relatedPosts->isNotEmpty())
        <section class="mt-10" aria-labelledby="related-posts-heading">
            <h2 id="related-posts-heading" class="mb-5 text-xl font-extrabold text-ink">
                مقالات ذات صلة من {{ $post->category?->name ?: 'المدونة' }}
            </h2>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($relatedPosts as $related)
                    <a href="{{ route('blog.show', $related->slug) }}"
                       class="group flex flex-col justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary-600/40 hover:shadow-md">
                        <div class="flex items-center gap-2 text-[11px] font-semibold text-mist">
                            @if ($related->category)
                                <span class="rounded-full bg-primary-600/10 px-2.5 py-1 text-primary-700">{{ $related->category->name }}</span>
                            @endif
                            <span>{{ $related->readMinutes() }} دقيقة قراءة</span>
                        </div>
                        <h3 class="text-sm font-bold leading-6 text-ink transition group-hover:text-primary-700">{{ $related->title }}</h3>
                        <time datetime="{{ $related->updated_at?->toIso8601String() }}" class="text-[11px] text-mist/80">
                            {{ $related->updated_at?->translatedFormat('d F Y') }}
                        </time>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>