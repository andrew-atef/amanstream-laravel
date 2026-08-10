<div class="rounded-lg bg-slate-500/5 p-3 text-sm ring-1 ring-slate-200">
    <div class="mb-2 flex items-center gap-2">
        <span class="fi-color-custom bg-custom-100 text-custom-700" style="--c-50:var(--color-primary-50);--c-100:var(--color-primary-100);--c-200:var(--color-primary-200);--c-500:var(--color-primary-500);--c-600:var(--color-primary-600);--c-700:var(--color-primary-700)"></span>
        <span class="font-semibold text-gray-950">روابط المنتج</span>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if ($product?->affiliate_url)
            <a
                href="{{ $product->affiliate_url }}"
                target="_blank"
                rel="noopener nofollow sponsored"
                class="fi-btn inline-flex items-center justify-center gap-1.5 rounded-lg bg-custom-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-custom-500"
                style="--c-500:var(--color-primary-500);--c-600:var(--color-primary-600)">
                <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" />
                فتح في أمازون
            </a>
        @endif

        @if ($productEditUrl)
            <a
                href="{{ $productEditUrl }}"
                class="fi-btn inline-flex items-center justify-center gap-1.5 rounded-lg bg-slate-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-slate-500">
                <x-heroicon-m-pencil-square class="h-4 w-4" />
                تعديل المنتج هنا
            </a>
        @endif

        @if ($articleUrl)
            <a
                href="{{ $articleUrl }}"
                target="_blank"
                rel="noopener"
                class="fi-btn inline-flex items-center justify-center gap-1.5 rounded-lg bg-slate-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-slate-500">
                <x-heroicon-m-eye class="h-4 w-4" />
                معاينة المقال
            </a>
        @endif

        @unless ($product || $articleUrl)
            <span class="text-xs text-gray-400">اختر منتجاً لعرض روابطه، وسيظهر رابط المعاينة بمجرد كتابة المعرّف (Slug).</span>
        @endunless
    </div>
</div>