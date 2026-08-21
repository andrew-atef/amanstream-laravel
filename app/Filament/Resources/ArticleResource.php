<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Models\Product;
use App\Services\ArticleMediaService;
use App\Services\SEOHelper;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'المحتوى';

    protected static ?string $navigationLabel = 'المقالات';

    protected static ?string $modelLabel = 'مقال';

    protected static ?string $pluralModelLabel = 'المقالات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('بيانات المقال')
                    ->schema([
                        ToggleButtons::make('type')
                            ->label('نوع المقال')
                            ->options([
                                'review' => '🛍️ مراجعة / مقارنة منتج',
                                'blog' => '✍️ مقال عام / مدونة إرشادية',
                            ])
                            ->default('review')
                            ->live()
                            ->columnSpanFull()
                            ->helperText('مراجعات المنتجات تُعرض تحت /articles، بينما تظهر المقالات الإرشادية والمدونة تحت /blog.'),
                        TextInput::make('title')
                            ->label('عنوان المقال')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->formatStateUsing(fn (?string $state, ?Article $record): string => (string) ($record?->getRawOriginal('title') ?? $state ?? ''))
                            ->helperText('💡 نصيحة: يمكنك استخدام [year] في العنوان أو الوصف ليتغير رقم السنة تلقائياً في بداية كل عام.')
                            ->afterStateUpdated(
                                fn (string $operation, Set $set, ?string $state) => $operation === 'create'
                                    ? $set('slug', Str::slug(SEOHelper::renderDynamicYear((string) ($state ?? ''))))
                                    : null
                            ),
                        TextInput::make('slug')
                            ->label('المعرف (Slug)')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->reactive()
                            ->helperText('يُستخدم في رابط المقال مثل /articles/اسم-المقال'),
                        Select::make('product_id')
                            ->label('المنتج المرتبط (للمقالات الفردية فقط)')
                            ->relationship('product', 'title')
                            ->preload()
                            ->searchable()
                            ->nullable()
                            ->reactive()
                            ->visible(fn (Get $get): bool => $get('type') === 'review')
                            ->helperText('اختر منتجاً في حالة المقال الفردي (مراجعة منتج واحد). اتركه فارغاً إذا كان هذا مقال مقارنة أو تجميعة وتستخدم [comparison_table] أو [product_cards].'),
                        Placeholder::make('product_links')
                            ->label('روابط المنتج')
                            ->reactive()
                            ->visible(fn (Get $get): bool => $get('type') === 'review')
                            ->content(function (Get $get, ?Article $record) {
                                $product = Product::query()->find($get('product_id'));

                                $slug = $get('slug');

                                return view('filament.product-links', [
                                    'product' => $product,
                                    'productEditUrl' => $product
                                        ? ProductResource::getUrl('edit', ['record' => $product])
                                        : null,
                                    'articleUrl' => filled($slug)
                                        ? route('articles.show', ['slug' => $slug])
                                        : null,
                                ]);
                            })
                            ->columnSpanFull(),
                        Select::make('category_id')
                            ->label('التصنيف (للمراجعات والمقارنات فقط)')
                            ->relationship('category', 'name')
                            ->preload()
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->visible(fn (Get $get): bool => $get('type') === 'review')
                            ->helperText('المقالات الإرشادية والمدونة لا تُنسب إلى تصنيف — يظهر هذا الحقل فقط عندما يكون النوع "مراجعة / مقارنة منتج".'),
                        Toggle::make('is_published')
                            ->label('منشور')
                            ->default(true),
                        FileUpload::make('featured_image_url')
                            ->label('صورة الغلاف المميزة (Featured Thumbnail / OG Image)')
                            ->image()
                            ->disk('r2')
                            ->directory('articles')
                            ->visibility('public')
                            ->imageEditor()
                            ->fetchFileInformation(false)
                            ->afterStateHydrated(static function (BaseFileUpload $component, ?Article $record): void {
                                $raw = filled($record?->getRawOriginal('featured_image_url'))
                                    ? [(string) $record->getRawOriginal('featured_image_url')]
                                    : [];

                                $component->state(collect($raw)->mapWithKeys(
                                    static fn (string $file): array => [(string) Str::uuid() => $file],
                                )->all());
                            })
                            ->getUploadedFileUsing(fn (string $file): array => [
                                'name' => basename($file),
                                'size' => 0,
                                'type' => null,
                                'url' => $file,
                            ])
                            ->saveUploadedFileUsing(
                                fn (TemporaryUploadedFile $file, ?Article $record): ?string => ArticleMediaService::uploadAndOptimize($file, $record)
                            )
                            ->dehydrateStateUsing(fn ($state) => is_array($state) && filled($state)
                                ? (string) reset($state)
                                : null)
                            ->helperText('صورة اختيارية مخصصة للغلاف والمشاركة على السوشيال ميديا. إذا تُركت فارغة، سيتم استخدام صورة المنتج تلقائياً.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('محتوى المقال')
                    ->description('استخدم الأكواد الديناميكية التالية داخل النص لتوليد مكونات SEO جاهزة تلقائيًا (تُترجم أيضاً إلى Markdown نظيف للوكلاء الذكية):')
                    ->schema([
                        MarkdownEditor::make('content')
                            ->label('المحتوى')
                            ->required()
                            ->fileAttachmentsDisk('r2')
                            ->fileAttachmentsDirectory('articles')
                            ->fileAttachmentsVisibility('public')
                            ->saveUploadedFileAttachmentsUsing(
                                fn (TemporaryUploadedFile $file, ?Article $record): ?string => ArticleMediaService::uploadAndOptimize($file, $record)
                            )
                            ->getUploadedAttachmentUrlUsing(
                                fn (?string $file): ?string => $file
                            )
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'strike',
                                'heading',
                                'bulletList',
                                'orderedList',
                                'codeBlock',
                                'link',
                                'table',
                                'attachFiles',
                                'undo',
                                'redo',
                            ])
                            ->helperText(
                                'الأكواد: [price] السعر | [rating] التقييم | [installment] التقسيط (أو [installment]) | [interactive_installment] حاسبة التقسيط التفاعلية | [price_history] مخطط الأسعار | [buy_button] زر الشراء | [buy_button position="1"] لزر منتج محدد بالمقارنة | [summary_box] ملخص مميزات وعيوب | [summary_box pros="أ|ب" cons="ج|د" verdict="الحكم"] نسخة مخصصة | [summary_box position="1" pros="أ|ب" cons="ج|د" verdict="الحكم"] لملخص منتج محدد بالمقارنة | [comparison_table] جدول المقارنة | [product_cards] كروت المنتجات'
                            )
                            ->columnSpanFull(),
                        Textarea::make('comparison_markdown')
                            ->label('جدول المقارنة المخصص (Markdown Custom Table)')
                            ->placeholder("| وجه المقارنة | كاريير | ميديا | فريش |\n| :--- | :--- | :--- | :--- |\n| الكباس | T3 انفرتر | T3 AI | انفرتر اقتصادي |")
                            ->rows(6)
                            ->helperText('اختياري: اكتب جدول مقارنة منسق بـ Markdown ليظهر في [comparison_table]. إذا تُرك فارغاً، سيقوم النظام ببناء جدول تلقائي من مواصفات المنتجات.')
                            ->columnSpanFull(),
                    ]),
                Section::make('المنتجات المقارنة في المقال (Listicle Products)')
                    ->description('أضف منتجات متعددة هنا لتصنيع مقالات "أفضل X" والمقارنات. تظهر عبر [comparison_table] و [product_cards] داخل المحتوى.')
                    ->visible(fn (Get $get): bool => $get('type') === 'review')
                    ->schema([
                        Repeater::make('articleProducts')
                            ->relationship()
                            ->label('المنتجات المقارنة')
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->relationship('product', 'title')
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->reactive(),
                                Placeholder::make('product_links')
                                    ->label('روابط المنتج')
                                    ->reactive()
                                    ->content(function (Get $get) {
                                        $product = Product::query()->find($get('product_id'));

                                        return view('filament.product-links', [
                                            'product' => $product,
                                            'productEditUrl' => $product
                                                ? ProductResource::getUrl('edit', ['record' => $product])
                                                : null,
                                            'articleUrl' => null,
                                        ]);
                                    })
                                    ->columnSpanFull(),
                                TextInput::make('sort_order')
                                    ->label('الترتيب')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                                TextInput::make('badge_label')
                                    ->label('الشارة المميزة')
                                    ->placeholder('مثال: الخيار الأفضل بصفة عامة')
                                    ->maxLength(120),
                                Textarea::make('quick_verdict')
                                    ->label('الحكم السريع')
                                    ->placeholder('مثال: أفضل خيار لو هتشتغل تطوير ألعاب وتطبيقات AI')
                                    ->rows(2)
                                    ->columnSpanFull(),
                                MarkdownEditor::make('specs_markdown')
                                    ->label('المواصفات الرئيسية (صيغة Markdown)')
                                    ->placeholder("- **خامات المواسير والكباس:** نحاس خالص 100%\n- **نوع الشاشة:** ديجيتال رقمية\n- **نوع الكباس:** Rotary Scroll")
                                    ->helperText('اكتب المواصفات على شكل نقاط Markdown سريعة باستخدام (- **اسم الميزة:** القيمة)')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->collapsible()
                            ->addActionLabel('أضف منتجاً للمقارنة'),
                    ]),
                Section::make('إعدادات SEO')
                    ->schema([
                        TextInput::make('meta_title')
                            ->label('عنوان SEO')
                            ->maxLength(255)
                            ->formatStateUsing(fn (?string $state, ?Article $record): string => (string) ($record?->getRawOriginal('meta_title') ?? $state ?? ''))
                            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : $state)
                            ->helperText('💡 نصيحة: يمكنك استخدام [year] في العنوان أو الوصف ليتغير رقم السنة تلقائياً في بداية كل عام. يُترك فارغًا لاستخدام عنوان المقال'),
                        Textarea::make('meta_description')
                            ->label('وصف SEO')
                            ->rows(2)
                            ->formatStateUsing(fn (?string $state, ?Article $record): string => (string) ($record?->getRawOriginal('meta_description') ?? $state ?? ''))
                            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : $state)
                            ->helperText('💡 نصيحة: يمكنك استخدام [year] في العنوان أو الوصف ليتغير رقم السنة تلقائياً في بداية كل عام. يُترك فارغًا لإنشاء وصف تلقائي'),
                    ])
                    ->columns(2),
                Section::make('آراء وتقييمات المشتريين في أمازون (لصياغة المقال)')
                    ->description('هذه التقييمات تم سحبها مرة واحدة فقط للاستعانة بها أثناء كتابة المقال ولا تتحدث تلقائياً لتوفير البروكسي.')
                    ->visible(fn (Get $get): bool => $get('type') === 'review')
                    ->schema([
                        Textarea::make('product.raw_reviews_text')
                            ->label('نصوص مراجعات العملاء الأصلين')
                            ->readOnly()
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('product.raw_amazon_data')
                            ->label('بيانات ونصوص أمازون الخام (مرجع الأدمن)')
                            ->readOnly()
                            ->rows(8)
                            ->columnSpanFull(),
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
                    ->label('العنوان')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->tooltip(fn (Article $record): ?string => $record->title)
                    ->copyable()
                    ->copyMessage('تم النسخ')
                    ->weight('medium')
                    ->extraHeaderAttributes(['class' => 'min-w-[320px]']),

                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->badge()
                    ->color('sky')
                    ->sortable(),

                TextColumn::make('product.title')
                    ->label('المنتج المرتبط')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->tooltip(fn (Article $record): ?string => $record->product?->title)
                    ->limit(60)
                    ->default('— مقارنة / تجميعة —')
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label('منشور')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('حالة النشر')
                    ->trueLabel('منشور فقط')
                    ->falseLabel('مسودة فقط'),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('عرض')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Article $record): string => $record->isBlog() ? route('blog.show', $record->slug) : route('articles.show', $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (Article $record): bool => (bool) $record->is_published),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
