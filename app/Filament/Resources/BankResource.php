<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankResource\Pages;
use App\Models\Bank;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class BankResource extends Resource
{
    protected static ?string $model = Bank::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'إعدادات التقسيط';

    protected static ?string $navigationLabel = 'البنوك والخطط';

    protected static ?string $modelLabel = 'بنك';

    protected static ?string $pluralModelLabel = 'البنوك والخطط';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name_ar')
                    ->label('اسم البنك بالعربي')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_en')
                    ->label('اسم البنك بالإنجليزية')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->label('كود البنك (cib, alex...)')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('logo_path')
                    ->label('رابط الشعار')
                    ->url()
                    ->nullable(),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),

                Repeater::make('plans')
                    ->relationship('plans')
                    ->label('خطط التقسيط المتاحة لهذا البنك')
                    ->schema([
                        TextInput::make('months')
                            ->label('عدد الشهور')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('interest_rate')
                            ->label('نسبة الفائدة %')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('admin_fee_percent')
                            ->label('الرسوم الإدارية %')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('min_order_amount')
                            ->label('الحد الأدنى للشراء (ج.م)')
                            ->numeric()
                            ->minValue(0)
                            ->default(500),
                        Toggle::make('is_zero_interest')
                            ->label('عرض 0% فائدة'),
                    ])
                    ->columnSpanFull()
                    ->defaultItems(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')
                    ->label('البنك')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('الكود')
                    ->searchable(),
                TextColumn::make('name_en')
                    ->label('الاسم اللاتيني')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plans_count')
                    ->label('الخطط')
                    ->counts('plans')
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('نشط'),
                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->placeholder('الكل')
                    ->trueLabel('نشط فقط')
                    ->falseLabel('غير نشط فقط'),
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
            'index' => Pages\ListBanks::route('/'),
            'create' => Pages\CreateBank::route('/create'),
            'edit' => Pages\EditBank::route('/{record}/edit'),
        ];
    }
}
