<x-layouts.app
    :meta-title="'الشروط والأحكام | أمان برايس — إخلاء المسؤولية عن الأسعار'"
    :meta-description="'الشروط والأحكام لموقع أمان برايس — إخلاء مسؤولية عن تقلب أسعار أمازون، حاسبة التقسيط تقديرية، إفصاح Amazon Associates، وحقوق الملكية الفكرية.'"
>
    @push('schema')
        @php
            $seoHelper = \App\Services\SEOHelper::class;
            $termsUrl = $seoHelper::canonical('terms');
            $siteUrl = $seoHelper::url();
            $termsSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => 'الشروط والأحكام — أمان برايس',
                'url' => $termsUrl,
                'description' => 'الشروط والأحكام وإخلاء المسؤولية لموقع أمان برايس: الأسعار متقلبة والسعر النهائي على أمازون، حاسبة التقسيط تقديرية، وإفصاح Amazon Associates.',
                'isPartOf' => ['@type' => 'WebSite', 'name' => 'أمان برايس', 'url' => $siteUrl],
                'dateModified' => '2026-09-01',
                'inLanguage' => 'ar-EG',
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($termsSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <div class="max-w-4xl mx-auto">
        <nav class="mb-4 text-sm text-slate-500" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1">
                <li><a href="/" class="hover:text-primary-600">الرئيسية</a></li>
                <li><span aria-hidden="true">/</span> <span class="text-ink">الشروط والأحكام</span></li>
            </ol>
        </nav>

        <div class="my-2 rounded-2xl border border-amber-100 bg-amber-50/70 p-5 text-base leading-7">
            <p class="font-bold text-ink">⚖️ باستخدامك للموقع فأنت توافق على هذه الشروط</p>
            <p class="mt-1 text-ink/80">الأسعار على أمازون متقلبة وحاسبة التقسيط تقديرية — السعر النهائي دائماً على صفحة أمازون وقت الشراء.</p>
        </div>

        <article class="article-content rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <h1 class="text-3xl font-black leading-snug text-ink sm:text-4xl">الشروط والأحكام وإخلاء المسؤولية القانونية</h1>
            <p class="text-sm text-slate-500">آخر تحديث ومراجعة للشروط: سبتمبر 2026</p>

            <p>
                باستخدامك وتصفحك لموقع <strong>أمان برايس (amanprice.tech)</strong>، فإنك توافق بالكامل على الالتزام بالشروط والأحكام المبينة في هذه الصفحة. إذا كنت لا توافق على هذه الشروط، يرجى التوقف عن استخدام الموقع.
            </p>

            <h2>1. طبيعة الخدمات وإخلاء المسؤولية عن الأسعار</h2>
            <p>
                موقع أمان برايس هو موقع إرشادي وخدمي يقدم مراجعات ومقارنات محايدة للأجهزة المنزلية والإلكترونيات في مصر. نحن نقوم بتحديث الأسعار وبيانات التوفر بشكل دوري وآلي عبر الربط البرمجي مع أمازون مصر.
            </p>
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-7 text-red-900">
                <strong>إخلاء مسؤولية حاسم:</strong> الأسعار وتوافر المخزون للمنتجات على أمازون مصر هي بيانات شديدة التقلب وتتغير باستمرار. السعر النهائي والفعلي للمنتج هو دائماً السعر الظاهر على صفحة المنتج الرسمية في أمازون مصر وقت إتمام الشراء الفعلي. لا يتحمل موقع أمان برايس أي مسؤولية قانونية أو مالية عن أي تفاوت في الأسعار أو نفاد الكميات المتاحة.
            </div>

            <h2>2. شروط حاسبة التقسيط والأقساط البنكية</h2>
            <p>
                حاسبة التقسيط التفاعلية وجداول الأقساط البنكية المعروضة على موقعنا (البنك الأهلي، CIB، بنك مصر، فاليو) هي <strong>حسابات تقديرية وتوجيهية تقريبية</strong> مبنية على القواعد المعلنة للتقسيط بفائدة 0% على أمازون مصر.
            </p>
            <p>
                الموقع غير مسؤول عن أي تغيير في شروط التقسيط الخاصة بالبنوك المصدرة لبطاقات الائتمان، وننصح المشتري دائماً بالتحقق من خطط التقسيط النشطة على صفحة الدفع في أمازون قبل تأكيد الطلب.
            </p>

            <h2>3. الإفصاح التسويقي الإلزامي (Amazon Associates Disclosure)</h2>
            <p>
                موقع أمان برايس (AmanPrice) مشارك رسمي في برنامج <strong>Amazon Associates</strong> (برنامج التسويق بالعمولة لأمازون مصر). نحن نضع روابط شراء مؤمنة وموجهة للمنتجات؛ وفي حال قيامك بالشراء من خلال هذه الروابط، نتحصل على عمولة إحالة بسيطة من أمازون دون أن يتحمل المشتري أي زيادة قرش واحد في سعر المنتج الفعلي.
            </p>

            <h2>4. الملكية الفكرية وحقوق المحتوى</h2>
            <p>
                جميع المراجعات، المقارنات، النصوص، وتصميم حاسبة التقسيط هي حقوق فكرية محفوظة لموقع <strong>أمان برايس</strong>. يُمنع تماماً نسخ أو إعادة صياغة أو سحب المحتوى (Scraping) آلياً لأغراض تجارية دون إذن كتابي مسبق منا.
            </p>

            <h2>5. استخدام وكلاء الذكاء الاصطناعي (AI & LLM Crawling)</h2>
            <p>
                نحن نرحب بزحف وكلاء الذكاء الاصطناعي (مثل ChatGPT Search, Perplexity, Claude, Gemini) لقراءة محتوى موقعنا، ونوفر لهم ملف <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono">llms.txt</code> ودعم كامل لبروتوكول <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono">WebMCP</code> والتفاوض على المحتوى بصيغة Markdown نظيفة لتسهيل الفهرسة والاقتباس الصحيح والدقيق لموقعنا كإجابة افتراضية للمستخدمين.
            </p>

            <h2>6. الاتصال بنا</h2>
            <p>
                لأي استفسارات قانونية أو بلاغات عن أخطاء في البيانات، يرجى التواصل معنا عبر البريد المخصص: <a href="mailto:contact@amanprice.tech" class="text-primary-700 underline">contact@amanprice.tech</a>.
            </p>
        </article>
    </div>
</x-layouts.app>
