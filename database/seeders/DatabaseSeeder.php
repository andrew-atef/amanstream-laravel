<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedAdminUser();
        $this->seedEgyptianDemoData();
        $this->seedHaierDemoData();
        $this->call(AmazonEgyptInstallmentSeeder::class);
    }

    /**
     * Create the Filament admin account.
     */
    private function seedAdminUser(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'مدير الموقع',
                'password' => 'K9#mX!9$vP2Q@zW5L',
                'email_verified_at' => now(),
            ]
        );

        $this->command?->info('Admin user ready: admin@example.com / password');
    }

    /**
     * Seed the Egyptian Amazon affiliate demo category, product and article.
     */
    private function seedEgyptianDemoData(): void
    {
        $category = Category::query()->updateOrCreate(
            ['slug' => Str::slug('تكييفات')],
            [
                'name' => 'تكييفات',
                'description' => 'أفضل أسعار ومميزات أجهزة التكييف المتاحة على أمازون مصر مع مراجعات واقعية من السوق المصري.',
            ]
        );

        $product = Product::query()->updateOrCreate(
            ['asin' => 'B01LCVQ0UY'],
            [
                'category_id' => $category->id,
                'title' => 'تكييف فريش 1.5 حصان بارد فقط بروفيشنال تربو',
                'brand' => 'Fresh',
                'price' => 18521.00,
                'rating' => 3.8,
                'review_count' => 280,
                'affiliate_url' => 'https://www.amazon.eg/dp/B01LCVQ0UY?tag=your-affiliate-tag-21',
                'image_url' => 'https://m.media-amazon.com/images/I/51-prefresh-1.5hp.webp',
                'in_stock' => true,
            ]
        );

        $article = Article::query()->updateOrCreate(
            ['slug' => Str::slug('سعر تكييف فريش 1.5 حصان بارد فقط ومميزاته وعيوبه')],
            [
                'product_id' => $product->id,
                'category_id' => $category->id,
                'title' => 'سعر تكييف فريش 1.5 حصان بارد فقط ومميزاته وعيوبه',
                'meta_title' => 'سعر تكييف فريش 1.5 حصان بارد فقط بروفيشنال تربو في مصر 2026 ومميزاته وعيوبه',
                'meta_description' => 'تعرف على سعر تكييف فريش 1.5 حصان بارد فقط بروفيشنال تربو في مصر، مع التقييم، خيارات التقسيط، المميزات والعيوب، وقرارنا النهائي.',
                'is_published' => true,
                'content' => $this->buildArticleContent(),
            ]
        );

        $this->seedPriceHistory($product, [
            [22400, 185],
            [21800, 156],
            [21200, 130],
            [21900, 104],
            [20100, 86],
            [18900, 70],
            [20400, 54],
            [19300, 38],
            [18650, 26],
            [18520, 16],
            [18700, 7],
            [18521, 0],
        ]);

        $this->command?->info('Demo data seeded: 1 category, 1 product, 1 article.');
        $this->command?->info("Article URL: /articles/{$article->slug}");
    }

    /**
     * Seed a realistic six-month historical price timeline for the Haier AC so
     * the Kanbakam-style barometer/chart has rich moment-to-moment data.
     */
    private function seedHaierDemoData(): void
    {
        $category = Category::query()->updateOrCreate(
            ['slug' => Str::slug('تكييفات')],
            [
                'name' => 'تكييفات',
                'description' => 'أفضل أسعار ومميزات أجهزة التكييف المتاحة على أمازون مصر مع مراجعات واقعية من السوق المصري.',
            ]
        );

        $product = Product::query()->updateOrCreate(
            ['asin' => 'B0HAIER0001'],
            [
                'category_id' => $category->id,
                'title' => 'تكييف هير Haier 1.5 حصان بارد فقط سمارت إنفرتر',
                'brand' => 'Haier',
                'price' => 21850.00,
                'original_price' => 24900.00,
                'rating' => 4.6,
                'review_count' => 145,
                'affiliate_url' => 'https://www.amazon.eg/dp/B0HAIER0001?tag=your-affiliate-tag-21',
                'image_url' => 'https://m.media-amazon.com/images/I/51-haier-1.5hp-inverter.webp',
                'in_stock' => true,
            ]
        );

        $this->seedPriceHistory($product, [
            [24900, 190],
            [24000, 160],
            [23400, 130],
            [24200, 100],
            [22800, 75],
            [24100, 55],
            [23000, 40],
            [22450, 25],
            [22000, 12],
            [22400, 5],
            [21850, 0],
        ]);

        Article::query()->updateOrCreate(
            ['slug' => Str::slug('سعر تكييف هير Haier 1.5 حصان بارد فقط سمارت إنفرتر')],
            [
                'product_id' => $product->id,
                'category_id' => $category->id,
                'title' => 'سعر تكييف هير Haier 1.5 حصان بارد فقط سمارت إنفرتر',
                'meta_title' => 'سعر تكييف هير 1.5 حصان بارد فقط سمارت إنفرتر في مصر 2026 ومميزاته وعيوبه',
                'meta_description' => 'تعرف على سعر تكييف هير Haier 1.5 حصان بارد فقط سمارت إنفرتر في مصر، مع مؤشر تاريخ السعر ومميزات وأبرز العيوب والقرار النهائي.',
                'is_published' => true,
                'content' => $this->buildHaierArticleContent(),
            ]
        );

        $this->command?->info("Haier article seeded: /articles/{$product->articles()->where('is_published', true)->first()?->slug}");
    }

    /**
     * Persist (idempotently) an ordered list of price points into the history
     * table, each keyed by [product, recorded_at] so re-seeding never duplicates.
     * After inserting, the ready-made lowest_price / highest_price columns and
     * the compact price_history_json window (max 10 points) are recomputed and
     * stored on the product row itself for zero-query page rendering.
     *
     * @param  array<int, array{0: float, 1: int}>  $timeline  [price, daysAgo]
     */
    private function seedPriceHistory(Product $product, array $timeline): void
    {
        foreach ($timeline as [$price, $daysAgo]) {
            ProductPriceHistory::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'recorded_at' => now()->subDays($daysAgo)->startOfDay(),
                ],
                ['price' => $price]
            );
        }

        $rows = ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->orderBy('recorded_at')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $product->lowest_price = (float) $rows->min('price');
        $product->highest_price = (float) $rows->max('price');
        $product->price_history_json = $rows
            ->take(-10)
            ->map(fn (ProductPriceHistory $row): array => [
                'p' => (float) $row->price,
                'd' => $row->recorded_at->format('d/m'),
            ])
            ->values()
            ->all();

        $product->save();
    }

    /**
     * Realistic Arabic article copy using every supported shortcode.
     */
    private function buildArticleContent(): string
    {
        return <<<'HTML'
<h2>لماذا تكييف فريش 1.5 حصان بارد فقط بروفيشنال تربو؟</h2>

<p>يبحث الكثير من المصريين في الصيف عن مكيف يوفر تبريدًا قويًا وسعرًا مناسبًا دون تعقيد. هنا يأتي <strong>تكييف فريش 1.5 حصان بارد فقط بروفيشنال تربو</strong>، وهو واحد من أكثر الموديلات طلبًا داخل السوق المصري بفضل العلامة التجارية المحلية الموثوقة وكفاءة الأداء اليومي.</p>

[summary_box]

<p>يتميز هذا الموديل بضاغط احترافي يضمن تبريدًا سريعًا للغرف المتوسطة، مع نظام موفر للطاقة يقلل من فاتورة الكهرباء الشهرية، مما يجعله خيارًا عمليًا للشقق والمكاتب الصغيرة. وعلى الرغم من أن الموديل «بارد فقط»، فإن موثوقية فريش في الصيانة وقطع الغيار تجعله بديلًا اقتصاديًا مدروسًا.</p>

<h2>سعر تكييف فريش 1.5 حصان في مصر</h2>

<p>يتفاوت السعر بين المتاجر وعبر المنصات الإلكترونية وفقًا للعروض ووسائل الدفع. على أمازون مصر، يبلغ السعر الحالي للموديل بروفيشنال تربو نحو:</p>

<p><strong>السعر الحالي على أمازون مصر:</strong> [price]</p>

<p>لا تشترِ قبل الاطلاع على مؤشر تاريخ السعر أدناه — فهو يوضّح هل نحن أمام سعر منخفض أم أن هناك وقتًا أفضل للشراء:</p>

[price_history]

[installment]

<p>تُعد خيارات التقسيط ميزة قوية للمتسوقين المصريين، حيث يمكن توزيع التكلفة على 12 شهرًا بدون فوائد عبر البنوك الشريكة مثل البنك الأهلي المصري والبنك التجاري الدولي، أو من خلال شركات التمويل الاستهلاكي مثل فاليو، مع مدة تصل إلى 60 شهرًا في بعض الحالات.</p>

<p>استخدم الحاسبة التفاعلية أدناه لاختيار مزود الخدمة ومدة التقسيط المناسبة لك واحسب دفعتك الشهرية التقريبية:</p>

[interactive_installment]

<h2>تقييم ومراجعات المستخدمين</h2>

<p>بناءً على تجارب المستخدمين الفعلية على أمازون مصر، يحصل الموديل على تقييم إجمالي معتدل، حيث يجمع بين الثناء على سرعة التبريد وسهولة الاستخدام، وبين بعض الملاحظات حول مستوى الضوضاء:</p>

<p><strong>تقييم المنتج:</strong> [rating]</p>

<p>تشير المراجعات إلى أن الأغلبية راضية عن الأداء في الغرف التي تتراوح بين 15 و20 مترًا مربعًا، مع الإشارة المتكررة إلى أهمية تركيب الجهاز على يد فنيين معتمدين لضمان أفضل كفاءة تبريد.</p>

<h2>المميزات الرئيسية</h2>

<ul>
<li>ضاغط «بروفيشنال تربو» عالي الكفاءة لتبريد أسرع.</li>
<li>تصميم أنيق يناسب معظم الديكورات الداخلية.</li>
<li>لوحة تحكم سهلة مع ريموت كونترول مريح.</li>
<li>ماركة فريش المصرية بانتشار واسع في قطع الغيار وخدمة ما بعد البيع.</li>
<li>استهلاك طاقة مدروس يلائم ظروف الجو المصري الحار.</li>
</ul>

<h2>الملاحظات الواجب الانتباه لها</h2>

<ul>
<li>الجهاز «بارد فقط» ولا يدعم وضع التدفئة.</li>
<li>مستوى الضوضاء قد يكون ملحوظًا على السرعات العالية.</li>
<li>يُفضّل الاعتماد على فني تركيب معتمد للحفاظ على الضمان.</li>
</ul>

<h2>هل تستحق الشراء؟</h2>

<p>إذا كنت تبحث عن تكييف عملي بتبريد قوي وسعر تنافسي داخل مصر، فإن هذا الموديل يستحق النظر إليه بجدية. ننصحك دائمًا بمقارنة السعر مع العروض الحالية والتحقق من توفر الدعم المحلي قبل اتخاذ القرار النهائي.</p>

<p>لأفضل سعر مضمون وضمان أمازون الرسمي، يمكنك الشراء الآن مباشرة:</p>

<p>[buy_button]</p>
HTML;
    }

    /**
     * Compact second demo article exercising the price-history widget.
     */
    private function buildHaierArticleContent(): string
    {
        return <<<'HTML'
<h2>تكييف هير Haier 1.5 حصان سمارت إنفرتر</h2>

<p>تكييف هير مبنيّ على ضاغط إنفرتر ذكي يجمع بين توفير الطاقة وتبريد سريع، مع تقييم مرتفع داخل السوق المصري. دعنا نلقي نظرة على سعره الحالي وتاريخه:</p>

<p><strong>السعر الحالي على أمازون مصر:</strong> [price]</p>

[price_history]

<h2>أبرز الملاحظات</h2>

<ul>
<li>إنفرتر يوفر حتى 40% من استهلاك الكهرباء.</li>
<li>تبريد سريع مع تشغيل هادئ على السرعات الليلية.</li>
<li>ضمان محلي متاح مع وكلاء منتشرون في جميع المحافظات.</li>
</ul>

<h2>القرار النهائي</h2>

<p>نوصي بمراجعة مؤشر تاريخ السعر أولًا قبل إتمام الشراء، ثم الشراء مباشرة بأفضل سعر متاح:</p>

<p>[buy_button]</p>
HTML;
    }
}
