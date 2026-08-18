<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Jobs\SyncProductFromAmazonJob;
use App\Models\Product;
use App\Services\Amazon\AmazonUrlDataFetcher;
use App\Services\ImageUploaderService;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Throwable;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'المحتوى';

    protected static ?string $navigationLabel = 'المنتجات';

    protected static ?string $modelLabel = 'منتج';

    protected static ?string $pluralModelLabel = 'المنتجات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('affiliate_url')
                    ->label('رابط الأمازون التابع')
                    ->placeholder('ضع رابط المنتج هنا ثم اضغط زر السحب')
                    ->required()
                    ->url()
                    ->columnSpanFull()
                    ->suffixAction(
                        Action::make('fetchAmazonData')
                            ->icon('heroicon-m-arrow-path')
                            ->label('سحب البيانات تلقائياً')
                            ->color('success')
                            ->action(function (Get $get, Set $set, ?Product $record = null) {
                                $url = trim((string) $get('affiliate_url'));

                                if (blank($url)) {
                                    Notification::make()
                                        ->warning()
                                        ->title('رجاءً أدخل رابط المنتج أولاً')
                                        ->send();

                                    return;
                                }

                                $fetcher = App::make(AmazonUrlDataFetcher::class);

                                try {
                                    $data = $fetcher->fetch($url);
                                } catch (Throwable $e) {
                                    Notification::make()
                                        ->danger()
                                        ->title('تعذر سحب البيانات، تحقق من الرابط')
                                        ->body($e->getMessage())
                                        ->send();

                                    return;
                                }

                                if ($asin = $data['asin']) {
                                    $set('asin', $asin);
                                }
                                if (filled($data['title'])) {
                                    $set('title', $data['title']);
                                }
                                if (filled($data['brand'])) {
                                    $set('brand', $data['brand']);
                                }
                                if (filled($data['raw_reviews_text'])) {
                                    $set('raw_reviews_text', $data['raw_reviews_text']);
                                }
                                if (filled($data['price'])) {
                                    $set('price', $data['price']);
                                }
                                if (filled($data['original_price'])) {
                                    $set('original_price', $data['original_price']);
                                }
                                if (filled($data['image_url'])) {
                                    $imageUrl = $data['image_url'];

                                    if ($record) {
                                        $uploadedUrl = ImageUploaderService::uploadToR2($record, $imageUrl);
                                    } else {
                                        $seed = ($asin ?: $record?->asin ?: '').'-'.$data['title'].'-'.substr(md5($imageUrl), 0, 8);
                                        $uploadedUrl = ImageUploaderService::upload($imageUrl, $seed);
                                    }

                                    $set('image_url', $uploadedUrl ?? $imageUrl);
                                }
                                if (array_key_exists('rating', $data) && $data['rating'] !== null) {
                                    $set('rating', $data['rating']);
                                }
                                if (array_key_exists('review_count', $data) && $data['review_count'] !== null) {
                                    $set('review_count', $data['review_count']);
                                }
                                if ($asin === null) {
                                    Notification::make()
                                        ->warning()
                                        ->title('لم يتم العثور على كود ASIN في الرابط')
                                        ->body('تحقق من أن الرابط يتبع نمط /dp/XXXXXXXXXX')
                                        ->send();
                                }

                                $set('in_stock', (bool) ($data['in_stock'] ?? true));

                                Notification::make()
                                    ->success()
                                    ->title('تم سحب كافة بيانات المنتج بنجاح!')
                                    ->send();
                            })
                    ),

                Select::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name')
                    ->preload()
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->label('اسم المنتج')
                    ->required()
                    ->maxLength(255),
                TextInput::make('asin')
                    ->label('معرف ASIN')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'المنتج مسجّل بالفعل بهذا الـ ASIN — لن يُضاف مرة أخرى.',
                    ])
                    ->helperText('كود المنتج على أمازون، يُستخرج تلقائياً من الرابط'),
                TextInput::make('brand')
                    ->label('الماركة')
                    ->maxLength(255),
                TextInput::make('price')
                    ->label('السعر الحالي (ج.م)')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                TextInput::make('original_price')
                    ->label('السعر القديم المشطوب (ج.م)')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),
                TextInput::make('rating')
                    ->label('التقييم (من 5)')
                    ->numeric()
                    ->step(0.1)
                    ->minValue(0)
                    ->maxValue(5)
                    ->default(4.5),
                TextInput::make('review_count')
                    ->label('عدد المراجعات')
                    ->numeric()
                    ->required()
                    ->default(100),
                TextInput::make('image_url')
                    ->label('رابط الصورة')
                    ->url()
                    ->columnSpanFull(),
                Toggle::make('in_stock')
                    ->label('متوفر بالمخزون')
                    ->default(true),
                Toggle::make('supports_installment')
                    ->label('يدعم التقسيط البنكي')
                    ->default(true)
                    ->helperText('عند إيقافه، يُخفي جدول الأقساط في المقال'),
                Toggle::make('is_active')
                    ->label('منتج نشط')
                    ->default(true)
                    ->helperText('المنتجات النشطة فقط تُرسل لسكرابر المزامنة'),
                TextInput::make('last_synced_at')
                    ->label('آخر مزامنة')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('يتم تحديثه تلقائيًا عبر أمر amazon:sync-prices'),
                Section::make('آراء وتقييمات المشتريين في أمازون (لصياغة المقال)')
                    ->description('هذه التقييمات تم سحبها مرة واحدة فقط للاستعانة بها أثناء كتابة المقال ولا تتحدث تلقائياً لتوفير البروكسي.')
                    ->schema([
                        Textarea::make('raw_reviews_text')
                            ->label('نصوص مراجعات العملاء الأصلين')
                            ->readOnly()
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('بيانات ونصوص أمازون الخام (مرجع الأدمن)')
                    ->description('نصوص وتفاصيل منسوخة مباشرة من صفحة أمازون للاستعانة بها أثناء كتابة المراجعة والمقارنة.')
                    ->schema([
                        Textarea::make('raw_amazon_data')
                            ->label('النص المنسوخ من أمازون')
                            ->rows(10)
                            ->columnSpanFull()
                            ->placeholder('ضع هنا النصوص والأسعار والعروض المنسوخة من صفحة أمازون...'),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('المنتج')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->sortable(),
                TextColumn::make('brand')
                    ->label('الماركة')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('rating')
                    ->label('التقييم')
                    ->sortable(),
                TextColumn::make('review_count')
                    ->label('المراجعات')
                    ->sortable(),
                IconColumn::make('in_stock')
                    ->label('المخزون')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('supports_installment')
                    ->label('تقسيط')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sync_status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'synced' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('sync_attempts')
                    ->label('المحاولات')
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('آخر مزامنة')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('in_stock')
                    ->label('التوفر'),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('syncNow')
                        ->label('سحب البيانات تلقائياً')
                        ->icon('heroicon-m-arrow-path')
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalHeading('سحب البيانات من أمازون؟')
                        ->modalDescription('سيتم وضع المنتجات المحددة في قائمة المزامنة فوراً ليتم سحب آخر الأسعار والتوفر والتقييمات تلقائياً. قد يستغرق هذا عدة دقائق.')
                        ->action(function (Collection $records) {
                            $queued = 0;
                            $inactive = 0;

                            foreach ($records as $product) {
                                if (! $product->is_active) {
                                    $inactive++;

                                    continue;
                                }

                                // Clearing last_synced_at puts each product at the
                                // FRONT of the pending catalog sync queue
                                // (NULLS FIRST), so the scraper pulls it immediately.
                                $product->update([
                                    'sync_status' => Product::SYNC_STATUS_PENDING,
                                    'sync_attempts' => 0,
                                    'last_sync_error' => null,
                                    'last_synced_at' => null,
                                ]);

                                SyncProductFromAmazonJob::dispatch($product->id);
                                $queued++;
                            }

                            Notification::make()
                                ->success()
                                ->title("تمت جدولة سحب البيانات لـ {$queued} منتج")
                                ->body($inactive > 0
                                    ? "تم تجاوز {$inactive} منتج غير نشط — فعّلها أولاً لتزامنها."
                                    : null)
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('تفعيل المنتجات')
                        ->icon('heroicon-m-check-circle')
                        ->color('primary')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalHeading('تفعيل المنتجات المحددة؟')
                        ->modalDescription('سيتم تفعيل المنتجات المحددة لتظهر في الموقع وتدخل قائمة المزامنة التلقائية.')
                        ->action(function (Collection $records) {
                            $count = $records->count();

                            Product::query()
                                ->whereIn('id', $records->pluck('id'))
                                ->update(['is_active' => true]);

                            Notification::make()
                                ->success()
                                ->title("تم تفعيل {$count} منتج")
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('إيقاف المنتجات')
                        ->icon('heroicon-m-x-circle')
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalHeading('إيقاف المنتجات المحددة؟')
                        ->modalDescription('سيتم إيقاف المنتجات المحددة واختفاؤها من الموقع ومن قائمة المزامنة.')
                        ->action(function (Collection $records) {
                            $count = $records->count();

                            Product::query()
                                ->whereIn('id', $records->pluck('id'))
                                ->update(['is_active' => false]);

                            Notification::make()
                                ->success()
                                ->title("تم إيقاف {$count} منتج")
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('requeue')
                        ->label('إعادة للمزامنة')
                        ->icon('heroicon-m-arrow-up-on-square')
                        ->color('info')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalHeading('إعادة المنتجات للمزامنة؟')
                        ->modalDescription('سيتم إدراج المنتجات المحددة في مقدمة قائمة المزامنة، بغض النظر عن حالتها الحالية.')
                        ->action(function (Collection $records) {
                            $count = $records->count();

                            foreach ($records as $product) {
                                $product->update([
                                    'sync_status' => Product::SYNC_STATUS_PENDING,
                                    'sync_attempts' => 0,
                                    'last_sync_error' => null,
                                    'last_synced_at' => null,
                                ]);
                            }

                            Notification::make()
                                ->success()
                                ->title("تمت إعادة {$count} منتج للمزامنة")
                                ->body('ستُسحب بياناتها تلقائياً من أمازون في أقرب دورة سحاب.')
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
