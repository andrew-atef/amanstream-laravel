<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Article;
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

        $urls = preg_split('/\r\n|\r|\n/', (string) ($data['urls'] ?? ''))
            ?: [];

        $urls = array_values(array_filter(array_map('trim', $urls), fn (string $url) => $url !== ''));

        if ($urls === []) {
            Notification::make()
                ->warning()
                ->title('لم يتم إدخال أي روابط صالحة')
                ->send();

            return;
        }

        $created = 0;
        $now = now();
        $categoryId = (int) ($data['category_id'] ?? 0);

        foreach ($urls as $url) {
            $asin = $this->extractAsin($url);

            $product = Product::query()->firstOrCreate(
                ['asin' => $asin],
                [
                    'category_id' => $categoryId,
                    'title' => 'مسودة منتج - '.$asin,
                    'affiliate_url' => $url,
                    'price' => 0,
                    'is_active' => false,
                    'sync_status' => Product::SYNC_STATUS_PENDING,
                ]
            );

            if ($product->articles()->where('slug', 'draft-'.$asin.'-'.$now->timestamp)->doesntExist()) {
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
            }

            $created++;
        }

        Notification::make()
            ->success()
            ->title('تم إضافة '.$created.' منتج ومقال كمسودات تنتظر المزامنة والتنقيح!')
            ->send();

        $this->redirect(ProductResource::getUrl('index'));
    }

    protected function extractAsin(string $url): string
    {
        if (preg_match('/(?:dp|gp\/product)\/([A-Za-z0-9]{10})/i', $url, $matches)) {
            return $matches[1];
        }

        return strtoupper(substr(md5($url), 0, 10));
    }
}
