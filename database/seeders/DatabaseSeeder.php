<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
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
                'password' => 'password',
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

        $this->command?->info('Demo data seeded: 1 category, 1 product, 1 article.');
        $this->command?->info("Article URL: /articles/{$article->slug}");
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
}
