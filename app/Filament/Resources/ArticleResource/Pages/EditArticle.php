<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Models\Article;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view_live')
                ->label('عرض المقال على الموقع ↗')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('success')
                ->url(fn (Article $record): string => $record->isBlog() ? route('blog.show', $record->slug) : route('articles.show', $record->slug))
                ->openUrlInNewTab()
                ->visible(fn (Article $record): bool => (bool) $record->is_published),
            Actions\DeleteAction::make(),
        ];
    }
}
