<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Models\Product;
use App\Services\ArticleMediaService;
use App\Services\GoogleSearchConsoleService;
use App\Services\SEOHelper;
use Carbon\Carbon;
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
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Resolve GSC date range from a timeframe key.
     */
    protected static function resolveGscRange(string $period): array
    {
        $days = match ($period) {
            '48h' => 2,
            '7d' => 7,
            '28d' => 28,
            '30d' => 30,
            '90d' => 90,
            '180d' => 180,
            default => 28,
        };

        $endDate = Carbon::now()->subDays(1);
        $startDate = Carbon::now()->subDays($days);

        return [$startDate, $endDate];
    }

    protected static function getActiveGscPeriod(): string
    {
        $value = request()->input('tableFilters.gsc_period_filter.value');

        return in_array($value, ['48h', '7d', '28d', '30d', '90d', '180d'], true) ? $value : '28d';
    }

    protected static function applyGscAggregates(Builder $query, string $period): Builder
    {
        [$start, $end] = self::resolveGscRange($period);

        return $query->withGscAggregates($start, $end);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('📊 أداء المقال في جوجل ومخطط النمو')
                    ->description(function (?Article $record): string {
                        if (! $record) {
                            return 'اختر فترة التحليل لعرض البيانات.';
                        }
                        $latest = $record->searchAnalytics()->max('date');

                        return $latest
                            ? 'آخر بيانات: '.$latest.' — حدّث من جدول المقالات بضغط "تحديث بيانات جوجل GSC".'
                            : 'لم تتم المزامنة بعد — اضغط زر "تحديث بيانات جوجل GSC" في جدول المقالات.';
                    })
                    ->schema([
                        Select::make('gsc_period')
                            ->label('فترة التحليل')
                            ->options([
                                '48h' => '⚡ آخر 48 ساعة',
                                '7d' => '📅 آخر 7 أيام',
                                '28d' => '📅 آخر 28 يوماً (افتراضي GSC)',
                                '30d' => '📅 آخر 30 يوماً',
                                '90d' => '📊 آخر 3 شهور',
                                '180d' => '📈 آخر 6 شهور',
                            ])
                            ->default('28d')
                            ->live()
                            ->columnSpanFull(),
                        Placeholder::make('gsc_period_impressions')
                            ->label('الظهور في جوجل')
                            ->content(function (Get $get, ?Article $record): string {
                                if (! $record) {
                                    return '—';
                                }
                                [$start, $end] = self::resolveGscRange((string) $get('gsc_period'));
                                $m = $record->getGscMetricsForPeriod($start, $end);

                                return number_format($m['impressions']);
                            }),
                        Placeholder::make('gsc_period_clicks')
                            ->label('النقرات')
                            ->content(function (Get $get, ?Article $record): string {
                                if (! $record) {
                                    return '—';
                                }
                                [$start, $end] = self::resolveGscRange((string) $get('gsc_period'));
                                $m = $record->getGscMetricsForPeriod($start, $end);

                                return number_format($m['clicks']);
                            }),
                        Placeholder::make('gsc_period_ctr')
                            ->label('نسبة النقر CTR')
                            ->content(function (Get $get, ?Article $record): string {
                                if (! $record) {
                                    return '—';
                                }
                                [$start, $end] = self::resolveGscRange((string) $get('gsc_period'));
                                $m = $record->getGscMetricsForPeriod($start, $end);

                                return $m['ctr'].'%';
                            }),
                        Placeholder::make('gsc_period_position')
                            ->label('متوسط الترتيب')
                            ->content(function (Get $get, ?Article $record): string {
                                if (! $record) {
                                    return '—';
                                }
                                [$start, $end] = self::resolveGscRange((string) $get('gsc_period'));
                                $m = $record->getGscMetricsForPeriod($start, $end);

                                return $m['position'] > 0 ? '#'.$m['position'] : '—';
                            }),
                        Select::make('gsc_chart_period')
                            ->label('فترة المخطط')
                            ->options([
                                '7d' => '7 أيام',
                                '28d' => '28 يوماً',
                                '30d' => '30 يوماً',
                                '90d' => '3 شهور',
                            ])
                            ->default('28d')
                            ->live()
                            ->columnSpanFull(),
                        Placeholder::make('gsc_chart')
                            ->label('مخطط النقرات والظهور اليومي')
                            ->content(function (Get $get, ?Article $record): \Illuminate\Support\HtmlString {
                                if (! $record) {
                                    return new \Illuminate\Support\HtmlString('');
                                }
                                [$start, $end] = self::resolveGscRange((string) ($get('gsc_chart_period') ?? '28d'));
                                $chartData = $record->getGscChartData($start, $end);

                                return new \Illuminate\Support\HtmlString(
                                    view('forms.gsc-chart', ['chartData' => $chartData])->render()
                                );
                            })
                            ->columnSpanFull(),
                        Placeholder::make('gsc_top_queries')
                            ->label('🔍 أهم الكلمات المفتاحية (Top Queries)')
                            ->content(function (Get $get, ?Article $record): \Illuminate\Support\HtmlString {
                                if (! $record) {
                                    return new \Illuminate\Support\HtmlString('');
                                }
                                [$start, $end] = self::resolveGscRange((string) ($get('gsc_period') ?? '28d'));
                                $queries = $record->getTopQueriesForPeriod($start, $end, 20);

                                return new \Illuminate\Support\HtmlString(
                                    view('forms.gsc-queries', ['queries' => $queries])->render()
                                );
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->collapsed(),
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
                        Textarea::make('product.facebook_insights')
                            ->label('بوستات ومناقشات جروبات الفيسبوك (تجارب حقيقية)')
                            ->readOnly()
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('product.video_transcripts')
                            ->label('نصوص وتفريغ فيديوهات مراجعات اليوتيوب')
                            ->readOnly()
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('product.catalog_manual')
                            ->label('نصوص الكتالوج ودليل المستخدم الرسمي')
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
            ->modifyQueryUsing(function (Builder $query): Builder {
                $period = self::getActiveGscPeriod();
                [$start, $end] = self::resolveGscRange($period);

                return $query->withGscAggregates($start, $end);
            })
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->tooltip(fn (Article $record): ?string => $record->title)
                    ->copyable()
                    ->copyMessage('تم النسخ')
                    ->weight('medium')
                    ->extraHeaderAttributes(['class' => 'min-w-[280px]']),

                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->badge()
                    ->color('sky')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('product.title')
                    ->label('المنتج المرتبط')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->tooltip(fn (Article $record): ?string => $record->product?->title)
                    ->limit(50)
                    ->default('— مقارنة / تجميعة —')
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label('منشور')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('gsc_clicks_sum')
                    ->label('النقرات')
                    ->getStateUsing(fn (Article $record): int => (int) ($record->gsc_clicks_sum ?? 0))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? number_format($state) : '—')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('gsc_clicks_sum', $direction);
                    })
                    ->badge()
                    ->color(fn (Article $record): string => ((int) ($record->gsc_clicks_sum ?? 0)) > 0 ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('gsc_impressions_sum')
                    ->label('الظهور')
                    ->getStateUsing(fn (Article $record): int => (int) ($record->gsc_impressions_sum ?? 0))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? number_format($state) : '—')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('gsc_impressions_sum', $direction);
                    })
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('gsc_ctr_computed')
                    ->label('CTR %')
                    ->getStateUsing(function (Article $record): string {
                        $clicks = (int) ($record->gsc_clicks_sum ?? 0);
                        $imp = (int) ($record->gsc_impressions_sum ?? 0);
                        if ($imp === 0) {
                            return '—';
                        }
                        $ctr = round(($clicks / $imp) * 100, 2);

                        return $ctr.'%';
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw('(gsc_clicks_sum * 1.0 / NULLIF(gsc_impressions_sum, 0)) '.$direction);
                    })
                    ->badge()
                    ->color(function (Article $record): string {
                        $clicks = (int) ($record->gsc_clicks_sum ?? 0);
                        $imp = (int) ($record->gsc_impressions_sum ?? 0);
                        if ($imp === 0) {
                            return 'gray';
                        }
                        $ctr = ($clicks / $imp) * 100;

                        return $ctr >= 5 ? 'success' : ($ctr >= 2 ? 'warning' : 'danger');
                    })
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('gsc_position_avg')
                    ->label('الترتيب')
                    ->getStateUsing(function (Article $record): string {
                        $pos = $record->gsc_position_avg;
                        if ($pos === null || (float) $pos === 0.0) {
                            return '—';
                        }

                        return '#'.round((float) $pos, 1);
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('gsc_position_avg', $direction);
                    })
                    ->badge()
                    ->color(function (Article $record): string {
                        $pos = $record->gsc_position_avg;
                        if ($pos === null || (float) $pos === 0.0) {
                            return 'gray';
                        }
                        $v = (float) $pos;

                        return $v <= 3 ? 'success' : ($v <= 10 ? 'warning' : 'gray');
                    })
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('gsc_synced_at')
                    ->label('آخر مزامنة GSC')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('gsc_impressions_sum', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('حالة النشر')
                    ->trueLabel('منشور فقط')
                    ->falseLabel('مسودة فقط'),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name'),
                Tables\Filters\SelectFilter::make('gsc_period_filter')
                    ->label('فترة GSC')
                    ->options([
                        '48h' => '⚡ 48 ساعة',
                        '7d' => '📅 7 أيام',
                        '28d' => '📅 28 يوم (افتراضي GSC)',
                        '30d' => '📅 30 يوم',
                        '90d' => '📊 3 شهور',
                        '180d' => '📈 6 شهور',
                    ])
                    ->default('28d')
                    ->query(fn (Builder $query): Builder => $query),
                Tables\Filters\Filter::make('gsc_improvement_opportunity')
                    ->label('⚠️ فرص تحسين (ظهور ≥300 و CTR < 3%)')
                    ->query(function (Builder $query): Builder {
                        $period = self::getActiveGscPeriod();
                        [$start, $end] = self::resolveGscRange($period);
                        $startStr = $start->format('Y-m-d');
                        $endStr = $end->format('Y-m-d');

                        $ids = \App\Models\ArticleSearchAnalytic::query()
                            ->whereBetween('date', [$startStr, $endStr])
                            ->whereNotNull('article_id')
                            ->groupBy('article_id')
                            ->havingRaw('SUM(impressions) >= 300')
                            ->havingRaw('(SUM(clicks) * 100.0 / NULLIF(SUM(impressions), 0)) < 3.0')
                            ->pluck('article_id');

                        return $query->whereIn('id', $ids);
                    })
                    ->toggle(),
                Tables\Filters\Filter::make('gsc_zero_clicks')
                    ->label('🚫 بدون نقرات (ظهور > 0)')
                    ->query(function (Builder $query): Builder {
                        $period = self::getActiveGscPeriod();
                        [$start, $end] = self::resolveGscRange($period);
                        $startStr = $start->format('Y-m-d');
                        $endStr = $end->format('Y-m-d');

                        $ids = \App\Models\ArticleSearchAnalytic::query()
                            ->whereBetween('date', [$startStr, $endStr])
                            ->whereNotNull('article_id')
                            ->groupBy('article_id')
                            ->havingRaw('SUM(clicks) = 0')
                            ->havingRaw('SUM(impressions) > 0')
                            ->pluck('article_id');

                        return $query->whereIn('id', $ids);
                    })
                    ->toggle(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('sync_gsc')
                    ->label('تحديث بيانات جوجل GSC')
                    ->icon('heroicon-m-arrow-path')
                    ->color('success')
                    ->action(function () {
                        $gsc = app(GoogleSearchConsoleService::class);
                        $result = $gsc->syncHistoricalSearchAnalytics(90);

                        if ($result['error'] !== null) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('خطأ في GSC')
                                ->body($result['error'])
                                ->send();

                            return;
                        }

                        $upserted = $result['upserted'];

                        if ($upserted === 0) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('لم يتم استلام أي بيانات من Google Search Console.')
                                ->send();

                            return;
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title("تم مزامنة {$upserted} صف يومي من بيانات GSC بنجاح.")
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تحديث بيانات Google Search Console')
                    ->modalDescription('سيتم جلب بيانات يومية للنقرات والظهور من آخر 90 يوم وتحديث جميع المقالات المنشرة.')
                    ->modalSubmitActionLabel('ابدأ المزامنة'),
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
