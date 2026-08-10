<footer class="mt-20 border-t border-slate-200 bg-white py-10 text-center text-xs text-ink/70">
    <div class="mx-auto max-w-6xl px-4">

        <!-- Footer Links -->
        <div class="mb-4 flex flex-wrap items-center justify-center gap-4 text-xs font-bold text-ink/70">
            <a href="/" class="transition hover:text-primary-600">الرئيسية</a>
            <span aria-hidden="true">•</span>
            <a href="/about" class="transition hover:text-primary-600">عن أمان ستريم</a>
            <span aria-hidden="true">•</span>
            <a href="/sitemap.xml" class="transition hover:text-primary-600">خريطة الموقع (Sitemap)</a>
            <span aria-hidden="true">•</span>
            <a href="/llms.txt" target="_blank" rel="noopener" class="rounded border border-slate-200 bg-slate-100 px-2 py-0.5 font-mono text-[11px] transition hover:text-primary-600">llms.txt (AI Specs)</a>
        </div>

        <div class="mb-4 flex items-center justify-center">
            <img src="/logo_dark.svg" alt="AmanStream Egypt" width="429" height="120" class="h-14 w-auto sm:h-16" loading="lazy">
        </div>

        <p>© {{ date('Y') }} {{ config('app.name') }} (amanstream.me) — جميع الحقوق محفوظة.</p>
        <p class="mx-auto mt-2 max-w-md text-[11px] text-mist">
            موقع أمان ستريم يقدم مراجعات ومقارنات أسعار محايدة. قد نتحصل على عمولة تسويقية عند الشراء من خلال روابط أمازون دون أي زيادة في السعر عليك.
        </p>
    </div>
</footer>