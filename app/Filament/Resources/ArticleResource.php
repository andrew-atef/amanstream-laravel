<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
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
                            ->helperText('يُستخدم في رابط المقال مثل /articles/اسم-المقال'),
                        Select::make('product_id')
                            ->label('المنتج المرتبط')
                            ->relationship('product', 'title')
                            ->preload()
                            ->searchable()
                            ->required()
                            ->helperText('تُستخرج البيانات الديناميكية (السعر، التقييم...) من هذا المنتج'),
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
                    ->description('استخدم الأكواد الديناميكية التالية داخل النص لتوليد مكونات SEO جاهزة تلقائيًا:')
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
                                'اكتب بمحرر Markdown (يدعم HTML أيضاً). الأكواد المتاحة: [price] السعر | [rating] التقييم | [installment] التقسيط | [buy_button] زر الشراء | [summary_box] ملخص مميزات وعيوب'
                            )
                            ->columnSpanFull(),
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
                    ->limit(40),
                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('المنتج')
                    ->sortable()
                    ->limit(30),
                IconColumn::make('is_published')
                    ->label('الحالة')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('الحالة'),
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
