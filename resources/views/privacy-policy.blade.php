<x-layouts.app
    :meta-title="'سياسة الخصوصية | أمان برايس — كيف نحمي بياناتك'"
    :meta-description="'سياسة الخصوصية لموقع أمان برايس (amanprice.tech) — لا نطلب تسجيل، نستخدم Cookies وGoogle Analytics بشكل مجهول، وإفصاح عن Google Ads API وروابط أمازون الآمنة.'"
>
    @push('schema')
        @php
            $seoHelper = \App\Services\SEOHelper::class;
            $privacyUrl = $seoHelper::canonical('privacy-policy');
            $siteUrl = $seoHelper::url();
            $privacySchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => 'سياسة الخصوصية — أمان برايس',
                'url' => $privacyUrl,
                'description' => 'سياسة الخصوصية لموقع أمان برايس: لا نجمع بيانات شخصية، نستخدم ملفات تعريف ارتباط وGoogle Analytics بشكل مجهول، ونكشف عن استخدام Google Ads API الداخلي وروابط أمازون عبر /go/{asin}.',
                'isPartOf' => ['@type' => 'WebSite', 'name' => 'أمان برايس', 'url' => $siteUrl],
                'dateModified' => '2026-09-01',
                'inLanguage' => 'ar-EG',
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($privacySchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush

    <div class="max-w-4xl mx-auto">
        <nav class="mb-4 text-sm text-slate-500" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1">
                <li><a href="/" class="hover:text-primary-600">الرئيسية</a></li>
                <li><span aria-hidden="true">/</span> <span class="text-ink">سياسة الخصوصية</span></li>
            </ol>
        </nav>

        <div class="my-2 rounded-2xl border border-primary-100 bg-primary-50/60 p-5 text-base leading-7">
            <p class="font-bold text-ink">🔒 خصوصيتك بأمان — تصفح مجهول 100%</p>
            <p class="mt-1 text-ink/80">لا نطلب تسجيل حساب ولا نجمع بيانات شخصية. كل ما نستخدمه هو Cookies خفيفة وGoogle Analytics مجهول الهوية لتحسين السرعة.</p>
        </div>

        <article class="article-content rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <h1 class="text-3xl font-black leading-snug text-ink sm:text-4xl">سياسة الخصوصية (Privacy Policy)</h1>
            <p class="text-sm text-slate-500">آخر تحديث ومراجعة للسياسة: سبتمبر 2026</p>

            <p>
                مرحباً بك في موقع <strong>أمان برايس (amanprice.tech)</strong>. نحن نحترم خصوصيتك ونلتزم بحماية بياناتك الشخصية بالكامل. توضح هذه السياسة نوعية البيانات التي نجمعها، وكيفية معالجتها، والتدابير الأمنية التي نتخذها لضمان بقاء تصفحك آمناً بنسبة 100%.
            </p>

            <h2>1. جمع ومعالجة البيانات</h2>
            <p>
                نحن لا نطلب من زوار موقعنا أي عملية تسجيل حساب، أو تقديم بيانات تعريفية شخصية (مثل الاسم أو العنوان أو تفاصيل الدفع) لتصفح المقالات والأسعار وحاسبة التقسيط. تصفحك للموقع مجهول الهوية بالكامل بشكل افتراضي.
            </p>

            <h2>2. ملفات تعريف الارتباط (Cookies) وتحليلات Google</h2>
            <p>
                يستخدم موقع أمان برايس ملفات تعريف ارتباط (Cookies) تقنية خفيفة لحفظ تفضيلاتك (مثل خيارات حاسبة التقسيط والعملة). كما نستخدم خدمة <strong>Google Analytics</strong> لتحليل حركة المرور العامة على الموقع (مثل عدد الزوار، نوع الجهاز، والصفحات الأكثر زيارة) بشكل مجهول الهوية بالكامل، بهدف تحسين أداء وسرعة الموقع لزوارنا في مصر.
            </p>

            <h2>3. الإفصاح عن استخدام واجهة برمجة تطبيقات إعلانات جوجل (Google Ads API Disclosure)</h2>
            <p>
                يتكامل موقع أمان برايس (AmanPrice.tech) داخلياً مع واجهة برمجة تطبيقات إعلانات جوجل (<strong>Google Ads API</strong>) حصرياً لأغراض التخطيط والتحليل التحريري للكلمات المفتاحية في السوق المصري (Keyword Planning &amp; Search Volume Analytics).
            </p>
            <p>
                نحن نستخدم هذه الواجهة البرمجية لسحب تقديرات حجم البحث الشهري، ومستوى المنافسة، والبيانات الإحصائية للكلمات الدلالية العامة دون الوصول إلى أي حسابات إعلانية تخص المستخدمين.
            </p>
            <ul class="list-disc pr-6 text-sm leading-8">
                <li><strong>حماية البيانات والاستخدام المحدود:</strong> نحن لا نجمع، ولا نخزن، ولا نشارك، ولا ننقل أي بيانات شخصية أو حسابات مستخدمين مرتبطة بخدمات Google Ads API مع أي أطراف ثالثة.</li>
                <li><strong>طبيعة الاستخدام:</strong> جميع طلبات الـ API تُجرى آلياً لأغراض التخطيط الداخلي المجرد وتخضع بالكامل لسياسات (Google API Services User Data Policy) وشروط الاستخدام المحدود (Limited Use Requirements).</li>
            </ul>

            <h2>4. روابط الـ Affiliate والتحويلات الخارجية</h2>
            <p>
                أمان برايس موقع مستقل لمقارنة الأسعار ومراجعة الأجهزة. جميع روابط الشراء الخارجية الموجهة إلى أمازون مصر تمر عبر مسار تحويل داخلي آمن ومحمي (<code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono">/go/{asin}</code>). عندما تقوم بإتمام عملية الشراء عبر هذه الروابط، نتحصل على عمولة تسويقية بسيطة من برنامج <strong>Amazon Associates</strong> دون أي زيادة إضافية في السعر عليك نهائياً.
            </p>

            <h2>5. حماية وتأمين البيانات</h2>
            <p>
                نحن نطبق معايير أمنية متقدمة وتشفير كامل للبيانات عبر بروتوكول <strong>HTTPS</strong> المعتمد لحماية تصفحك. كما نطبق جدار حماية صارم عند الحافة (WAF via Cloudflare) لمنع أي محاولات اختراق أو مسح خبيث لملفات السيرفر.
            </p>

            <h2>6. الاتصال بنا</h2>
            <p>
                إذا كان لديك أي سؤال أو استفسار حول سياسة الخصوصية الخاصة بنا، يمكنك التواصل معنا مباشرة عبر البريد الإلكتروني الرسمي المخصص للمطورين وحماية البيانات: <a href="mailto:contact@amanprice.tech" class="text-primary-700 underline">contact@amanprice.tech</a>.
            </p>
        </article>
    </div>
</x-layouts.app>
