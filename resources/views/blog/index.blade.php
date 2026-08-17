@php
    $siteName = config('app.name', 'أمان برايس');
@endphp

<x-layouts.app
    :meta-title="$siteName.' | المدونة والمقالات الإرشادية'"
    :meta-description="'مقالات إرشادية ودلائل شراء من الأمان برايس: نصائح قبل الشراء، طرق الاستخدام، والاختيار بين الأجهزة المنزلية والتكنولوجيا بدون تقييد بمنتج محدد.'"
    :og-title="$siteName.' | المدونة والمقالات الإرشادية'"
    :og-description="'دلائل ومقالات إرشادية من الأمان برايس لمساعدتك على اتخاذ قرارات الشراء الصحيحة.'"
    og-type="website"
>
    @push('schema')
        @php
            $seoHelper = \App\Services\SEOHelper::class;
            $breadcrumbs = [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => $seoHelper::canonical()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'المدونة والمقالات الإرشادية', 'item' => $seoHelper::canonical('blog')],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <div class="space-y-8">
        <!-- Page Header -->
        <div class="border-b border-primary-600/20 pb-6">
            <h1 class="text-2xl font-extrabold text-ink sm:text-3xl">المدونة والمقالات الإرشادية ✍️</h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-ink-soft">
                أدلة ونصائح عملية قبل الشراء: كيف تختار الجهاز المناسب، كيفية المقارنة بين الخيارات،
                نصائح التركيب والتشغيل، وأحدث اتجاهات السوق — محتوى مستقل غير مربوط بمنتج وحيد.
            </p>
            @if ($blogCategories->isNotEmpty())
                <nav class="mt-5 flex flex-wrap gap-2" aria-label="أقسام المدونة">
                    @foreach ($blogCategories as $category)
                        <a href="{{ $seoHelper::canonical('category/'.$category->slug) }}"
                           class="rounded-full border border-primary-600/30 bg-primary-600/5 px-3.5 py-1.5 text-xs font-semibold text-primary-700 transition hover:bg-primary-600 hover:text-white">
                            {{ $category->name }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>

        <!-- Editorial Grid -->
        @if ($posts->isNotEmpty())
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    @php
                        $coverClass = match ($post->id % 4) {
                            0 => 'from-primary-600 to-indigo-600',
                            1 => 'from-emerald-600 to-primary-700',
                            2 => 'from-amber-500 to-orange-600',
                            default => 'from-slate-700 to-primary-800',
                        };
                    @endphp
                    <article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                        <a href="{{ route('blog.show', $post->slug) }}" class="bg-gradient-to-br {{ $coverClass }} grid aspect-[16/9] place-items-center" aria-label="{{ $post->title }}">
                            <span class="text-5xl font-extrabold text-white/90 drop-shadow-sm">{{ mb_substr($post->title, 0, 1) }}</span>
                        </a>
                        <div class="flex flex-1 flex-col gap-3 p-5">
                            <div class="flex items-center gap-2 text-[11px] font-semibold text-ink-soft">
                                @if ($post->category)
                                    <a href="{{ $seoHelper::canonical('category/'.$post->category->slug) }}" class="rounded-full bg-primary-600/10 px-2.5 py-1 text-primary-700 transition hover:bg-primary-600 hover:text-white">
                                        {{ $post->category->name }}
                                    </a>
                                @endif
                                <span class="text-ink-soft/60">•</span>
                                <span>{{ $post->readMinutes() }} دقيقة قراءة</span>
                            </div>
                            <h2 class="text-base font-bold leading-7 text-ink">
                                <a href="{{ route('blog.show', $post->slug) }}" class="transition group-hover:text-primary-700">
                                    {{ $post->title }}
                                </a>
                            </h2>
                            @if ($post->meta_description)
                                <p class="text-xs leading-6 text-ink-soft">{{ \Illuminate\Support\Str::limit($post->meta_description, 110) }}</p>
                            @endif
                            <div class="mt-auto flex items-center justify-between pt-2 text-[11px] text-ink-soft/80">
                                <time datetime="{{ $post->updated_at?->toIso8601String() }}">{{ $post->updated_at?->translatedFormat('d F Y') }}</time>
                                <a href="{{ route('blog.show', $post->slug) }}" class="font-bold text-primary-700 transition hover:text-primary-800">
                                    اقرأ المقال ←
                                </a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $posts->links() }}
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-primary-600/30 bg-primary-600/5 p-10 text-center">
                <p class="text-sm font-semibold text-ink-soft">لا توجد مقالات إرشادية منشورة بعد — عد قريباً!</p>
            </div>
        @endif
    </div>
</x-layouts.app>