<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class BulkImportProducts extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'استيراد جماعي (Bulk Import)';

    protected static ?string $navigationGroup = 'المحتوى';

    protected static string $view = 'filament.pages.bulk-import-products';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('category_id')
                    ->label('التصنيف')
                    ->options(Category::query()->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->helperText('سيتم ربط جميع المنتجات والمقالات المستوردة بهذا التصنيف'),
                Textarea::make('urls')
                    ->label('روابط منتجات أمازون')
                    ->required()
                    ->rows(10)
                    ->helperText('ضع روابط منتجات أمازون هنا - رابط واحد في كل سطر')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function import(): void
    {
        $data = $this->form->getState();

        $urls = $this->parseUrls((string) ($data['urls'] ?? ''));

        if ($urls === []) {
            Notification::make()
                ->warning()
                ->title('لم يتم إدخال أي روابط صالحة')
                ->send();

            return;
        }

        $result = $this->createProductsFromUrls($urls, (int) ($data['category_id'] ?? 0));

        $skipped = $result['skipped'];
        $hasSkipped = $skipped !== [];

        Notification::make()
            ->title($result['created'] > 0
                ? 'تم إضافة '.$result['created'].' منتج ومقال كمسودات تنتظر المزامنة والتنقيح!'
                : 'لم تتم إضافة أي منتج جديد')
            ->body($hasSkipped
                ? 'تخطّيت '.count($skipped).' منتجاً مسجلاً مسبقاً ولن يُكرَّر: '.implode('، ', array_slice($skipped, 0, 8))
                : null)
            ->color($hasSkipped ? 'warning' : 'success')
            ->send();

        $this->redirect(ProductResource::getUrl('index'));
    }

    /**
     * Creates draft products + articles from the given URLs.
     *
     * Products whose ASIN already exists in the database are refused (never
     * duplicated, and no draft article is created for them either).
     *
     * @return array{created: int, skipped: array<int, string>}
     */
    public function createProductsFromUrls(array $urls, int $categoryId): array
    {
        $created = 0;
        $skipped = [];
        $seen = [];
        $now = now();

        foreach ($urls as $url) {
            $url = trim($url);

            if ($url === '') {
                continue;
            }

            $asin = strtoupper(trim($this->extractAsin($url)));

            if ($asin === '' || in_array($asin, $seen, true)) {
                continue;
            }

            $seen[] = $asin;

            if (Product::query()->whereRaw('UPPER(asin) = ?', [$asin])->exists()) {
                $skipped[] = $asin;

                continue;
            }

            $product = Product::create([
                'category_id' => $categoryId,
                'title' => 'مسودة منتج - '.$asin,
                'affiliate_url' => $url,
                'price' => 0,
                'is_active' => false,
                'sync_status' => Product::SYNC_STATUS_PENDING,
            ]);

            Article::query()->create([
                'product_id' => $product->id,
                'category_id' => $categoryId,
                'title' => 'مسودة مقال - '.$asin,
                'slug' => 'draft-'.Str::slug($asin).'-'.$now->timestamp,
                'content' => implode("\n\n", [
                    '## مقدمة',
                    '[summary_box]',
                    '[price]',
                    '[interactive_installment]',
                    '[price_history]',
                    '[rating]',
                    '[buy_button]',
                ]),
                'is_published' => false,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array<int, string>
     */
    protected function parseUrls(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));
    }

    protected function extractAsin(string $url): string
    {
        if (preg_match('/(?:dp|gp\/product)\/([A-Za-z0-9]{10})/i', $url, $matches)) {
            return $matches[1];
        }

        return strtoupper(substr(md5($url), 0, 10));
    }
}
