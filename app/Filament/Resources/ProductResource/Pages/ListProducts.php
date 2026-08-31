<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Widgets\AffiliateClicksWidget;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AffiliateClicksWidget::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('جميع المنتجات')
                ->badge($this->getTableQuery()->count()),

            'active' => Tab::make('نشطة ✅')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge($this->getTableQuery()->where('is_active', true)->count())
                ->badgeColor('success'),

            'inactive' => Tab::make('غير نشطة ⛔')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge($this->getTableQuery()->where('is_active', false)->count())
                ->badgeColor('danger'),

            'out_of_stock' => Tab::make('غير متوفرة / الخلصانة 🚫')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('in_stock', false))
                ->badge($this->getTableQuery()->where('in_stock', false)->count())
                ->badgeColor('danger'),

            'pending' => Tab::make('بانتظار المزامنة ⏳')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sync_status', Product::SYNC_STATUS_PENDING))
                ->badge($this->getTableQuery()->where('sync_status', Product::SYNC_STATUS_PENDING)->count())
                ->badgeColor('warning'),

            'synced' => Tab::make('مزامَنة ✅')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sync_status', Product::SYNC_STATUS_SYNCED))
                ->badge($this->getTableQuery()->where('sync_status', Product::SYNC_STATUS_SYNCED)->count())
                ->badgeColor('success'),

            'failed' => Tab::make('فشلت المزامنة ❌')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sync_status', Product::SYNC_STATUS_FAILED))
                ->badge($this->getTableQuery()->where('sync_status', Product::SYNC_STATUS_FAILED)->count())
                ->badgeColor('danger'),
        ];
    }
}
