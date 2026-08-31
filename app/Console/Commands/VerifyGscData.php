<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyGscData extends Command
{
    protected $signature = 'gsc:verify-data';

    protected $description = 'Verify GSC analytics data is correctly linked to articles.';

    public function handle(): int
    {
        $totalRows = DB::table('article_search_analytics')->count();
        $linkedRows = DB::table('article_search_analytics')->whereNotNull('article_id')->count();
        $unlinkedRows = $totalRows - $linkedRows;

        $this->info("📊 Total analytics rows: {$totalRows}");
        $this->info("🔗 Linked to articles: {$linkedRows}");
        $this->line("   Unlinked (homepage/categories/etc): {$unlinkedRows}");
        $this->newLine();

        // Top articles by clicks
        $this->info('── Top 15 Articles by Clicks (all time) ──');
        $this->table(
            ['#', 'المقال', 'النقرات', 'الظهور', 'CTR', 'الترتيب'],
            DB::table('article_search_analytics')
                ->join('articles', 'articles.id', '=', 'article_search_analytics.article_id')
                ->select('articles.id', 'articles.title')
                ->selectRaw('SUM(clicks) as total_clicks, SUM(impressions) as total_imp')
                ->selectRaw('ROUND(CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 100.0 / SUM(impressions) ELSE 0 END, 2) as ctr')
                ->selectRaw('ROUND(AVG(position), 1) as avg_pos')
                ->whereNotNull('article_search_analytics.article_id')
                ->groupBy('articles.id', 'articles.title')
                ->orderByDesc('total_clicks')
                ->limit(15)
                ->get()
                ->map(fn ($row) => [
                    $row->id,
                    mb_substr($row->title, 0, 50),
                    number_format($row->total_clicks),
                    number_format($row->total_imp),
                    $row->ctr.'%',
                    '#'.$row->avg_pos,
                ])
        );

        $this->newLine();

        // Articles with impressions but no clicks (optimization opportunities)
        $this->info('──Articles with Impressions but 0 Clicks (CTR optimization) ──');
        $this->table(
            ['#', 'المقال', 'الظهور', 'الترتيب'],
            DB::table('article_search_analytics')
                ->join('articles', 'articles.id', '=', 'article_search_analytics.article_id')
                ->select('articles.id', 'articles.title')
                ->selectRaw('SUM(impressions) as total_imp')
                ->selectRaw('ROUND(AVG(position), 1) as avg_pos')
                ->whereNotNull('article_search_analytics.article_id')
                ->groupBy('articles.id', 'articles.title')
                ->havingRaw('SUM(clicks) = 0 AND SUM(impressions) > 0')
                ->orderByDesc('total_imp')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    $row->id,
                    mb_substr($row->title, 0, 50),
                    number_format($row->total_imp),
                    '#'.$row->avg_pos,
                ])
        );

        $this->newLine();

        // Unlinked URLs (non-article pages)
        if ($unlinkedRows > 0) {
            $this->info('── Unlinked URLs (homepage, categories, etc) ──');
            $this->table(
                ['الرابط', 'النقرات', 'الظهور'],
                DB::table('article_search_analytics')
                    ->whereNull('article_id')
                    ->select('page_url')
                    ->selectRaw('SUM(clicks) as total_clicks, SUM(impressions) as total_imp')
                    ->groupBy('page_url')
                    ->orderByDesc('total_imp')
                    ->limit(10)
                    ->get()
                    ->map(fn ($row) => [
                        $row->page_url,
                        number_format($row->total_clicks),
                        number_format($row->total_imp),
                    ])
            );
        }

        return self::SUCCESS;
    }
}
