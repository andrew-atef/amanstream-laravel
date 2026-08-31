<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class AffiliateClicksWidget extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 0;

    public function getHeading(): ?string
    {
        return 'إحصائيات النقرات';
    }

    protected function getStats(): array
    {
        $totalDbClicks = (int) Product::sum('clicks_count');
        $pendingTotal = (int) Cache::get('pending_clicks_total', 0);
        $totalClicks = $totalDbClicks + $pendingTotal;

        $todayKey = 'pending_clicks_today_'.date('Y-m-d');
        $pendingToday = (int) Cache::get($todayKey, 0);

        $topProduct = Product::query()
            ->where('clicks_count', '>', 0)
            ->orderByDesc('clicks_count')
            ->first();

        return [
            Stat::make('إجمالي النقرات', number_format($totalClicks))
                ->description($pendingTotal > 0
                    ? '+'.number_format($pendingTotal).' في الذاكرة المؤقتة'
                    : 'تمت المزامنة بالكامل')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('نقرات اليوم', number_format($pendingToday))
                ->description('لم تُحفظ بعد في قاعدة البيانات')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('أكثر منتج طلباً', $topProduct?->title ?? '—')
                ->description($topProduct
                    ? number_format($topProduct->clicks_count).' نقرة'
                    : 'لا توجد نقرات بعد')
                ->descriptionIcon('heroicon-m-fire')
                ->color('danger'),
        ];
    }
}
