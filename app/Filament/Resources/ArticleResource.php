<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Models\Product;
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
                            ->afterStateUpdated(
                                fn (string $operation, Set $set, ?string $state) => $operation === 'create'
                                    ? $set('slug', Str::slug($state ?? ''))
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
                            ->label('التصنيف')
                            ->relationship('category', 'name')
                            ->preload()
                            ->searchable()
                            ->required(),
                        Toggle::make('is_published')
                            ->label('منشور')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('محتوى المقال')
                    ->description('استخدم الأكواد الديناميكية التالية داخل النص لتوليد مكونات SEO جاهزة تلقائيًا (تُترجم أيضاً إلى Markdown نظيف للوكلاء الذكية):')
                    ->schema([
                        MarkdownEditor::make('content')
                            ->label('المحتوى')
                            ->required()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'heading',
                                'bulletList',
                                'orderedList',
                                'codeBlock',
                                'link',
                                'undo',
                                'redo',
                            ])
                            ->helperText(
                                'الأكواد: [price] السعر | [rating] التقييم | [installment] التقسيط (أو [installment]) | [interactive_installment] حاسبة التقسيط التفاعلية | [price_history] مخطط الأسعار | [buy_button] زر الشراء | [buy_button position="1"] لزر منتج محدد بالمقارنة | [summary_box] ملخص مميزات وعيوب | [summary_box pros="أ|ب" cons="ج|د" verdict="الحكم"] نسخة مخصصة | [summary_box position="1" pros="أ|ب" cons="ج|د" verdict="الحكم"] لملخص منتج محدد بالمقارنة | [comparison_table] جدول المقارنة | [product_cards] كروت المنتجات'
                            )
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
                            ->helperText('يُترك فارغًا لاستخدام عنوان المقال'),
                        Textarea::make('meta_description')
                            ->label('وصف SEO')
                            ->rows(2)
                            ->helperText('يُترك فارغًا لإنشاء وصف تلقائي'),
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
                    ->limit(35)
                    ->tooltip(fn ($record) => $record->title),

                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->badge()
                    ->color('sky')
                    ->sortable(),

                TextColumn::make('product.title')
                    ->label('المنتج المرتبط')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->default('— مقارنة / تجميعة —'),

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
