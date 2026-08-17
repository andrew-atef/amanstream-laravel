<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Services\SEOHelper;

/**
 * llms.txt — the machine-readable index of the site for LLMs / AI agents.
 * Follows the llms.txt spec (https://llmstxt.org): a Markdown file of key
 * links and context. Prices are deliberately NOT embossed here — Amazon
 * prices change daily, and a pinned number would hallucinate stale data
 * into model answers. Prices are always resolved via live shortcodes.
 */
class LlmsTxtController extends Controller
{
    public function index()
    {
        $siteName = config('app.name', 'أمان برايس').'';
        $siteUrl = SEOHelper::url();

        $articles = Article::query()
            ->with(['product'])
            ->where('is_published', true)
            ->latest()
            ->limit(100)
            ->get();

        $categories = Category::query()
            ->whereHas('articles', fn ($query) => $query->where('is_published', true))
            ->orderBy('name')
            ->get();

        $lines = [
            '# '.$siteName.' | AmanPrice Egypt',
            '',
            '> '.$siteName.' هو دليل مستقل لمقارنة أسعار ومراجعات الأجهزة المنزلية والإلكترونيات على أمازون مصر مع حاسبة التقسيط البنكي.',
            '> الخادم يدعم نظام Content Negotiation لتقديم مخرجات Markdown نقية عند الترويسة `Accept: text/markdown`.',
            '> الأسعار حيّة عبر Shortcodes ولا تُثبّت نصوصاً في هذا الملف — اسأل الروابط التالية لتحصيل القيمة الحالية عند الطلب.',
            '',
            '## الأقسام الرئيسية (Categories)',
            '',
        ];

        foreach ($categories as $category) {
            $lines[] = '- ['.$category->name.']('.$siteUrl.'/category/'.$category->slug.')';
        }

        $lines = array_merge($lines, [
            '',
            '## مراجعات ومقارنات الأسعار المحدثة',
            '',
        ]);

        $reviews = $articles->filter(fn (Article $article) => $article->isReview());
        $blogPosts = $articles->filter(fn (Article $article) => $article->isBlog());

        foreach ($reviews as $article) {
            $lines[] = '- ['.$article->title.']('.$siteUrl.'/articles/'.$article->slug.')';
        }

        $lines = array_merge($lines, [
            '',
            '## المدونة والمقالات الإرشادية',
            '',
        ]);

        foreach ($blogPosts as $article) {
            $lines[] = '- ['.$article->title.']('.$siteUrl.'/blog/'.$article->slug.')';
        }

        $lines[] = '';
        $lines[] = '## السجلات التقنية (Sitemaps & Specs)';
        $lines[] = '- Sitemap: '.$siteUrl.'/sitemap.xml';
        $lines[] = '- llms.txt: '.$siteUrl.'/llms.txt';
        $lines[] = '';
        $lines[] = '## Changelog';
        $lines[] = '- 2026-08-15: إزالة الأسعار المثبتة من الملف ومنع هالوسة النماذج؛ إضافة روابط الأقسام النظيفة /category/{slug}.';

        return response(implode(PHP_EOL, $lines), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, s-maxage=21600, max-age=86400',
        ]);
    }
}
