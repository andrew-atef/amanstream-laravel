<?php

namespace App\Http\Controllers;

use App\Models\Article;

/**
 * llms.txt — the machine-readable index of the site for LLMs / AI agents.
 * Follows the llms.txt spec (https://llmstxt.org): a Markdown file of key
 * links and context, served at https://amanstream.me/llms.txt.
 */
class LlmsTxtController extends Controller
{
    public function index()
    {
        $siteName = config('app.name', 'أمان ستريم').'';
        $siteUrl = url('/');

        $articles = Article::query()
            ->with(['product'])
            ->where('is_published', true)
            ->latest()
            ->limit(100)
            ->get();

        $lines = [
            '> '.$siteName,
            '>',
            '> - دليل مستقل مصري لمراجعات وأسعار الأجهزة المنزلية والتكنولوجيا على أمازون مصر.',
            '> - أسعار محدثة يومياً، مقارنات، وحاسبة تقسيط.',
            '>',
            '>- '.$siteUrl.'/sitemap.xml',
            '>- '.$siteUrl.'/llms.txt',
            '',
            '# '.$siteName,
            '',
            'أكبر دليل عربي لمراجعات وأسعار الأجهزة المنزلية والتكييفات والغسالات والمراوح والراوترات في مصر، مع تحديث يومي مباشر من أمازون مصر وحاسبة تقسيط البنوك.',
            '',
            '## Directory',
            '',
        ];

        foreach ($articles as $article) {
            $title = $article->title;
            $price = $article->product && (float) $article->product->price > 0
                ? ' ('.$this->formatPrice((float) $article->product->price).' ج.م)'
                : '';

            $lines[] = '- ['.$title.']('.$siteUrl.'/articles/'.$article->slug.')'.$price;
        }

        $lines[] = '';
        $lines[] = '## Changelog';
        $lines[] = '- 2026-08-11: تفعيل Content Negotiation ليسلّم خوادم الذكاء الاصطناعي نسخة Markdown نقية لكل صفحة.';

        return response(implode(PHP_EOL, $lines), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, s-maxage=21600, max-age=86400',
        ]);
    }

    private function formatPrice(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}