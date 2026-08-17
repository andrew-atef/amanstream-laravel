<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('جميع المقالات')
                ->badge($this->getTableQuery()->count()),

            'reviews' => Tab::make('مراجعات ومقارنات 🛍️')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'review'))
                ->badge($this->getTableQuery()->where('type', 'review')->count())
                ->badgeColor('primary'),

            'blog' => Tab::make('المدونة ومقالات إرشادية ✍️')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'blog'))
                ->badge($this->getTableQuery()->where('type', 'blog')->count())
                ->badgeColor('info'),

            'published' => Tab::make('منشور 🚀')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', true))
                ->badge($this->getTableQuery()->where('is_published', true)->count())
                ->badgeColor('success'),

            'drafts' => Tab::make('مسودات / غير منشور 📝')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', false))
                ->badge($this->getTableQuery()->where('is_published', false)->count())
                ->badgeColor('warning'),
        ];
    }
}
